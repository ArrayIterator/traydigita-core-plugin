<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use TrayDigita\WP\Plugin\Core\Modules\Passkeys\PasskeysAjax;
use WP_User;
use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function delete_transient;
use function get_bloginfo;
use function get_current_user_id;
use function get_transient;
use function get_user_by;
use function is_string;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function wp_hash_password;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use const MINUTE_IN_SECONDS;

/**
 * AJAX endpoints for the Two-Factor module.
 *
 * Authenticated endpoints only (nonce `traydigita_2fa_user`,
 * `wp_ajax_*` only): TOTP enrolment, backup codes, and
 * trusted-device revocation. The caller always acts on their
 * own account, unless they hold `edit_users`, in which case
 * they may act on another user's account — the capability check
 * lives in targetUserId().
 *
 * Passkey endpoints (registration, removal, login options and
 * verification) live in PasskeysAjax now; the passkey action
 * names below are kept as deprecated aliases so existing
 * callers keep working.
 *
 * No endpoint ever logs or prints a secret, a code, or a
 * challenge.
 */
final class TwoFactorAjax
{
    // Authenticated actions (profile management).
    public const string TOTP_START_ACTION = 'traydigita_2fa_totp_start';

    public const string TOTP_CONFIRM_ACTION = 'traydigita_2fa_totp_confirm';

    public const string TOTP_DISABLE_ACTION = 'traydigita_2fa_totp_disable';

    public const string BACKUP_REGENERATE_ACTION = 'traydigita_2fa_backup_regenerate';

    /**
     * @deprecated Moved to PasskeysAjax::PASSKEY_REGISTER_OPTIONS_ACTION.
     */
    public const string PASSKEY_REGISTER_OPTIONS_ACTION = PasskeysAjax::PASSKEY_REGISTER_OPTIONS_ACTION;

    /**
     * @deprecated Moved to PasskeysAjax::PASSKEY_REGISTER_ACTION.
     */
    public const string PASSKEY_REGISTER_ACTION = PasskeysAjax::PASSKEY_REGISTER_ACTION;

    /**
     * @deprecated Moved to PasskeysAjax::PASSKEY_REMOVE_ACTION.
     */
    public const string PASSKEY_REMOVE_ACTION = PasskeysAjax::PASSKEY_REMOVE_ACTION;

    public const string DEVICE_REVOKE_ACTION = 'traydigita_2fa_device_revoke';

    public const string DEVICE_REVOKE_ALL_ACTION = 'traydigita_2fa_device_revoke_all';

    /**
     * @deprecated Moved to PasskeysAjax::PASSKEY_OPTIONS_ACTION.
     */
    public const string PASSKEY_OPTIONS_ACTION = PasskeysAjax::PASSKEY_OPTIONS_ACTION;

    /**
     * @deprecated Moved to PasskeysAjax::PASSKEY_VERIFY_ACTION.
     */
    public const string PASSKEY_VERIFY_ACTION = PasskeysAjax::PASSKEY_VERIFY_ACTION;

    /**
     * Register all AJAX actions.
     *
     * @return void
     */
    public static function register(): void
    {
        $authenticated = [
            self::TOTP_START_ACTION => 'totpStart',
            self::TOTP_CONFIRM_ACTION => 'totpConfirm',
            self::TOTP_DISABLE_ACTION => 'totpDisable',
            self::BACKUP_REGENERATE_ACTION => 'backupRegenerate',
            self::DEVICE_REVOKE_ACTION => 'deviceRevoke',
            self::DEVICE_REVOKE_ALL_ACTION => 'deviceRevokeAll',
        ];
        foreach ($authenticated as $action => $method) {
            add_action('wp_ajax_' . $action, [self::class, $method]);
        }
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    /**
     * Verify the authenticated-user nonce (dies on failure).
     *
     * @return void
     */
    private static function checkUserNonce(): void
    {
        check_ajax_referer(TwoFactorModule::NONCE_ACTION);
    }

    /**
     * Resolve the user an authenticated request may act on.
     *
     * The caller's own account is always allowed. Acting on
     * another user requires `edit_users`. Returns 0 when the
     * request is not allowed (or no user is logged in).
     *
     * @return int Target user id, or 0 when not allowed.
     */
    private static function targetUserId(): int
    {
        $current = get_current_user_id();
        if ($current <= 0) {
            return 0;
        }
        $requested = isset($_POST['user_id']) ? absint(wp_unslash($_POST['user_id'])) : 0;
        if ($requested <= 0 || $requested === $current) {
            return $current;
        }
        return current_user_can('edit_users') ? $requested : 0;
    }

    /**
     * Read a sanitized POST string.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postString(string $key): string
    {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    // ------------------------------------------------------------------
    // TOTP enrolment
    // ------------------------------------------------------------------

    /**
     * Start TOTP enrolment: issue a secret and its QR code.
     *
     * The secret is held in a short-lived transient and only
     * becomes active when the user confirms a correct code
     * (totpConfirm). Until then the account is unchanged. The
     * transient holds the secret encrypted (the same AES-256-CBC
     * as enrolled secrets), never plaintext — a database read
     * must not expose a usable secret.
     *
     * @return void
     */
    public static function totpStart(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $settings = TwoFactorModule::getSettings();
        if (!$settings['totp_enabled']) {
            wp_send_json_error(['message' => __('Authenticator app setup is disabled on this site.', 'traydigita-core')], 403);
        }
        if (TwoFactorModule::hasTotp($userId)) {
            wp_send_json_error(['message' => __('An authenticator app is already set up for this account.', 'traydigita-core')], 400);
        }
        $secret = Totp::generateSecret();
        // Encrypted at rest: an openssl failure stores '' and
        // totpConfirm treats it as an expired session, so the
        // user simply starts again — no plaintext secret is
        // ever written to the options table.
        set_transient(
            TwoFactorModule::totpPendingTransient($userId),
            TwoFactorModule::encryptSecret($secret),
            TwoFactorModule::TOTP_PENDING_MINUTES * MINUTE_IN_SECONDS
        );
        $issuer = (string) get_bloginfo('name');
        $uri = Totp::provisioningUri($secret, $user->user_login, $issuer);
        wp_send_json_success([
            'qr' => QrCode::renderSvg($uri),
            'secret' => $secret,
            'uri' => $uri,
        ]);
    }

    /**
     * Confirm TOTP enrolment with a code from the app.
     *
     * On success the secret is activated AND ten backup codes
     * are generated and returned (plain text, shown once). The
     * order is deliberate: backup codes exist before 2FA is
     * active, so the user can never lock themselves out by
     * losing the authenticator app. A wrong code counts as a
     * failed attempt (same throttling and logging as the
     * challenge path) and a locked-out user gets no new try.
     *
     * @return void
     */
    public static function totpConfirm(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!TwoFactorModule::getSettings()['totp_enabled']) {
            wp_send_json_error(['message' => __('Authenticator app setup is disabled on this site.', 'traydigita-core')], 403);
        }
        if (TwoFactorModule::isLockedOut($userId)) {
            /* translators: %d is the number of minutes until the lockout ends. */
            $lockedMessage = sprintf(
                __('Too many failed attempts. Please try again in %d minutes.', 'traydigita-core'),
                TwoFactorModule::getSettings()['lockout_minutes']
            );
            wp_send_json_error(['message' => $lockedMessage], 429);
        }
        $stored = get_transient(TwoFactorModule::totpPendingTransient($userId));
        // The pending secret is stored encrypted (see
        // totpStart). A value written before that change is
        // plaintext and cannot decrypt, so it is treated as an
        // expired session and the user simply starts again.
        $secret = is_string($stored) ? TwoFactorModule::decryptSecret($stored) : null;
        if ($secret === null) {
            wp_send_json_error(['message' => __('The setup session expired. Please start again.', 'traydigita-core')], 400);
        }
        $code = self::postString('code');
        if (!Totp::verifyCode($secret, $code)) {
            TwoFactorModule::recordFailedAttempt($userId, 'totp', 'bad_code');
            wp_send_json_error(['message' => __('That code did not match. Check the time on your device and try again.', 'traydigita-core')], 400);
        }
        TwoFactorModule::setTotpSecret($userId, $secret);
        delete_transient(TwoFactorModule::totpPendingTransient($userId));
        $codes = Totp::generateBackupCodes();
        $hashes = [];
        foreach ($codes as $plain) {
            $hashes[] = wp_hash_password($plain);
        }
        TwoFactorModule::setBackupCodeHashes($userId, $hashes);
        TwoFactorModule::logEvent($userId, 'totp', 'success', 'enrolled');
        wp_send_json_success([
            'backup_codes' => $codes,
            'remaining' => count($codes),
            'message' => __('Authenticator app enabled. Save your backup codes — they are shown only once.', 'traydigita-core'),
        ]);
    }

    /**
     * Remove the authenticator app (and its backup codes).
     *
     * @return void
     */
    public static function totpDisable(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        TwoFactorModule::removeTotp($userId);
        TwoFactorModule::logEvent($userId, 'totp', 'success', 'disabled');
        wp_send_json_success(['message' => __('Authenticator app removed.', 'traydigita-core')]);
    }

    /**
     * Generate a fresh set of backup codes.
     *
     * Requires an active TOTP enrolment. The old codes stop
     * working immediately; the new ones are returned plain text
     * and shown once.
     *
     * @return void
     */
    public static function backupRegenerate(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!TwoFactorModule::hasTotp($userId)) {
            wp_send_json_error(['message' => __('Set up an authenticator app first.', 'traydigita-core')], 400);
        }
        $codes = Totp::generateBackupCodes();
        $hashes = [];
        foreach ($codes as $plain) {
            $hashes[] = wp_hash_password($plain);
        }
        TwoFactorModule::setBackupCodeHashes($userId, $hashes);
        TwoFactorModule::logEvent($userId, 'backup', 'success', 'regenerated');
        wp_send_json_success([
            'backup_codes' => $codes,
            'remaining' => count($codes),
            'message' => __('New backup codes generated. The old codes no longer work.', 'traydigita-core'),
        ]);
    }

    // ------------------------------------------------------------------
    // Trusted devices
    // ------------------------------------------------------------------

    /**
     * Revoke one trusted device.
     *
     * @return void
     */
    public static function deviceRevoke(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $deviceId = self::postString('device_id');
        if ($deviceId === '' || !TwoFactorModule::revokeDevice($userId, $deviceId)) {
            wp_send_json_error(['message' => __('That device was not found.', 'traydigita-core')], 400);
        }
        wp_send_json_success(['message' => __('Device removed.', 'traydigita-core')]);
    }

    /**
     * Revoke all trusted devices for an account.
     *
     * @return void
     */
    public static function deviceRevokeAll(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        TwoFactorModule::revokeAllDevices($userId);
        wp_send_json_success(['message' => __('All trusted devices removed.', 'traydigita-core')]);
    }
}
