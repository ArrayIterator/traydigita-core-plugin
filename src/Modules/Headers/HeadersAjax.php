<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Headers;

use function absint;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function esc_html__;
use function esc_url_raw;
use function home_url;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function preg_match;
use function sanitize_key;
use function sanitize_text_field;
use function str_starts_with;
use function strtolower;
use function wp_parse_url;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the Custom Headers module.
 *
 * Both endpoints require the `traydigita-admin` nonce (sent as
 * `_ajax_nonce`, the field check_ajax_referer() reads) and the
 * `manage_options` capability:
 *
 * - traydigita_headers_check: server-side check. Requests the
 *   site home URL with wp_remote_get() through HeaderChecker and
 *   returns the evaluated header rows. When the request is
 *   denied or returns nothing, the response carries ok=false and
 *   the admin page offers the browser-fetch fallback instead.
 * - traydigita_headers_check_browser: browser-fetch check. The
 *   admin JavaScript fetches the home URL from the browser
 *   (same origin, so all response headers are readable) and posts
 *   the observed headers back as a JSON object; the server
 *   validates every name and value, evaluates them the same way
 *   as the server-side check, and returns the rows. The posted
 *   headers are never trusted blindly: names must match the
 *   header-name pattern, values are plain strings, the count and
 *   lengths are capped, and the checked URL must belong to this
 *   site's host.
 */
final class HeadersAjax
{
    /**
     * Nonce action shared by every admin endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Maximum number of headers accepted from a browser check.
     */
    public const int MAX_BROWSER_HEADERS = 50;

    /**
     * Maximum length of a single header value from a browser check.
     */
    public const int MAX_HEADER_VALUE_LENGTH = 2000;

    /**
     * Register both AJAX actions (authenticated admin only).
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_headers_check', [self::class, 'check']);
        add_action('wp_ajax_traydigita_headers_check_browser', [self::class, 'checkBrowser']);
    }

    /**
     * Server-side header check of the site home URL.
     *
     * POST: timeout (optional, seconds; clamped to the module's
     * allowed range). Always answers with JSON success carrying
     * an `ok` flag: ok=false means the request itself failed and
     * the UI should offer the browser-fetch fallback.
     *
     * @return void
     */
    public static function check(): void
    {
        self::authorizeAdmin();
        $settings = HeadersModule::getSettings();
        $timeout = isset($_POST['timeout']) && is_string($_POST['timeout'])
            ? HeadersModule::clampTimeout((int) $_POST['timeout'])
            : $settings['check_timeout'];
        $url = home_url('/');
        $isHttps = str_starts_with($url, 'https://');
        $result = HeaderChecker::check($url, $timeout);
        wp_send_json_success([
            'ok' => $result['ok'],
            'url' => $result['url'],
            'status' => $result['status'],
            'error' => $result['error'],
            'results' => HeaderChecker::evaluate($result['headers'], $isHttps),
        ]);
    }

    /**
     * Browser-fetch header check.
     *
     * POST: headers (JSON object of name => value, as observed by
     * fetch() in the browser), status (HTTP status code), url (the
     * URL that was fetched). The URL must belong to this site's
     * host; anything else is rejected so the endpoint cannot be
     * used to probe other sites.
     *
     * @return void
     */
    public static function checkBrowser(): void
    {
        self::authorizeAdmin();
        $raw = isset($_POST['headers']) && is_string($_POST['headers'])
            ? wp_unslash($_POST['headers'])
            : '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            wp_send_json_error(
                ['message' => esc_html__('The browser check did not send readable headers.', 'traydigita-core')],
                400
            );
        }
        $headers = self::sanitizeBrowserHeaders($decoded);
        if ($headers === null) {
            wp_send_json_error(
                ['message' => esc_html__('The browser check sent invalid header data.', 'traydigita-core')],
                400
            );
        }
        $url = isset($_POST['url']) && is_string($_POST['url'])
            ? esc_url_raw(wp_unslash($_POST['url']))
            : '';
        if (!self::isSameHostUrl($url)) {
            wp_send_json_error(
                ['message' => esc_html__('The browser check URL must belong to this site.', 'traydigita-core')],
                400
            );
        }
        $status = isset($_POST['status']) && is_string($_POST['status'])
            ? absint($_POST['status'])
            : 0;
        $isHttps = str_starts_with($url, 'https://');
        wp_send_json_success([
            'ok' => true,
            'url' => $url,
            'status' => $status,
            'error' => '',
            'results' => HeaderChecker::evaluate($headers, $isHttps),
        ]);
    }

    /**
     * Verify the admin nonce and the manage_options capability.
     *
     * check_ajax_referer() dies with -1 on a bad nonce; a missing
     * capability is answered with a JSON 403 instead.
     *
     * @return void
     */
    private static function authorizeAdmin(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')], 403);
        }
    }

    /**
     * Validate headers posted back from a browser fetch.
     *
     * Names must be valid header names, values must be plain
     * strings, the count is capped, and over-long values are
     * rejected. Returns null when anything is invalid.
     *
     * @param array<mixed> $decoded Decoded JSON payload.
     * @return array<string, string>|null
     */
    private static function sanitizeBrowserHeaders(array $decoded): ?array
    {
        if (count($decoded) === 0 || count($decoded) > self::MAX_BROWSER_HEADERS) {
            return null;
        }
        $clean = [];
        foreach ($decoded as $name => $value) {
            if (!is_string($name)
                || preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $name) !== 1
                || !is_string($value)
                || mb_strlen($value) > self::MAX_HEADER_VALUE_LENGTH
            ) {
                return null;
            }
            $clean[sanitize_key($name)] = sanitize_text_field($value);
        }
        return $clean;
    }

    /**
     * Whether a URL belongs to this site's host.
     *
     * Compares hosts case-insensitively; a missing or different
     * host is rejected. Relative URLs are rejected too (the
     * browser always posts the absolute URL it fetched).
     *
     * @param string $url URL to check.
     * @return bool
     */
    private static function isSameHostUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $homeHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $urlHost = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($homeHost) || !is_string($urlHost)) {
            return false;
        }
        return strtolower($homeHost) === strtolower($urlHost);
    }
}
