<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Passkeys;

use TrayDigita\WP\Plugin\Core\Modules\TwoFactor\TwoFactorModule;
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
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_set_auth_cookie;
use function wp_unslash;
use const MINUTE_IN_SECONDS;

/**
 * AJAX endpoints for the Passkeys module.
 *
 * Two groups:
 * - Authenticated endpoints (nonce `traydigita_2fa_user`,
 *   `wp_ajax_*` only): passkey registration and removal. The
 *   caller always acts on their own account, unless they hold
 *   `edit_users`, in which case they may act on another user's
 *   account — the capability check lives in targetUserId().
 * - Unauthenticated endpoints (nonce `traydigita_2fa_login`,
 *   `wp_ajax_nopriv_*` only): passkey options and verification
 *   for the login page. These run before any auth cookie exists,
 *   so they are rate-limited per user (lockout) and never reveal
 *   whether a credential id exists.
 *
 * The nonces are shared with the Two-Factor module on purpose
 * and must not be renamed: the profile page prints one JSON
 * config block carrying a single user nonce for both modules'
 * endpoints, and the login page prints one config block carrying
 * a single login nonce. Both nonce actions live on
 * TwoFactorModule (NONCE_ACTION / LOGIN_NONCE_ACTION).
 *
 * WebAuthn responses are posted as a JSON string in the `data`
 * field (form-encoded, so check_ajax_referer() keeps working);
 * the frontend converts ArrayBuffers to base64url first.
 *
 * The `challenge` mode (passkey as a second factor) depends on
 * the Two-Factor module's pending-login plumbing
 * (getPendingLogin / finishPendingLogin / lockout / failed-
 * attempt log): only TwoFactor parks a password login, so a
 * challenge-mode request without a pending login can only fail
 * with the generic expired-link message. The `login` mode
 * (usernameless sign-in) works with TwoFactor disabled.
 *
 * No endpoint ever logs or prints a secret, a code, or a
 * challenge.
 */
final class PasskeysAjax
{
    // Authenticated actions (profile management).
    public const string PASSKEY_REGISTER_OPTIONS_ACTION = 'traydigita_2fa_passkey_register_options';

    public const string PASSKEY_REGISTER_ACTION = 'traydigita_2fa_passkey_register';

    public const string PASSKEY_REMOVE_ACTION = 'traydigita_2fa_passkey_remove';

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
            self::PASSKEY_REGISTER_OPTIONS_ACTION => 'passkeyRegisterOptions',
            self::PASSKEY_REGISTER_ACTION => 'passkeyRegister',
            self::PASSKEY_REMOVE_ACTION => 'passkeyRemove',
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
     * Shared with TwoFactorAjax: the nonce action lives on
     * TwoFactorModule and must not be renamed (see the class
     * docblock).
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
     * Shared with TwoFactorAjax: the nonce action lives on
     * TwoFactorModule and must not be renamed (see the class
     * docblock).
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
     * Decode a hex-encoded challenge read back from a transient.
     *
     * Challenges are raw random bytes; storing them raw in the
     * options table breaks MariaDB's utf8mb4 validation ("Could
     * not perform query because it contains invalid data"), so
     * they are hex-encoded before set_transient() and decoded
     * here. Returns null for anything that is not valid hex.
     *
     * @param mixed $stored Raw transient value.
     * @return string|null Raw challenge bytes, or null when invalid.
     */
    private static function decodeChallenge(mixed $stored): ?string
    {
        if (!is_string($stored) || $stored === '' || strlen($stored) % 2 !== 0) {
            return null;
        }
        if (preg_match('/^[0-9a-f]+$/', $stored) !== 1) {
            return null;
        }
        $decoded = hex2bin($stored);
        return $decoded === false || $decoded === '' ? null : $decoded;
    }

    /**
     * Build the WebAuthn server for this site.
     *
     * @return WebAuthn
     */
    private static function webauthn(): WebAuthn
    {
        return new WebAuthn(PasskeysModule::rpId(), PasskeysModule::expectedOrigin());
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
     * Failures are recorded in the Two-Factor module's
     * failed-attempt log (the security log for passkey attempts);
     * the log and its lockout still apply when TwoFactor itself
     * is disabled, so a usernameless login cannot be brute-
     * forced through a disabled 2FA module.
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
    // Passkey registration
    // ------------------------------------------------------------------

    /**
     * Issue passkey registration options.
     *
     * The challenge is stored server-side in a short-lived
     * transient and never trusted from the client. It is
     * hex-encoded before storage: the raw bytes break
     * MariaDB's utf8mb4 validation on the options table.
     * Registration is refused on plain HTTP (except
     * localhost) — the profile page explains why instead of
     * offering a button that fails.
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
        if (!PasskeysModule::isPasskeysEnabled()) {
            wp_send_json_error(['message' => __('Passkey registration is disabled on this site.', 'traydigita-core')], 403);
        }
        if (!PasskeysModule::isPasskeyAvailable()) {
            wp_send_json_error(['message' => __('Passkeys need a secure connection (HTTPS). This site is served over plain HTTP.', 'traydigita-core')], 400);
        }
        $exclude = [];
        foreach (PasskeysModule::getPasskeys($userId) as $record) {
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
            PasskeysModule::passkeyRegChallengeTransient($userId),
            bin2hex($built['challenge']),
            PasskeysModule::PASSKEY_CHALLENGE_MINUTES * MINUTE_IN_SECONDS
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
        if (!PasskeysModule::isPasskeysEnabled() || !PasskeysModule::isPasskeyAvailable()) {
            wp_send_json_error(['message' => __('Passkey registration is not available on this site.', 'traydigita-core')], 403);
        }
        $challenge = get_transient(PasskeysModule::passkeyRegChallengeTransient($userId));
        delete_transient(PasskeysModule::passkeyRegChallengeTransient($userId));
        $challenge = self::decodeChallenge($challenge);
        if ($challenge === null) {
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
        $existing = PasskeysModule::getPasskeys($userId);
        /* translators: %d is the passkey number for this account. */
        $label = sprintf(__('Passkey %d', 'traydigita-core'), count($existing) + 1);
        PasskeysModule::addPasskey($userId, [
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
        if ($credentialId === '' || !PasskeysModule::removePasskey($userId, $credentialId)) {
            wp_send_json_error(['message' => __('That passkey was not found.', 'traydigita-core')], 400);
        }
        TwoFactorModule::logEvent($userId, 'passkey', 'success', 'removed');
        wp_send_json_success(['message' => __('Passkey removed.', 'traydigita-core')]);
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
        if (!PasskeysModule::isPasskeysEnabled() || !PasskeysModule::isPasskeyAvailable()) {
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
            foreach (PasskeysModule::getPasskeys($userId) as $record) {
                $allow[] = ['id' => (string) ($record['id'] ?? '')];
            }
            if ($allow === []) {
                wp_send_json_error(['message' => __('This account has no passkeys.', 'traydigita-core')], 400);
            }
            $built = $webauthn->assertionOptions($allow);
            set_transient(
                PasskeysModule::passkeyChallengeTransient($token),
                ['challenge' => bin2hex($built['challenge']), 'user_id' => $userId],
                PasskeysModule::PASSKEY_CHALLENGE_MINUTES * MINUTE_IN_SECONDS
            );
            wp_send_json_success(['options' => $built['options']]);
        }
        if ($mode === 'login') {
            $token = bin2hex(random_bytes(16));
            $built = $webauthn->assertionOptions([]);
            set_transient(
                PasskeysModule::passkeyChallengeTransient($token),
                ['challenge' => bin2hex($built['challenge']), 'user_id' => 0],
                PasskeysModule::PASSKEY_CHALLENGE_MINUTES * MINUTE_IN_SECONDS
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
            $stored = get_transient(PasskeysModule::passkeyChallengeTransient($token));
            delete_transient(PasskeysModule::passkeyChallengeTransient($token));
            if (!is_array($stored) || !isset($stored['challenge']) || !is_string($stored['challenge'])) {
                $pendingUser = TwoFactorModule::getPendingLogin($token);
                $knownId = $pendingUser !== null ? (int) $pendingUser['user_id'] : 0;
                TwoFactorModule::logEvent($knownId, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('This verification link has expired. Please log in again.', 'traydigita-core')], 400);
            }
            $userId = (int) ($stored['user_id'] ?? 0);
            $challenge = self::decodeChallenge($stored['challenge']);
            $pending = TwoFactorModule::getPendingLogin($token);
            if ($pending === null || $userId <= 0 || (int) $pending['user_id'] !== $userId || $challenge === null) {
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
                    $challenge,
                    static function (string $credentialId, string $userHandle) use ($userId): ?array {
                        foreach (PasskeysModule::getPasskeys($userId) as $record) {
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
            PasskeysModule::updatePasskeyCounter($userId, $result['credential_id'], $result['counter']);
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
            $stored = get_transient(PasskeysModule::passkeyChallengeTransient($token));
            delete_transient(PasskeysModule::passkeyChallengeTransient($token));
            if (!is_array($stored) || !isset($stored['challenge']) || !is_string($stored['challenge'])) {
                TwoFactorModule::logEvent(0, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
            }
            $challenge = self::decodeChallenge($stored['challenge']);
            if ($challenge === null) {
                TwoFactorModule::logEvent(0, 'passkey', 'failed', 'expired');
                wp_send_json_error(['message' => __('Passkey sign-in failed. Please try again.', 'traydigita-core')], 400);
            }
            try {
                $result = self::webauthn()->verifyAssertion(
                    $data,
                    $challenge,
                    static function (string $credentialId, string $userHandle): ?array {
                        $handle = WebAuthn::base64urlDecode($userHandle);
                        if ($handle === null || !ctype_digit($handle)) {
                            return null;
                        }
                        $userId = (int) $handle;
                        if ($userId <= 0) {
                            return null;
                        }
                        foreach (PasskeysModule::getPasskeys($userId) as $record) {
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
            PasskeysModule::updatePasskeyCounter($userId, $result['credential_id'], $result['counter']);
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
