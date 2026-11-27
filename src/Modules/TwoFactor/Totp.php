<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use TrayDigita\WP\Plugin\Core\Stack\Components\Auth\Totp as StackTotp;
use function ctype_digit;
use function hash_equals;
use function ord;
use function random_int;
use function sprintf;
use function str_pad;
use function str_replace;
use function strlen;
use function trim;
use function urlencode;
use const STR_PAD_LEFT;

/**
 * Time-based one-time passwords (RFC 6238 over RFC 4226).
 *
 * Thin adapter over the vendored Stack TOTP
 * (`TrayDigita\WP\Plugin\Core\Stack\Components\Auth\Totp`): secret
 * generation and the HMAC-SHA1 core come from Stack, so the
 * theme's hand-rolled TOTP implementation is gone. Two bugs in
 * the vendored copy were fixed in place (see the `Core fix`
 * notes in that file): the counter was emitted little-endian
 * instead of big-endian, and `verifyTOTP()` only checked future
 * time steps. The adapter still computes codes per counter
 * (rather than calling `StackTotp::generate()`) so the `$now`
 * parameter of `verifyCode()` - used by tests and by the CLI -
 * is honoured.
 *
 * Parameters are the authenticator-app defaults: SHA-1,
 * 30-second step, 6 digits. Verification accepts a window of
 * one step either side of the current time, so a code stays
 * valid while the user's clock drifts by up to a step.
 *
 * Backup codes are plain 8-digit codes shown as XXXX-XXXX. This
 * class only generates them; the module hashes them with
 * wp_hash_password() before storage and checks them with
 * wp_check_password(), so a database read never yields a usable
 * backup code.
 */
final class Totp
{
    /**
     * Default time step in seconds.
     */
    public const int DEFAULT_STEP = 30;

    /**
     * Default number of digits in a code.
     */
    public const int DEFAULT_DIGITS = 6;

    /**
     * Default verification window in steps either side of now.
     */
    public const int DEFAULT_WINDOW = 1;

    /**
     * Default number of backup codes generated at enrolment.
     */
    public const int BACKUP_CODE_COUNT = 10;

    /**
     * Generate a random TOTP secret.
     *
     * 20 random bytes (160 bits, the RFC 6238 recommendation)
     * via the Stack TOTP generator, as base32 without padding -
     * the form every authenticator app accepts for manual entry.
     *
     * @param int $bytes Random bytes to draw (20 by default).
     * @return string Base32 secret.
     */
    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16) {
            $bytes = 16;
        }
        if ($bytes > 32) {
            $bytes = 32;
        }
        return (new StackTotp())->generateKey($bytes);
    }

    /**
     * Generate the TOTP code for one counter value.
     *
     * RFC 4226 dynamic truncation over the HMAC-SHA1 the Stack
     * TOTP computes for the counter. Returns an empty string
     * when the secret is not valid base32.
     *
     * @param string $secret Base32 secret.
     * @param int $counter Time counter (unix time divided by the step).
     * @param int $digits Digits in the code.
     * @return string Zero-padded code, or '' when the secret is invalid.
     */
    public static function generateCode(string $secret, int $counter, int $digits = self::DEFAULT_DIGITS): string
    {
        $totp = new StackTotp();
        if (!$totp->isValidKey($secret)) {
            return '';
        }
        $hash = $totp->generateHmacOTP($secret, $counter);
        if (strlen($hash) !== 20) {
            return '';
        }
        $offset = ord($hash[19]) & 15;
        $value = ((ord($hash[$offset]) & 127) << 24)
            | ((ord($hash[$offset + 1]) & 255) << 16)
            | ((ord($hash[$offset + 2]) & 255) << 8)
            | (ord($hash[$offset + 3]) & 255);
        $modulo = 10 ** $digits;
        return str_pad((string) ($value % $modulo), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a submitted code against the current time.
     *
     * The code must be exactly the configured number of digits;
     * anything else fails without touching the secret. Codes are
     * compared with hash_equals() across the whole window, so no
     * early exit leaks which time step matched.
     *
     * @param string $secret Base32 secret.
     * @param string $code Submitted code (spaces and dashes are stripped).
     * @param int $window Steps accepted either side of now.
     * @param int|null $now Unix time to verify against (current time when null).
     * @param int $step Time step in seconds.
     * @param int $digits Digits in the code.
     * @return bool True when the code matches any step in the window.
     */
    public static function verifyCode(
        string $secret,
        string $code,
        int $window = self::DEFAULT_WINDOW,
        ?int $now = null,
        int $step = self::DEFAULT_STEP,
        int $digits = self::DEFAULT_DIGITS
    ): bool {
        $code = str_replace([' ', '-'], '', trim($code));
        if (strlen($code) !== $digits || !ctype_digit($code)) {
            return false;
        }
        $now = $now ?? time();
        $counter = (int) ($now / $step);
        $matched = false;
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::generateCode($secret, $counter + $i, $digits);
            if ($expected !== '' && hash_equals($expected, $code)) {
                $matched = true;
            }
        }
        return $matched;
    }

    /**
     * Build the otpauth:// provisioning URI for an authenticator app.
     *
     * Format: otpauth://totp/<issuer>:<account>?secret=...&issuer=...
     * &algorithm=SHA1&digits=6&period=30. The account is usually the
     * user's login or email; the issuer is the site name.
     *
     * @param string $secret Base32 secret.
     * @param string $accountName Account label (login or email).
     * @param string $issuer Issuer label (site name).
     * @return string Provisioning URI.
     */
    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = urlencode($issuer . ':' . $accountName);
        return 'otpauth://totp/' . $label
            . '?secret=' . urlencode($secret)
            . '&issuer=' . urlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /**
     * Generate single-use backup codes.
     *
     * Each code is 8 random digits shown as XXXX-XXXX. Uniqueness
     * inside the set is enforced by regeneration. The caller must
     * hash the returned codes before storing them and show them to
     * the user exactly once - there is no way to recover a lost
     * code, by design.
     *
     * @param int $count Codes to generate.
     * @return array<int, string> Plain backup codes.
     */
    public static function generateBackupCodes(int $count = self::BACKUP_CODE_COUNT): array
    {
        $codes = [];
        while (count($codes) < $count) {
            $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * Normalize a submitted backup code for comparison.
     *
     * Strips spaces and dashes and uppercases nothing (backup codes
     * are digits only). An empty result stays empty.
     *
     * @param string $code Submitted code.
     * @return string Digits only.
     */
    public static function normalizeBackupCode(string $code): string
    {
        return str_replace([' ', '-'], '', trim($code));
    }
}
