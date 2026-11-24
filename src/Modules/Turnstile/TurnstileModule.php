<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Turnstile;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Integrations\IntegrationsModule;
use WP_Error;
use WP_User;
use function __;
use function add_action;
use function add_filter;
use function admin_url;
use function apply_filters;
use function array_key_exists;
use function array_merge;
use function checked;
use function constant;
use function current_user_can;
use function date_i18n;
use function defined;
use function disabled;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function filter_var;
use function get_current_screen;
use function get_option;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sanitize_text_field;
use function selected;
use function sprintf;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function time;
use function trim;
use function update_option;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_script_module;
use function wp_json_encode;
use function wp_script_modules;
use function wp_send_json_error;
use function wp_unslash;

/**
 * Cloudflare Turnstile module.
 *
 * DECISION — standalone module, not part of the Login Builder:
 * the Login Builder designs the login page; Turnstile is bot
 * protection that spans the login form, the comment form, and the
 * Email module's newsletter subscribe form. Keeping it a separate,
 * independently toggleable module means a site can switch bot
 * protection on or off without touching its login design, and can
 * protect comments or the newsletter without protecting login.
 *
 * What it protects (each behind its own toggle):
 * - Login (`wp-login.php`): the challenge renders in the login form
 *   (`login_form`) and the token is verified on the `authenticate`
 *   filter, after WordPress has checked the username and password,
 *   so a failed challenge never reveals whether the credentials
 *   were right. Verification only runs for real login-form posts
 *   (a `log` field is present), so XML-RPC and REST logins are
 *   untouched.
 * - Comments: the challenge renders in the comment form
 *   (`comment_form`) and the token is verified on
 *   `preprocess_comment`. Users who can moderate comments (staff
 *   replying from the admin or the REST API) are exempt.
 * - Newsletter: the challenge is injected into the Email module's
 *   subscribe form by the frontend script, and the token is
 *   verified by a priority-1 handler on the subscribe AJAX actions
 *   that runs before `EmailAjax::subscribe()`. This module never
 *   edits the Email module's files; the hook runs only while the
 *   Email module itself is enabled.
 *
 * Keys: the site key is public and is printed (escaped) into the
 * widget markup; the secret key is a secret. It is stored in the
 * single option `traydigita_turnstile` (autoload `no`), rendered
 * as an always-empty password field, only overwritten when a
 * non-empty value is submitted, and never printed, never logged,
 * and never returned by any endpoint — the same rule the SMTP
 * module applies to its password. `saveSettings()` returns no
 * `data` at all, so the generic dispatcher has nothing secret to
 * echo back.
 *
 * Fail-open vs fail-closed: the setting only governs what happens
 * when Cloudflare cannot be reached (network error, non-200, or an
 * unparseable answer). A token Cloudflare definitively rejects
 * always blocks, whichever way the setting points.
 * - Login defaults to FAIL-CLOSED: the login form is the site's
 *   highest-value target, and failing open would let credential
 *   stuffing through exactly while verification is down. A short
 *   login outage during a Cloudflare outage is the safer failure.
 * - Comment and newsletter forms default to FAIL-OPEN: they are
 *   lower risk, and blocking every comment or subscription while
 *   Cloudflare is unreachable hurts the site more than a few spam
 *   comments do. Either default can be flipped explicitly.
 *
 * Never locked out: when the keys are missing the challenge is
 * simply not rendered and login works normally (the module card
 * shows "needs setup"). And when the login script entry has not
 * been wired into the Vite manifest yet, the widget cannot render,
 * so verification is skipped rather than blocking every login —
 * the settings page warns about the missing entry instead.
 * Emergency escape hatch: define
 * `TRAYDIGITA_TURNSTILE_FAIL_OPEN` as `true` in `wp-config.php`
 * to force fail-open everywhere (for example while recovering
 * from a Cloudflare outage); the
 * `traydigita:core:turnstile:fail-open` filter does the same for
 * code that runs after plugins load.
 *
 * Frontend script: `resources/js/modules/turnstile.ts` renders the
 * widget through Cloudflare's explicit-render API and injects it
 * into the newsletter form. It ships inside the `app` entry on the
 * frontend (the coordinator imports it there) and as its own
 * `turnstile` Vite entry on `wp-login.php` — the coordinator must
 * register that entry in `AssetLoader::ASSETS` for the login
 * widget to load; see `isLoginChallengeRenderable()`.
 *
 * Content-Security-Policy note: Turnstile loads its script and
 * renders its challenge iframe from
 * `https://challenges.cloudflare.com`, so a site that enforces a
 * Content-Security-Policy (the Security Headers module only sends
 * a report-only policy) must allow that host in `script-src` and
 * `frame-src`, or the widget will not load.
 *
 * Settings live in the single option `traydigita_turnstile`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; `saveSettings()` still checks the capability itself
 * and re-sanitizes every field for its own type.
 *
 * The settings form is rendered inline here (no template partial),
 * following the SeoModule pattern, so the module stays
 * self-contained in its own directory.
 */
final class TurnstileModule implements ModuleInterface
{
    /**
     * Option name for all Turnstile settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_turnstile';

    /**
     * Option name holding the last key-test result (autoload `no`).
     *
     * Shape: `['time' => int, 'success' => bool]`. No key material
     * and no error text are ever stored here.
     */
    public const string LAST_TEST_OPTION = 'traydigita_turnstile_last_test';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Script-module handle of the standalone `turnstile` Vite entry.
     *
     * The entry is built automatically from
     * `resources/js/modules/turnstile.ts` (Vite globs
     * `resources/*\/*.ts`); it still has to be registered in
     * `AssetLoader::ASSETS` before WordPress will enqueue it.
     */
    public const string SCRIPT_HANDLE = 'traydigita-theme-turnstile';

    /**
     * DOM id of the JSON config block printed for the frontend script.
     */
    public const string CONFIG_ELEMENT_ID = 'traydigita-turnstile-config';

    /**
     * Name of the token field Turnstile posts back with the form.
     */
    public const string TOKEN_FIELD = 'cf-turnstile-response';

    /**
     * Filter that forces fail-open everywhere when it returns true.
     */
    public const string FAIL_OPEN_FILTER = 'traydigita:core:turnstile:fail-open';

    /**
     * wp-config.php constant that forces fail-open everywhere.
     */
    public const string FAIL_OPEN_CONSTANT = 'TRAYDIGITA_TURNSTILE_FAIL_OPEN';

    /**
     * Allowed widget appearance values.
     *
     * @var array<int, string>
     */
    public const array APPEARANCES = ['auto', 'light', 'dark'];

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'turnstile';
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
        return __('Cloudflare Turnstile', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Bot protection for the login form, comments, and the newsletter subscribe form, using Cloudflare Turnstile. Disabled until the site key and secret key are saved.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'shield';
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
            __('Cloudflare Turnstile bot protection.', 'traydigita-core'),
            __('Covers the login form, comments, and the newsletter subscribe form.', 'traydigita-core'),
            __('Disabled until the site key and secret key are entered.', 'traydigita-core'),
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
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-turnstile';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Configured means both keys are saved. A configured module
     * whose login script entry is not registered yet still counts
     * as configured — the settings page warns about the entry
     * instead, and login verification is skipped until the widget
     * can actually render (see `isLoginChallengeRenderable()`).
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['site_key'] !== '' && $settings['secret_key'] !== '';
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable, and disabled
     * by default because it needs keys before it can do anything.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Hooks are registered per toggle, so a toggle switched off
     * registers nothing for that surface. The login hooks are
     * registered whenever login protection is on; the challenge
     * itself is only rendered (and only enforced) while the module
     * is configured and the login script entry is registered.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        $settings = self::getSettings();
        if ($settings['protect_login']) {
            add_action('login_enqueue_scripts', [self::class, 'enqueueLoginScript']);
            // WordPress prints script modules only on `wp_footer` and
            // `admin_print_footer_scripts`, neither of which fires on
            // `wp-login.php` — without this the enqueued module would
            // never reach the browser (see printLoginScriptModules()).
            add_action('login_footer', [self::class, 'printLoginScriptModules'], 9);
            add_action('login_form', [self::class, 'renderLoginWidget']);
            add_filter('authenticate', [self::class, 'verifyLogin'], 40, 3);
        }
        if ($settings['protect_comments']) {
            add_action('comment_form', [self::class, 'renderCommentWidget']);
            add_filter('preprocess_comment', [self::class, 'verifyComment']);
        }
        if ($settings['protect_newsletter']) {
            add_action('wp_footer', [self::class, 'printFrontendConfig']);
            add_action('wp_ajax_traydigita_email_subscribe', [self::class, 'verifyNewsletterSubscribe'], 1);
            add_action('wp_ajax_nopriv_traydigita_email_subscribe', [self::class, 'verifyNewsletterSubscribe'], 1);
        }
        if (!self::isConfigured() || ($settings['protect_login'] && !self::isLoginChallengeRenderable())) {
            add_action('admin_notices', [self::class, 'maybeShowSetupNotice']);
        }
        TurnstileAjax::register();
    }

    /**
     * Register the Turnstile entry on the Integrations hub.
     *
     * Registered centrally by IntegrationsModule::registerEntries()
     * (not from boot(), so the entry appears even while this
     * module is disabled). Status reuses isConfigured() (both keys
     * saved) and the protection toggles: 'active' when keys are
     * saved and at least one form is protected, 'configured' when
     * keys are saved but no form is protected yet, 'not-configured'
     * when the keys are missing, 'module-disabled' with a link to
     * the Modules page when this module is disabled.
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the Turnstile entry appended.
     */
    public static function registerIntegrationEntry(array $entries): array
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            $entries[] = [
                'id' => 'turnstile',
                'name' => 'Cloudflare Turnstile',
                'description' => __(
                    'The Turnstile module is disabled — enable it in Modules to use bot protection.',
                    'traydigita-core'
                ),
                'status' => 'module-disabled',
                'url' => IntegrationsModule::modulesUrl(),
                'module' => self::id(),
                'icon' => 'shield',
            ];
            return $entries;
        }
        $settings = self::getSettings();
        $protected = $settings['protect_login'] || $settings['protect_comments'] || $settings['protect_newsletter'];
        if (!self::isConfigured()) {
            $status = 'not-configured';
            $description = __(
                'Bot protection for login, comments and newsletter — save both keys to switch it on.',
                'traydigita-core'
            );
        } elseif ($protected) {
            $status = 'active';
            $description = __(
                'Bot protection is live on at least one form — login, comments or newsletter.',
                'traydigita-core'
            );
        } else {
            $status = 'configured';
            $description = __(
                'Keys are saved, but no form is protected yet — switch on login, comment or newsletter protection.',
                'traydigita-core'
            );
        }
        $entries[] = [
            'id' => 'turnstile',
            'name' => 'Cloudflare Turnstile',
            'description' => $description,
            'status' => $status,
            'url' => admin_url('admin.php?page=' . self::settingsPageSlug()),
            'module' => self::id(),
            'icon' => 'shield',
        ];
        return $entries;
    }

    /**
     * Default settings.
     *
     * Login protection is on (it is the point of the module) and
     * fails closed; comment and newsletter protection are off until
     * an administrator opts in, and fail open when switched on.
     *
     * @return array{site_key: string, secret_key: string, protect_login: bool, protect_comments: bool, protect_newsletter: bool, login_fail_closed: bool, forms_fail_open: bool, appearance: string}
     */
    public static function getDefaults(): array
    {
        return [
            'site_key' => '',
            'secret_key' => '',
            'protect_login' => true,
            'protect_comments' => false,
            'protect_newsletter' => false,
            'login_fail_closed' => true,
            'forms_fail_open' => true,
            'appearance' => 'auto',
        ];
    }

    /**
     * Read the stored settings, merged over the defaults and normalized.
     *
     * The returned array includes the secret key; callers must never
     * print, log, or return it. `renderSettingsPage()` strips it
     * before the settings reach any markup.
     *
     * @return array{site_key: string, secret_key: string, protect_login: bool, protect_comments: bool, protect_newsletter: bool, login_fail_closed: bool, forms_fail_open: bool, appearance: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $appearance = strtolower(trim(self::asString($settings['appearance'] ?? 'auto')));
        if (!in_array($appearance, self::APPEARANCES, true)) {
            $appearance = 'auto';
        }
        return [
            'site_key' => sanitize_text_field(self::asString($settings['site_key'] ?? '')),
            'secret_key' => self::asString($settings['secret_key'] ?? ''),
            'protect_login' => (bool) ($settings['protect_login'] ?? true),
            'protect_comments' => (bool) ($settings['protect_comments'] ?? false),
            'protect_newsletter' => (bool) ($settings['protect_newsletter'] ?? false),
            'login_fail_closed' => (bool) ($settings['login_fail_closed'] ?? true),
            'forms_fail_open' => (bool) ($settings['forms_fail_open'] ?? true),
            'appearance' => $appearance,
        ];
    }

    /**
     * Read the last key-test result, if one was recorded.
     *
     * @return array{time: int, success: bool}|null Null when no test has run yet.
     */
    public static function getLastTest(): ?array
    {
        $stored = get_option(self::LAST_TEST_OPTION, null);
        if (!is_array($stored) || !isset($stored['time'], $stored['success'])) {
            return null;
        }
        return [
            'time' => (int) $stored['time'],
            'success' => (bool) $stored['success'],
        ];
    }

    /**
     * Record the outcome of a key test (timestamp + success only).
     *
     * No key material and no error text are ever stored.
     *
     * @param bool $success Whether the test passed.
     * @return void
     */
    public static function recordLastTest(bool $success): void
    {
        update_option(self::LAST_TEST_OPTION, ['time' => time(), 'success' => $success], false);
    }

    /**
     * Remove the stored secret key from a string, if it appears in it.
     *
     * Used before writing an error message to a log or a JSON
     * response. Only secrets of at least four characters are
     * redacted: replacing a shorter secret would mangle every
     * message that contains those characters.
     *
     * @param string $text Text that may contain the secret key.
     * @return string
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        $secret = self::getSettings()['secret_key'];
        if (strlen($secret) < 4) {
            return $text;
        }
        return str_replace($secret, '••••••••', $text);
    }

    /**
     * Client IP address, or null when it cannot be determined.
     *
     * Only a syntactically valid IP address is returned, so a
     * forged header value can never reach Cloudflare as `remoteip`.
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

    /**
     * Whether the login challenge can actually render.
     *
     * True only when the module is configured AND the standalone
     * `turnstile` script module is registered (the coordinator
     * registers it by adding the entry to
     * `AssetLoader::ASSETS`). When the entry is not registered
     * the widget cannot load on `wp-login.php`, so login
     * verification is skipped rather than blocking every login —
     * the settings page warns about the missing entry instead.
     *
     * @return bool
     */
    public static function isLoginChallengeRenderable(): bool
    {
        if (!self::isConfigured()) {
            return false;
        }
        if (!function_exists('wp_script_modules')) {
            return true;
        }
        try {
            return wp_script_modules()->get_registered(self::SCRIPT_HANDLE) !== null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Whether verification failures caused by Cloudflare being
     * unreachable should let the request through.
     *
     * The `TRAYDIGITA_TURNSTILE_FAIL_OPEN` wp-config.php constant
     * and the `traydigita:core:turnstile:fail-open` filter force
     * fail-open for every surface (the emergency escape hatch);
     * otherwise the per-surface setting applies: login follows
     * `login_fail_closed` (inverted), comments and the newsletter
     * follow `forms_fail_open`.
     *
     * @param string $context `login`, `comment`, or `newsletter`.
     * @return bool True when an unreachable Cloudflare lets the request through.
     */
    public static function isFailOpen(string $context): bool
    {
        if (defined(self::FAIL_OPEN_CONSTANT) && constant(self::FAIL_OPEN_CONSTANT) === true) {
            return true;
        }
        $forced = apply_filters(self::FAIL_OPEN_FILTER, false);
        if ($forced === true) {
            return true;
        }
        $settings = self::getSettings();
        if ($context === 'login') {
            return !$settings['login_fail_closed'];
        }
        return $settings['forms_fail_open'];
    }

    /**
     * Enqueue the standalone Turnstile script on `wp-login.php`.
     *
     * Runs on `login_enqueue_scripts`. When the entry has not been
     * registered in `AssetLoader::ASSETS` yet this is a no-op —
     * WordPress silently skips unregistered script modules — and
     * `isLoginChallengeRenderable()` reports the gap so the
     * settings page can warn about it. The module is printed by
     * `printLoginScriptModules()` on `login_footer`.
     *
     * @return void
     */
    public static function enqueueLoginScript(): void
    {
        if (!self::isConfigured()) {
            return;
        }
        if (!function_exists('wp_enqueue_script_module')) {
            return;
        }
        wp_enqueue_script_module(self::SCRIPT_HANDLE);
    }

    /**
     * Print enqueued script modules in the login page footer.
     *
     * Runs on `login_footer`. WordPress prints script modules
     * only on `wp_footer` and `admin_print_footer_scripts`,
     * neither of which fires on `wp-login.php`, so a module
     * enqueued on `login_enqueue_scripts` would otherwise never
     * reach the browser: the widget div renders but no challenge
     * ever loads, and with `login_fail_closed` every login fails.
     * This mirrors what core's `WP_Script_Modules::add_hooks()`
     * does for the admin footer — import map, then the modules,
     * then preloads — at priority 9, before the classic footer
     * scripts print at priority 20. Printing is idempotent
     * (core marks printed modules done), so a module is never
     * printed twice even if another footer hook prints it too.
     * Any other module enqueued on the login page (e.g. the
     * Two-Factor login script) is printed by the same hook.
     *
     * @return void
     */
    public static function printLoginScriptModules(): void
    {
        if (!function_exists('wp_script_modules')) {
            return;
        }
        $modules = wp_script_modules();
        $modules->print_import_map();
        $modules->print_enqueued_script_modules();
        $modules->print_script_module_preloads();
    }

    /**
     * Print the Turnstile widget container inside the login form.
     *
     * Runs on the `login_form` action, between the password field
     * and the submit button. Prints nothing while the module is
     * unconfigured, so the login form is untouched until keys exist.
     *
     * @return void
     */
    public static function renderLoginWidget(): void
    {
        echo self::widgetHtml('login');
    }

    /**
     * Print the Turnstile widget container inside the comment form.
     *
     * Runs on the `comment_form` action. Prints nothing while the
     * module is unconfigured.
     *
     * @param int $postId Id of the post being commented on.
     * @return void
     */
    public static function renderCommentWidget(int $postId = 0): void
    {
        echo self::widgetHtml('comment');
    }

    /**
     * Widget container markup for one surface.
     *
     * The site key is public by design (it is embedded in every
     * page that shows a challenge) and is escaped as an attribute.
     * The frontend script finds containers by class, loads the
     * Cloudflare API, and renders the challenge into each one.
     * An empty string is returned while the module is unconfigured,
     * so callers can echo the result unconditionally.
     *
     * @param string $context `login`, `comment`, or `newsletter`.
     * @return string
     */
    public static function widgetHtml(string $context): string
    {
        $settings = self::getSettings();
        if ($settings['site_key'] === '') {
            return '';
        }
        return sprintf(
            '<div class="traydigita-turnstile" data-sitekey="%s" data-theme="%s" data-context="%s"></div>',
            esc_attr($settings['site_key']),
            esc_attr($settings['appearance']),
            esc_attr($context)
        );
    }

    /**
     * Print the frontend config block for the newsletter injection.
     *
     * Runs on `wp_footer` while newsletter protection is on. The
     * frontend script reads the site key and appearance from this
     * JSON block and injects a widget into each
     * `.email-subscribe-form` (the Email module renders that form
     * without a Turnstile hook, so JavaScript injection is the only
     * way in that does not edit the Email module's files). The site
     * key is public; the secret key is never printed here.
     *
     * @return void
     */
    public static function printFrontendConfig(): void
    {
        $settings = self::getSettings();
        if ($settings['site_key'] === '') {
            return;
        }
        $config = wp_json_encode([
            'sitekey' => $settings['site_key'],
            'theme' => $settings['appearance'],
        ]);
        if (!is_string($config)) {
            return;
        }
        echo '<script type="application/json" id="' . esc_attr(self::CONFIG_ELEMENT_ID) . '">'
            . $config
            . '</script>';
    }

    /**
     * Verify the Turnstile token on login.
     *
     * Runs on the `authenticate` filter at priority 40, after
     * WordPress core has checked the username and password
     * (priorities 20 and 30). A failed credential check is returned
     * untouched, and a valid user is only blocked when the challenge
     * itself fails. Requests that are not login-form posts (no `log`
     * field — XML-RPC, REST, and programmatic logins) are passed
     * through, as are logins while the module is unconfigured or
     * the login widget cannot render.
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
        if (!self::isConfigured() || !self::isLoginChallengeRenderable()) {
            return $user;
        }
        $result = Verifier::verify(self::postedToken(), self::clientIp());
        if ($result['success']) {
            return $user;
        }
        if ($result['unreachable']) {
            if (self::isFailOpen('login')) {
                return $user;
            }
            return new WP_Error(
                'turnstile_unavailable',
                __('The security check could not be reached. Please try again in a moment.', 'traydigita-core')
            );
        }
        return new WP_Error(
            'turnstile_failed',
            __('Please complete the security check and try again.', 'traydigita-core')
        );
    }

    /**
     * Verify the Turnstile token on comment submission.
     *
     * Runs on the `preprocess_comment` filter. Staff who can
     * moderate comments are exempt, so admin and REST replies keep
     * working. A failed challenge stops the comment with a 403
     * page; an unreachable Cloudflare follows the forms fail-open
     * setting.
     *
     * @param array<string, mixed> $commentdata Comment data from WordPress.
     * @return array<string, mixed> Unchanged comment data when the check passes.
     */
    public static function verifyComment(array $commentdata): array
    {
        if (!self::isConfigured()) {
            return $commentdata;
        }
        if (current_user_can('moderate_comments')) {
            return $commentdata;
        }
        $result = Verifier::verify(self::postedToken(), self::clientIp());
        if ($result['success']) {
            return $commentdata;
        }
        if ($result['unreachable'] && self::isFailOpen('comment')) {
            return $commentdata;
        }
        wp_die(
            $result['unreachable']
                ? esc_html__('The security check could not be reached. Please try again in a moment.', 'traydigita-core')
                : esc_html__('Please complete the security check and try again.', 'traydigita-core'),
            esc_html__('Security check failed', 'traydigita-core'),
            ['response' => 403, 'back_link' => true]
        );
        // Unreachable in production: wp_die() stops execution. The
        // return only satisfies the declared return type.
        return $commentdata;
    }

    /**
     * Verify the Turnstile token on newsletter subscription.
     *
     * Runs at priority 1 on both subscribe AJAX actions, before
     * `EmailAjax::subscribe()` (priority 10), so a failed challenge
     * never reaches the subscriber list. Runs only while the Email
     * module itself is enabled — when it is off there is no
     * subscribe endpoint to protect. An unreachable Cloudflare
     * follows the forms fail-open setting.
     *
     * @return void
     */
    public static function verifyNewsletterSubscribe(): void
    {
        if (!ModuleRegistry::isEnabled('email')) {
            return;
        }
        if (!self::isConfigured()) {
            return;
        }
        $result = Verifier::verify(self::postedToken(), self::clientIp());
        if ($result['success']) {
            return;
        }
        if ($result['unreachable'] && self::isFailOpen('newsletter')) {
            return;
        }
        wp_send_json_error(
            ['message' => $result['unreachable']
                ? esc_html__('The security check could not be reached. Please try again in a moment.', 'traydigita-core')
                : esc_html__('Please complete the security check and try again.', 'traydigita-core')],
            $result['unreachable'] ? 503 : 403
        );
    }

    /**
     * Show a setup notice on TrayDigita admin pages.
     *
     * Registered by `boot()` while the module is unconfigured, or
     * while login protection is on but the login script entry is
     * not registered. Only shown to administrators on screens
     * whose id contains `traydigita`.
     *
     * @return void
     */
    public static function maybeShowSetupNotice(): void
    {
        $screen = get_current_screen();
        if ($screen === null || !str_contains((string) $screen->id, 'traydigita')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $url = admin_url('admin.php?page=' . self::settingsPageSlug());
        if (!self::isConfigured()) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-warning"><p>'
                . esc_html__('Cloudflare Turnstile is enabled but not configured yet. Logins are unprotected until the site key and secret key are saved.', 'traydigita-core')
                . ' '
                . sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($url),
                    esc_html__('Open Turnstile settings', 'traydigita-core')
                )
                . '</p></div>';
            return;
        }
        if (self::getSettings()['protect_login'] && !self::isLoginChallengeRenderable()) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-warning"><p>'
                . esc_html__('Cloudflare Turnstile is configured, but its login script is not registered yet, so the login challenge cannot render and login verification is skipped. Ask the theme maintainer to register the turnstile entry in AssetLoader::ASSETS.', 'traydigita-core')
                . ' '
                . sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($url),
                    esc_html__('Open Turnstile settings', 'traydigita-core')
                )
                . '</p></div>';
        }
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Follows the SeoModule pattern: the form is rendered inline
     * here, no template partial. The secret key is stripped before
     * the settings reach the markup — the password field is always
     * rendered empty, and an empty submission keeps the stored key.
     * The test form posts to the module's own AJAX action through
     * the shell's generic `data-action` handling, which shows the
     * result as a toast.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        $settings = self::getSettings();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the inline markup below, in the same scope.
        $turnstileConfigured = self::isConfigured();
        $turnstileHasSecret = $settings['secret_key'] !== '';
        $turnstileRenderable = self::isLoginChallengeRenderable();
        $turnstileLastTest = self::getLastTest();
        $turnstileNonceAction = self::NONCE_ACTION;
        $turnstileTestAction = TurnstileAjax::AJAX_ACTION;
        $turnstileSaveAction = 'traydigita_admin_save';
        $turnstileSiteKey = $settings['site_key'];
        $turnstileProtectLogin = $settings['protect_login'];
        $turnstileProtectComments = $settings['protect_comments'];
        $turnstileProtectNewsletter = $settings['protect_newsletter'];
        $turnstileLoginFailClosed = $settings['login_fail_closed'];
        $turnstileFormsFailOpen = $settings['forms_fail_open'];
        $turnstileAppearance = $settings['appearance'];
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        ?>
        <div class="traydigita-module-settings turnstile-settings">
            <div class="turnstile-status" role="status">
                <?php if ($turnstileConfigured) : ?>
                    <p class="turnstile-status-badge turnstile-status-configured"><?php esc_html_e('Configured', 'traydigita-core'); ?></p>
                    <p class="turnstile-status-text"><?php esc_html_e('The keys are saved. Each protection below only takes effect while its toggle is on.', 'traydigita-core'); ?></p>
                <?php else : ?>
                    <p class="turnstile-status-badge turnstile-status-unconfigured"><?php esc_html_e('Needs setup', 'traydigita-core'); ?></p>
                    <p class="turnstile-status-text">
                        <?php
                        SettingsControls::helpText(
                            __('Create a Turnstile widget in the Cloudflare dashboard (Turnstile section) and '
                                . 'paste its site key and secret key below.', 'traydigita-core'),
                            __('Until both keys are saved, no challenge is shown and logins work exactly as before.', 'traydigita-core')
                        );
                        ?>
                        <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener"><?php esc_html_e('Open the Cloudflare Turnstile dashboard', 'traydigita-core'); ?></a>
                    </p>
                <?php endif; ?>
                <?php if ($turnstileConfigured && $turnstileProtectLogin && !$turnstileRenderable) : ?>
                    <p class="turnstile-status-warning">
                        <?php
                        SettingsControls::helpText(
                            __('The login challenge cannot render yet: the turnstile script entry is not '
                                . 'registered in AssetLoader::ASSETS.', 'traydigita-core'),
                            __('Login verification is skipped until it is registered, so logins are not blocked.', 'traydigita-core')
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <?php if ($turnstileLastTest !== null) : ?>
                    <p class="turnstile-last-test">
                        <?php
                        /* translators: %s is the date and time of the last key test. */
                        echo esc_html(sprintf(__('Last key test: %s', 'traydigita-core'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $turnstileLastTest['time'])));
                        ?>
                        <?php if ($turnstileLastTest['success']) : ?>
                            <span class="turnstile-test-badge turnstile-test-badge-success"><?php esc_html_e('Succeeded', 'traydigita-core'); ?></span>
                        <?php else : ?>
                            <span class="turnstile-test-badge turnstile-test-badge-failed"><?php esc_html_e('Failed', 'traydigita-core'); ?></span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <form class="turnstile-settings-form" data-traydigita-module-form data-traydigita-module="turnstile" data-module="turnstile" method="post" action="">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($turnstileNonceAction)); ?>">
                <input type="hidden" name="module" value="turnstile">

                <p class="turnstile-field">
                    <label class="turnstile-label" for="turnstile-site-key"><?php esc_html_e('Site key', 'traydigita-core'); ?></label>
                    <input class="traydigita-ui-input traydigita-ui-input-mono turnstile-input" type="text" id="turnstile-site-key" name="site_key" value="<?php echo esc_attr($turnstileSiteKey); ?>" autocomplete="off" spellcheck="false">
                    <span class="description"><?php SettingsControls::helpText(__('The public key from your Cloudflare Turnstile widget.', 'traydigita-core'), __('It is shown in the page markup by design — only the secret key must stay private.', 'traydigita-core')); ?></span>
                </p>

                <p class="turnstile-field">
                    <label class="turnstile-label" for="turnstile-secret-key"><?php esc_html_e('Secret key', 'traydigita-core'); ?></label>
                    <input
                        class="traydigita-ui-input turnstile-input"
                        type="password"
                        id="turnstile-secret-key"
                        name="secret_key"
                        value=""
                        autocomplete="new-password"
                        placeholder="<?php echo esc_attr($turnstileHasSecret ? __('•••••••• (saved)', 'traydigita-core') : __('Enter secret key', 'traydigita-core')); ?>"
                    >
                    <span class="description"><?php SettingsControls::helpText(__('Leave empty to keep the saved secret key.', 'traydigita-core'), __('The secret key is never shown, never logged, and never returned by any endpoint.', 'traydigita-core')); ?></span>
                </p>

                <p class="turnstile-field">
                    <label class="turnstile-label" for="turnstile-appearance"><?php esc_html_e('Widget appearance', 'traydigita-core'); ?></label>
                    <select class="traydigita-ui-select turnstile-input" id="turnstile-appearance" name="appearance">
                        <option value="auto" <?php selected($turnstileAppearance, 'auto'); ?>><?php esc_html_e('Auto (follow the visitor’s system)', 'traydigita-core'); ?></option>
                        <option value="light" <?php selected($turnstileAppearance, 'light'); ?>><?php esc_html_e('Light', 'traydigita-core'); ?></option>
                        <option value="dark" <?php selected($turnstileAppearance, 'dark'); ?>><?php esc_html_e('Dark', 'traydigita-core'); ?></option>
                    </select>
                </p>

                <p class="turnstile-field">
                    <input type="hidden" name="protect_login" value="0">
                    <label class="traydigita-ui-toggle" for="turnstile-protect-login"><input class="traydigita-ui-toggle-input" type="checkbox" id="turnstile-protect-login" name="protect_login" value="1" <?php checked($turnstileProtectLogin, true); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Protect the login form', 'traydigita-core'); ?></span></label>
                    <span class="description"><?php esc_html_e('Show the Turnstile challenge on wp-login.php and verify it before letting a login through.', 'traydigita-core'); ?></span>
                </p>

                <p class="turnstile-field">
                    <input type="hidden" name="protect_comments" value="0">
                    <label class="traydigita-ui-toggle" for="turnstile-protect-comments"><input class="traydigita-ui-toggle-input" type="checkbox" id="turnstile-protect-comments" name="protect_comments" value="1" <?php checked($turnstileProtectComments, true); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Protect the comment form', 'traydigita-core'); ?></span></label>
                    <span class="description"><?php esc_html_e('Show the challenge on the comment form. Staff who can moderate comments are exempt.', 'traydigita-core'); ?></span>
                </p>

                <p class="turnstile-field">
                    <input type="hidden" name="protect_newsletter" value="0">
                    <label class="traydigita-ui-toggle" for="turnstile-protect-newsletter"><input class="traydigita-ui-toggle-input" type="checkbox" id="turnstile-protect-newsletter" name="protect_newsletter" value="1" <?php checked($turnstileProtectNewsletter, true); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Protect the newsletter subscribe form', 'traydigita-core'); ?></span></label>
                    <span class="description"><?php esc_html_e('Show the challenge on the Email module’s subscribe form. Only takes effect while the Email module is enabled.', 'traydigita-core'); ?></span>
                </p>

                <p class="turnstile-field">
                    <input type="hidden" name="login_fail_closed" value="0">
                    <label class="traydigita-ui-toggle" for="turnstile-login-fail-closed"><input class="traydigita-ui-toggle-input" type="checkbox" id="turnstile-login-fail-closed" name="login_fail_closed" value="1" <?php checked($turnstileLoginFailClosed, true); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Fail closed for login when Cloudflare is unreachable', 'traydigita-core'); ?></span></label>
                    <span class="description"><?php SettingsControls::helpText(__('Recommended: when Cloudflare cannot be reached, logins are blocked instead of letting bots through exactly while verification is down.', 'traydigita-core'), __('A token Cloudflare definitively rejects always blocks, whichever way this is set.', 'traydigita-core')); ?></span>
                </p>

                <p class="turnstile-field">
                    <input type="hidden" name="forms_fail_open" value="0">
                    <label class="traydigita-ui-toggle" for="turnstile-forms-fail-open"><input class="traydigita-ui-toggle-input" type="checkbox" id="turnstile-forms-fail-open" name="forms_fail_open" value="1" <?php checked($turnstileFormsFailOpen, true); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Fail open for comment and newsletter forms when Cloudflare is unreachable', 'traydigita-core'); ?></span></label>
                    <span class="description"><?php SettingsControls::helpText(__('When Cloudflare cannot be reached, comments and subscriptions go through instead of being blocked.', 'traydigita-core'), __('Uncheck to block them too. This is an explicit choice: these forms are lower risk than login, so availability wins by default.', 'traydigita-core')); ?></span>
                </p>

                <p class="turnstile-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('Emergency escape hatch: if Cloudflare is unreachable and logins are blocked, define '
                            . 'TRAYDIGITA_TURNSTILE_FAIL_OPEN as true in wp-config.php to let logins through.', 'traydigita-core'),
                        __('Remove it again once Cloudflare recovers.', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="turnstile-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('Content-Security-Policy note: Turnstile loads its script and challenge iframe '
                            . 'from https://challenges.cloudflare.com.', 'traydigita-core'),
                        __('A site that enforces a Content-Security-Policy must allow that host in script-src and frame-src, or the widget will not load.', 'traydigita-core')
                    );
                    ?>
                </p>

                <p class="turnstile-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>

            <form class="turnstile-test-form" method="post" data-action="<?php echo esc_attr($turnstileTestAction); ?>" data-turnstile-test-form>
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($turnstileNonceAction)); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($turnstileTestAction); ?>">
                <p class="turnstile-form-actions">
                    <button class="traydigita-admin-button traydigita-admin-button-secondary" type="submit" <?php disabled(!$turnstileConfigured); ?>><?php esc_html_e('Test keys', 'traydigita-core'); ?></button>
                </p>
                <p class="turnstile-test-note">
                    <?php esc_html_e('Checks the saved keys against Cloudflare with a dummy token. Limited to one test every 30 seconds.', 'traydigita-core'); ?>
                </p>
                <div class="turnstile-test-result" data-turnstile-test-result aria-live="polite"></div>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Only keys present in `$data` are changed; missing keys keep
     * their stored values. The secret key is only overwritten when
     * a non-empty value is submitted; an empty or missing secret
     * field keeps the stored key. Values in `$data` are raw POST
     * values (possibly slashed); they are unslashed here.
     *
     * Site and secret keys must match Cloudflare's documented key
     * format (`0x…` for production keys, `1x…` for the documented
     * test keys, alphanumeric, at least 10 characters after the
     * prefix). On failure the returned message lists every invalid
     * field and nothing is saved. No secret is ever included in the
     * returned array.
     *
     * @param array<string, mixed> $data Submitted settings.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Turnstile settings.', 'traydigita-core'),
            ];
        }
        $current = self::getSettings();
        $new = $current;
        $invalid = [];

        if (array_key_exists('site_key', $data)) {
            $siteKey = trim(sanitize_text_field(wp_unslash(self::inputString($data, 'site_key'))));
            if ($siteKey === '') {
                $new['site_key'] = '';
            } elseif (!self::isValidKey($siteKey)) {
                $invalid[] = __('Site key', 'traydigita-core');
            } else {
                $new['site_key'] = $siteKey;
            }
        }

        if (array_key_exists('secret_key', $data)) {
            // Stored as-is (no sanitize_text_field: keys are
            // validated against the key format below instead).
            // Only a non-empty submission overwrites the stored value.
            $secretKey = trim(wp_unslash(self::inputString($data, 'secret_key')));
            if ($secretKey === '') {
                // Empty submission keeps the stored secret key.
            } elseif (!self::isValidKey($secretKey)) {
                $invalid[] = __('Secret key', 'traydigita-core');
            } else {
                $new['secret_key'] = $secretKey;
            }
        }

        if (array_key_exists('appearance', $data)) {
            $appearance = strtolower(trim(self::inputString($data, 'appearance')));
            if (!in_array($appearance, self::APPEARANCES, true)) {
                $invalid[] = __('Widget appearance', 'traydigita-core');
            } else {
                $new['appearance'] = $appearance;
            }
        }

        foreach (['protect_login', 'protect_comments', 'protect_newsletter', 'login_fail_closed', 'forms_fail_open'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $new[$flag] = (bool) filter_var(self::inputValue($data, $flag), FILTER_VALIDATE_BOOLEAN);
            }
        }

        if ($invalid !== []) {
            return [
                'success' => false,
                /* translators: %s is a comma-separated list of invalid field names. */
                'message' => sprintf(__('Please check the following fields: %s.', 'traydigita-core'), implode(', ', $invalid)),
            ];
        }

        update_option(self::OPTION, $new, false);
        return [
            'success' => true,
            'message' => __('Turnstile settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Check a key value against Cloudflare's documented key format.
     *
     * Production keys start with `0x`, the documented test keys
     * with `1x`, followed by at least 10 alphanumeric characters.
     *
     * @param string $key Submitted key value.
     * @return bool
     */
    private static function isValidKey(string $key): bool
    {
        return preg_match('/^[01]x[0-9A-Za-z]{10,}$/', $key) === 1;
    }

    /**
     * Read the submitted Turnstile token from the POST data.
     *
     * The token is a single opaque string; anything else is treated
     * as no token at all.
     *
     * @return string
     */
    private static function postedToken(): string
    {
        $value = $_POST[self::TOKEN_FIELD] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Read a submitted value as a string (empty string for anything else).
     *
     * @param array<string, mixed> $data Submitted data.
     * @param string $key Field name.
     * @return string
     */
    private static function inputString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Read a submitted value for boolean parsing (string, int, or bool).
     *
     * @param array<string, mixed> $data Submitted data.
     * @param string $key Field name.
     * @return string|int|bool
     */
    private static function inputValue(array $data, string $key): string|int|bool
    {
        $value = $data[$key] ?? false;
        if (is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        return false;
    }

    /**
     * Cast a stored option value to a string (empty string for
     * anything that is not a string, int, float, or bool).
     *
     * Guards against a hand-edited option holding an array where a
     * string is expected, which a plain `(string)` cast would turn
     * into a warning and the word "Array".
     *
     * @param mixed $value Stored value.
     * @return string
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }
}
