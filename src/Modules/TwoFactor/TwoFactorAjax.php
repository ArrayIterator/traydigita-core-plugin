<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use WP_User;
use function __;
use function absint;
use function add_action;
use function admin_url;
use function bin2hex;
use function check_ajax_referer;
use function count;
use function ctype_digit;
use function current_user_can;
use function delete_transient;
use function get_bloginfo;
use function get_current_user_id;
use function get_transient;
use function get_user_by;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function random_bytes;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function time;
use function wp_hash_password;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_set_auth_cookie;
use function wp_unslash;
use const MINUTE_IN_SECONDS;

/**
 * AJAX endpoints for the Two-Factor module.
 *
 * Two groups:
 * - Authenticated endpoints (nonce `traydigita_2fa_user`,
 *   `wp_ajax_*` only): TOTP enrolment, backup codes, passkey
 *   registration and removal, trusted-device revocation. The
 *   caller always acts on their own account, unless they hold
 *   `edit_users`, in which case they may act on another user's
 *   account — the capability check lives in targetUserId().
 * - Unauthenticated endpoints (nonce `traydigita_2fa_login`,
 *   `wp_ajax_nopriv_*` only): passkey options and verification
 *   for the login page. These run before any auth cookie exists,
 *   so they are rate-limited per user (lockout) and never reveal
 *   whether a credential id exists.
 *
 * WebAuthn responses are posted as a JSON string in the `data`
 * field (form-encoded, so check_ajax_referer() keeps working);
 * the frontend converts ArrayBuffers to base64url first.
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

    public const string PASSKEY_REGISTER_OPTIONS_ACTION = 'traydigita_2fa_passkey_register_options';

    public const string PASSKEY_REGISTER_ACTION = 'traydigita_2fa_passkey_register';

    public const string PASSKEY_REMOVE_ACTION = 'traydigita_2fa_passkey_remove';

    public const string DEVICE_REVOKE_ACTION = 'traydigita_2fa_device_revoke';

    public const string DEVICE_REVOKE_ALL_ACTION = 'traydigita_2fa_device_revoke_all';

    // Unauthenticated actions (login page).
    public const string PASSKEY_OPTIONS_ACTION = 'traydigita_2fa_passkey_options';

    public const string PASSKEY_VERIFY_ACTION = 'traydigita_2fa_passkey_verify';

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
            self::PASSKEY_REGISTER_OPTIONS_ACTION => 'passkeyRegisterOptions',
            self::PASSKEY_REGISTER_ACTION => 'passkeyRegister',
            self::PASSKEY_REMOVE_ACTION => 'passkeyRemove',
            self::DEVICE_REVOKE_ACTION => 'deviceRevoke',
            self::DEVICE_REVOKE_ALL_ACTION => 'deviceRevokeAll',
        ];
        foreach ($authenticated as $action => $method) {
            add_action('wp_ajax_' . $action, [self::class, $method]);
        }
        add_action('wp_ajax_nopriv_' . self::PASSKEY_OPTIONS_ACTION, [self::class, 'passkeyOptions']);
        add_action('wp_ajax_nopriv_' . self::PASSKEY_VERIFY_ACTION, [self::class, 'passkeyVerify']);
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
     * Verify the login-page nonce (dies on failure).
     *
     * @return void
     */
    private static function checkLoginNonce(): void
    {
        check_ajax_referer(TwoFactorModule::LOGIN_NONCE_ACTION);
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

    /**
     * Read a JSON object posted as a form field.
     *
     * @param string $key Field name.
     * @return array<string, mixed>|null Decoded object, or null on bad input.
     */
    private static function postJson(string $key): ?array
    {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return null;
        }
        $decoded = json_decode(wp_unslash($_POST[$key]), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Read a pending-login token posted as the `token` field.
     *
     * Only a 64-character lowercase hex token is accepted.
     *
     * @return string
     */
    private static function challengeTokenFromPost(): string
    {
        $token = self::postString('token');
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : '';
    }

    /**
     * Build the WebAuthn server for this site.
     *
     * @return WebAuthn
     */
    private static function webauthn(): WebAuthn
    {
        return new WebAuthn(TwoFactorModule::rpId(), TwoFactorModule::expectedOrigin());
    }

    /**
     * Send a generic WebAuthn failure response.
     *
     * The exception reason is logged for the administrator but
     * never shown to the visitor, so a failed ceremony cannot
     * leak internals (or be used to probe the server). This
     * always ends the request with wp_send_json_error(), so it
     * never returns.
     *
     * @param WebAuthnException $exception The failure.
     * @param int $userId User id, for the failed-attempt log (0 logs the attempt as `unknown_user`, not skipped).
     * @param string $method Method label for the log ('passkey').
     * @return never
     */
    private static function sendWebauthnError(WebAuthnException $exception, int $userId = 0, string $method = 'passkey'): never
    {
        if ($userId > 0) {
            TwoFactorModule::recordFailedAttempt($userId, $method, $exception->reason);
        } else {
            // Usernameless failure with an unresolvable user
            // handle: there is no user to count attempts against,
            // but the attempt itself is still logged (user 0) so
            // it is not invisible to the Activity Log.
            TwoFactorModule::logEvent(0, $method, 'failed', 'unknown_user');
        }
        wp_send_json_error(
            ['message' => __('Passkey verification failed. Please try again.', 'traydigita-core'), 'code' => $exception->reason],
            400
        );
    }

    // ------------------------------------------------------------------
    // TOTP enrolment
    // ------------------------------------------------------------------

    /**
     * Start TOTP enrolment: issue a secret and its QR code.
     *
     * The secret is held in a short-lived transient and only
     * becomes active when the user confirms a correct code
     * (totpConfirm). Until then the account is unchanged.
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
        set_transient(
            TwoFactorModule::totpPendingTransient($userId),
            $secret,
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
        $secret = get_transient(TwoFactorModule::totpPendingTransient($userId));
        if (!is_string($secret) || $secret === '') {
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
    // Passkey registration
    // ------------------------------------------------------------------

    /**
     * Issue passkey registration options.
     *
     * The challenge is stored server-side in a short-lived
     * transient and never trusted from the client. Registration
     * is refused on plain HTTP (except localhost) — the profile
     * page explains why instead of offering a button that fails.
     *
     * @return void
     */
    public static function passkeyRegisterOptions(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!TwoFactorModule::getSettings()['passkey_enabled']) {
            wp_send_json_error(['message' => __('Passkey registration is disabled on this site.', 'traydigita-core')], 403);
        }
        if (!TwoFactorModule::isPasskeyAvailable()) {
            wp_send_json_error(['message' => __('Passkeys need a secure connection (HTTPS). This site is served over plain HTTP.', 'traydigita-core')], 400);
        }
        $exclude = [];
        foreach (TwoFactorModule::getPasskeys($userId) as $record) {
            $exclude[] = ['id' => (string) ($record['id'] ?? '')];
        }
        $built = self::webauthn()->registrationOptions(
            (string) $userId,
            $user->user_login,
            $user->display_name,
            (string) get_bloginfo('name'),
            $exclude
        );
        set_transient(
            TwoFactorModule::passkeyRegChallengeTransient($userId),
            $built['challenge'],
            TwoFactorModule::PASSKEY_CHALLENGE_MINUTES * MINUTE_IN_SECONDS
        );
        wp_send_json_success(['options' => $built['options']]);
    }

    /**
     * Verify a passkey registration response and store the key.
     *
     * The challenge is single-use: it is deleted before
     * verification, so a replayed response cannot register a
     * second key. A credential id that already exists replaces
     * the old record (re-registration updates the key).
     *
     * @return void
     */
    public static function passkeyRegister(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!TwoFactorModule::getSettings()['passkey_enabled'] || !TwoFactorModule::isPasskeyAvailable()) {
            wp_send_json_error(['message' => __('Passkey registration is not available on this site.', 'traydigita-core')], 403);
        }
        $challenge = get_transient(TwoFactorModule::passkeyRegChallengeTransient($userId));
        delete_transient(TwoFactorModule::passkeyRegChallengeTransient($userId));
        if (!is_string($challenge) || $challenge === '') {
            wp_send_json_error(['message' => __('The registration session expired. Please start again.', 'traydigita-core')], 400);
        }
        $data = self::postJson('data');
        if ($data === null) {
            wp_send_json_error(['message' => __('Passkey registration failed. Please try again.', 'traydigita-core')], 400);
        }
        try {
            $record = self::webauthn()->verifyRegistration($data, $challenge);
        } catch (WebAuthnException $exception) {
            self::sendWebauthnError($exception, $userId);
        }
        /** @var array{id: string, public_key: string, counter: int, transports: array<int, string>, aaguid: string, backup_eligible: bool, backup_state: bool, user_verified: bool} $record */
        $existing = TwoFactorModule::getPasskeys($userId);
        /* translators: %d is the passkey number for this account. */
        $label = sprintf(__('Passkey %d', 'traydigita-core'), count($existing) + 1);
        TwoFactorModule::addPasskey($userId, [
            'id' => $record['id'],
            'public_key' => $record['public_key'],
            'counter' => $record['counter'],
            'transports' => $record['transports'],
            'aaguid' => $record['aaguid'],
            'backup_eligible' => $record['backup_eligible'],
            'backup_state' => $record['backup_state'],
            'user_verified' => $record['user_verified'],
            'label' => $label,
            'created' => time(),
            'last_used' => 0,
        ]);
        TwoFactorModule::logEvent($userId, 'passkey', 'success', 'registered');
        wp_send_json_success([
            'message' => __('Passkey added. You can now sign in with your fingerprint or Face ID.', 'traydigita-core'),
            'credential_id' => $record['id'],
            'label' => $label,
        ]);
    }

    /**
     * Remove one passkey from an account.
     *
     * @return void
     */
    public static function passkeyRemove(): void
    {
        self::checkUserNonce();
        $userId = self::targetUserId();
        $user = $userId > 0 ? get_user_by('id', $userId) : false;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $credentialId = self::postString('credential_id');
        if ($credentialId === '' || !TwoFactorModule::removePasskey($userId, $credentialId)) {
            wp_send_json_error(['message' => __('That passkey was not found.', 'traydigita-core')], 400);
        }
        TwoFactorModule::logEvent($userId, 'passkey', 'success', 'removed');
        wp_send_json_success(['message' => __('Passkey removed.', 'traydigita-core')]);
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

    // ------------------------------------------------------------------
    // Passkey login (unauthenticated)
    // ------------------------------------------------------------------

    /**
     * Issue passkey authentication options for the login page.
     *
     * Two modes, both unauthenticated (the login nonce is the
     * only gate — the challenge itself is worthless without the
     * private key):
     * - `challenge`: second factor after a password login. The
     *   pending-login token identifies the user; only their own
     *   credentials are offered, and a locked-out user gets no
     *   challenge at all.
     * - `login`: usernameless login from the login form. No
     *   allow list — the browser offers any discoverable
     *   credential — and the token returned here must be sent
     *   back with the verification, so the challenge cannot be
     *   replayed against a different session.
     *
     * @return void
     */
    public static function passkeyOptions(): void
    {
        self::checkLoginNonce();
        $settings = TwoFactorModule::getSettings();
        if (!$settings['passkey_enabled'] || !TwoFactorModule::isPasskeyAvailable()) {
            wp_send_json_error(['message' => __('Passkey sign-in is not available on this site.', 'traydigita-core')], 403);
        }
        $mode = self::postString('mode');
        $webauthn = self::webauthn();
        if ($mode === 'challenge') {
            $token = self::challengeTokenFromPost();
            $pending = TwoFactorModule::getPendingLogin($token);
            if ($pending === null) {
                TwoFactorModule::logEvent(0, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('This verification link has expired. Please log in again.', 'traydigita-core')], 400);
            }
            $userId = (int) $pending['user_id'];
            if (TwoFactorModule::isLockedOut($userId)) {
                TwoFactorModule::logEvent($userId, 'passkey', 'locked', 'locked_out');
                wp_send_json_error(['message' => __('Too many failed attempts. Please try again later.', 'traydigita-core')], 429);
            }
            $allow = [];
            foreach (TwoFactorModule::getPasskeys($userId) as $record) {
                $allow[] = ['id' => (string) ($record['id'] ?? '')];
            }
            if ($allow === []) {
                wp_send_json_error(['message' => __('This account has no passkeys.', 'traydigita-core')], 400);
            }
            $built = $webauthn->assertionOptions($allow);
            set_transient(
                TwoFactorModule::passkeyChallengeTransient($token),
                ['challenge' => $built['challenge'], 'user_id' => $userId],
                TwoFactorModule::PASSKEY_CHALLENGE_MINUTES * MINUTE_IN_SECONDS
            );
            wp_send_json_success(['options' => $built['options']]);
        }
        if ($mode === 'login') {
            $token = bin2hex(random_bytes(16));
            $built = $webauthn->assertionOptions([]);
            set_transient(
                TwoFactorModule::passkeyChallengeTransient($token),
                ['challenge' => $built['challenge'], 'user_id' => 0],
                TwoFactorModule::PASSKEY_CHALLENGE_MINUTES * MINUTE_IN_SECONDS
            );
            wp_send_json_success(['options' => $built['options'], 'token' => $token]);
        }
        wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
    }

    /**
     * Verify a passkey authentication response and log the user in.
     *
     * This flow does not run the Turnstile check: the WebAuthn
     * ceremony requires a physical authenticator and a user
     * gesture, which a bot cannot perform, so the bot check adds
     * nothing here. Password login still goes through Turnstile
     * as before.
     *
     * Mode `challenge` finishes a parked password login
     * (finishPendingLogin sets the auth cookie and returns the
     * original redirect). Mode `login` is a usernameless
     * sign-in: the credential resolves the user, and the auth
     * cookie is set directly.
     *
     * @return void
     */
    public static function passkeyVerify(): void
    {
        self::checkLoginNonce();
        $mode = self::postString('mode');
        $data = self::postJson('data');
        if ($data === null) {
            wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
        }
        if ($mode === 'challenge') {
            $token = self::challengeTokenFromPost();
            $stored = get_transient(TwoFactorModule::passkeyChallengeTransient($token));
            delete_transient(TwoFactorModule::passkeyChallengeTransient($token));
            if (!is_array($stored) || !isset($stored['challenge']) || !is_string($stored['challenge'])) {
                $pendingUser = TwoFactorModule::getPendingLogin($token);
                $knownId = $pendingUser !== null ? (int) $pendingUser['user_id'] : 0;
                TwoFactorModule::logEvent($knownId, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('This verification link has expired. Please log in again.', 'traydigita-core')], 400);
            }
            $userId = (int) ($stored['user_id'] ?? 0);
            $pending = TwoFactorModule::getPendingLogin($token);
            if ($pending === null || $userId <= 0 || (int) $pending['user_id'] !== $userId) {
                TwoFactorModule::logEvent($userId, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('This verification link has expired. Please log in again.', 'traydigita-core')], 400);
            }
            if (TwoFactorModule::isLockedOut($userId)) {
                TwoFactorModule::logEvent($userId, 'passkey', 'locked', 'locked_out');
                wp_send_json_error(['message' => __('Too many failed attempts. Please try again later.', 'traydigita-core')], 429);
            }
            try {
                $result = self::webauthn()->verifyAssertion(
                    $data,
                    (string) $stored['challenge'],
                    static function (string $credentialId, string $userHandle) use ($userId): ?array {
                        foreach (TwoFactorModule::getPasskeys($userId) as $record) {
                            if ((string) ($record['id'] ?? '') === $credentialId) {
                                return ['user_id' => $userId, 'record' => $record];
                            }
                        }
                        return null;
                    }
                );
            } catch (WebAuthnException $exception) {
                self::sendWebauthnError($exception, $userId);
            }
            /** @var array{user_id: int, credential_id: string, counter: int, user_verified: bool, backup_state: bool} $result */
            TwoFactorModule::updatePasskeyCounter($userId, $result['credential_id'], $result['counter']);
            TwoFactorModule::logEvent($userId, 'passkey', 'success', 'verified');
            $rememberDevice = self::postString('remember') === '1';
            $redirect = TwoFactorModule::finishPendingLogin($token, $pending, $rememberDevice);
            wp_send_json_success(['redirect' => $redirect]);
        }
        if ($mode === 'login') {
            $token = self::postString('token');
            if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
                wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
            }
            $stored = get_transient(TwoFactorModule::passkeyChallengeTransient($token));
            delete_transient(TwoFactorModule::passkeyChallengeTransient($token));
            if (!is_array($stored) || !isset($stored['challenge']) || !is_string($stored['challenge'])) {
                TwoFactorModule::logEvent(0, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
            }
            try {
                $result = self::webauthn()->verifyAssertion(
                    $data,
                    (string) $stored['challenge'],
                    static function (string $credentialId, string $userHandle): ?array {
                        $handle = WebAuthn::base64urlDecode($userHandle);
                        if ($handle === null || !ctype_digit($handle)) {
                            return null;
                        }
                        $userId = (int) $handle;
                        if ($userId <= 0) {
                            return null;
                        }
                        foreach (TwoFactorModule::getPasskeys($userId) as $record) {
                            if ((string) ($record['id'] ?? '') === $credentialId) {
                                return ['user_id' => $userId, 'record' => $record];
                            }
                        }
                        return null;
                    }
                );
            } catch (WebAuthnException $exception) {
                $userId = self::userIdFromAssertionResponse($data);
                self::sendWebauthnError($exception, $userId);
            }
            /** @var array{user_id: int, credential_id: string, counter: int, user_verified: bool, backup_state: bool} $result */
            $userId = (int) $result['user_id'];
            if (TwoFactorModule::isLockedOut($userId)) {
                TwoFactorModule::logEvent($userId, 'passkey', 'locked', 'locked_out');
                wp_send_json_error(['message' => __('Too many failed attempts. Please try again later.', 'traydigita-core')], 429);
            }
            TwoFactorModule::updatePasskeyCounter($userId, $result['credential_id'], $result['counter']);
            TwoFactorModule::logEvent($userId, 'passkey', 'success', 'verified');
            wp_set_auth_cookie($userId, false);
            wp_send_json_success(['redirect' => admin_url()]);
        }
        wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
    }

    /**
     * Best-effort user id from an assertion response.
     *
     * The user handle the browser returns is the base64url of
     * the WordPress user id string (set at registration). Used
     * only to attach a failed attempt to the right user in the
     * log — never trusted for authentication.
     *
     * @param array<string, mixed> $response Browser response.
     * @return int User id, or 0 when it cannot be resolved.
     */
    private static function userIdFromAssertionResponse(array $response): int
    {
        $handle = $response['response']['userHandle'] ?? null;
        if (!is_string($handle)) {
            return 0;
        }
        $decoded = WebAuthn::base64urlDecode($handle);
        if ($decoded === null || !ctype_digit($decoded)) {
            return 0;
        }
        return (int) $decoded;
    }
}
