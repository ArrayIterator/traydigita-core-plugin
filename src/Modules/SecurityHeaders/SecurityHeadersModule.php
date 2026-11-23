<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\SecurityHeaders;

use function __;
use function add_action;
use function array_key_exists;
use function array_merge;
use function current_user_can;
use function esc_html__;
use function file_exists;
use function get_option;
use function header;
use function headers_sent;
use function in_array;
use function is_admin;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function strip_tags;
use function trim;
use function update_option;
use function wp_doing_ajax;
use function wp_unslash;

/**
 * Security Headers module.
 *
 * Emits a small set of defensive HTTP response headers on frontend
 * responses, from one option, `traydigita_security_headers`
 * (autoload `no`):
 *
 * - X-Content-Type-Options: nosniff
 * - X-Frame-Options: SAMEORIGIN
 * - Referrer-Policy: one of the allowlisted values (default
 *   strict-origin-when-cross-origin)
 * - Permissions-Policy: camera=(), microphone=(), geolocation=()
 * - Content-Security-Policy-Report-Only: a site-owner policy
 *   (report-only mode ONLY — never an enforcing CSP)
 *
 * Why report-only only: an enforcing Content-Security-Policy can
 * break the site (blocked scripts, styles, fonts, embeds) the
 * moment a directive is too strict, and the breakage is silent for
 * visitors. Report-only mode lets the site owner watch the
 * violation reports first and tighten the policy with confidence,
 * without ever breaking a page. This module therefore never emits
 * an enforcing `Content-Security-Policy` header, under any setting.
 *
 * Header duplication: PHP's header() with replace (the default)
 * replaces any same-name header already set by PHP, so this module
 * never duplicates its own headers. Headers set at the server level
 * (Apache / nginx / OpenLiteSpeed / Cloudflare) are applied after
 * PHP runs and cannot be removed from here — if the server already
 * sends one of these headers, both values may reach the browser.
 * Configure each header in exactly one place: either here or at
 * the server level, not both.
 *
 * Headers are emitted on the `send_headers` action, frontend
 * responses only. They are skipped in wp-admin, during AJAX
 * requests, for REST requests, and on wp-login.php.
 *
 * isConfigured() returns true once the settings option exists and
 * at least one header is enabled.
 */
final class SecurityHeadersModule implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface
{
    /**
     * Option name holding all security header settings.
     */
    public const string OPTION = 'traydigita_security_headers';

    /**
     * Maximum Content-Security-Policy length in characters.
     */
    public const int MAX_POLICY_LENGTH = 2000;

    /**
     * Fixed Permissions-Policy value: no camera, microphone, or
     * geolocation for the page or any embedded frame.
     */
    public const string PERMISSIONS_POLICY_VALUE = 'camera=(), microphone=(), geolocation=()';

    /**
     * Default report-only policy.
     *
     * Conservative: everything must come from the site itself,
     * except Google Fonts (stylesheets + font files) and the
     * Open-Meteo / CoinGecko hosts the theme's weather and market
     * widgets talk to. Frames may only embed the site itself.
     */
    public const string DEFAULT_CSP_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://api.open-meteo.com https://geocoding-api.open-meteo.com; connect-src 'self' https://api.open-meteo.com https://geocoding-api.open-meteo.com https://api.coingecko.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

    /**
     * Allowlisted Referrer-Policy values. An empty string means
     * the header is disabled.
     *
     * @var array<int, string>
     */
    public const array REFERRER_POLICIES = [
        'no-referrer',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
    ];

    /**
     * Setting keys that are simple on/off toggles.
     *
     * @var array<int, string>
     */
    public const array BOOLEAN_KEYS = [
        'x_content_type_options',
        'x_frame_options',
        'permissions_policy',
        'csp_report_only',
    ];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'security-headers';
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
     * Module name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Security Headers', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Sends defensive HTTP security headers, including a report-only Content-Security-Policy.', 'traydigita-core');
    }

    /**
     * Dashicons slug for the module card.
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
        return 'traydigita-security-headers';
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
     * True once the settings option exists and at least one header
     * is enabled.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $stored = get_option(self::OPTION, false);
        if (!is_array($stored)) {
            return false;
        }
        return self::getHeaders() !== [];
    }

    /**
     * Whether this is a core module.
     *
     * Always false for the modules shipped today: every module is
     * optional and can be disabled from the grid. Phase 3 marks
     * modules as core by flipping this to true; the registry, the
     * grid toggle and the toggle endpoint all read this flag.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'headers', 'label' => __('Headers', 'traydigita-core'), 'icon' => 'shield'],
            ['id' => 'sent', 'label' => __('Headers that will be sent', 'traydigita-core'), 'icon' => 'list-view'],
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
        $headers = self::getHeaders();
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/security-headers.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Security Headers settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `x_content_type_options`,
     * `x_frame_options`, `referrer_policy`, `permissions_policy`,
     * `csp_report_only`, `csp_policy`. Unknown keys are dropped.
     * Every value is validated against its allowlist; the
     * Content-Security-Policy is rejected when it is empty while
     * report-only mode is on, longer than 2000 characters, or
     * contains line breaks or control characters. A rejected save
     * stores nothing. Missing keys keep the stored value, so a
     * partial submission never wipes a setting.
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

        if (!empty($incoming['csp_report_only'])) {
            $rawPolicy = isset($incoming['csp_policy']) && is_string($incoming['csp_policy'])
                ? trim($incoming['csp_policy'])
                : '';
            if ($rawPolicy === '') {
                return [
                    'success' => false,
                    'message' => __('Enter a Content-Security-Policy before enabling report-only mode.', 'traydigita-core'),
                ];
            }
            if (mb_strlen($rawPolicy) > self::MAX_POLICY_LENGTH) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        /* translators: %d is the maximum policy length in characters. */
                        __('The policy must be at most %d characters.', 'traydigita-core'),
                        self::MAX_POLICY_LENGTH
                    ),
                ];
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $rawPolicy) === 1) {
                return [
                    'success' => false,
                    'message' => __('The policy must not contain line breaks or control characters.', 'traydigita-core'),
                ];
            }
        }

        $stored = self::getSettings();
        $clean = self::sanitizeSettings(array_merge($stored, $incoming));
        update_option(self::OPTION, $clean, false);
        return [
            'success' => true,
            'message' => __('Security header settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the header hook. Called only when enabled.
     *
     * Headers are skipped in wp-admin, during AJAX requests, for
     * REST requests, and on wp-login.php.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (is_admin() || wp_doing_ajax() || self::isRestRequest() || self::isLoginPage()) {
            return;
        }
        add_action('send_headers', [self::class, 'sendHeaders'], 10);
    }

    /**
     * Emit the enabled headers on `send_headers`.
     *
     * header() replaces any same-name header already set by PHP,
     * so the module never duplicates its own headers. Server-level
     * headers are applied after PHP and may still duplicate or
     * override these — see the class docblock.
     *
     * @return void
     */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        foreach (self::getHeaders() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * Default settings (also the fallback for every missing key).
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
            'csp_report_only' => false,
            'csp_policy' => self::DEFAULT_CSP_POLICY,
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
     * Headers to emit, as name => value pairs.
     *
     * Only enabled headers are included, and the report-only CSP
     * is included only when its toggle is on and the policy is
     * non-empty. An enforcing `Content-Security-Policy` header is
     * never returned.
     *
     * @return array<string, string>
     */
    public static function getHeaders(): array
    {
        $settings = self::getSettings();
        $headers = [];
        if (!empty($settings['x_content_type_options'])) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }
        if (!empty($settings['x_frame_options'])) {
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }
        if (isset($settings['referrer_policy']) && is_string($settings['referrer_policy'])
            && $settings['referrer_policy'] !== ''
        ) {
            $headers['Referrer-Policy'] = $settings['referrer_policy'];
        }
        if (!empty($settings['permissions_policy'])) {
            $headers['Permissions-Policy'] = self::PERMISSIONS_POLICY_VALUE;
        }
        if (!empty($settings['csp_report_only'])
            && isset($settings['csp_policy']) && is_string($settings['csp_policy'])
            && $settings['csp_policy'] !== ''
        ) {
            $headers['Content-Security-Policy-Report-Only'] = $settings['csp_policy'];
        }
        return $headers;
    }

    /**
     * Sanitize a settings array, dropping unknown keys.
     *
     * Toggle keys are coerced to booleans. The Referrer-Policy
     * must be one of the allowlisted values or an empty string
     * (disabled); anything else disables the header. The CSP is
     * cleaned defensively (tags stripped, control characters
     * replaced, whitespace collapsed, capped at 2000 characters)
     * and falls back to the default policy when empty.
     *
     * @param array<string, mixed> $data Raw settings (already unslashed).
     * @return array<string, mixed>
     */
    private static function sanitizeSettings(array $data): array
    {
        $defaults = self::defaults();
        $clean = [];
        foreach (self::BOOLEAN_KEYS as $key) {
            // An absent toggle keeps its default; a present one is
            // coerced to boolean. (On the save path the input is
            // always complete — merged over the stored settings —
            // so absent keys only occur when reading a partial
            // stored option, where the default is the safe choice.)
            $clean[$key] = array_key_exists($key, $data)
                ? !empty($data[$key])
                : $defaults[$key];
        }

        if (!array_key_exists('referrer_policy', $data)) {
            $clean['referrer_policy'] = self::defaults()['referrer_policy'];
        } else {
            $value = is_string($data['referrer_policy'])
                ? trim($data['referrer_policy'])
                : '';
            $clean['referrer_policy'] = in_array($value, self::REFERRER_POLICIES, true) ? $value : '';
        }

        $policy = isset($data['csp_policy']) && is_string($data['csp_policy'])
            ? self::cleanCspPolicy($data['csp_policy'])
            : '';
        $clean['csp_policy'] = $policy !== '' ? $policy : self::DEFAULT_CSP_POLICY;

        return $clean;
    }

    /**
     * Clean a CSP string defensively for storage.
     *
     * Tags are stripped, control characters are replaced with a
     * space, runs of whitespace are collapsed, and the result is
     * capped at 2000 characters. This is the read-path safety net;
     * the save path rejects invalid policies outright instead.
     *
     * @param string $policy Raw policy string.
     * @return string
     */
    private static function cleanCspPolicy(string $policy): string
    {
        $policy = strip_tags($policy);
        $policy = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $policy);
        $policy = (string) preg_replace('/\s+/', ' ', $policy);
        $policy = trim($policy);
        if (mb_strlen($policy) > self::MAX_POLICY_LENGTH) {
            $policy = mb_substr($policy, 0, self::MAX_POLICY_LENGTH);
        }
        return $policy;
    }

    /**
     * Recursively unslash submitted data (string keys only).
     *
     * @param array<string, mixed> $data Raw submitted data.
     * @return array<string, mixed>
     */
    private static function unslashArray(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
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
