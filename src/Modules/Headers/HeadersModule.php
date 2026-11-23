<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Headers;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\SecurityHeaders\SecurityHeaderSet;
use TrayDigita\WP\Plugin\Core\Modules\SecurityHeaders\SecurityHeadersModule;
use function __;
use function absint;
use function add_action;
use function array_diff_key;
use function array_intersect;
use function array_intersect_key;
use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function count;
use function current_user_can;
use function esc_html__;
use function file_exists;
use function get_editable_roles;
use function get_option;
use function home_url;
use function in_array;
use function is_admin;
use function is_array;
use function is_ssl;
use function is_string;
use function is_user_logged_in;
use function mb_strlen;
use function mb_substr;
use function parse_url;
use function sanitize_key;
use function sanitize_text_field;
use function str_starts_with;
use function trim;
use function update_option;
use function wp_get_current_user;
use function wp_roles;
use function wp_unslash;

/**
 * Custom Headers module.
 *
 * The audit tool for HTTP security headers: per-path exceptions,
 * logged-in / per-role bypass, a header checker for the site's
 * own URL, and copy-paste .htaccess / Nginx snippets for moving
 * the headers to the server level. It also owns the one header
 * the Security Headers module never sends,
 * Strict-Transport-Security.
 *
 * This module never sends a header itself. Sending is owned by
 * the Security Headers module: SecurityHeadersModule::sendHeaders()
 * is the only `send_headers` callback in the plugin, and it
 * emits this module's headers (from this module's settings, with
 * this module's advanced rules applied) whenever this module is
 * enabled. When the Security Headers module is enabled it sends
 * the overlapping headers itself and this module defers to it,
 * so the same header is never sent twice. The settings page
 * lists exactly which headers are deferred and why.
 *
 * HSTS is only ever sent when the site runs over HTTPS: sending
 * it over plain HTTP is meaningless and browsers ignore it. The
 * toggle is disabled in the UI and forced off on save while the
 * site is not HTTPS.
 *
 * The Content-Security-Policy is report-only only, like the
 * Security Headers module: an enforcing policy can break the site
 * silently, so this module never emits an enforcing
 * `Content-Security-Policy` header under any setting.
 *
 * Settings live in the single option `traydigita_headers`
 * (autoload `no`). Headers are skipped in wp-admin, during AJAX
 * requests, for REST requests, and on wp-login.php.
 */
final class HeadersModule implements ModuleInterface
{
    /**
     * Option name holding all custom header settings.
     */
    public const string OPTION = 'traydigita_headers';

    /**
     * Fixed Permissions-Policy value: no camera, microphone, or
     * geolocation for the page or any embedded frame.
     *
     * Canonical value lives in SecurityHeaderSet; this is a
     * compatibility alias.
     */
    public const string PERMISSIONS_POLICY_VALUE = SecurityHeaderSet::PERMISSIONS_POLICY_VALUE;

    /**
     * Strict-Transport-Security value: HTTPS only, one year,
     * including subdomains. No preload flag — preloading is a
     * one-way commitment that belongs in a deliberate, separate
     * decision, not a theme toggle.
     */
    public const string HSTS_VALUE = 'max-age=31536000; includeSubDomains';

    /**
     * Default report-only policy.
     *
     * Same conservative default as the Security Headers module:
     * everything from the site itself, except Google Fonts and the
     * Open-Meteo / CoinGecko hosts the theme widgets talk to.
     *
     * Canonical value lives in SecurityHeaderSet; this is a
     * compatibility alias.
     */
    public const string DEFAULT_CSP_POLICY = SecurityHeaderSet::DEFAULT_CSP_POLICY;

    /**
     * Maximum Content-Security-Policy length in characters.
     *
     * Canonical value lives in SecurityHeaderSet; this is a
     * compatibility alias.
     */
    public const int MAX_POLICY_LENGTH = SecurityHeaderSet::MAX_POLICY_LENGTH;

    /**
     * Header-check timeout bounds in seconds.
     */
    public const int CHECK_TIMEOUT_MIN = 3;

    public const int CHECK_TIMEOUT_MAX = 30;

    /**
     * Maximum number of per-path exceptions.
     */
    public const int MAX_PATH_EXCEPTIONS = 20;

    /**
     * Maximum length of one path exception in characters.
     */
    public const int MAX_PATH_LENGTH = 200;

    /**
     * Allowlisted Referrer-Policy values. An empty string means
     * the header is disabled.
     *
     * Canonical value lives in SecurityHeaderSet; this is a
     * compatibility alias.
     *
     * @var array<int, string>
     */
    public const array REFERRER_POLICIES = SecurityHeaderSet::REFERRER_POLICIES;

    /**
     * Setting keys that are simple on/off toggles.
     *
     * @var array<int, string>
     */
    public const array BOOLEAN_KEYS = [
        'x_content_type_options',
        'x_frame_options',
        'permissions_policy',
        'hsts',
        'csp_report_only',
        'bypass_logged_in',
    ];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'headers';
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
        return 'performance';
    }

    /**
     * Module name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Custom Headers', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Enable the recommended security headers, check them on your own site, and get copy-paste server snippets. Defers to the Security Headers module for any header it already sends.', 'traydigita-core');
    }

    /**
     * Dashicons slug for the module card.
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
            __('Enable the recommended security headers.', 'traydigita-core'),
            __('Check the headers on your own site.', 'traydigita-core'),
            __('Copy-paste server snippets.', 'traydigita-core'),
            __('Defers to the Security Headers module for headers it already sends.', 'traydigita-core'),
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
     * Admin menu slug of the settings page.
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
        return 'traydigita-headers';
    }

    /**
     * The module has no external dependency, so it is always available.
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * True once at least one header is switched on.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::getHeaders() !== [];
    }

    /**
     * Whether this is a core module.
     *
     * Always false: the module is optional and can be disabled
     * from the grid.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Settings-page tabs shown by the admin shell.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => 'headers', 'label' => __('Headers', 'traydigita-core')],
            ['id' => 'rules', 'label' => __('Advanced rules', 'traydigita-core')],
            ['id' => 'checker', 'label' => __('Header checker', 'traydigita-core')],
            ['id' => 'snippets', 'label' => __('Server snippets', 'traydigita-core')],
        ];
    }

    /**
     * Echo the settings UI inside the admin shell content area.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $settings = self::getSettings();
        $effectiveHeaders = self::getEffectiveHeaders();
        $deferredHeaders = self::getDeferredHeaders();
        $securityHeadersActive = self::isSecurityHeadersActive();
        $isHttps = is_ssl();
        $roles = get_editable_roles();
        $checkUrl = home_url('/');
        $snippetsHtaccess = SnippetGenerator::htaccess($effectiveHeaders);
        $snippetsNginx = SnippetGenerator::nginx($effectiveHeaders);
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/headers.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Custom Headers settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: the six boolean toggles,
     * `referrer_policy`, `csp_policy`, `check_timeout`,
     * `bypass_roles` (array of role slugs), `path_exceptions`
     * (array of path prefixes). Unknown keys are dropped. Every
     * value is validated against its allowlist or range; the
     * report-only CSP is rejected when it is empty while
     * report-only mode is on, longer than 2000 characters, or
     * contains line breaks or control characters. HSTS is forced
     * off while the site is not HTTPS. A rejected save stores
     * nothing.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to do that.', 'traydigita-core'),
            ];
        }
        $incoming = self::unslashArray($data);

        $cspError = SecurityHeaderSet::validateCspForSave($incoming);
        if ($cspError !== null) {
            return [
                'success' => false,
                'message' => $cspError,
            ];
        }

        $stored = self::getSettings();
        $clean = self::sanitizeSettings(array_merge($stored, $incoming));
        update_option(self::OPTION, $clean, false);
        return [
            'success' => true,
            'message' => __('Custom header settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the checker AJAX actions and, when the Security
     * Headers module is disabled, the canonical header sender.
     *
     * Called only when the module is enabled. This module never
     * sends a header itself: SecurityHeadersModule::sendHeaders()
     * is the only `send_headers` callback. When the Security
     * Headers module is enabled its boot() already registers
     * that callback, so this boot registers it only as the
     * fallback that keeps this module's headers going out while
     * Security Headers is disabled. Headers are sent on frontend
     * responses only — skipped in wp-admin, during AJAX
     * requests, for REST requests, and on wp-login.php.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        HeadersAjax::register();
        if (is_admin() || self::isAjaxRequest() || self::isRestRequest() || self::isLoginPage()) {
            return;
        }
        if (!self::isSecurityHeadersActive()) {
            add_action('send_headers', [SecurityHeadersModule::class, 'sendHeaders'], 10);
        }
    }

    /**
     * Default settings (also the fallback for every missing key).
     *
     * Safe headers are on; HSTS and the report-only CSP are off
     * until an administrator opts in.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'x_content_type_options' => true,
            'x_frame_options' => true,
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => true,
            'hsts' => false,
            'csp_report_only' => false,
            'csp_policy' => self::DEFAULT_CSP_POLICY,
            'check_timeout' => 10,
            'bypass_logged_in' => false,
            'bypass_roles' => [],
            'path_exceptions' => [],
        ];
    }

    /**
     * Current settings: stored option merged over the defaults,
     * sanitized the same way a save would sanitize them.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), self::sanitizeSettings($stored));
    }

    /**
     * Headers this module wants to send, before precedence.
     *
     * The shared headers come from the canonical
     * SecurityHeaderSet builder; Strict-Transport-Security is
     * added here because only this module owns it. HSTS is
     * included only when its toggle is on AND the site runs over
     * HTTPS; the report-only CSP only when its toggle is on and
     * the policy is non-empty. An enforcing
     * `Content-Security-Policy` header is never returned.
     * Sending itself is owned by SecurityHeadersModule.
     *
     * @return array<string, string>
     */
    public static function getHeaders(): array
    {
        $settings = self::getSettings();
        $headers = SecurityHeaderSet::build($settings);
        if (!empty($settings['hsts']) && is_ssl()) {
            $headers['Strict-Transport-Security'] = self::HSTS_VALUE;
        }
        return $headers;
    }

    /**
     * Headers the canonical sender emits for this module,
     * after precedence.
     *
     * Every header the Security Headers module is currently
     * sending is removed, so the same header is never sent twice.
     * Strict-Transport-Security is never deferred: the Security
     * Headers module does not send it.
     *
     * @return array<string, string>
     */
    public static function getEffectiveHeaders(): array
    {
        return array_diff_key(self::getHeaders(), self::getDeferredHeaders());
    }

    /**
     * Headers this module defers to the Security Headers module.
     *
     * When the Security Headers module is enabled, any header it
     * is currently sending is deferred here (name => the value
     * it sends, for display). When it is disabled, nothing is
     * deferred. The Security Headers class may not exist (for
     * example on a partial install), which is treated the same
     * as disabled.
     *
     * @return array<string, string>
     */
    public static function getDeferredHeaders(): array
    {
        if (!self::isSecurityHeadersActive()) {
            return [];
        }
        return array_intersect_key(self::getHeaders(), SecurityHeadersModule::getHeaders());
    }

    /**
     * Whether the Security Headers module is enabled right now.
     *
     * @return bool
     */
    public static function isSecurityHeadersActive(): bool
    {
        if (!class_exists(SecurityHeadersModule::class)) {
            return false;
        }
        return ModuleRegistry::isEnabled(SecurityHeadersModule::id());
    }

    /**
     * Whether the advanced rules skip headers for this request.
     *
     * Headers are skipped when the request path starts with one
     * of the configured path exceptions, when the visitor is
     * logged in and the logged-in bypass is on, or when the
     * current user has one of the excepted roles.
     *
     * @return bool
     */
    public static function shouldSkipRequest(): bool
    {
        $settings = self::getSettings();
        $path = self::currentRequestPath();
        foreach ($settings['path_exceptions'] as $exception) {
            if (is_string($exception) && $exception !== '' && str_starts_with($path, $exception)) {
                return true;
            }
        }
        if (!is_user_logged_in()) {
            return false;
        }
        if (!empty($settings['bypass_logged_in'])) {
            return true;
        }
        $roles = $settings['bypass_roles'];
        if (!is_array($roles) || $roles === []) {
            return false;
        }
        $user = wp_get_current_user();
        if (!is_array($user->roles)) {
            return false;
        }
        return array_intersect($roles, $user->roles) !== [];
    }

    /**
     * Clamp a header-check timeout to the allowed range.
     *
     * @param int $timeout Requested timeout in seconds.
     * @return int
     */
    public static function clampTimeout(int $timeout): int
    {
        return max(self::CHECK_TIMEOUT_MIN, min(self::CHECK_TIMEOUT_MAX, $timeout));
    }

    /**
     * Sanitize a settings array, dropping unknown keys.
     *
     * Toggle keys are coerced to booleans. The Referrer-Policy
     * must be one of the allowlisted values or an empty string
     * (disabled); anything else disables the header. HSTS is
     * forced off while the site is not HTTPS. The CSP is cleaned
     * defensively and falls back to the default policy when
     * empty. The timeout is clamped to 3–30 seconds. Role
     * exceptions keep only roles that exist; path exceptions
     * keep only entries starting with `/`, at most 200
     * characters, at most 20 entries, deduplicated.
     *
     * @param array<string, mixed> $data Raw settings (already unslashed).
     * @return array<string, mixed>
     */
    private static function sanitizeSettings(array $data): array
    {
        $defaults = self::defaults();
        $clean = [];
        foreach (self::BOOLEAN_KEYS as $key) {
            $clean[$key] = array_key_exists($key, $data)
                ? !empty($data[$key])
                : $defaults[$key];
        }

        if (!array_key_exists('referrer_policy', $data)) {
            $clean['referrer_policy'] = $defaults['referrer_policy'];
        } else {
            $clean['referrer_policy'] = SecurityHeaderSet::sanitizeReferrerPolicy($data['referrer_policy']);
        }

        if (!empty($clean['hsts']) && !is_ssl()) {
            $clean['hsts'] = false;
        }

        $policy = isset($data['csp_policy']) && is_string($data['csp_policy'])
            ? SecurityHeaderSet::cleanCspPolicy($data['csp_policy'])
            : '';
        $clean['csp_policy'] = $policy !== '' ? $policy : self::DEFAULT_CSP_POLICY;

        $clean['check_timeout'] = isset($data['check_timeout'])
            ? self::clampTimeout(absint($data['check_timeout']))
            : $defaults['check_timeout'];

        $clean['bypass_roles'] = self::sanitizeRoles($data['bypass_roles'] ?? []);
        $clean['path_exceptions'] = self::sanitizePathExceptions($data['path_exceptions'] ?? []);

        return $clean;
    }

    /**
     * Role slugs that exist on this site.
     *
     * Uses wp_roles(), which is available on the frontend.
     * get_editable_roles() lives in wp-admin and is not loaded
     * outside wp-admin, so calling it from the send_headers
     * handler is fatal on every frontend request.
     *
     * @return array<int, string>
     */
    private static function validRoleSlugs(): array
    {
        return array_keys(wp_roles()->get_names());
    }

    /**
     * Keep only role slugs that exist on this site.
     *
     * @param mixed $value Raw role list.
     * @return array<int, string>
     */
    private static function sanitizeRoles(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $valid = self::validRoleSlugs();
        $clean = [];
        foreach ($value as $role) {
            if (!is_string($role)) {
                continue;
            }
            $slug = sanitize_key($role);
            if ($slug !== '' && in_array($slug, $valid, true)) {
                $clean[] = $slug;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * Keep only well-formed path exceptions.
     *
     * Each entry must start with `/`, be at most 200 characters,
     * and duplicates are removed. At most 20 entries are kept.
     *
     * @param mixed $value Raw path-exception list.
     * @return array<int, string>
     */
    private static function sanitizePathExceptions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        foreach ($value as $path) {
            if (!is_string($path)) {
                continue;
            }
            $path = trim(sanitize_text_field(wp_unslash($path)));
            if ($path === '' || !str_starts_with($path, '/')) {
                continue;
            }
            if (mb_strlen($path) > self::MAX_PATH_LENGTH) {
                $path = mb_substr($path, 0, self::MAX_PATH_LENGTH);
            }
            $clean[] = $path;
            if (count($clean) >= self::MAX_PATH_EXCEPTIONS) {
                break;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * Recursively unslash submitted data.
     *
     * Both string keys (field names) and integer keys (list
     * entries such as path_exceptions[] and bypass_roles[]) are
     * preserved — dropping the numeric keys would silently wipe
     * every submitted list.
     *
     * @param array<mixed> $data Raw submitted data.
     * @return array<mixed>
     */
    private static function unslashArray(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = wp_unslash($value);
            } elseif (is_array($value)) {
                $clean[$key] = self::unslashArray($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /**
     * Current request path (no query string), or '/' when unknown.
     *
     * @return string
     */
    private static function currentRequestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * Whether the current request is an AJAX request.
     *
     * @return bool
     */
    private static function isAjaxRequest(): bool
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    /**
     * Whether the current request is a REST request.
     *
     * @return bool
     */
    private static function isRestRequest(): bool
    {
        return defined('REST_REQUEST') && REST_REQUEST;
    }

    /**
     * Whether the current request is wp-login.php.
     *
     * @return bool
     */
    private static function isLoginPage(): bool
    {
        return isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php';
    }
}
