<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Maintenance;

use TrayDigita\WP\Plugin\Core\Modules\Email\Newsletter;
use function __;
use function add_action;
use function add_query_arg;
use function array_slice;
use function array_intersect;
use function array_map;
use function array_values;
use function bin2hex;
use function current_user_can;
use function date;
use function date_i18n;
use function esc_html;
use function esc_html__;
use function file_exists;
use function filter_var;
use function function_exists;
use function get_bloginfo;
use function get_editable_roles;
use function get_option;
use function hash_equals;
use function hash_hmac;
use function header;
use function home_url;
use function implode;
use function in_array;
use function is_admin;
use function is_ssl;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_string;
use function is_user_logged_in;
use function mb_strlen;
use function mb_substr;
use function nocache_headers;
use function random_bytes;
use function remove_query_arg;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function setcookie;
use function status_header;
use function strtotime;
use function str_contains;
use function substr;
use function time;
use function trim;
use function update_option;
use function user_can;
use function wp_create_nonce;
use function wp_doing_ajax;
use function wp_get_current_user;
use function wp_kses_post;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_strip_all_tags;
use function wp_unslash;
use function wp_verify_nonce;
use const DAY_IN_SECONDS;
use const FILTER_VALIDATE_IP;

/**
 * Maintenance Mode module.
 *
 * Serves a standalone 503 maintenance page (templates/maintenance.php)
 * while the module is enabled. Settings live in the single option
 * `traydigita_maintenance` (autoload `no`).
 *
 * Bypass rules, checked in this order inside the `template_redirect`
 * gate: a user who can `manage_options` or holds an allowlisted role;
 * a request IP in the allowlist (compared against `REMOTE_ADDR`
 * only — `X-Forwarded-For` is never trusted, because it is trivially
 * spoofable when the site sits behind an untrusted proxy); a valid
 * bypass query token, which sets a 24 hour HttpOnly cookie and then
 * redirects to strip the token from the URL; a valid bypass cookie.
 *
 * Bypass token scheme: the stored secret is a random 32-byte hex
 * string (64 characters). The token is
 * `hash_hmac('sha256', 'maintenance-bypass', $secret)` and is only
 * ever compared with `hash_equals()`. The secret itself is never
 * printed in full, never logged, and can be rotated from the
 * settings page (rotating invalidates every outstanding token and
 * cookie). There is deliberately no plain `?maintenance=off` switch.
 *
 * The countdown is informational only: when it passes, maintenance
 * mode stays on until an admin turns it off. It never auto-disables.
 *
 * `isConfigured()` means the module is enabled and has a non-empty
 * title and message, so the public page has something to show. The
 * mere existence of the option is not enough.
 */
final class MaintenanceModule implements \TrayDigita\WP\Plugin\Core\Admin\ModuleInterface
{
    /**
     * Option name for all maintenance settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_maintenance';

    /**
     * Cookie name for a validated bypass token.
     */
    public const string COOKIE_NAME = 'traydigita_bypass';

    /**
     * Query parameter carrying the bypass token.
     */
    public const string QUERY_PARAM = 'traydigita_bypass';

    /**
     * Query parameter requesting an admin preview of the page.
     */
    public const string PREVIEW_PARAM = 'traydigita_maintenance_preview';

    /**
     * Nonce action for the admin preview URL.
     */
    public const string PREVIEW_NONCE_ACTION = 'traydigita_maintenance_preview';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_maintenance_save';

    /**
     * Default `Retry-After` value in seconds when no countdown is set.
     */
    public const int RETRY_AFTER_DEFAULT = 3600;

    /**
     * Bypass cookie lifetime in seconds (24 hours).
     */
    public const int COOKIE_TTL = DAY_IN_SECONDS;

    /**
     * Maximum title length in characters.
     */
    public const int TITLE_MAX_LENGTH = 200;

    /**
     * HMAC message used to derive the bypass token from the secret.
     */
    private const string BYPASS_HMAC_MESSAGE = 'maintenance-bypass';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'maintenance';
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
        return 'administration';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Maintenance Mode', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Take the site offline for visitors with a branded 503 page, a countdown, and role, IP, and signed-token bypasses.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'hammer';
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
            __('Branded 503 maintenance page for visitors.', 'traydigita-core'),
            __('Countdown timer.', 'traydigita-core'),
            __('Bypass by role, IP address, or signed token.', 'traydigita-core'),
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
        return 'traydigita-maintenance';
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
     * Whether the module is configured.
     *
     * Configured means enabled with a non-empty title and a message
     * that contains visible text. The mere existence of the option
     * is not enough: a disabled module, or one with no content to
     * show, reports as unconfigured.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['enabled']
            && $settings['title'] !== ''
            && wp_strip_all_tags($settings['message']) !== '';
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
            ['id' => 'general', 'label' => __('General', 'traydigita-core'), 'icon' => 'admin-settings'],
            ['id' => 'design', 'label' => __('Design', 'traydigita-core'), 'icon' => 'admin-appearance'],
            ['id' => 'access', 'label' => __('Access', 'traydigita-core'), 'icon' => 'lock'],
            ['id' => 'preview', 'label' => __('Preview', 'traydigita-core'), 'icon' => 'visibility'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Prepares the settings, the editable role list, the preview URL
     * and the masked bypass hint, then includes the settings partial.
     * The partial is a plain form; saving is handled by the shell
     * through `saveSettings()`.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $roles = self::editableRoles();
        $previewUrl = self::getPreviewUrl();
        $bypassHint = self::maskSecret($settings['bypass_secret']);
        $bypassUrl = self::getBypassUrl($settings);
        $isConfigured = self::isConfigured();
        $design = MaintenanceDesigner::getDesign();
        $designLabels = MaintenanceDesigner::blockLabels();
        $designHints = MaintenanceDesigner::blockHints();
        $designPreview = MaintenanceDesigner::renderPreview(
            $design,
            $settings['title'],
            $settings['message'],
            self::countdownParts($settings)
        );
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/maintenance.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Maintenance settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected keys in `$data`: `enabled` (`1` when checked),
     * `title`, `message`, `countdown_to` (a `datetime-local` value
     * or an empty string), `allowed_roles` (array of role slugs),
     * `allowed_ips` (array of IP strings, or a string with one IP
     * per line / comma-separated), `regenerate_bypass` (`1` to
     * rotate the bypass secret). The bypass secret is generated on
     * first save and is preserved otherwise, unless regeneration
     * was requested.
     *
     * @param array<string, mixed> $data Raw submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change maintenance settings.', 'traydigita-core'),
            ];
        }
        $existing = self::getSettings();
        // The design is validated first and saved together with the
        // settings, so a bad design never saves while the settings
        // do (and vice versa).
        $designResult = null;
        if (isset($data['design'])) {
            $designResult = MaintenanceDesigner::sanitizeDesign($data['design']);
            if ($designResult['design'] === null) {
                return ['success' => false, 'message' => $designResult['error'] ?? __('The maintenance design is invalid.', 'traydigita-core')];
            }
        }
        $sanitized = self::sanitizeSettings($data, $existing);
        if ($sanitized['error'] !== '') {
            return ['success' => false, 'message' => $sanitized['error']];
        }
        $settings = $sanitized['settings'];
        if ($settings['enabled'] && ($settings['title'] === '' || wp_strip_all_tags($settings['message']) === '')) {
            return [
                'success' => false,
                'message' => __('A title and a message are required while maintenance mode is enabled.', 'traydigita-core'),
            ];
        }
        if ($settings['bypass_secret'] === '' || !empty($data['regenerate_bypass'])) {
            try {
                $settings['bypass_secret'] = bin2hex(random_bytes(32));
            } catch (\Random\RandomException $exception) {
                return [
                    'success' => false,
                    'message' => __('Could not generate a bypass secret. Please try again.', 'traydigita-core'),
                ];
            }
        }
        update_option(self::OPTION, $settings, false);
        if ($designResult !== null && $designResult['design'] !== null) {
            // The design was already sanitized above; a failed save
            // here means the templates-table write failed.
            if (!MaintenanceDesigner::saveDesign($designResult['design'])) {
                return [
                    'success' => false,
                    'message' => __('The maintenance design is invalid.', 'traydigita-core'),
                ];
            }
        }
        return [
            'success' => true,
            'message' => __('Maintenance settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the maintenance gate. Called only when the module is enabled.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_action('template_redirect', [self::class, 'maybeServeMaintenance'], 9);
        add_action('admin_post_traydigita_maintenance_newsletter', [self::class, 'handleNewsletterSignup']);
        add_action('admin_post_nopriv_traydigita_maintenance_newsletter', [self::class, 'handleNewsletterSignup']);
    }

    /**
     * Handle a newsletter signup from the maintenance page.
     *
     * Verifies the nonce, subscribes the email through the
     * Newsletter module (double opt-in is honoured there), and
     * redirects back to the home page with a status flag the
     * maintenance template renders as a confirmation.
     *
     * @return void
     */
    public static function handleNewsletterSignup(): void
    {
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce']) ? sanitize_key(wp_unslash($_POST['_wpnonce'])) : '';
        if ($nonce === '' || wp_verify_nonce($nonce, 'traydigita_maintenance_newsletter') === false) {
            wp_safe_redirect(add_query_arg('maintenance_subscribed', 'error', home_url('/')));
            exit;
        }
        $email = isset($_POST['email']) && is_string($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if ($email === '') {
            wp_safe_redirect(add_query_arg('maintenance_subscribed', 'error', home_url('/')));
            exit;
        }
        $result = Newsletter::subscribe($email);
        $flag = !empty($result['success']) ? 'ok' : 'error';
        wp_safe_redirect(add_query_arg('maintenance_subscribed', $flag, home_url('/')));
        exit;
    }

    /**
     * Countdown parts for the preview and the page.
     *
     * @param array<string, mixed> $settings Settings array.
     * @return array{days: int, hours: int, minutes: int, seconds: int}
     */
    private static function countdownParts(array $settings): array
    {
        $parts = ['days' => 0, 'hours' => 0, 'minutes' => 0, 'seconds' => 0];
        $countdown = is_string($settings['countdown_to'] ?? null) ? (string) $settings['countdown_to'] : '';
        if ($countdown === '') {
            return $parts;
        }
        $timestamp = strtotime($countdown);
        if ($timestamp === false) {
            return $parts;
        }
        $remaining = $timestamp - time();
        if ($remaining <= 0) {
            return $parts;
        }
        return [
            'days' => (int) ($remaining / DAY_IN_SECONDS),
            'hours' => (int) (($remaining % DAY_IN_SECONDS) / 3600),
            'minutes' => (int) (($remaining % 3600) / 60),
            'seconds' => $remaining % 60,
        ];
    }

    /**
     * Serve the maintenance page when the gate applies.
     *
     * Order: disabled module and excluded requests pass through;
     * a valid admin preview renders the page with status 200; a
     * valid bypass query token sets the bypass cookie and redirects
     * to strip the token; role, IP, and cookie bypasses pass
     * through; everyone else gets the 503 page.
     *
     * @return void
     */
    public static function maybeServeMaintenance(): void
    {
        $settings = self::getSettings();
        if (!$settings['enabled']) {
            return;
        }
        if (self::isExcludedRequest()) {
            return;
        }
        if (self::isPreviewRequest() && self::isValidPreviewRequest()) {
            self::renderMaintenancePage($settings, 200, 0);
            exit;
        }
        $token = isset($_GET[self::QUERY_PARAM]) && is_string($_GET[self::QUERY_PARAM])
            ? sanitize_text_field(wp_unslash($_GET[self::QUERY_PARAM]))
            : '';
        if ($token !== '' && self::isValidBypassToken($token, $settings['bypass_secret'])) {
            self::setBypassCookie($token);
            wp_safe_redirect(remove_query_arg(self::QUERY_PARAM));
            exit;
        }
        if (self::isBypassed($settings)) {
            return;
        }
        self::renderMaintenancePage($settings, 503, self::retryAfterSeconds($settings));
        exit;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{enabled: bool, title: string, message: string, countdown_to: string, allowed_roles: array<int, string>, allowed_ips: array<int, string>, bypass_secret: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $settings['enabled'] = (bool) ($settings['enabled'] ?? false);
        $settings['title'] = is_string($settings['title'] ?? null) ? (string) $settings['title'] : '';
        $settings['message'] = is_string($settings['message'] ?? null) ? (string) $settings['message'] : '';
        $settings['countdown_to'] = is_string($settings['countdown_to'] ?? null) ? (string) $settings['countdown_to'] : '';
        $settings['allowed_roles'] = is_array($settings['allowed_roles'] ?? null)
            ? array_values(array_map('strval', $settings['allowed_roles']))
            : [];
        $settings['allowed_ips'] = is_array($settings['allowed_ips'] ?? null)
            ? array_values(array_map('strval', $settings['allowed_ips']))
            : [];
        $settings['bypass_secret'] = is_string($settings['bypass_secret'] ?? null) ? (string) $settings['bypass_secret'] : '';
        return $settings;
    }

    /**
     * Default settings.
     *
     * @return array{enabled: bool, title: string, message: string, countdown_to: string, allowed_roles: array<int, string>, allowed_ips: array<int, string>, bypass_secret: string}
     */
    public static function getDefaults(): array
    {
        return [
            'enabled' => false,
            'title' => __('We&rsquo;ll be back soon', 'traydigita-core'),
            'message' => __('We are performing scheduled maintenance. Please check back soon.', 'traydigita-core'),
            'countdown_to' => '',
            'allowed_roles' => [],
            'allowed_ips' => [],
            'bypass_secret' => '',
        ];
    }

    /**
     * Derive the bypass token from a secret.
     *
     * @param string $secret Stored bypass secret.
     * @return string Empty when there is no secret.
     */
    public static function bypassToken(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', self::BYPASS_HMAC_MESSAGE, $secret);
    }

    /**
     * Compare a provided token against the secret, timing-safely.
     *
     * @param string $provided Token from the query string or cookie.
     * @param string $secret Stored bypass secret.
     * @return bool
     */
    public static function isValidBypassToken(string $provided, string $secret): bool
    {
        if ($provided === '' || $secret === '') {
            return false;
        }
        return hash_equals(self::bypassToken($secret), $provided);
    }

    /**
     * Validate one IP address (IPv4 or IPv6).
     *
     * @param string $ip Candidate address.
     * @return bool
     */
    public static function isValidIp(string $ip): bool
    {
        return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Mask a secret for display: first 4 and last 4 characters only.
     *
     * @param string $secret Stored bypass secret.
     * @return string Masked hint, or an empty string when unset.
     */
    public static function maskSecret(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return substr($secret, 0, 4) . '…' . substr($secret, -4);
    }

    /**
     * Mask a bypass token for display: first 4 and last 4 characters only.
     *
     * @param string $token Bypass token.
     * @return string Masked hint, or an empty string when unset.
     */
    public static function maskToken(string $token): string
    {
        if ($token === '') {
            return '';
        }
        return substr($token, 0, 4) . '…' . substr($token, -4);
    }

    /**
     * Build the nonce-protected admin preview URL for the home page.
     *
     * @return string
     */
    public static function getPreviewUrl(): string
    {
        $url = add_query_arg(self::PREVIEW_PARAM, '1', home_url('/'));
        return wp_nonce_url($url, self::PREVIEW_NONCE_ACTION);
    }

    /**
     * Build the full bypass URL for the home page.
     *
     * Shown only on the `manage_options` settings page. The URL
     * carries the derived token, not the secret; rotating the
     * secret invalidates it.
     *
     * @param array<string, mixed> $settings Settings array.
     * @return string Empty when no secret exists yet.
     */
    public static function getBypassUrl(array $settings): string
    {
        $secret = is_string($settings['bypass_secret'] ?? null) ? (string) $settings['bypass_secret'] : '';
        $token = self::bypassToken($secret);
        if ($token === '') {
            return '';
        }
        return add_query_arg(self::QUERY_PARAM, $token, home_url('/'));
    }

    /**
     * Editable roles as slug => display name, for the settings form.
     *
     * @return array<string, string>
     */
    public static function editableRoles(): array
    {
        if (function_exists('get_editable_roles')) {
            $roles = get_editable_roles();
            $names = [];
            foreach ($roles as $slug => $role) {
                $names[(string) $slug] = (string) ($role['name'] ?? $slug);
            }
            return $names;
        }
        $wpRoles = $GLOBALS['wp_roles'] ?? null;
        if (is_object($wpRoles) && isset($wpRoles->roles) && is_array($wpRoles->roles)) {
            $names = [];
            foreach ($wpRoles->roles as $slug => $role) {
                $names[(string) $slug] = (string) ($role['name'] ?? $slug);
            }
            return $names;
        }
        return [];
    }

    /**
     * Sanitize submitted settings against the stored ones.
     *
     * @param array<string, mixed> $data Raw submitted data.
     * @param array<string, mixed> $existing Stored settings.
     * @return array{settings: array<string, mixed>, error: string} Error is empty on success.
     */
    private static function sanitizeSettings(array $data, array $existing): array
    {
        $settings = $existing;
        $settings['enabled'] = !empty($data['enabled']);
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field(wp_unslash($data['title'])) : '';
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $title = mb_substr($title, 0, self::TITLE_MAX_LENGTH);
        }
        $settings['title'] = $title;
        $settings['message'] = isset($data['message']) && is_string($data['message'])
            ? wp_kses_post(wp_unslash($data['message']))
            : '';
        $countdown = isset($data['countdown_to']) && is_string($data['countdown_to'])
            ? sanitize_text_field(wp_unslash($data['countdown_to']))
            : '';
        if ($countdown !== '') {
            $timestamp = strtotime($countdown);
            if ($timestamp === false) {
                return [
                    'settings' => $existing,
                    'error' => __('The countdown date is not valid.', 'traydigita-core'),
                ];
            }
            $settings['countdown_to'] = date('Y-m-d H:i:s', $timestamp);
        } else {
            $settings['countdown_to'] = '';
        }
        $allowedRoles = self::editableRoles();
        $roles = [];
        if (isset($data['allowed_roles']) && is_array($data['allowed_roles'])) {
            foreach ($data['allowed_roles'] as $role) {
                $slug = sanitize_key(is_string($role) ? $role : '');
                if ($slug !== '' && isset($allowedRoles[$slug]) && !in_array($slug, $roles, true)) {
                    $roles[] = $slug;
                }
            }
        }
        $settings['allowed_roles'] = $roles;
        $ipInput = [];
        if (isset($data['allowed_ips']) && is_array($data['allowed_ips'])) {
            $ipInput = $data['allowed_ips'];
        } elseif (isset($data['allowed_ips']) && is_string($data['allowed_ips'])) {
            // Split on any whitespace or comma, not just newlines: the
            // admin shell dispatcher runs sanitize_text_field() over
            // the submitted data first, which collapses newlines to
            // spaces, so a newline-only split would glue a whole
            // textarea into one invalid token.
            $ipInput = preg_split('/[\s,]+/', (string) wp_unslash($data['allowed_ips']), -1, PREG_SPLIT_NO_EMPTY);
            $ipInput = is_array($ipInput) ? $ipInput : [];
        }
        $ips = [];
        $invalid = [];
        foreach ($ipInput as $candidate) {
            $candidate = trim(is_string($candidate) ? wp_unslash($candidate) : '');
            if ($candidate === '') {
                continue;
            }
            $normalized = self::normalizeIp($candidate);
            if ($normalized === null) {
                $invalid[] = $candidate;
                continue;
            }
            if (!in_array($normalized, $ips, true)) {
                $ips[] = $normalized;
            }
        }
        if ($invalid !== []) {
            $shown = array_map(static fn (string $ip): string => $ip, array_slice($invalid, 0, 5));
            return [
                'settings' => $existing,
                'error' => sprintf(
                    /* translators: %s is a comma-separated list of invalid IP addresses. */
                    __('These IP addresses are not valid: %s', 'traydigita-core'),
                    implode(', ', $shown)
                ),
            ];
        }
        $settings['allowed_ips'] = $ips;
        return ['settings' => $settings, 'error' => ''];
    }

    /**
     * Normalize an IP address to its canonical form, or null when invalid.
     *
     * Canonicalization matters for IPv6: the same address can be
     * written in several forms, so both the stored allowlist and the
     * request address are normalized before comparison.
     *
     * @param string $ip Candidate address.
     * @return string|null
     */
    private static function normalizeIp(string $ip): ?string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $normalized = inet_ntop($packed);
        return $normalized === false ? null : $normalized;
    }

    /**
     * Whether the request must never be blocked.
     *
     * Covers the admin area, the login page, AJAX, REST API
     * requests, and WP-Cron. `wp-login.php` never reaches
     * `template_redirect` in practice, but it is checked anyway so
     * the gate stays safe if the hook ever runs there.
     *
     * @return bool
     */
    private static function isExcludedRequest(): bool
    {
        if (function_exists('is_admin') && is_admin()) {
            return true;
        }
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        $pagenow = isset($GLOBALS['pagenow']) && is_string($GLOBALS['pagenow']) ? $GLOBALS['pagenow'] : '';
        if (in_array($pagenow, ['wp-login.php', 'wp-cron.php'], true)) {
            return true;
        }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (str_contains($uri, '/wp-json/') || str_contains($uri, 'admin-ajax.php')) {
            return true;
        }
        return false;
    }

    /**
     * Whether the request carries the preview flag.
     *
     * @return bool
     */
    private static function isPreviewRequest(): bool
    {
        return isset($_GET[self::PREVIEW_PARAM]);
    }

    /**
     * Whether the preview request is an authorized admin preview.
     *
     * Requires the `manage_options` capability and a valid nonce
     * for the preview action. The nonce is read from the
     * `_wpnonce` query argument added by `wp_nonce_url()`.
     *
     * @return bool
     */
    private static function isValidPreviewRequest(): bool
    {
        if (!current_user_can('manage_options')) {
            return false;
        }
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce'])
            ? sanitize_key(wp_unslash($_GET['_wpnonce']))
            : '';
        if ($nonce === '') {
            return false;
        }
        return wp_verify_nonce($nonce, self::PREVIEW_NONCE_ACTION) !== false;
    }

    /**
     * Whether the current request bypasses maintenance mode.
     *
     * Checks, in order: a logged-in user who can `manage_options`
     * or holds an allowlisted role; a request IP in the allowlist
     * (`REMOTE_ADDR` only); a valid bypass cookie. The query-token
     * case is handled separately in `maybeServeMaintenance()`
     * because it redirects.
     *
     * @param array<string, mixed> $settings Settings array.
     * @return bool
     */
    private static function isBypassed(array $settings): bool
    {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            if ($user->exists() && user_can($user, 'manage_options')) {
                return true;
            }
            $allowedRoles = is_array($settings['allowed_roles'] ?? null) ? $settings['allowed_roles'] : [];
            if ($user->exists() && array_intersect($user->roles, $allowedRoles) !== []) {
                return true;
            }
        }
        $allowedIps = is_array($settings['allowed_ips'] ?? null) ? $settings['allowed_ips'] : [];
        $remoteAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if ($remoteAddress !== '' && $allowedIps !== []) {
            $normalized = self::normalizeIp($remoteAddress);
            if ($normalized !== null && in_array($normalized, $allowedIps, true)) {
                return true;
            }
        }
        $cookie = isset($_COOKIE[self::COOKIE_NAME]) && is_string($_COOKIE[self::COOKIE_NAME]) ? $_COOKIE[self::COOKIE_NAME] : '';
        $secret = is_string($settings['bypass_secret'] ?? null) ? (string) $settings['bypass_secret'] : '';
        return self::isValidBypassToken($cookie, $secret);
    }

    /**
     * Set the bypass cookie after a valid query token.
     *
     * Secure when the site runs over HTTPS, HttpOnly, `SameSite=Lax`,
     * path `/`, 24 hour lifetime.
     *
     * @param string $token Validated bypass token.
     * @return void
     */
    private static function setBypassCookie(string $token): void
    {
        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires' => time() + self::COOKIE_TTL,
                'path' => '/',
                'secure' => function_exists('is_ssl') && is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    /**
     * Seconds until the countdown, for the `Retry-After` header.
     *
     * Falls back to the default when no countdown is set or it is
     * already in the past.
     *
     * @param array<string, mixed> $settings Settings array.
     * @return int
     */
    private static function retryAfterSeconds(array $settings): int
    {
        $countdown = is_string($settings['countdown_to'] ?? null) ? (string) $settings['countdown_to'] : '';
        if ($countdown !== '') {
            $timestamp = strtotime($countdown);
            if ($timestamp !== false) {
                $remaining = $timestamp - time();
                if ($remaining > 0) {
                    return $remaining;
                }
            }
        }
        return self::RETRY_AFTER_DEFAULT;
    }

    /**
     * Render the maintenance page with the given HTTP status.
     *
     * Loads `templates/maintenance.php` with the page variables in
     * scope. If the template is missing, a minimal escaped fallback
     * page is printed instead, so the gate never fatals.
     *
     * @param array<string, mixed> $settings Settings array.
     * @param int $status HTTP status code (503, or 200 for previews).
     * @param int $retryAfter Retry-After value in seconds (503 only).
     * @return void
     */
    private static function renderMaintenancePage(array $settings, int $status, int $retryAfter): void
    {
        status_header($status);
        if ($status === 503) {
            header('Retry-After: ' . $retryAfter);
            nocache_headers();
        }
        $maintenance_title = is_string($settings['title'] ?? null) ? (string) $settings['title'] : '';
        $maintenance_message = is_string($settings['message'] ?? null) ? (string) $settings['message'] : '';
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $maintenance_site_name = get_bloginfo('name');
        $maintenance_design = MaintenanceDesigner::getDesign();
        $maintenance_design_css = MaintenanceDesigner::buildCss($maintenance_design);
        $maintenance_newsletter_nonce = wp_create_nonce('traydigita_maintenance_newsletter');
        $maintenance_subscribed = isset($_GET['maintenance_subscribed']) && is_string($_GET['maintenance_subscribed'])
            ? sanitize_key(wp_unslash($_GET['maintenance_subscribed']))
            : '';
        $maintenance_countdown = '';
        $maintenance_countdown_parts = self::countdownParts($settings);
        $countdown = is_string($settings['countdown_to'] ?? null) ? (string) $settings['countdown_to'] : '';
        if ($countdown !== '') {
            $timestamp = strtotime($countdown);
            if ($timestamp !== false) {
                $maintenance_countdown = date_i18n('c', $timestamp);
            }
        }
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $template = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/maintenance.php';
        if (file_exists($template)) {
            require $template;
            return;
        }
        echo self::fallbackHtml($maintenance_title, $maintenance_message);
    }

    /**
     * Minimal fallback page used only when the template file is missing.
     *
     * @param string $title Maintenance title.
     * @param string $message Maintenance message (already sanitized on save).
     * @return string
     */
    private static function fallbackHtml(string $title, string $message): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . esc_html($title) . '</title></head><body>'
            . '<h1>' . esc_html($title) . '</h1>'
            . '<div>' . wp_kses_post($message) . '</div>'
            . '</body></html>';
    }
}
