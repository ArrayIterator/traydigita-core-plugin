<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Passkeys;

use TrayDigita\WP\Plugin\Core\Admin\AdminNotice;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\TwoFactor\TwoFactorModule;
use WP_User;
use function __;
use function add_action;
use function admin_url;
use function array_key_exists;
use function array_merge;
use function current_user_can;
use function date_i18n;
use function delete_user_meta;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function filter_var;
use function function_exists;
use function get_current_user_id;
use function get_option;
use function get_user_meta;
use function home_url;
use function in_array;
use function is_array;
use function is_ssl;
use function is_string;
use function parse_url;
use function sprintf;
use function time;
use function update_option;
use function update_user_meta;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_script_module;
use function wp_enqueue_style;
use function wp_json_encode;
use function wp_nonce_field;
use function wp_style_is;
use const FILTER_VALIDATE_BOOLEAN;

/**
 * Passkeys module (WebAuthn, WebAuthn.php + Cbor.php).
 *
 * What it does:
 * - Passkey login on wp-login.php: fingerprint / Face ID /
 *   security-key sign-in with discoverable credentials
 *   (usernameless) and as a second factor on the Two-Factor
 *   challenge screen. Password login is never replaced - it
 *   stays the fallback, and TOTP stays available alongside
 *   passkeys. Enrolment happens on the user profile page.
 * - The implementation is a deliberate, scope-limited, fully
 *   tested minimal server (ES256 + RS256; EdDSA is rejected
 *   because PHP's openssl_verify() cannot verify Ed25519
 *   signatures) instead of the 31-package web-auth/webauthn-lib
 *   tree; the trade-off is documented on WebAuthn.php.
 * - The credential store is user meta key
 *   `traydigita_2fa_passkeys` (META_PASSKEYS, byte-identical
 *   with the key the Two-Factor module used before this module
 *   was split out, so already-enrolled passkeys keep working
 *   with zero data migration). The relying-party id and the
 *   expected origin are derived from the site host, not from
 *   settings - the only setting is enable/disable.
 *
 * Relationship with TwoFactor (MUST-7 split):
 * - Passkeys is a login method in its own right, not just a
 *   second factor - that is why it is a module of its own.
 *   TwoFactor keeps offering passkeys on its challenge screen,
 *   but only through this module's public API
 *   (isPasskeysEnabled / getPasskeys / renderPasskeyBlock).
 * - The passkey-as-second-factor ceremony needs TwoFactor's
 *   pending-login plumbing (PasskeysAjax challenge mode), and
 *   passkey attempts are recorded in TwoFactor's failed-attempt
 *   log. Usernameless login works with TwoFactor disabled.
 * - The two modules share one profile-page JSON config block
 *   (printed by printProfileConfig(), id
 *   `traydigita-two-factor-profile-config`) and one login-page
 *   config block (printLoginConfig(), id
 *   `traydigita-two-factor-config`), because the theme's
 *   `two-factor` script entry reads those blocks by id and
 *   drives both modules' UI from them. The profile block is
 *   printed at most once per request, by whichever module
 *   renders first.
 * - The profile section has a single container (the theme
 *   script selects the first `[data-traydigita-two-factor-profile]`
 *   element and queries the passkey buttons inside it), so the
 *   passkey block lives inside TwoFactor's profile shell while
 *   TwoFactor is enabled (TwoFactor calls renderPasskeyBlock()
 *   in place) and this module renders its own shell only when
 *   TwoFactor is disabled (see boot()).
 * - Nonces are shared with TwoFactor on purpose: the user nonce
 *   `traydigita_2fa_user` and the login nonce
 *   `traydigita_2fa_login` live on TwoFactorModule and must not
 *   be renamed - the theme script passes the nonce value
 *   straight from the printed config blocks.
 *
 * Settings: the single option `traydigita_passkeys`
 * (autoload `no`), saved through the phase-2 admin AJAX
 * dispatcher like every other module. Installs that set the
 * passkey toggle in the Two-Factor settings before this split
 * keep their value: getSettings() falls back to the legacy
 * `traydigita_two_factor` option value until this module's
 * settings page is saved once (read-through, no migration
 * write). TwoFactorModule::saveSettings() preserves the legacy
 * value untouched, so the fallback stays intact.
 *
 * Default-enabled: YES (listed in ModuleRegistry::
 * DEFAULT_ENABLED). A passkey is purely additive - password
 * login always works, enrolment is opt-in per user, and the
 * module enforces nothing. The four combinations behave as:
 * - Passkeys on + TwoFactor off: usernameless login works,
 *   no 2FA challenge.
 * - Passkeys off + TwoFactor on: TOTP/backup only, no passkey
 *   button anywhere (challenge, login form, profile).
 * - Both off: nothing passkey-related renders.
 * - Both on: full behaviour (login button, challenge button,
 *   profile enrolment) exactly as before the split.
 *
 * The `TRAYDIGITA_2FA_DISABLE` emergency constant does not
 * gate the login-form passkey button or the login config: a
 * passkey is a login method, not 2FA enforcement, and it is a
 * recovery path for a locked-out administrator. The challenge
 * screen itself never appears while the constant is set,
 * because TwoFactor parks no login then.
 */
final class PasskeysModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'passkeys';

    /**
     * Option name for the passkey setting (autoload `no`).
     */
    public const string OPTION = 'traydigita_passkeys';

    /**
     * User meta key holding the registered passkeys.
     *
     * Byte-identical with the key the Two-Factor module used
     * before the split - already-enrolled passkeys keep
     * working with zero data migration.
     *
     * A list of credential records as returned by
     * WebAuthn::verifyRegistration(), plus `label`, `created`, and
     * `last_used` timestamps.
     */
    public const string META_PASSKEYS = 'traydigita_2fa_passkeys';

    /**
     * Passkey challenge lifetime in minutes.
     */
    public const int PASSKEY_CHALLENGE_MINUTES = 5;

    /**
     * DOM id of the JSON config block printed on the login page.
     */
    public const string LOGIN_CONFIG_ELEMENT_ID = 'traydigita-two-factor-config';

    /**
     * DOM id of the JSON config block printed on the profile page.
     */
    public const string PROFILE_CONFIG_ELEMENT_ID = 'traydigita-two-factor-profile-config';

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Whether the profile config block was already printed.
     */
    private static bool $profileConfigPrinted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * Default category slug for the module list.
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
        return __('Passkeys', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Fingerprint or Face ID sign-in (WebAuthn passkeys): usernameless login on the login page and a second factor '
            . 'on the two-factor challenge screen. Password login always stays available. Enabled by default.', 'traydigita-core');
    }

    /**
     * Dashicon slug for the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'unlock';
    }

    /**
     * Module version.
     *
     * 1.0.0 splits passkeys out of the Two-Factor module
     * (MUST-7): same server, same credential store, own module.
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
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Usernameless sign-in with fingerprint or Face ID.', 'traydigita-core'),
            __('Passkey as a second factor on the two-factor challenge screen.', 'traydigita-core'),
            __('Passkey enrolment from the user profile page.', 'traydigita-core'),
            __('Secure-context only (HTTPS, or localhost for development).', 'traydigita-core'),
            __('Counter-based clone detection on every sign-in.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Passkeys split out of the Two-Factor module into a module of their own, with the same WebAuthn server, '
                        . 'the same credential store, and the same AJAX actions.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin page slug for this module's settings page.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-passkeys';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * Needs the hash and OpenSSL extensions (WebAuthn
     * signatures) and random_bytes(). All are present in any
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
     * No site-wide setup is needed: the relying-party id and
     * origin are derived from the site address, and enrolment
     * is per user. The card therefore never shows "needs setup"
     * for this module.
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
     * False: the module is optional and toggleable. It is
     * enabled by default because it only adds a login method
     * and enforces nothing.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Default settings.
     *
     * Passkeys are enabled by default: enrolment is opt-in per
     * user and password login always stays available.
     *
     * @return array{enabled: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'enabled' => true,
        ];
    }

    /**
     * Read the stored settings, merged over the defaults.
     *
     * Legacy fallback (read-through, no migration write):
     * installs that set the passkey toggle in the Two-Factor
     * settings before the split keep their value - when this
     * module's own option has never been saved, the legacy
     * `traydigita_two_factor` option's `passkey_enabled` value
     * is used instead. Once this module's settings page is
     * saved, the legacy value is ignored. The raw legacy
     * option is read directly (never through
     * TwoFactorModule::getSettings(), which reads back through
     * this method).
     *
     * @return array{enabled: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (is_array($stored) && array_key_exists('enabled', $stored)) {
            return ['enabled' => (bool) $stored['enabled']];
        }
        $legacy = get_option(TwoFactorModule::OPTION, []);
        if (is_array($legacy) && array_key_exists('passkey_enabled', $legacy)) {
            return ['enabled' => (bool) $legacy['passkey_enabled']];
        }
        return self::getDefaults();
    }

    /**
     * Whether passkeys are effectively offered right now.
     *
     * Both the module toggle and the enable setting must be on.
     * The secure-context check is separate
     * (isPasskeyAvailable()): a site on plain HTTP with
     * passkeys enabled reports the setting as on but offers no
     * passkey UI.
     *
     * @return bool
     */
    public static function isPasskeysEnabled(): bool
    {
        return ModuleRegistry::isEnabled(self::id()) && self::getSettings()['enabled'];
    }

    /**
     * Whether passkeys can be offered on this site right now.
     *
     * WebAuthn requires a secure context: HTTPS, or localhost
     * (WordPress-style exception for local development). On plain
     * HTTP the passkey UI is disabled with an honest notice, never
     * a cryptic error - and registration is refused server-side
     * too, so a forged request cannot enroll a passkey over HTTP.
     *
     * @return bool
     */
    public static function isPasskeyAvailable(): bool
    {
        if (is_ssl()) {
            return true;
        }
        $host = parse_url(home_url(), PHP_URL_HOST);
        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * Relying-party id for WebAuthn (the site host).
     *
     * @return string
     */
    public static function rpId(): string
    {
        $host = parse_url(home_url(), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /**
     * Expected WebAuthn origin (scheme + host + port, no path).
     *
     * Built from home_url() without its path, so a WordPress
     * install in a subdirectory still produces the bare origin
     * the browser reports.
     *
     * @return string
     */
    public static function expectedOrigin(): string
    {
        $parts = parse_url(home_url());
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return 'https://localhost';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $origin;
    }

    // Credential store

    /**
     * Read the user's registered passkeys.
     *
     * @param int $userId User id.
     * @return array<int, array<string, mixed>> Credential records.
     */
    public static function getPasskeys(int $userId): array
    {
        $stored = get_user_meta($userId, self::META_PASSKEYS, true);
        if (!is_array($stored)) {
            return [];
        }
        $keys = [];
        foreach ($stored as $record) {
            if (!is_array($record) || !isset($record['id'], $record['public_key'])) {
                continue;
            }
            $keys[] = $record;
        }
        return $keys;
    }

    /**
     * Store the user's passkeys (replaces the whole list).
     *
     * @param int $userId User id.
     * @param array<int, array<string, mixed>> $passkeys Credential records.
     * @return void
     */
    public static function setPasskeys(int $userId, array $passkeys): void
    {
        update_user_meta($userId, self::META_PASSKEYS, array_values($passkeys));
    }

    /**
     * Add one passkey to the user's list.
     *
     * A credential id that already exists replaces the old record
     * (re-registration of the same authenticator updates its key
     * and counter instead of duplicating it).
     *
     * @param int $userId User id.
     * @param array<string, mixed> $record Credential record.
     * @return void
     */
    public static function addPasskey(int $userId, array $record): void
    {
        $hadMethod = TwoFactorModule::hasAnyMethod($userId);
        $passkeys = self::getPasskeys($userId);
        $found = false;
        foreach ($passkeys as $index => $existing) {
            if (($existing['id'] ?? null) === ($record['id'] ?? null)) {
                $passkeys[$index] = array_merge($existing, $record);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $passkeys[] = $record;
        }
        self::setPasskeys($userId, $passkeys);
        if (!$hadMethod) {
            update_user_meta($userId, TwoFactorModule::META_ENROLLED_AT, time());
        }
    }

    /**
     * Remove one passkey by credential id.
     *
     * @param int $userId User id.
     * @param string $credentialId Base64url credential id.
     * @return bool True when a record was removed.
     */
    public static function removePasskey(int $userId, string $credentialId): bool
    {
        $passkeys = self::getPasskeys($userId);
        $kept = [];
        $removed = false;
        foreach ($passkeys as $record) {
            if (($record['id'] ?? null) === $credentialId) {
                $removed = true;
                continue;
            }
            $kept[] = $record;
        }
        if ($removed) {
            self::setPasskeys($userId, $kept);
            if (!TwoFactorModule::hasAnyMethod($userId)) {
                delete_user_meta($userId, TwoFactorModule::META_ENROLLED_AT);
            }
        }
        return $removed;
    }

    /**
     * Update the stored counter (and last-used time) of one passkey.
     *
     * Called after a successful assertion, so clone detection has a
     * current value to compare against.
     *
     * @param int $userId User id.
     * @param string $credentialId Base64url credential id.
     * @param int $counter New counter value.
     * @return void
     */
    public static function updatePasskeyCounter(int $userId, string $credentialId, int $counter): void
    {
        $passkeys = self::getPasskeys($userId);
        foreach ($passkeys as $index => $record) {
            if (($record['id'] ?? null) === $credentialId) {
                $passkeys[$index]['counter'] = $counter;
                $passkeys[$index]['last_used'] = time();
                break;
            }
        }
        self::setPasskeys($userId, $passkeys);
    }

    /**
     * Transient name for a passkey challenge.
     *
     * The $key is a pending-login token (second factor) or a random
     * challenge token (usernameless login).
     *
     * @param string $key Challenge key.
     * @return string
     */
    public static function passkeyChallengeTransient(string $key): string
    {
        return 'traydigita_2fa_pk_' . $key;
    }

    /**
     * Transient name for a pending passkey registration challenge.
     *
     * @param int $userId User id.
     * @return string
     */
    public static function passkeyRegChallengeTransient(int $userId): string
    {
        return 'traydigita_2fa_pk_reg_' . $userId;
    }

    // Hooks

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Login hooks are registered unconditionally here (the login
     * page must offer passkeys whenever the module is on); each
     * handler re-checks the enable setting and the secure
     * context, so a setting switched off registers nothing
     * observable.
     *
     * The profile hooks depend on TwoFactor: while TwoFactor is
     * enabled, its profile shell owns the single
     * `data-traydigita-two-factor-profile` container the theme
     * script queries, and it calls renderPasskeyBlock() inside
     * that shell - so this module hooks nothing on the profile
     * page itself. When TwoFactor is disabled, this module
     * renders its own standalone profile section instead.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        PasskeysAjax::register();
        add_action('login_enqueue_scripts', [self::class, 'enqueueLoginAssets']);
        add_action('login_form', [self::class, 'renderLoginButton']);
        add_action('login_footer', [self::class, 'printLoginConfig']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueProfileAssets']);
        if (ModuleRegistry::isEnabled(TwoFactorModule::id())) {
            return;
        }
        add_action('show_user_profile', [self::class, 'renderProfileFields']);
        add_action('edit_user_profile', [self::class, 'renderProfileFields']);
    }

    // Login page

    /**
     * Enqueue the login-page assets.
     *
     * Runs on `login_enqueue_scripts`. The script module and its
     * bundled CSS are only enqueued when the entry is registered;
     * otherwise the login page is untouched and password login
     * works exactly as before. The entry is the shared
     * `two-factor` theme entry (see TwoFactorModule), and
     * enqueueing the same handle twice is a no-op in WordPress,
     * so both modules enqueueing it is harmless.
     *
     * @return void
     */
    public static function enqueueLoginAssets(): void
    {
        if (!function_exists('wp_enqueue_script_module')) {
            return;
        }
        if (!TwoFactorModule::isLoginScriptRenderable()) {
            return;
        }
        wp_enqueue_script_module(TwoFactorModule::SCRIPT_HANDLE);
        if (wp_style_is(TwoFactorModule::STYLE_HANDLE, 'registered')) {
            wp_enqueue_style(TwoFactorModule::STYLE_HANDLE);
        }
    }

    /**
     * Enqueue the profile-page assets.
     *
     * Runs on `admin_enqueue_scripts`, only on profile.php and
     * user-edit.php. The same `two-factor` entry drives the
     * profile setup UI (TOTP wizard, passkey registration, device
     * revocation).
     *
     * @param mixed $hookSuffix Admin page hook suffix.
     * @return void
     */
    public static function enqueueProfileAssets(mixed $hookSuffix = null): void
    {
        if (!function_exists('wp_enqueue_script_module')) {
            return;
        }
        if (!in_array($hookSuffix, ['profile.php', 'user-edit.php'], true)) {
            return;
        }
        if (!TwoFactorModule::isLoginScriptRenderable()) {
            return;
        }
        wp_enqueue_script_module(TwoFactorModule::SCRIPT_HANDLE);
        if (wp_style_is(TwoFactorModule::STYLE_HANDLE, 'registered')) {
            wp_enqueue_style(TwoFactorModule::STYLE_HANDLE);
        }
    }

    /**
     * Print the passkey button on the login form.
     *
     * Runs on the `login_form` action, below the password field.
     * The button is an alternative, never a replacement: the
     * password form stays exactly as it is. The frontend script
     * hides the button when the browser has no WebAuthn support.
     * On plain HTTP the button is not rendered at all (passkeys
     * need a secure context); the profile page explains why.
     *
     * @return void
     */
    public static function renderLoginButton(): void
    {
        if (!self::isPasskeysEnabled() || !self::isPasskeyAvailable() || !TwoFactorModule::isLoginScriptRenderable()) {
            return;
        }
        ?>
        <div class="traydigita-two-factor-login" data-traydigita-passkey-login>
            <div class="traydigita-two-factor-divider" data-traydigita-passkey-divider aria-hidden="true"><span><?php esc_html_e('or', 'traydigita-core'); ?></span></div>
            <button type="button" class="button button-secondary traydigita-two-factor-passkey-button" data-traydigita-passkey-login-button
                data-msg-no-webauthn="<?php echo esc_attr__('Your browser does not support passkeys. Please log in with your password.', 'traydigita-core'); ?>"
                data-msg-cancelled="<?php echo esc_attr__('Passkey sign-in was cancelled. Please log in with your password.', 'traydigita-core'); ?>"
                data-msg-failed="<?php echo esc_attr__('Passkey sign-in failed. Please log in with your password.', 'traydigita-core'); ?>">
                <?php esc_html_e('Sign in with fingerprint or Face ID', 'traydigita-core'); ?>
            </button>
            <p class="traydigita-two-factor-passkey-note" data-traydigita-passkey-login-note aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * Print the login-page JSON config block.
     *
     * Runs on `login_footer`, so it is present on both the normal
     * login form and the challenge screen. It carries the AJAX URL,
     * the login nonce, and the pending-login token (challenge
     * screen only). No secret is ever printed here.
     *
     * @return void
     */
    public static function printLoginConfig(): void
    {
        if (!self::isPasskeysEnabled() || !self::isPasskeyAvailable()) {
            return;
        }
        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(TwoFactorModule::LOGIN_NONCE_ACTION),
            'optionsAction' => PasskeysAjax::PASSKEY_OPTIONS_ACTION,
            'verifyAction' => PasskeysAjax::PASSKEY_VERIFY_ACTION,
            'isChallenge' => TwoFactorModule::isChallengePage(),
            'token' => TwoFactorModule::postedToken(),
        ]);
        if (!is_string($config)) {
            return;
        }
        echo '<script type="application/json" id="' . esc_attr(self::LOGIN_CONFIG_ELEMENT_ID) . '">'
            . $config
            . '</script>';
    }

    // Profile UI

    /**
     * Print the profile-page JSON config block (at most once).
     *
     * The block carries the AJAX URL, the user nonce, the target
     * user id, and the feature flags - never a secret. The
     * theme's `two-factor` script reads it by element id and
     * drives the TOTP, passkey, and trusted-device UI from it,
     * so the flags cover both modules: TOTP and trusted-device
     * flags come from TwoFactor (false when that module is
     * disabled), passkey flags from this module.
     *
     * Called by TwoFactorModule::renderProfileFields() and by
     * this module's own renderProfileFields(); the second call
     * in a request is a no-op, so the block is never printed
     * twice.
     *
     * @param WP_User $user The user being edited.
     * @return void
     */
    public static function printProfileConfig(WP_User $user): void
    {
        if (self::$profileConfigPrinted) {
            return;
        }
        self::$profileConfigPrinted = true;
        $userId = (int) $user->ID;
        $twoFactorOn = ModuleRegistry::isEnabled(TwoFactorModule::id());
        $twoFactorSettings = $twoFactorOn ? TwoFactorModule::getSettings() : null;
        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(TwoFactorModule::NONCE_ACTION),
            'userId' => $userId,
            'isOwn' => get_current_user_id() === $userId,
            'totpEnabled' => $twoFactorSettings !== null && (bool) $twoFactorSettings['totp_enabled'],
            'passkeyEnabled' => self::isPasskeysEnabled(),
            'passkeyAvailable' => self::isPasskeyAvailable(),
            'trustedDeviceDays' => $twoFactorSettings !== null ? (int) $twoFactorSettings['trusted_device_days'] : 0,
            'scriptReady' => TwoFactorModule::isLoginScriptRenderable(),
        ]);
        if (!is_string($config)) {
            return;
        }
        echo '<script type="application/json" id="' . esc_attr(self::PROFILE_CONFIG_ELEMENT_ID) . '">'
            . $config . '</script>';
    }

    /**
     * Render the passkey enrolment block for the profile page.
     *
     * Called in place by TwoFactorModule::renderProfileFields()
     * (inside its profile shell, between the TOTP and trusted-
     * device blocks) while TwoFactor is enabled, and by this
     * module's own renderProfileFields() when TwoFactor is
     * disabled. Renders nothing while passkeys are effectively
     * off. All interactive elements are `type="button"` so they
     * never submit the surrounding profile form; the frontend
     * script drives them over AJAX with the user nonce. Passkey
     * management by another admin requires the `edit_users`
     * capability - the AJAX layer enforces that, not the markup.
     *
     * On plain HTTP the block shows the honest HTTPS notice
     * instead of the add-passkey button.
     *
     * @param WP_User $user The user being edited.
     * @return void
     */
    public static function renderPasskeyBlock(WP_User $user): void
    {
        if (!self::isPasskeysEnabled()) {
            return;
        }
        $userId = (int) $user->ID;
        $passkeys = self::getPasskeys($userId);
        $passkeyAvailable = self::isPasskeyAvailable();
        ?>
            <div class="traydigita-two-factor-block" data-traydigita-passkey-block>
                <h3><?php esc_html_e('Passkeys (fingerprint / Face ID)', 'traydigita-core'); ?></h3>
                <?php if (!$passkeyAvailable) : ?>
                    <p class="description">
                        <?php esc_html_e('Passkeys need a secure connection (HTTPS). This site is served over plain HTTP, so passkeys are disabled here — everything else still works. If the site is behind a proxy, check that WordPress knows it is on HTTPS.', 'traydigita-core'); ?>
                    </p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e('Sign in with your fingerprint or Face ID instead of typing a code. Your passkey never leaves this device.', 'traydigita-core'); ?></p>
                    <?php if ($passkeys === []) : ?>
                        <p class="traydigita-two-factor-status"><?php esc_html_e('No passkeys yet.', 'traydigita-core'); ?></p>
                    <?php else : ?>
                        <ul class="traydigita-two-factor-passkeys" data-traydigita-passkey-list>
                            <?php foreach ($passkeys as $passkey) : ?>
                                <li>
                                    <span class="traydigita-two-factor-passkey-label"><?php echo esc_html((string) ($passkey['label'] ?? '')); ?></span>
                                    <?php if (!empty($passkey['user_verified'])) : ?>
                                        <span class="traydigita-two-factor-badge"><?php esc_html_e('Biometric', 'traydigita-core'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($passkey['backup_eligible'])) : ?>
                                        <span class="traydigita-two-factor-badge"><?php esc_html_e('Synced', 'traydigita-core'); ?></span>
                                    <?php endif; ?>
                                    <span class="traydigita-two-factor-meta">
                                        <?php
                                        /* translators: %s is the date the passkey was created. */
                                        echo esc_html(sprintf(__('Added %s', 'traydigita-core'), date_i18n(get_option('date_format', 'F j, Y'), (int) ($passkey['created'] ?? 0))));
                                        ?>
                                    </span>
                                    <button type="button" class="button button-small" data-traydigita-passkey-remove data-credential-id="<?php echo esc_attr((string) ($passkey['id'] ?? '')); ?>"><?php esc_html_e('Remove', 'traydigita-core'); ?></button>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p>
                        <button type="button" class="button" data-traydigita-passkey-add><?php esc_html_e('Add a passkey', 'traydigita-core'); ?></button>
                    </p>
                    <p class="traydigita-two-factor-note" data-traydigita-passkey-note aria-live="polite"></p>
                <?php endif; ?>
            </div>
        <?php
    }

    /**
     * Render the standalone passkeys section on the profile page.
     *
     * Runs on `show_user_profile` (own profile) and
     * `edit_user_profile` (an admin editing another user) - but
     * only while TwoFactor is disabled (see boot()): while
     * TwoFactor is enabled its shell renders the section and
     * calls renderPasskeyBlock() inside it, because the theme
     * script only ever queries the first
     * `data-traydigita-two-factor-profile` container on the
     * page.
     *
     * The container carries the `data-msg-*` attributes the
     * theme script reads for the passkey UI, and the shared
     * profile config block (see printProfileConfig()).
     *
     * @param WP_User $user The user being edited.
     * @return void
     */
    public static function renderProfileFields(WP_User $user): void
    {
        if (!self::isPasskeysEnabled()) {
            return;
        }
        ?>
        <h2><?php esc_html_e('Passkeys', 'traydigita-core'); ?></h2>
        <div class="traydigita-two-factor-profile" data-traydigita-two-factor-profile
            data-msg-error="<?php echo esc_attr__('Request failed. Please try again.', 'traydigita-core'); ?>"
            data-msg-passkey-no-support="<?php echo esc_attr__('This browser does not support passkeys.', 'traydigita-core'); ?>"
            data-msg-passkey-cancelled="<?php echo esc_attr__('Passkey registration was cancelled.', 'traydigita-core'); ?>"
            data-msg-passkey-failed="<?php echo esc_attr__('Passkey registration failed. Please try again.', 'traydigita-core'); ?>"
            data-msg-confirm-remove-passkey="<?php echo esc_attr__('Remove this passkey? You will no longer be able to sign in with it.', 'traydigita-core'); ?>"
            data-msg-no-passkeys="<?php echo esc_attr__('No passkeys yet.', 'traydigita-core'); ?>"
            data-msg-remove="<?php echo esc_attr__('Remove', 'traydigita-core'); ?>">
            <?php self::printProfileConfig($user); ?>
            <p class="description">
                <?php esc_html_e('Passkeys let you sign in with your fingerprint or Face ID instead of typing your password. Your passkey never leaves this device.', 'traydigita-core'); ?>
            </p>
            <?php self::renderPasskeyBlock($user); ?>
        </div>
        <?php
    }

    // Settings page

    /**
     * Framework sections for the left navigation.
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'general', 'label' => __('General', 'traydigita-core'), 'icon' => 'key'],
            ['id' => 'relying-party', 'label' => __('Relying party', 'traydigita-core'), 'icon' => 'admin-site-alt'],
        ];
    }

    /**
     * Render the module settings form.
     *
     * Settings-framework layout (section cards, Bantuan pills
     * for the long descriptions, modern controls), like the
     * other modules: an intro, one section per concern, and the
     * sticky framework save bar. The form posts through the
     * module dispatcher (`data-traydigita-module-form` +
     * `data-module`, nonce field `_ajax_nonce` with action
     * `traydigita-admin`) and saveSettings() re-sanitizes the
     * field. Checkboxes use the "absent means off" convention
     * the dispatcher relies on, so saveSettings() treats a
     * missing key as false.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        $settings = self::getSettings();
        $passkeyAvailable = self::isPasskeyAvailable();
        ?>
        <div class="traydigita-module-settings passkeys-settings">
            <?php
            SettingsControls::intro(
                __('Passkey sign-in: fingerprint or Face ID instead of a password.', 'traydigita-core'),
                __('Users enroll a passkey on their profile and sign in with it — on the login page without a username, or as the second factor '
                    . 'on the two-factor challenge screen.', 'traydigita-core')
            );
            ?>
            <?php if (!$passkeyAvailable) : ?>
                <?php AdminNotice::render('warning', __('Passkeys need a secure connection (HTTPS). This site is served over plain HTTP, so passkey registration is '
                    . 'currently disabled — the toggle takes effect once the site is on HTTPS (localhost is excepted).', 'traydigita-core')); ?>
            <?php endif; ?>

            <form method="post" data-traydigita-module-form data-traydigita-module="passkeys" data-module="passkeys">
                <?php wp_nonce_field('traydigita-admin', '_ajax_nonce'); ?>
                <input type="hidden" name="module" value="passkeys">

                <?php SettingsControls::sectionOpen('general', __('General', 'traydigita-core')); ?>
                <?php
                SettingsControls::toggle([
                    'id' => 'passkeys-enabled',
                    'name' => 'enabled',
                    'label' => __('Enable passkey sign-in', 'traydigita-core'),
                    'checked' => $settings['enabled'],
                    'help' => __('Users can register a passkey on their profile and sign in with a fingerprint or Face ID.', 'traydigita-core'),
                    'help_long' => __('Passkeys are an alternative — password login always stays available, and disabling passkeys never deletes '
                        . 'enrolled passkeys: re-enabling restores them.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>

                <?php SettingsControls::sectionOpen('relying-party', __('Relying party', 'traydigita-core')); ?>
                <p>
                    <?php esc_html_e('Passkeys are bound to this site. The relying-party id and origin are derived from the site address — there is nothing to configure.', 'traydigita-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('Relying-party id:', 'traydigita-core'); ?>
                    <code><?php echo esc_html(self::rpId()); ?></code>
                </p>
                <p>
                    <?php esc_html_e('Expected origin:', 'traydigita-core'); ?>
                    <code><?php echo esc_html(self::expectedOrigin()); ?></code>
                </p>
                <p class="td-settings-help">
                    <?php
                    SettingsControls::helpText(
                        __('A passkey enrolled here only works on this origin.', 'traydigita-core'),
                        __('Moving the site to a different domain or from HTTP to HTTPS invalidates enrolled passkeys — users enroll again on the new '
                            . 'address.', 'traydigita-core')
                    );
                    ?>
                </p>
                <?php SettingsControls::sectionClose(); ?>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Save the module settings.
     *
     * Called by the module dispatcher with the submitted form
     * data. A checkbox field absent from the data is treated as
     * off (unchecked checkboxes are not submitted by browsers).
     * Unknown keys are dropped, so a crafted request cannot
     * inject settings.
     *
     * @param array<string, mixed> $data Submitted form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You do not have permission to change passkey settings.', 'traydigita-core'),
            ];
        }
        update_option(self::OPTION, [
            'enabled' => isset($data['enabled'])
                ? filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN)
                : false,
        ]);
        return [
            'success' => true,
            'message' => __('Passkey settings saved.', 'traydigita-core'),
        ];
    }
}
