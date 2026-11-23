<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use TrayDigita\WP\Plugin\Core\Admin\AdminNotice;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Modules\Passkeys\PasskeysModule;
use Throwable;
use WP_Error;
use WP_User;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function add_query_arg;
use function admin_url;
use function apply_filters;
use function array_intersect;
use function array_key_exists;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function constant;
use function count;
use function ctype_digit;
use function current_user_can;
use function date_i18n;
use function defined;
use function delete_transient;
use function delete_user_meta;
use function do_action;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function explode;
use function filter_var;
use function function_exists;
use function get_current_user_id;
use function get_editable_roles;
use function get_option;
use function get_transient;
use function get_user_by;
use function get_user_meta;
use function hash;
use function hash_equals;
use function hash_hmac;
use function in_array;
use function is_array;
use function is_ssl;
use function is_string;
use function is_user_logged_in;
use function login_footer;
use function login_header;
use function openssl_decrypt;
use function openssl_encrypt;
use function preg_match;
use function random_bytes;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function setcookie;
use function sprintf;
use function str_contains;
use function strlen;
use function substr;
use function time;
use function translate_user_role;
use function trim;
use function update_option;
use function update_user_meta;
use function wp_doing_ajax;
use function wp_enqueue_script_module;
use function wp_enqueue_style;
use function wp_get_current_user;
use function wp_check_password;
use function wp_login_url;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_salt;
use function wp_script_modules;
use function wp_set_auth_cookie;
use function wp_style_is;
use function wp_unslash;
use function wp_validate_redirect;
use const COOKIEPATH;
use const COOKIE_DOMAIN;
use const DAY_IN_SECONDS;
use const FILTER_VALIDATE_BOOLEAN;
use const MINUTE_IN_SECONDS;
use const OPENSSL_RAW_DATA;

/**
 * Two-Factor Authentication module (TOTP + trusted devices).
 *
 * What it does:
 * - TOTP (RFC 6238, SHA-1, 30-second step, ±1 window) via Totp.php,
 *   with the secret encrypted in user meta (AES-256-CBC, key derived
 *   from wp_salt('secure_auth')) and the QR code rendered locally as
 *   inline SVG by QrCode.php (vendored phpqrcode, no external
 *   service, no GD). Ten single-use backup codes, hashed with
 *   wp_hash_password(), are generated BEFORE the authenticator is
 *   activated — a user can never be locked out by enabling 2FA
 *   without a recovery path.
 * - Passkeys are NOT owned here: they live in the Passkeys module
 *   (PasskeysModule), which owns the WebAuthn server, the
 *   credential store, the enrolment UI, and the login-form button.
 *   This module only offers an enrolled passkey as a second
 *   factor on its challenge screen, through the Passkeys
 *   module's public API — see hasAnyMethod() and
 *   renderChallengeForm(). The passkey setting
 *   (`passkey_enabled` in getSettings()) reads through to the
 *   Passkeys module, and the deprecated passkey helpers below
 *   delegate to it.
 * - Trusted devices: "remember this device for 30 days" via an
 *   HMAC-signed cookie (wp_salt), revocable per device from the
 *   user profile. A revoked device invalidates the cookie on the
 *   next login.
 * - Enforcement: per-role (administrators by default). An enforced
 *   user without any method is redirected to their profile 2FA
 *   section until they enroll; the frontend is untouched.
 * - Rate limiting: per-user transient lockout after N failed
 *   verification attempts, plus a lightweight failed-attempt log
 *   (200 rows, auto-pruned — a security log, not a full activity
 *   log).
 * - Safe anti-lockout recovery: WP-CLI commands
 *   (`wp traydigita-2fa status|disable|reset-lockout <user>`) and
 *   the `TRAYDIGITA_2FA_DISABLE` wp-config.php constant (or the
 *   `traydigita:core:two-factor:disable` filter). Both require
 *   server access or another administrator — they are escape
 *   hatches, not backdoors.
 *
 * Login flow (integrates with the Login Builder and Turnstile,
 * never replaces them): the `authenticate` filter runs at priority
 * 50, after WordPress core (20/30) and Turnstile (40). When the
 * password checks out and the user has an enabled 2FA method, the
 * login is parked in a 10-minute transient and the browser is
 * redirected to wp-login.php?action=traydigita_2fa with a
 * single-use token. That action is a custom login action
 * (verified against WP core: wp-login.php keeps an unknown action
 * when a `login_form_{action}` hook exists, and fires it before
 * the switch — the handler owns the whole page: login_header(),
 * the challenge form, login_footer(), exit). The challenge accepts
 * a TOTP code, a backup code, or a passkey assertion; success sets
 * the auth cookie exactly as a normal login would. REST, XML-RPC,
 * and application-password logins are untouched (they post no
 * `log` field). When Cloudflare Turnstile is also on, its
 * challenge runs first and a failed Turnstile check still blocks
 * before 2FA is considered.
 *
 * TOTP secrets and wp_salt rotation: the encryption key is derived
 * from wp_salt('secure_auth'), so rotating the salts in
 * wp-config.php makes stored secrets unreadable — affected users
 * simply re-enroll (the profile page says so). This is the safer
 * failure: a database read alone never yields a usable secret.
 *
 * Settings live in the single option `traydigita_two_factor`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type. Per-user data
 * lives in user meta (see META_* constants) and is never returned
 * by any endpoint except in aggregate (counts) or after the
 * owning user's own verification.
 *
 * The settings form is rendered inline here (no template partial),
 * following the SeoModule/TurnstileModule pattern, so the module
 * stays self-contained in its own directory.
 */
final class TwoFactorModule implements ModuleInterface
{
    /**
     * Option name for all Two-Factor settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_two_factor';

    /**
     * Option name for the failed-attempt log (autoload `no`).
     *
     * Shape: a list of at most MAX_LOG_ENTRIES entries, each
     * `['time' => int, 'user' => string, 'method' => string,
     * 'result' => string, 'reason' => string, 'ip' => string]`.
     * The `ip` value is an HMAC of the client IP, never the IP
     * itself.
     */
    public const string LOG_OPTION = 'traydigita_two_factor_log';

    /**
     * Nonce action for the profile-page AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * check_ajax_referer()), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita_2fa_user';

    /**
     * Nonce action for the login-page (unauthenticated) AJAX
     * endpoints — the passkey options/verify calls from
     * wp-login.php. The nonce is printed in the login config
     * block; it is a CSRF token for a public page, not a secret.
     */
    public const string LOGIN_NONCE_ACTION = 'traydigita_2fa_login';

    /**
     * Script-module handle of the standalone `two-factor` Vite entry.
     *
     * The entry is built from `resources/js/modules/two-factor.ts`
     * (listed explicitly in vite.config.ts, like the turnstile
     * entry) and registered in `AssetLoader::ASSETS` by the
     * coordinator. The same entry serves the login page and the
     * profile page.
     */
    public const string SCRIPT_HANDLE = 'traydigita-theme-two-factor';

    /**
     * Style handle of the CSS bundled with the `two-factor` entry.
     *
     * AssetLoader registers an entry's CSS dependencies under the
     * entry handle (first dependency) — see AssetLoader::initAssets().
     */
    public const string STYLE_HANDLE = 'traydigita-theme-two-factor';

    /**
     * DOM id of the JSON config block printed on the login page.
     *
     * @deprecated Moved to PasskeysModule::LOGIN_CONFIG_ELEMENT_ID.
     *   The login config block is printed by the Passkeys module now.
     */
    public const string LOGIN_CONFIG_ELEMENT_ID = PasskeysModule::LOGIN_CONFIG_ELEMENT_ID;

    /**
     * DOM id of the JSON config block printed on the profile page.
     *
     * @deprecated Moved to PasskeysModule::PROFILE_CONFIG_ELEMENT_ID.
     *   The profile config block is printed by the Passkeys module now.
     */
    public const string PROFILE_CONFIG_ELEMENT_ID = PasskeysModule::PROFILE_CONFIG_ELEMENT_ID;

    /**
     * wp-login.php action for the 2FA challenge screen.
     */
    public const string CHALLENGE_ACTION = 'traydigita_2fa';

    /**
     * Filter that disables all 2FA enforcement when it returns true.
     */
    public const string DISABLE_FILTER = 'traydigita:core:two-factor:disable';

    /**
     * Action fired for every two-factor verification outcome.
     *
     * Fired by logEvent() after the outcome is written to the
     * module's own failed-attempt log, so listeners (the
     * Activity Log module) see successes, failures, lockouts,
     * enrolments and passkey verifications through one hook
     * point. Arguments: int $userId, string $method (`totp`,
     * `backup`, `passkey`, `login`, `all`), string $result
     * (`success`, `failed`, `locked`), string $reason
     * (machine-readable reason code). No secret or code is ever
     * passed.
     */
    public const string VERIFIED_ACTION = 'traydigita:core:two-factor:verified';

    /**
     * wp-config.php constant that disables all 2FA enforcement.
     */
    public const string DISABLE_CONSTANT = 'TRAYDIGITA_2FA_DISABLE';

    /**
     * User meta key holding the encrypted TOTP secret.
     *
     * Format: `v1:<base64 IV>:<base64 ciphertext>` (AES-256-CBC).
     * An empty or missing value means TOTP is not enrolled.
     */
    public const string META_TOTP = 'traydigita_2fa_totp';

    /**
     * User meta key holding the backup-code hashes.
     *
     * A list of wp_hash_password() hashes. A used code's hash is
     * removed, so each code works exactly once.
     */
    public const string META_BACKUP_CODES = 'traydigita_2fa_backup_codes';

    /**
     * User meta key holding the registered passkeys.
     *
     * @deprecated Moved to PasskeysModule::META_PASSKEYS. The value
     *   is byte-identical (`traydigita_2fa_passkeys`), so enrolled
     *   passkeys keep working.
     */
    public const string META_PASSKEYS = 'traydigita_2fa_passkeys';

    /**
     * User meta key holding the trusted devices.
     *
     * A map of device id => `['label' => string, 'created' => int,
     * 'last_used' => int, 'expires' => int]`.
     */
    public const string META_DEVICES = 'traydigita_2fa_devices';

    /**
     * User meta key holding the first-enrolment timestamp.
     */
    public const string META_ENROLLED_AT = 'traydigita_2fa_enrolled_at';

    /**
     * Trusted-device cookie name.
     */
    public const string DEVICE_COOKIE = 'traydigita_2fa_device';

    /**
     * Maximum failed-attempt log entries kept.
     */
    public const int MAX_LOG_ENTRIES = 200;

    /**
     * Pending-login lifetime in minutes.
     */
    public const int PENDING_LOGIN_MINUTES = 10;

    /**
     * Passkey challenge lifetime in minutes.
     *
     * @deprecated Moved to PasskeysModule::PASSKEY_CHALLENGE_MINUTES.
     */
    public const int PASSKEY_CHALLENGE_MINUTES = 5;

    /**
     * Pending TOTP enrolment lifetime in minutes.
     */
    public const int TOTP_PENDING_MINUTES = 10;

    /**
     * Default trusted-device lifetime in days.
     */
    public const int DEFAULT_TRUSTED_DEVICE_DAYS = 30;

    /**
     * Default failed attempts before lockout.
     */
    public const int DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * Default lockout duration in minutes.
     */
    public const int DEFAULT_LOCKOUT_MINUTES = 15;

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Error from a failed challenge POST, shown when the challenge
     * form renders in the same request.
     */
    private static string $challengeError = '';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'two-factor';
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'security';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Two-Factor Authentication', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Authenticator-app codes (TOTP) and trusted devices — with per-role enforcement, rate limiting, and a safe recovery path. Passkeys are a separate module. Disabled by default.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'lock';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Authenticator-app codes (TOTP).', 'traydigita-core'),
            __('Trusted devices.', 'traydigita-core'),
            __('Per-role enforcement and rate limiting.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-two-factor';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * Needs the hash and OpenSSL extensions (TOTP HMAC, secret
     * encryption) and random_bytes(). All are present in any
     * standard PHP 8.3 build; a minimal build without OpenSSL
     * reports the module unavailable instead of failing at the
     * first login.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return function_exists('hash_hmac')
            && function_exists('openssl_verify')
            && function_exists('openssl_encrypt')
            && function_exists('random_bytes');
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * No site-wide setup is needed: the defaults produce working
     * TOTP, and enrolment is per user. The card therefore never
     * shows "needs setup" for this module.
     *
     * @return bool Always true.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable, and disabled
     * by default because it changes the login flow.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether all 2FA enforcement is switched off.
     *
     * The `TRAYDIGITA_2FA_DISABLE` wp-config.php constant and the
     * `traydigita:core:two-factor:disable` filter force it off
     * (the emergency escape hatch, for example while recovering
     * a locked-out administrator). This is not a backdoor: both
     * require file access or code that runs after plugins load.
     *
     * @return bool True when enforcement is disabled.
     */
    public static function isDisabled(): bool
    {
        if (defined(self::DISABLE_CONSTANT) && constant(self::DISABLE_CONSTANT) === true) {
            return true;
        }
        return apply_filters(self::DISABLE_FILTER, false) === true;
    }

    /**
     * Whether passkeys can be offered on this site right now.
     *
     * @deprecated Moved to PasskeysModule::isPasskeyAvailable().
     *
     * @return bool
     */
    public static function isPasskeyAvailable(): bool
    {
        return PasskeysModule::isPasskeyAvailable();
    }

    /**
     * Relying-party id for WebAuthn (the site host).
     *
     * @deprecated Moved to PasskeysModule::rpId().
     *
     * @return string
     */
    public static function rpId(): string
    {
        return PasskeysModule::rpId();
    }

    /**
     * Expected WebAuthn origin (scheme + host + port, no path).
     *
     * @deprecated Moved to PasskeysModule::expectedOrigin().
     *
     * @return string
     */
    public static function expectedOrigin(): string
    {
        return PasskeysModule::expectedOrigin();
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Login hooks are registered unconditionally here (the login
     * page must enforce 2FA whenever the module is on); each
     * handler re-checks the site toggles and the per-user
     * enrolment, so a toggle switched off registers nothing
     * observable for that method. The passkey login button and
     * the login config block are owned by the Passkeys module
     * now (it hooks `login_form` / `login_footer` itself), so
     * they are not registered here.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (defined('WP_CLI') && WP_CLI) {
            TwoFactorCli::register();
        }
        TwoFactorAjax::register();
        if (self::isDisabled()) {
            return;
        }
        add_action('login_enqueue_scripts', [self::class, 'enqueueLoginAssets']);
        add_filter('authenticate', [self::class, 'verifyLogin'], 50, 3);
        add_action('login_init', [self::class, 'handleChallengePost']);
        add_action('login_form_' . self::CHALLENGE_ACTION, [self::class, 'renderChallengeForm']);
        add_action('admin_init', [self::class, 'maybeEnforceSetup']);
        add_action('admin_notices', [self::class, 'maybeShowSetupNotice']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueProfileNoticeAssets']);
        add_action('show_user_profile', [self::class, 'renderProfileFields']);
        add_action('edit_user_profile', [self::class, 'renderProfileFields']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueProfileAssets']);
    }

    /**
     * Default settings.
     *
     * TOTP is allowed, administrators are enforced, trusted
     * devices last 30 days, and verification locks out after 5
     * failures for 15 minutes. The `passkey_enabled` default is
     * kept for shape, but getSettings() reads the effective
     * value through from the Passkeys module.
     *
     * @return array{totp_enabled: bool, passkey_enabled: bool, enforced_roles: array<int, string>, trusted_device_days: int, max_attempts: int, lockout_minutes: int}
     */
    public static function getDefaults(): array
    {
        return [
            'totp_enabled' => true,
            'passkey_enabled' => true,
            'enforced_roles' => ['administrator'],
            'trusted_device_days' => self::DEFAULT_TRUSTED_DEVICE_DAYS,
            'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
            'lockout_minutes' => self::DEFAULT_LOCKOUT_MINUTES,
        ];
    }

    /**
     * Read the stored settings, merged over the defaults and normalized.
     *
     * The `passkey_enabled` value reads through to the Passkeys
     * module (PasskeysModule::isPasskeysEnabled()): passkeys are
     * owned there now, and this module must report the effective
     * value — false when the Passkeys module is disabled or its
     * setting is off — so the challenge screen and the
     * enforcement redirect never offer a method that cannot
     * actually be used. The stored legacy value in this
     * module's own option is only a fallback source for the
     * Passkeys module (see PasskeysModule::getSettings()) and is
     * never read here.
     *
     * @return array{totp_enabled: bool, passkey_enabled: bool, enforced_roles: array<int, string>, trusted_device_days: int, max_attempts: int, lockout_minutes: int}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $roles = [];
        if (isset($settings['enforced_roles']) && is_array($settings['enforced_roles'])) {
            foreach ($settings['enforced_roles'] as $role) {
                if (is_string($role) && $role !== '' && !in_array($role, $roles, true)) {
                    $roles[] = $role;
                }
            }
        }
        $trustedDays = isset($settings['trusted_device_days']) ? (int) $settings['trusted_device_days'] : self::DEFAULT_TRUSTED_DEVICE_DAYS;
        if ($trustedDays < 0) {
            $trustedDays = 0;
        } elseif ($trustedDays > 90) {
            $trustedDays = 90;
        }
        $maxAttempts = isset($settings['max_attempts']) ? (int) $settings['max_attempts'] : self::DEFAULT_MAX_ATTEMPTS;
        if ($maxAttempts < 1) {
            $maxAttempts = 1;
        } elseif ($maxAttempts > 20) {
            $maxAttempts = 20;
        }
        $lockoutMinutes = isset($settings['lockout_minutes']) ? (int) $settings['lockout_minutes'] : self::DEFAULT_LOCKOUT_MINUTES;
        if ($lockoutMinutes < 1) {
            $lockoutMinutes = 1;
        } elseif ($lockoutMinutes > 60) {
            $lockoutMinutes = 60;
        }
        return [
            'totp_enabled' => (bool) ($settings['totp_enabled'] ?? true),
            'passkey_enabled' => PasskeysModule::isPasskeysEnabled(),
            'enforced_roles' => $roles,
            'trusted_device_days' => $trustedDays,
            'max_attempts' => $maxAttempts,
            'lockout_minutes' => $lockoutMinutes,
        ];
    }

    // ------------------------------------------------------------------
    // Per-user data
    // ------------------------------------------------------------------

    /**
     * Whether the user has any usable 2FA method.
     *
     * A method only counts while its site toggle is on: switching
     * TOTP off site-wide logs a TOTP-only user in with the password
     * alone, instead of stranding them at a challenge they can no
     * longer pass.
     *
     * @param int $userId User id.
     * @return bool
     */
    public static function hasAnyMethod(int $userId): bool
    {
        $settings = self::getSettings();
        if ($settings['totp_enabled'] && self::hasTotp($userId)) {
            return true;
        }
        return $settings['passkey_enabled'] && PasskeysModule::getPasskeys($userId) !== [];
    }

    /**
     * Whether the user has TOTP enrolled.
     *
     * A secret that cannot be decrypted (for example after a
     * wp_salt rotation) counts as not enrolled, so the user is
     * offered re-enrolment instead of failing every login.
     *
     * @param int $userId User id.
     * @return bool
     */
    public static function hasTotp(int $userId): bool
    {
        return self::getTotpSecret($userId) !== null;
    }

    /**
     * Read and decrypt the user's TOTP secret.
     *
     * @param int $userId User id.
     * @return string|null Base32 secret, or null when not enrolled or unreadable.
     */
    public static function getTotpSecret(int $userId): ?string
    {
        $stored = get_user_meta($userId, self::META_TOTP, true);
        if (!is_string($stored) || $stored === '') {
            return null;
        }
        return self::decryptSecret($stored);
    }

    /**
     * Store the user's TOTP secret (encrypted).
     *
     * Also records the first-enrolment timestamp when the user had
     * no method before.
     *
     * @param int $userId User id.
     * @param string $secret Base32 secret.
     * @return void
     */
    public static function setTotpSecret(int $userId, string $secret): void
    {
        $hadMethod = self::hasAnyMethod($userId);
        update_user_meta($userId, self::META_TOTP, self::encryptSecret($secret));
        if (!$hadMethod) {
            update_user_meta($userId, self::META_ENROLLED_AT, time());
        }
    }

    /**
     * Remove the user's TOTP enrolment and backup codes.
     *
     * @param int $userId User id.
     * @return void
     */
    public static function removeTotp(int $userId): void
    {
        delete_user_meta($userId, self::META_TOTP);
        delete_user_meta($userId, self::META_BACKUP_CODES);
        self::maybeClearEnrolledAt($userId);
    }

    /**
     * Read the user's backup-code hashes.
     *
     * @param int $userId User id.
     * @return array<int, string> Password hashes of unused codes.
     */
    public static function getBackupCodeHashes(int $userId): array
    {
        $stored = get_user_meta($userId, self::META_BACKUP_CODES, true);
        if (!is_array($stored)) {
            return [];
        }
        $hashes = [];
        foreach ($stored as $hash) {
            if (is_string($hash) && $hash !== '') {
                $hashes[] = $hash;
            }
        }
        return $hashes;
    }

    /**
     * Store backup-code hashes (replaces any previous set).
     *
     * @param int $userId User id.
     * @param array<int, string> $hashes Password hashes.
     * @return void
     */
    public static function setBackupCodeHashes(int $userId, array $hashes): void
    {
        update_user_meta($userId, self::META_BACKUP_CODES, array_values($hashes));
    }

    /**
     * Verify a backup code and consume it on success.
     *
     * The code must be 8 digits (dashes and spaces are ignored).
     * A matching hash is removed, so the code works exactly once.
     *
     * @param int $userId User id.
     * @param string $code Submitted code.
     * @return bool True when the code matched and was consumed.
     */
    public static function verifyBackupCode(int $userId, string $code): bool
    {
        $normalized = Totp::normalizeBackupCode($code);
        if (strlen($normalized) !== 8 || !ctype_digit($normalized)) {
            return false;
        }
        $hashes = self::getBackupCodeHashes($userId);
        foreach ($hashes as $index => $hash) {
            if (wp_check_password($normalized, $hash)) {
                unset($hashes[$index]);
                self::setBackupCodeHashes($userId, array_values($hashes));
                return true;
            }
        }
        return false;
    }

    /**
     * Read the user's registered passkeys.
     *
     * @deprecated Moved to PasskeysModule::getPasskeys().
     *
     * @param int $userId User id.
     * @return array<int, array<string, mixed>> Credential records.
     */
    public static function getPasskeys(int $userId): array
    {
        return PasskeysModule::getPasskeys($userId);
    }

    /**
     * Store the user's passkeys (replaces the whole list).
     *
     * @deprecated Moved to PasskeysModule::setPasskeys().
     *
     * @param int $userId User id.
     * @param array<int, array<string, mixed>> $passkeys Credential records.
     * @return void
     */
    public static function setPasskeys(int $userId, array $passkeys): void
    {
        PasskeysModule::setPasskeys($userId, $passkeys);
    }

    /**
     * Add one passkey to the user's list.
     *
     * @deprecated Moved to PasskeysModule::addPasskey().
     *
     * @param int $userId User id.
     * @param array<string, mixed> $record Credential record.
     * @return void
     */
    public static function addPasskey(int $userId, array $record): void
    {
        PasskeysModule::addPasskey($userId, $record);
    }

    /**
     * Remove one passkey by credential id.
     *
     * @deprecated Moved to PasskeysModule::removePasskey().
     *
     * @param int $userId User id.
     * @param string $credentialId Base64url credential id.
     * @return bool True when a record was removed.
     */
    public static function removePasskey(int $userId, string $credentialId): bool
    {
        return PasskeysModule::removePasskey($userId, $credentialId);
    }

    /**
     * Update the stored counter (and last-used time) of one passkey.
     *
     * @deprecated Moved to PasskeysModule::updatePasskeyCounter().
     *
     * @param int $userId User id.
     * @param string $credentialId Base64url credential id.
     * @param int $counter New counter value.
     * @return void
     */
    public static function updatePasskeyCounter(int $userId, string $credentialId, int $counter): void
    {
        PasskeysModule::updatePasskeyCounter($userId, $credentialId, $counter);
    }

    /**
     * Remove the enrolment timestamp when the user has no method left.
     *
     * @param int $userId User id.
     * @return void
     */
    private static function maybeClearEnrolledAt(int $userId): void
    {
        if (!self::hasAnyMethod($userId)) {
            delete_user_meta($userId, self::META_ENROLLED_AT);
        }
    }

    /**
     * Read the user's trusted devices.
     *
     * @param int $userId User id.
     * @return array<string, array{label: string, created: int, last_used: int, expires: int}> Devices keyed by device id.
     */
    public static function getDevices(int $userId): array
    {
        $stored = get_user_meta($userId, self::META_DEVICES, true);
        if (!is_array($stored)) {
            return [];
        }
        $devices = [];
        $now = time();
        foreach ($stored as $id => $device) {
            if (!is_string($id) || !is_array($device) || !isset($device['expires']) || (int) $device['expires'] <= $now) {
                continue;
            }
            $devices[$id] = [
                'label' => is_string($device['label'] ?? null) ? (string) $device['label'] : '',
                'created' => (int) ($device['created'] ?? 0),
                'last_used' => (int) ($device['last_used'] ?? 0),
                'expires' => (int) $device['expires'],
            ];
        }
        return $devices;
    }

    /**
     * Store the user's trusted devices.
     *
     * @param int $userId User id.
     * @param array<string, array{label: string, created: int, last_used: int, expires: int}> $devices Devices keyed by device id.
     * @return void
     */
    public static function setDevices(int $userId, array $devices): void
    {
        update_user_meta($userId, self::META_DEVICES, $devices);
    }

    /**
     * Revoke one trusted device.
     *
     * @param int $userId User id.
     * @param string $deviceId Device id.
     * @return bool True when a device was removed.
     */
    public static function revokeDevice(int $userId, string $deviceId): bool
    {
        $devices = self::getDevices($userId);
        if (!array_key_exists($deviceId, $devices)) {
            return false;
        }
        unset($devices[$deviceId]);
        self::setDevices($userId, $devices);
        return true;
    }

    /**
     * Revoke all of the user's trusted devices.
     *
     * @param int $userId User id.
     * @return void
     */
    public static function revokeAllDevices(int $userId): void
    {
        delete_user_meta($userId, self::META_DEVICES);
    }

    // ------------------------------------------------------------------
    // TOTP secret encryption
    // ------------------------------------------------------------------

    /**
     * Encrypt a TOTP secret for storage.
     *
     * AES-256-CBC with a key derived from wp_salt('secure_auth')
     * and a fresh random IV per secret. Format:
     * `v1:<base64 IV>:<base64 ciphertext>`.
     *
     * @param string $secret Base32 secret.
     * @return string Encrypted blob.
     */
    public static function encryptSecret(string $secret): string
    {
        $key = hash('sha256', wp_salt('secure_auth') . '|traydigita-2fa', true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($secret, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($cipher)) {
            return '';
        }
        return 'v1:' . base64_encode($iv) . ':' . base64_encode($cipher);
    }

    /**
     * Decrypt a stored TOTP secret.
     *
     * Returns null for anything unreadable — wrong format, bad
     * base64, or a decryption failure (for example after a
     * wp_salt rotation). Callers treat null as "not enrolled".
     *
     * @param string $blob Encrypted blob.
     * @return string|null Base32 secret, or null when unreadable.
     */
    public static function decryptSecret(string $blob): ?string
    {
        $parts = explode(':', $blob);
        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }
        $iv = base64_decode($parts[1], true);
        $cipher = base64_decode($parts[2], true);
        if ($iv === false || $cipher === false || strlen($iv) !== 16) {
            return null;
        }
        $key = hash('sha256', wp_salt('secure_auth') . '|traydigita-2fa', true);
        $secret = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if (!is_string($secret) || $secret === '') {
            return null;
        }
        return $secret;
    }

    // ------------------------------------------------------------------
    // Trusted-device cookie
    // ------------------------------------------------------------------

    /**
     * Issue a trusted-device cookie for the user.
     *
     * The cookie value is `userId.deviceId.expiry.hmac`, where the
     * HMAC covers all three fields with wp_salt('secure_auth').
     * The device record is stored in user meta first, so revoking
     * it there invalidates the cookie on the next login even
     * before it expires.
     *
     * @param int $userId User id.
     * @return void
     */
    public static function issueTrustedDevice(int $userId): void
    {
        $settings = self::getSettings();
        $days = $settings['trusted_device_days'];
        if ($days < 1) {
            return;
        }
        $deviceId = bin2hex(random_bytes(16));
        $expires = time() + $days * DAY_IN_SECONDS;
        $devices = self::getDevices($userId);
        $devices[$deviceId] = [
            'label' => substr(self::deviceLabel(), 0, 100),
            'created' => time(),
            'last_used' => time(),
            'expires' => $expires,
        ];
        self::setDevices($userId, $devices);
        $hmac = hash_hmac('sha256', $userId . '|' . $deviceId . '|' . $expires, wp_salt('secure_auth'));
        $value = $userId . '.' . $deviceId . '.' . $expires . '.' . $hmac;
        setcookie(
            self::DEVICE_COOKIE,
            $value,
            [
                'expires' => $expires,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Whether the request carries a valid trusted-device cookie.
     *
     * Checks the format, the expiry, the HMAC (constant-time),
     * and that the device still exists in the user's device list.
     * The last-used timestamp is refreshed at most once a day, so
     * a trusted login does not write user meta on every request.
     *
     * @param int $userId User id.
     * @return bool
     */
    public static function hasValidTrustedDevice(int $userId): bool
    {
        $raw = $_COOKIE[self::DEVICE_COOKIE] ?? '';
        if (!is_string($raw) || $raw === '') {
            return false;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 4) {
            return false;
        }
        [$cookieUserId, $deviceId, $expires, $hmac] = $parts;
        if ((int) $cookieUserId !== $userId || !ctype_digit($expires) || (int) $expires <= time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $userId . '|' . $deviceId . '|' . $expires, wp_salt('secure_auth'));
        if (!hash_equals($expected, $hmac)) {
            return false;
        }
        $devices = self::getDevices($userId);
        if (!array_key_exists($deviceId, $devices)) {
            return false;
        }
        if ($devices[$deviceId]['last_used'] < time() - DAY_IN_SECONDS) {
            $devices[$deviceId]['last_used'] = time();
            self::setDevices($userId, $devices);
        }
        return true;
    }

    /**
     * Human label for the current device, from the User-Agent.
     *
     * Only the first recognisable browser/platform token is kept;
     * the full User-Agent string is never stored.
     *
     * @return string
     */
    private static function deviceLabel(): string
    {
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!is_string($agent) || $agent === '') {
            return 'Unknown browser';
        }
        $agent = sanitize_text_field(wp_unslash($agent));
        foreach (['Firefox', 'Chrome', 'Safari', 'Edge', 'Opera'] as $browser) {
            if (str_contains($agent, $browser)) {
                foreach (['Windows', 'Macintosh', 'Linux', 'Android', 'iPhone', 'iPad'] as $platform) {
                    if (str_contains($agent, $platform)) {
                        return $browser . ' on ' . $platform;
                    }
                }
                return $browser;
            }
        }
        return 'Unknown browser';
    }

    // ------------------------------------------------------------------
    // Rate limiting and logging
    // ------------------------------------------------------------------

    /**
     * Transient name for a user's failed-attempt counter.
     *
     * @param int $userId User id.
     * @return string
     */
    public static function attemptsTransient(int $userId): string
    {
        return 'traydigita_2fa_attempts_' . $userId;
    }

    /**
     * Transient name for a user's lockout flag.
     *
     * @param int $userId User id.
     * @return string
     */
    public static function lockoutTransient(int $userId): string
    {
        return 'traydigita_2fa_lockout_' . $userId;
    }

    /**
     * Whether the user is currently locked out of verification.
     *
     * @param int $userId User id.
     * @return bool
     */
    public static function isLockedOut(int $userId): bool
    {
        return get_transient(self::lockoutTransient($userId)) !== false;
    }

    /**
     * Record a failed verification attempt.
     *
     * Increments the per-user counter (15-minute window, extended
     * to the lockout duration once the limit is hit) and sets the
     * lockout flag at the limit. Also appends to the failed-attempt
     * log. A successful verification clears the counter via
     * clearAttempts().
     *
     * @param int $userId User id.
     * @param string $method `totp`, `backup`, `passkey`, or `login`.
     * @param string $reason Machine-readable reason code.
     * @return void
     */
    public static function recordFailedAttempt(int $userId, string $method, string $reason): void
    {
        $settings = self::getSettings();
        $count = (int) get_transient(self::attemptsTransient($userId));
        $count++;
        set_transient(self::attemptsTransient($userId), $count, $settings['lockout_minutes'] * MINUTE_IN_SECONDS);
        if ($count >= $settings['max_attempts']) {
            set_transient(self::lockoutTransient($userId), time(), $settings['lockout_minutes'] * MINUTE_IN_SECONDS);
            self::logEvent($userId, 'login', 'locked', 'too_many_attempts');
            return;
        }
        self::logEvent($userId, $method, 'failed', $reason);
    }

    /**
     * Clear a user's attempt counter and lockout flag.
     *
     * Called after a successful verification, and by the WP-CLI
     * reset-lockout command.
     *
     * @param int $userId User id.
     * @return void
     */
    public static function clearAttempts(int $userId): void
    {
        delete_transient(self::attemptsTransient($userId));
        delete_transient(self::lockoutTransient($userId));
    }

    /**
     * Append an entry to the failed-attempt log.
     *
     * The log is a security log, not an activity log: it records
     * verification outcomes only, keeps at most MAX_LOG_ENTRIES
     * entries, and stores an HMAC of the client IP instead of the
     * IP itself. Successes are logged too, so an administrator
     * can see when a method was used — but secrets and codes are
     * never logged.
     *
     * @param int $userId User id.
     * @param string $method `totp`, `backup`, `passkey`, or `login`.
     * @param string $result `success`, `failed`, or `locked`.
     * @param string $reason Machine-readable reason code.
     * @return void
     */
    public static function logEvent(int $userId, string $method, string $result, string $reason): void
    {
        $user = get_user_by('id', $userId);
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            $log = [];
        }
        array_unshift($log, [
            'time' => time(),
            'user' => $user instanceof WP_User ? $user->user_login : '#' . $userId,
            'method' => $method,
            'result' => $result,
            'reason' => $reason,
            'ip' => self::clientIpHash(),
        ]);
        update_option(self::LOG_OPTION, array_slice($log, 0, self::MAX_LOG_ENTRIES), false);
        // Hook point for listeners (Activity Log): one action
        // for every outcome — success, failure and lockout —
        // including TOTP enrolment confirmation and passkey
        // verification, which all flow through here.
        do_action(self::VERIFIED_ACTION, $userId, $method, $result, $reason);
    }

    /**
     * Read the failed-attempt log, newest first.
     *
     * @return array<int, array{time: int, user: string, method: string, result: string, reason: string, ip: string}>
     */
    public static function getLog(): array
    {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) {
            return [];
        }
        $entries = [];
        foreach ($log as $entry) {
            if (!is_array($entry) || !isset($entry['time'], $entry['user'])) {
                continue;
            }
            $entries[] = [
                'time' => (int) $entry['time'],
                'user' => (string) $entry['user'],
                'method' => (string) ($entry['method'] ?? ''),
                'result' => (string) ($entry['result'] ?? ''),
                'reason' => (string) ($entry['reason'] ?? ''),
                'ip' => (string) ($entry['ip'] ?? ''),
            ];
        }
        return $entries;
    }

    /**
     * HMAC of the client IP for the log, or 'unknown'.
     *
     * The raw IP is never stored. Only a syntactically valid IP
     * is hashed, so a forged header value cannot reach the log.
     *
     * @return string
     */
    public static function clientIpHash(): string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return 'unknown';
        }
        $ip = trim(wp_unslash($raw));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return 'unknown';
        }
        return substr(hash_hmac('sha256', $ip, wp_salt('auth')), 0, 12);
    }

    /**
     * Client IP address, or null when it cannot be determined.
     *
     * @return string|null
     */
    public static function clientIp(): ?string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $ip = trim(wp_unslash($raw));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    // ------------------------------------------------------------------
    // Pending logins and passkey challenges
    // ------------------------------------------------------------------

    /**
     * Transient name for a parked login.
     *
     * @param string $token Pending-login token (64 hex chars).
     * @return string
     */
    public static function pendingTransient(string $token): string
    {
        return 'traydigita_2fa_pending_' . $token;
    }

    /**
     * Transient name for a passkey challenge.
     *
     * @deprecated Moved to PasskeysModule::passkeyChallengeTransient().
     *
     * @param string $key Challenge key.
     * @return string
     */
    public static function passkeyChallengeTransient(string $key): string
    {
        return PasskeysModule::passkeyChallengeTransient($key);
    }

    /**
     * Transient name for a pending TOTP enrolment.
     *
     * @param int $userId User id.
     * @return string
     */
    public static function totpPendingTransient(int $userId): string
    {
        return 'traydigita_2fa_totp_pending_' . $userId;
    }

    /**
     * Transient name for a pending passkey registration challenge.
     *
     * @deprecated Moved to PasskeysModule::passkeyRegChallengeTransient().
     *
     * @param int $userId User id.
     * @return string
     */
    public static function passkeyRegChallengeTransient(int $userId): string
    {
        return PasskeysModule::passkeyRegChallengeTransient($userId);
    }

    /**
     * Read a parked login by token.
     *
     * @param string $token Pending-login token.
     * @return array{user_id: int, redirect_to: string, remember: bool, created: int}|null
     */
    public static function getPendingLogin(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $pending = get_transient(self::pendingTransient($token));
        if (!is_array($pending) || !isset($pending['user_id'])) {
            return null;
        }
        return [
            'user_id' => (int) $pending['user_id'],
            'redirect_to' => is_string($pending['redirect_to'] ?? null) ? (string) $pending['redirect_to'] : admin_url(),
            'remember' => (bool) ($pending['remember'] ?? false),
            'created' => (int) ($pending['created'] ?? 0),
        ];
    }

    /**
     * Read the posted/queried pending-login token.
     *
     * Only a 64-character lowercase hex token is accepted; anything
     * else is treated as no token at all.
     *
     * @return string
     */
    public static function postedToken(): string
    {
        $token = '';
        if (isset($_GET['td2fa']) && is_string($_GET['td2fa'])) {
            $token = sanitize_text_field(wp_unslash($_GET['td2fa']));
        } elseif (isset($_POST['td2fa']) && is_string($_POST['td2fa'])) {
            $token = sanitize_text_field(wp_unslash($_POST['td2fa']));
        }
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? $token : '';
    }

    /**
     * Whether the current request is the 2FA challenge page.
     *
     * @return bool
     */
    public static function isChallengePage(): bool
    {
        $action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
        return $action === self::CHALLENGE_ACTION;
    }

    /**
     * Whether the standalone `two-factor` script entry is registered.
     *
     * Mirrors TurnstileModule::isLoginChallengeRenderable(): when
     * the coordinator has not wired the entry into
     * AssetLoader::ASSETS yet, the passkey UI is omitted
     * instead of rendering a button that can never work — password
     * and TOTP login keep working.
     *
     * @return bool
     */
    public static function isLoginScriptRenderable(): bool
    {
        if (!function_exists('wp_script_modules')) {
            return false;
        }
        try {
            return wp_script_modules()->get_registered(self::SCRIPT_HANDLE) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Finish a parked login: clear its state, set the auth cookie.
     *
     * Returns the redirect URL instead of redirecting, so the
     * AJAX passkey handler can answer with JSON and the form POST
     * handler can redirect itself. The caller is responsible for
     * the final redirect (or JSON response).
     *
     * @param string $token Pending-login token.
     * @param array{user_id: int, redirect_to: string, remember: bool, created: int} $pending Parked login.
     * @param bool $rememberDevice Whether to trust this device.
     * @return string Redirect URL.
     */
    public static function finishPendingLogin(string $token, array $pending, bool $rememberDevice): string
    {
        $userId = (int) $pending['user_id'];
        delete_transient(self::pendingTransient($token));
        delete_transient(PasskeysModule::passkeyChallengeTransient($token));
        self::clearAttempts($userId);
        if ($rememberDevice) {
            self::issueTrustedDevice($userId);
        }
        wp_set_auth_cookie($userId, (bool) ($pending['remember'] ?? false));
        $redirect = (string) ($pending['redirect_to'] ?? '');
        if ($redirect === '') {
            $redirect = admin_url();
        }
        return $redirect;
    }

    // ------------------------------------------------------------------
    // Login flow
    // ------------------------------------------------------------------

    /**
     * Enqueue the login-page assets.
     *
     * Runs on `login_enqueue_scripts`. The script module and its
     * bundled CSS are only enqueued when the entry is registered;
     * otherwise the login page is untouched and password login
     * works exactly as before.
     *
     * @return void
     */
    public static function enqueueLoginAssets(): void
    {
        if (self::isDisabled() || !function_exists('wp_enqueue_script_module')) {
            return;
        }
        if (!self::isLoginScriptRenderable()) {
            return;
        }
        wp_enqueue_script_module(self::SCRIPT_HANDLE);
        if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
    }

    /**
     * Enqueue the profile-page assets.
     *
     * Runs on `admin_enqueue_scripts`, only on profile.php and
     * user-edit.php. The same `two-factor` entry drives the
     * profile setup UI (TOTP wizard, device revocation).
     *
     * @param mixed $hookSuffix Admin page hook suffix.
     * @return void
     */
    public static function enqueueProfileAssets(mixed $hookSuffix = null): void
    {
        if (self::isDisabled() || !function_exists('wp_enqueue_script_module')) {
            return;
        }
        if (!in_array($hookSuffix, ['profile.php', 'user-edit.php'], true)) {
            return;
        }
        if (!self::isLoginScriptRenderable()) {
            return;
        }
        wp_enqueue_script_module(self::SCRIPT_HANDLE);
        if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
    }

    /**
     * Verify the second factor on login.
     *
     * Runs on the `authenticate` filter at priority 50, after
     * WordPress core (20/30) and Turnstile (40). A failed
     * credential check is returned untouched, and a valid user is
     * only parked when they actually have an enabled 2FA method.
     * Requests that are not login-form posts (no `log` field —
     * XML-RPC, REST, application passwords, and programmatic
     * logins) pass through untouched, as do logins while
     * enforcement is disabled or the user has a valid trusted
     * device. Parking redirects to the challenge screen and exits;
     * the redirect is the only way to stop WordPress from logging
     * the user in at this point, and it is the same pattern the
     * WordPress.org Two Factor plugin uses.
     *
     * @param WP_User|WP_Error|null $user Authenticated user, error, or null.
     * @param string $username Submitted username (unused).
     * @param string $password Submitted password (unused).
     * @return WP_User|WP_Error|null
     */
    public static function verifyLogin(mixed $user, string $username = '', string $password = ''): mixed
    {
        if ($user instanceof WP_Error || !$user instanceof WP_User) {
            return $user;
        }
        if (!isset($_POST['log'])) {
            return $user;
        }
        $userId = (int) $user->ID;
        if (self::isDisabled() || !self::hasAnyMethod($userId)) {
            return $user;
        }
        if (self::hasValidTrustedDevice($userId)) {
            return $user;
        }
        $token = bin2hex(random_bytes(32));
        $redirectTo = '';
        if (isset($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to'])) {
            $redirectTo = wp_unslash($_REQUEST['redirect_to']);
        }
        $redirectTo = wp_validate_redirect($redirectTo, admin_url());
        $remember = isset($_POST['rememberme']) && $_POST['rememberme'] === 'forever';
        set_transient(
            self::pendingTransient($token),
            [
                'user_id' => $userId,
                'redirect_to' => $redirectTo,
                'remember' => $remember,
                'created' => time(),
            ],
            self::PENDING_LOGIN_MINUTES * MINUTE_IN_SECONDS
        );
        wp_safe_redirect(add_query_arg(
            ['action' => self::CHALLENGE_ACTION, 'td2fa' => $token],
            wp_login_url()
        ));
        exit;
    }

    /**
     * Handle a challenge-form POST.
     *
     * Runs on `login_init`, before the challenge screen renders.
     * Only POSTs to the challenge action are handled; anything
     * else returns immediately. On success the login is completed
     * (auth cookie + redirect + exit). On failure the error is
     * stored for the form to show when it renders in the same
     * request — the user stays on the challenge screen and can
     * retry until the lockout limit. Every outcome is logged,
     * including an expired link and an attempt made while
     * locked out, so no failure path is invisible to the log.
     *
     * The code field accepts both forms: 6 digits are checked as
     * a TOTP code, 8 digits as a backup code. Anything else fails
     * without touching the secret.
     *
     * @return void
     */
    public static function handleChallengePost(): void
    {
        if (!self::isChallengePage()) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $token = self::postedToken();
        $pending = self::getPendingLogin($token);
        if ($pending === null) {
            self::logEvent(0, 'login', 'failed', 'expired');
            self::$challengeError = __('This verification link has expired. Please log in again.', 'traydigita-core');
            return;
        }
        $userId = (int) $pending['user_id'];
        if (self::isLockedOut($userId)) {
            self::logEvent($userId, 'login', 'locked', 'locked_out');
            /* translators: %d is the number of minutes until the lockout ends. */
            self::$challengeError = sprintf(__('Too many failed attempts. Please try again in %d minutes.', 'traydigita-core'), self::getSettings()['lockout_minutes']);
            return;
        }
        $code = '';
        if (isset($_POST['traydigita_2fa_code']) && is_string($_POST['traydigita_2fa_code'])) {
            $code = sanitize_text_field(wp_unslash($_POST['traydigita_2fa_code']));
        }
        $digits = Totp::normalizeBackupCode($code);
        $settings = self::getSettings();
        $method = strlen($digits) === 8 ? 'backup' : 'totp';
        $verified = false;
        if (strlen($digits) === 6 && $settings['totp_enabled']) {
            $secret = self::getTotpSecret($userId);
            if ($secret !== null && Totp::verifyCode($secret, $digits)) {
                $verified = true;
                $method = 'totp';
            }
        } elseif (strlen($digits) === 8) {
            if (self::verifyBackupCode($userId, $digits)) {
                $verified = true;
                $method = 'backup';
            }
        }
        if (!$verified) {
            self::recordFailedAttempt($userId, $method, 'bad_code');
            self::$challengeError = __('That code did not match. Please try again.', 'traydigita-core');
            return;
        }
        self::logEvent($userId, $method, 'success', 'verified');
        $rememberDevice = isset($_POST['traydigita_2fa_remember']) && $_POST['traydigita_2fa_remember'] === '1';
        $redirect = self::finishPendingLogin($token, $pending, $rememberDevice);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Render the 2FA challenge screen.
     *
     * Runs on `login_form_traydigita_2fa`, which wp-login.php
     * fires before the switch for any action that has the hook
     * (verified against WP core source). The handler owns the
     * whole page — login_header(), the form, login_footer() —
     * and exits, so the normal login form never renders after it.
     *
     * The form shows the TOTP field when the user has TOTP, the
     * passkey button when they have passkeys and the site is on
     * HTTPS, and the remember-device checkbox when trusted
     * devices are enabled. A locked-out user sees the error and
     * a link back to log in, but no form.
     *
     * @return void
     */
    public static function renderChallengeForm(): void
    {
        $token = self::postedToken();
        $pending = self::getPendingLogin($token);
        if ($pending === null) {
            login_header(
                __('Two-factor authentication', 'traydigita-core'),
                '<p class="message">' . esc_html__('This verification link has expired. Please log in again.', 'traydigita-core') . '</p>'
            );
            echo '<p class="traydigita-two-factor-back"><a href="' . esc_url(wp_login_url()) . '">'
                . esc_html__('Back to log in', 'traydigita-core') . '</a></p>';
            login_footer();
            exit;
        }
        $userId = (int) $pending['user_id'];
        $user = get_user_by('id', $userId);
        $settings = self::getSettings();
        $hasTotp = $settings['totp_enabled'] && self::hasTotp($userId);
        $passkeys = PasskeysModule::isPasskeysEnabled() ? PasskeysModule::getPasskeys($userId) : [];
        $showPasskey = $passkeys !== [] && PasskeysModule::isPasskeyAvailable() && self::isLoginScriptRenderable();
        $lockedOut = self::isLockedOut($userId);
        $error = self::$challengeError;
        if ($lockedOut && $error === '') {
            /* translators: %d is the number of minutes until the lockout ends. */
            $error = sprintf(__('Too many failed attempts. Please try again in %d minutes.', 'traydigita-core'), $settings['lockout_minutes']);
        }
        $message = '';
        if ($error !== '') {
            $message = '<p class="message traydigita-two-factor-error">' . esc_html($error) . '</p>';
        }
        login_header(__('Two-factor authentication', 'traydigita-core'), $message);
        $loginName = $user instanceof WP_User ? $user->user_login : '';
        ?>
        <form name="traydigita-2fa-form" id="traydigita-2fa-form" class="traydigita-two-factor-form"
            action="<?php echo esc_url(add_query_arg(['action' => self::CHALLENGE_ACTION, 'td2fa' => $token], wp_login_url())); ?>"
            method="post">
            <p class="traydigita-two-factor-intro">
                <?php
                /* translators: %s is the account login name. */
                echo esc_html(sprintf(__('Signing in as %s. Enter the code from your authenticator app to continue.', 'traydigita-core'), $loginName));
                ?>
            </p>
            <?php if ($lockedOut) : ?>
                <p class="traydigita-two-factor-back">
                    <a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to log in', 'traydigita-core'); ?></a>
                </p>
            <?php else : ?>
                <?php if ($hasTotp) : ?>
                    <p>
                        <label for="traydigita-2fa-code"><?php esc_html_e('Authentication code', 'traydigita-core'); ?></label>
                        <input type="text" name="traydigita_2fa_code" id="traydigita-2fa-code" class="input" value=""
                            inputmode="numeric" autocomplete="one-time-code" maxlength="9" autofocus>
                    </p>
                    <p class="traydigita-two-factor-hint">
                        <?php esc_html_e('Enter the 6-digit code from your authenticator app, or one of your 8-digit backup codes.', 'traydigita-core'); ?>
                    </p>
                <?php elseif ($passkeys === []) : ?>
                    <p class="traydigita-two-factor-hint">
                        <?php esc_html_e('This account has no second-factor method set up. Please contact an administrator.', 'traydigita-core'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($settings['trusted_device_days'] > 0) : ?>
                    <p class="traydigita-two-factor-remember">
                        <label for="traydigita-2fa-remember">
                            <input type="checkbox" name="traydigita_2fa_remember" id="traydigita-2fa-remember" value="1">
                            <?php
                            /* translators: %d is the number of days the device is remembered. */
                            echo esc_html(sprintf(__('Remember this device for %d days', 'traydigita-core'), $settings['trusted_device_days']));
                            ?>
                        </label>
                    </p>
                <?php endif; ?>
                <input type="hidden" name="td2fa" value="<?php echo esc_attr($token); ?>">
                <?php if ($hasTotp) : ?>
                    <p class="submit">
                        <input type="submit" name="wp-submit" id="traydigita-2fa-submit" class="button button-primary button-large"
                            value="<?php echo esc_attr__('Verify and log in', 'traydigita-core'); ?>">
                    </p>
                <?php endif; ?>
                <?php if ($showPasskey) : ?>
                    <div class="traydigita-two-factor-divider" data-traydigita-passkey-divider aria-hidden="true"><span><?php esc_html_e('or', 'traydigita-core'); ?></span></div>
                    <p>
                        <button type="button" class="button button-secondary traydigita-two-factor-passkey-button" data-traydigita-passkey-challenge
                            data-token="<?php echo esc_attr($token); ?>"
                            data-msg-no-webauthn="<?php echo esc_attr__('Your browser does not support passkeys. Please use your authenticator code.', 'traydigita-core'); ?>"
                            data-msg-cancelled="<?php echo esc_attr__('Passkey verification was cancelled.', 'traydigita-core'); ?>"
                            data-msg-failed="<?php echo esc_attr__('Passkey verification failed. Please use your authenticator code.', 'traydigita-core'); ?>">
                            <?php esc_html_e('Use fingerprint or Face ID instead', 'traydigita-core'); ?>
                        </button>
                    </p>
                    <p class="traydigita-two-factor-passkey-note" data-traydigita-passkey-challenge-note aria-live="polite"></p>
                <?php endif; ?>
                <p class="traydigita-two-factor-back">
                    <a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to log in', 'traydigita-core'); ?></a>
                </p>
            <?php endif; ?>
        </form>
        <?php
        login_footer();
        exit;
    }

    // ------------------------------------------------------------------
    // Enforcement
    // ------------------------------------------------------------------

    /**
     * Redirect users in enforced roles without any 2FA method.
     *
     * Runs on `admin_init`. The user is sent to their own profile
     * page (where they can enroll) and stays there until they
     * have a method — every other admin page redirects back.
     * Exclusions, each deliberate:
     * - admin-ajax.php (wp_doing_ajax): the enrolment wizard and
     *   other admin AJAX must keep working while the user sets up.
     * - profile.php and admin-post.php: the profile page itself,
     *   and form posts that may carry the enrolment flow.
     * - Logged-out users and users outside the enforced roles:
     *   not this module's concern.
     * The login challenge is unaffected: a user with no method
     * never reaches this redirect, because verifyLogin() only
     * parks users who have one.
     *
     * @return void
     */
    public static function maybeEnforceSetup(): void
    {
        if (self::isDisabled() || wp_doing_ajax() || !is_user_logged_in()) {
            return;
        }
        $user = wp_get_current_user();
        if (!$user instanceof WP_User || $user->ID <= 0) {
            return;
        }
        $userId = (int) $user->ID;
        if (self::hasAnyMethod($userId)) {
            return;
        }
        $settings = self::getSettings();
        if ($settings['enforced_roles'] === []) {
            return;
        }
        if (array_intersect((array) $user->roles, $settings['enforced_roles']) === []) {
            return;
        }
        global $pagenow;
        if (isset($pagenow) && in_array($pagenow, ['profile.php', 'admin-post.php'], true)) {
            return;
        }
        wp_safe_redirect(admin_url('profile.php?traydigita_2fa_setup=1'));
        exit;
    }

    /**
     * Whether the profile setup reminder will show for the current user.
     *
     * Shared by maybeShowSetupNotice() and enqueueProfileNoticeAssets()
     * so the library assets load exactly when the notice does.
     *
     * @return bool
     */
    private static function willShowSetupNotice(): bool
    {
        global $pagenow;
        if (($pagenow ?? '') !== 'profile.php' || self::isDisabled() || !is_user_logged_in()) {
            return false;
        }
        $userId = get_current_user_id();
        if ($userId <= 0 || self::hasAnyMethod($userId)) {
            return false;
        }
        $settings = self::getSettings();
        if ($settings['enforced_roles'] === []) {
            return false;
        }
        $user = wp_get_current_user();
        return $user instanceof WP_User
            && array_intersect((array) $user->roles, $settings['enforced_roles']) !== [];
    }

    /**
     * Enqueue the admin UI library on the profile screen when the
     * setup reminder will show (see willShowSetupNotice()).
     *
     * @return void
     */
    public static function enqueueProfileNoticeAssets(): void
    {
        if (!self::willShowSetupNotice()) {
            return;
        }
        Core::assets()->enqueueAdminEntry();
    }

    /**
     * Show the setup reminder on the profile page.
     *
     * Runs on `admin_notices`, only on profile.php, only for
     * logged-in users in an enforced role who have not enrolled
     * yet. The notice is informational — the redirect in
     * maybeEnforceSetup() does the enforcing.
     *
     * @return void
     */
    public static function maybeShowSetupNotice(): void
    {
        if (!self::willShowSetupNotice()) {
            return;
        }
        AdminNotice::render('warning', __('Your role requires two-factor authentication. Set it up below to keep using the admin area.', 'traydigita-core'));
    }

    // ------------------------------------------------------------------
    // Profile UI
    // ------------------------------------------------------------------

    /**
     * Number of unused backup codes for a user.
     *
     * @param int $userId User id.
     * @return int
     */
    public static function remainingBackupCodes(int $userId): int
    {
        $hashes = get_user_meta($userId, self::META_BACKUP_CODES, true);
        if (!is_array($hashes)) {
            return 0;
        }
        return count($hashes);
    }

    /**
     * Render the 2FA section on the profile page.
     *
     * Runs on `show_user_profile` (own profile) and
     * `edit_user_profile` (an admin editing another user).
     * All interactive elements are `type="button"` so they never
     * submit the surrounding profile form; the frontend script
     * drives them over AJAX with the user nonce. Passkey
     * management by another admin requires the `edit_users`
     * capability — the AJAX layer enforces that, not the markup.
     *
     * The JSON config block is printed by
     * PasskeysModule::printProfileConfig() (shared with the
     * Passkeys module's own profile section, printed at most
     * once per request). The passkey block itself is rendered
     * by PasskeysModule::renderPasskeyBlock() in place, between
     * the TOTP and trusted-device blocks — it must stay inside
     * this container, because the theme script only queries the
     * first `data-traydigita-two-factor-profile` element.
     *
     * @param WP_User $user The user being edited.
     * @return void
     */
    public static function renderProfileFields(WP_User $user): void
    {
        $userId = (int) $user->ID;
        $settings = self::getSettings();
        $hasTotp = $settings['totp_enabled'] && self::hasTotp($userId);
        $backupCount = self::remainingBackupCodes($userId);
        $devices = $settings['trusted_device_days'] > 0 ? self::getDevices($userId) : [];
        $scriptReady = self::isLoginScriptRenderable();
        ?>
        <h2><?php esc_html_e('Two-factor authentication', 'traydigita-core'); ?></h2>
        <div class="traydigita-two-factor-profile" data-traydigita-two-factor-profile
            data-msg-error="<?php echo esc_attr__('Something went wrong. Please try again.', 'traydigita-core'); ?>"
            data-msg-copied="<?php echo esc_attr__('Copied.', 'traydigita-core'); ?>"
            data-msg-passkey-no-support="<?php echo esc_attr__('This browser does not support passkeys.', 'traydigita-core'); ?>"
            data-msg-passkey-cancelled="<?php echo esc_attr__('Passkey registration was cancelled.', 'traydigita-core'); ?>"
            data-msg-passkey-failed="<?php echo esc_attr__('Passkey registration failed. Please try again.', 'traydigita-core'); ?>"
            data-msg-confirm-disable="<?php echo esc_attr__('Remove the authenticator app from this account? You will no longer be asked for a code at login.', 'traydigita-core'); ?>"
            data-msg-confirm-revoke="<?php echo esc_attr__('Remove this trusted device? You will be asked for a code again on it.', 'traydigita-core'); ?>"
            data-msg-confirm-revoke-all="<?php echo esc_attr__('Remove all trusted devices for this account?', 'traydigita-core'); ?>"
            data-msg-confirm-remove-passkey="<?php echo esc_attr__('Remove this passkey? You will no longer be able to sign in with it.', 'traydigita-core'); ?>"
            data-msg-no-passkeys="<?php echo esc_attr__('No passkeys yet.', 'traydigita-core'); ?>"
            data-msg-no-devices="<?php echo esc_attr__('No trusted devices.', 'traydigita-core'); ?>"
            data-msg-remove="<?php echo esc_attr__('Remove', 'traydigita-core'); ?>">
            <?php PasskeysModule::printProfileConfig($user); ?>
            <p class="description">
                <?php esc_html_e('Two-factor authentication asks for a second proof of identity at login — a code from an authenticator app, a fingerprint or Face ID, or a one-time backup code. Your password still works as before.', 'traydigita-core'); ?>
            </p>
            <?php if (!$scriptReady) : ?>
                <p class="description traydigita-two-factor-warning">
                    <?php esc_html_e('The two-factor scripts are not loaded yet (the theme entry is missing). The settings below will work after the theme is rebuilt.', 'traydigita-core'); ?>
                </p>
            <?php endif; ?>

            <?php if ($settings['totp_enabled']) : ?>
            <div class="traydigita-two-factor-block" data-traydigita-totp-block>
                <h3><?php esc_html_e('Authenticator app (TOTP)', 'traydigita-core'); ?></h3>
                <p class="traydigita-two-factor-status" data-traydigita-totp-status>
                    <?php if ($hasTotp) : ?>
                        <?php
                        /* translators: %d is the number of unused backup codes. */
                        echo esc_html(sprintf(__('Enabled. %d backup codes left.', 'traydigita-core'), $backupCount));
                        ?>
                    <?php else : ?>
                        <?php esc_html_e('Not set up yet.', 'traydigita-core'); ?>
                    <?php endif; ?>
                </p>
                <div data-traydigita-totp-setup hidden>
                    <p><?php esc_html_e('Scan this code with your authenticator app (Google Authenticator, Authy, 1Password, or any TOTP app), then enter the 6-digit code it shows to confirm.', 'traydigita-core'); ?></p>
                    <div class="traydigita-two-factor-qr" data-traydigita-totp-qr></div>
                    <p class="traydigita-two-factor-secret">
                        <span><?php esc_html_e('Secret key (manual entry):', 'traydigita-core'); ?></span>
                        <code data-traydigita-totp-secret></code>
                        <button type="button" class="button button-small" data-traydigita-totp-copy-secret><?php esc_html_e('Copy', 'traydigita-core'); ?></button>
                    </p>
                    <p>
                        <label for="traydigita-2fa-setup-code"><?php esc_html_e('Code from the app', 'traydigita-core'); ?></label>
                        <input type="text" id="traydigita-2fa-setup-code" class="traydigita-ui-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" data-traydigita-totp-confirm-code>
                    </p>
                    <p>
                        <button type="button" class="button button-primary" data-traydigita-totp-confirm><?php esc_html_e('Confirm and enable', 'traydigita-core'); ?></button>
                        <button type="button" class="button" data-traydigita-totp-cancel><?php esc_html_e('Cancel', 'traydigita-core'); ?></button>
                    </p>
                    <p class="traydigita-two-factor-note" data-traydigita-totp-setup-note aria-live="polite"></p>
                </div>
                <div data-traydigita-totp-backup hidden>
                    <p><strong><?php esc_html_e('Backup codes — save these now.', 'traydigita-core'); ?></strong></p>
                    <p><?php esc_html_e('Each code works once, if you lose access to your authenticator app. Store them somewhere safe — they are shown only once.', 'traydigita-core'); ?></p>
                    <ul class="traydigita-two-factor-codes" data-traydigita-totp-backup-codes></ul>
                    <p>
                        <button type="button" class="button" data-traydigita-totp-copy-codes><?php esc_html_e('Copy backup codes', 'traydigita-core'); ?></button>
                        <button type="button" class="button" data-traydigita-totp-backup-done><?php esc_html_e('Done', 'traydigita-core'); ?></button>
                    </p>
                </div>
                <p data-traydigita-totp-start-wrap <?php echo $hasTotp ? 'hidden' : ''; ?>>
                    <button type="button" class="button button-primary" data-traydigita-totp-start><?php esc_html_e('Set up authenticator app', 'traydigita-core'); ?></button>
                </p>
                <p data-traydigita-totp-manage-wrap <?php echo $hasTotp ? '' : 'hidden'; ?>>
                    <button type="button" class="button" data-traydigita-backup-regenerate><?php esc_html_e('Regenerate backup codes', 'traydigita-core'); ?></button>
                    <button type="button" class="button" data-traydigita-totp-disable><?php esc_html_e('Remove authenticator app', 'traydigita-core'); ?></button>
                </p>
                <p class="traydigita-two-factor-note" data-traydigita-totp-note aria-live="polite"></p>
            </div>
            <?php endif; ?>

            <?php PasskeysModule::renderPasskeyBlock($user); ?>

            <?php if ($settings['trusted_device_days'] > 0) : ?>
            <div class="traydigita-two-factor-block" data-traydigita-device-block>
                <h3><?php esc_html_e('Trusted devices', 'traydigita-core'); ?></h3>
                <p class="description">
                    <?php
                    /* translators: %d is the number of days a device stays trusted. */
                    echo esc_html(sprintf(__('Devices you trust skip the second factor for %d days. Remove a device any time to ask for a code again.', 'traydigita-core'), $settings['trusted_device_days']));
                    ?>
                </p>
                <?php if ($devices === []) : ?>
                    <p class="traydigita-two-factor-status"><?php esc_html_e('No trusted devices.', 'traydigita-core'); ?></p>
                <?php else : ?>
                    <ul class="traydigita-two-factor-devices" data-traydigita-device-list>
                        <?php foreach ($devices as $device) : ?>
                            <li>
                                <span class="traydigita-two-factor-device-label"><?php echo esc_html((string) ($device['label'] ?? '')); ?></span>
                                <span class="traydigita-two-factor-meta">
                                    <?php
                                    /* translators: 1: date the device was last used, 2: date the trust expires. */
                                    echo esc_html(sprintf(
                                        __('Last used %1$s — expires %2$s', 'traydigita-core'),
                                        date_i18n(get_option('date_format', 'F j, Y'), (int) ($device['last_used'] ?? 0)),
                                        date_i18n(get_option('date_format', 'F j, Y'), (int) ($device['expires'] ?? 0))
                                    ));
                                    ?>
                                </span>
                                <button type="button" class="button button-small" data-traydigita-device-revoke data-device-id="<?php echo esc_attr((string) ($device['id'] ?? '')); ?>"><?php esc_html_e('Remove', 'traydigita-core'); ?></button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p>
                        <button type="button" class="button" data-traydigita-device-revoke-all><?php esc_html_e('Remove all trusted devices', 'traydigita-core'); ?></button>
                    </p>
                <?php endif; ?>
                <p class="traydigita-two-factor-note" data-traydigita-device-note aria-live="polite"></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------
    // Settings page
    // ------------------------------------------------------------------

    /**
     * Framework sections for the left navigation.
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'methods', 'label' => __('Methods', 'traydigita-core'), 'icon' => 'smartphone'],
            ['id' => 'enforcement', 'label' => __('Require for roles', 'traydigita-core'), 'icon' => 'admin-users'],
            ['id' => 'devices', 'label' => __('Trusted devices', 'traydigita-core'), 'icon' => 'laptop'],
            ['id' => 'lockout', 'label' => __('Failed attempts', 'traydigita-core'), 'icon' => 'lock'],
            ['id' => 'recovery', 'label' => __('Emergency recovery', 'traydigita-core'), 'icon' => 'sos'],
            ['id' => 'log', 'label' => __('Recent failed attempts', 'traydigita-core'), 'icon' => 'list-view'],
        ];
    }

    /**
     * Render the module settings form.
     *
     * Settings-framework layout (section cards, Bantuan pills
     * for the long descriptions, modern controls), like the
     * other modules: an intro, one section per concern, and the
     * sticky framework save bar. The form itself is unchanged
     * — it still posts through the module dispatcher
     * (`data-traydigita-module-form` + `data-module`, nonce
     * field `_ajax_nonce` with action `traydigita-admin`) —
     * and saveSettings() is untouched, so the save round-trip
     * behaves exactly as before. Checkboxes use the
     * "absent means off" convention the dispatcher relies on,
     * so saveSettings() treats a missing key as false.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $log = self::getLog();
        $scriptReady = self::isLoginScriptRenderable();
        $roles = get_editable_roles();
        $roleOptions = [];
        foreach ($roles as $slug => $role) {
            $roleOptions[(string) $slug] = translate_user_role((string) ($role['name'] ?? $slug));
        }
        ?>
        <div class="traydigita-module-settings two-factor-settings">
            <?php
            SettingsControls::intro(
                __('Two-factor sign-in: authenticator codes and trusted devices.', 'traydigita-core'),
                __('Users verify a second factor after their password. Choose which methods are offered, which roles must use them, and how failed attempts are limited below. Passkeys are managed on the Passkeys module page.', 'traydigita-core')
            );
            ?>
            <?php if (!$scriptReady) : ?>
                <?php AdminNotice::render('warning', __('The two-factor login script is not registered yet. Passkey buttons will not appear on the login page until the theme entry "two-factor" is wired into the Vite manifest (see the module notes in the integration report). Password and TOTP login are unaffected.', 'traydigita-core')); ?>
            <?php endif; ?>

            <form method="post" data-traydigita-module-form data-traydigita-module="two-factor" data-module="two-factor">
                <?php wp_nonce_field('traydigita-admin', '_ajax_nonce'); ?>
                <input type="hidden" name="module" value="two-factor">

                <?php SettingsControls::sectionOpen('methods', __('Methods', 'traydigita-core')); ?>
                <?php
                SettingsControls::toggle([
                    'id' => 'two-factor-totp-enabled',
                    'name' => 'totp_enabled',
                    'label' => __('Enable authenticator app (TOTP)', 'traydigita-core'),
                    'checked' => $settings['totp_enabled'],
                    'help' => __('Users enroll a 6-digit code from any authenticator app.', 'traydigita-core'),
                    'help_long' => __('QR codes are generated locally — no external service is involved.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>

                <?php SettingsControls::sectionOpen('enforcement', __('Require for roles', 'traydigita-core')); ?>
                <?php
                SettingsControls::checkboxGroup([
                    'id' => 'two-factor-enforced-roles',
                    'name' => 'enforced_roles[]',
                    'label' => __('Require for roles', 'traydigita-core'),
                    'options' => $roleOptions,
                    'checked' => $settings['enforced_roles'],
                    'help' => __('Users in these roles must set up two-factor authentication before they can use the admin area.', 'traydigita-core'),
                    'help_long' => __('Users without any method are redirected to their profile page.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>

                <?php SettingsControls::sectionOpen('devices', __('Trusted devices', 'traydigita-core')); ?>
                <?php
                SettingsControls::number([
                    'id' => 'two-factor-trusted-device-days',
                    'name' => 'trusted_device_days',
                    'label' => __('Remember devices', 'traydigita-core'),
                    'value' => $settings['trusted_device_days'],
                    'attrs' => ['min' => '0', 'max' => '90', 'step' => '1'],
                    'help' => __('After verifying once, a trusted device skips the second factor for this many days (0 disables trusted devices).', 'traydigita-core'),
                    'help_long' => __('Devices are stored as HMAC-signed cookies and can be revoked from the user profile.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>

                <?php SettingsControls::sectionOpen('lockout', __('Failed attempts', 'traydigita-core')); ?>
                <?php
                SettingsControls::number([
                    'id' => 'two-factor-max-attempts',
                    'name' => 'max_attempts',
                    'label' => __('Lock out after failed attempts', 'traydigita-core'),
                    'value' => $settings['max_attempts'],
                    'attrs' => ['min' => '1', 'max' => '20', 'step' => '1'],
                    'help' => __('How many failed verifications trigger a lockout.', 'traydigita-core'),
                    'help_long' => __('Rate limiting applies to code verification and passkey attempts, per user.', 'traydigita-core'),
                ]);
                SettingsControls::number([
                    'id' => 'two-factor-lockout-minutes',
                    'name' => 'lockout_minutes',
                    'label' => __('Lockout duration in minutes', 'traydigita-core'),
                    'value' => $settings['lockout_minutes'],
                    'attrs' => ['min' => '1', 'max' => '60', 'step' => '1'],
                    'help' => __('How long the lockout lasts.', 'traydigita-core'),
                    'help_long' => __('The lockout is a temporary delay, not a permanent block.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>

                <?php SettingsControls::saveBar(); ?>

                <?php SettingsControls::sectionOpen('recovery', __('Emergency recovery', 'traydigita-core')); ?>
                <p>
                    <?php esc_html_e('If a user is locked out of their account, an administrator can disable two-factor authentication for that user from the command line:', 'traydigita-core'); ?>
                </p>
                <p><code>wp traydigita-2fa disable &lt;user&gt;</code></p>
                <p>
                    <?php esc_html_e('As a last resort, with server file access, define the constant below in wp-config.php (or set the filter) to switch two-factor authentication off site-wide until it can be re-enabled:', 'traydigita-core'); ?>
                </p>
                <p><code>define( 'TRAYDIGITA_2FA_DISABLE', true );</code></p>
                <p class="td-settings-help">
                    <?php
                    SettingsControls::helpText(
                        __('This is not a backdoor: it requires editing a file on the server.', 'traydigita-core'),
                        __('It disables the check for everyone — including administrators — so it must be removed again after the recovery.', 'traydigita-core')
                    );
                    ?>
                </p>
                <?php SettingsControls::sectionClose(); ?>

                <?php SettingsControls::sectionOpen('log', __('Recent failed attempts', 'traydigita-core')); ?>
                <?php if ($log === []) : ?>
                    <p class="description"><?php esc_html_e('No failed attempts recorded yet.', 'traydigita-core'); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Time', 'traydigita-core'); ?></th>
                                <th><?php esc_html_e('User', 'traydigita-core'); ?></th>
                                <th><?php esc_html_e('Method', 'traydigita-core'); ?></th>
                                <th><?php esc_html_e('Reason', 'traydigita-core'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($log, 0, 20) as $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html(date_i18n(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a'), (int) ($entry['time'] ?? 0))); ?></td>
                                    <td><?php echo esc_html((string) ($entry['user'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($entry['method'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($entry['reason'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="td-settings-help">
                        <?php
                        SettingsControls::helpText(
                            __('Only failed attempts are recorded (up to 200 entries, '
                                . 'oldest first removed).', 'traydigita-core'),
                            __('This is a lightweight security log, not a full activity log.', 'traydigita-core')
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <?php SettingsControls::sectionClose(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Save the module settings.
     *
     * Called by the module dispatcher with the submitted form
     * data. Checkbox fields absent from the data are treated as
     * off (unchecked checkboxes are not submitted by browsers).
     * Every value is validated: role slugs against the editable
     * roles, numbers clamped to their ranges. Unknown keys are
     * dropped, so a crafted request cannot inject settings.
     *
     * @param array<string, mixed> $data Submitted form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You do not have permission to change these settings.', 'traydigita-core'),
            ];
        }
        $current = self::getSettings();
        $settings = $current;
        $settings['totp_enabled'] = isset($data['totp_enabled'])
            ? filter_var($data['totp_enabled'], FILTER_VALIDATE_BOOLEAN)
            : false;
        // The passkey toggle lives on the Passkeys module page now
        // and is never written here: the stored legacy
        // `passkey_enabled` value is the fallback source for
        // PasskeysModule::getSettings() on installs that never
        // saved the Passkeys page, so overwriting it with the
        // (absent) form field would silently turn passkeys off.
        // Preserve whatever is stored, or leave the key out when
        // nothing was ever stored.
        $rawStored = get_option(self::OPTION, []);
        if (is_array($rawStored) && array_key_exists('passkey_enabled', $rawStored)) {
            $settings['passkey_enabled'] = (bool) $rawStored['passkey_enabled'];
        } else {
            unset($settings['passkey_enabled']);
        }
        $roles = [];
        if (isset($data['enforced_roles']) && is_array($data['enforced_roles'])) {
            $validRoles = array_keys(get_editable_roles());
            foreach ($data['enforced_roles'] as $role) {
                $role = sanitize_key((string) $role);
                if (in_array($role, $validRoles, true)) {
                    $roles[] = $role;
                }
            }
        }
        $settings['enforced_roles'] = array_values(array_unique($roles));
        $settings['trusted_device_days'] = min(
            90,
            max(0, absint($data['trusted_device_days'] ?? $current['trusted_device_days']))
        );
        $settings['max_attempts'] = min(
            20,
            max(1, absint($data['max_attempts'] ?? $current['max_attempts']))
        );
        $settings['lockout_minutes'] = min(
            60,
            max(1, absint($data['lockout_minutes'] ?? $current['lockout_minutes']))
        );
        update_option(self::OPTION, $settings);
        return [
            'success' => true,
            'message' => __('Two-factor settings saved.', 'traydigita-core'),
        ];
    }
}
