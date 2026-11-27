<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use TrayDigita\WP\Plugin\Core\Libs\GoogleServiceAccount;
use function add_action;
use function add_filter;
use function add_query_arg;
use function add_rewrite_rule;
use function bin2hex;
use function delete_option;
use function get_option;
use function get_permalink;
use function get_post;
use function get_query_var;
use function header;
use function home_url;
use function in_array;
use function is_array;
use function is_string;
use function is_wp_error;
use function json_decode;
use function nocache_headers;
use function random_bytes;
use function status_header;
use function time;
use function update_option;
use function wp_json_encode;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_post;
use function wp_remote_retrieve_response_code;

/**
 * Instant indexing: IndexNow and the Google Indexing API.
 *
 * Both are opt-in and OFF by default, and both are gated on the
 * compatibility rule: they only ping while no SEO plugin is
 * active, because Yoast and Rank Math run their own instant
 * indexing and a second ping of the same URL would be a duplicate
 * submission. The toggles are rendered disabled in the settings
 * form while an SEO plugin is active, and the stored values are
 * preserved on save in that case.
 *
 * IndexNow (Bing, Yandex, and any engine polling the shared
 * endpoint): the key is a 32-hex-character string generated on
 * first enable, served as a plain-text file at /{key}.txt from
 * the site root (the IndexNow key-location requirement), and sent
 * with every ping. Ping endpoints are allowlisted by host; the
 * key is never sent anywhere else.
 *
 * Google Indexing API: the admin pastes a Google service-account
 * JSON (service account with the Indexing API enabled). The JSON
 * is stored in its own option (`traydigita_seo_google_sa`,
 * autoload `no`), validated on save (must decode and carry
 * client_email, private_key, and token_uri), and is NEVER printed,
 * logged, or returned in any AJAX response. It is used only to
 * fetch a short-lived access token through the shared
 * GoogleServiceAccount client (RS256 JWT, pure PHP), and the
 * access token is used only for the Indexing API endpoint. If
 * no JSON is provided the feature reports as unconfigured,
 * never faked. A published post or page that is trashed or
 * permanently deleted is notified as URL_DELETED; a post
 * trashed and then permanently deleted is notified once, at
 * trash time, because its URL already stopped existing then.
 *
 * Logs (last ping / last notification only, no secrets, no
 * tokens): `traydigita_seo_indexnow_log` and
 * `traydigita_seo_google_log`, each holding time, URL, and a
 * per-endpoint or HTTP status.
 */
final class SeoIndexing
{
    /**
     * Option holding the Google service-account JSON (autoload `no`).
     */
    public const string GOOGLE_OPTION = 'traydigita_seo_google_sa';

    /**
     * Option holding the last IndexNow ping record (autoload `no`).
     */
    public const string INDEXNOW_LOG_OPTION = 'traydigita_seo_indexnow_log';

    /**
     * Option holding the last Google notification record (autoload `no`).
     */
    public const string GOOGLE_LOG_OPTION = 'traydigita_seo_google_log';

    /**
     * Query variable carrying a requested IndexNow key file name.
     */
    public const string KEY_QUERY_VAR = 'traydigita_indexnow_key';

    /**
     * IndexNow ping endpoints (GET with `url` and `key` parameters).
     *
     * @var array<int, string>
     */
    public const array INDEXNOW_ENDPOINTS = [
        'https://api.indexnow.org/indexnow',
        'https://www.bing.com/indexnow',
        'https://yandex.com/indexnow',
    ];

    /**
     * Hosts an IndexNow ping may be sent to (allowlist).
     *
     * @var array<int, string>
     */
    public const array INDEXNOW_HOSTS = [
        'api.indexnow.org',
        'www.bing.com',
        'yandex.com',
    ];

    /**
     * Google Indexing API notification endpoint (allowlisted).
     */
    public const string GOOGLE_NOTIFY_URL = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

    /**
     * OAuth scope the Google Indexing API requires.
     */
    public const string GOOGLE_SCOPE = 'https://www.googleapis.com/auth/indexing';

    /**
     * Notification type sent for a published or updated URL.
     */
    public const string GOOGLE_TYPE_UPDATED = 'URL_UPDATED';

    /**
     * Notification type sent for a URL that no longer exists.
     */
    public const string GOOGLE_TYPE_DELETED = 'URL_DELETED';

    /**
     * Minimum seconds between IndexNow key regenerations from the same user.
     */
    public const int REGENERATE_RATE_LIMIT_SECONDS = 60;

    /**
     * Register the key-file rule, the key-file handler, and the publish pings.
     *
     * Called from SeoModule::boot() (only when the module is
     * enabled). The key-file rewrite rule is registered on `init`
     * only while IndexNow is enabled; the publish hook is
     * registered whenever either indexing feature is enabled. The
     * trash and delete hooks are registered while the Google
     * Indexing API is enabled, so a published post or page that
     * is trashed or permanently deleted is notified as
     * URL_DELETED. Each handler re-checks its toggle and the SEO
     * plugin detection at run time.
     *
     * @return void
     */
    public static function register(): void
    {
        $settings = SeoModule::getSettings();
        if ($settings['indexnow_enabled']) {
            add_action('init', [self::class, 'registerKeyFileRule']);
            add_action('template_redirect', [self::class, 'maybeServeKeyFile']);
        }
        if ($settings['indexnow_enabled'] || $settings['google_indexing_enabled']) {
            add_action('transition_post_status', [self::class, 'maybePingOnPublish'], 10, 3);
        }
        if ($settings['google_indexing_enabled']) {
            add_action('wp_trash_post', [self::class, 'maybeNotifyOnTrash']);
            add_action('before_delete_post', [self::class, 'maybeNotifyOnDelete'], 10, 1);
        }
    }

    /**
     * Register the IndexNow key-file rewrite rule and query variable.
     *
     * Called on `init`. The rule matches any 32-hex file name and
     * hands it to the handler, which serves the file only when it
     * is the exact stored key.
     *
     * @return void
     */
    public static function registerKeyFileRule(): void
    {
        add_filter('query_vars', [self::class, 'addKeyQueryVar']);
        add_rewrite_rule(
            '^([0-9a-f]{32})\\.txt$',
            'index.php?' . self::KEY_QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * Add the IndexNow key query variable to the allowlist.
     *
     * @param array<int, string> $vars Registered public query variables.
     * @return array<int, string>
     */
    public static function addKeyQueryVar(array $vars): array
    {
        $vars[] = self::KEY_QUERY_VAR;
        return $vars;
    }

    /**
     * Serve the IndexNow key file, or answer 404.
     *
     * Only the exact stored key is served, as plain text, with no
     * extra output. Any other 32-hex file name answers 404, so
     * the rule can never leak the key or serve an unrelated file.
     *
     * @return void
     */
    public static function maybeServeKeyFile(): void
    {
        $requested = get_query_var(self::KEY_QUERY_VAR);
        if ($requested === '') {
            return;
        }
        $settings = SeoModule::getSettings();
        $key = $settings['indexnow_key'];
        if (!$settings['indexnow_enabled'] || $key === '' || $requested !== $key || SeoDetector::hasSeoPlugin()) {
            $GLOBALS['wp_query']->set_404();
            status_header(404);
            return;
        }
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo $key;
        exit;
    }

    /**
     * Ping the indexing services when a post or page is published or updated.
     *
     * Hooked on `transition_post_status`. Only published posts and
     * pages with a permalink are pinged, and only while no SEO
     * plugin is active. Revisions, autosaves, drafts, and other
     * post types never ping.
     *
     * @param string $newStatus New post status.
     * @param string $oldStatus Old post status.
     * @param \WP_Post $post Post object.
     * @return void
     */
    public static function maybePingOnPublish(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus !== 'publish') {
            return;
        }
        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
            return;
        }
        if (SeoDetector::hasSeoPlugin()) {
            return;
        }
        $url = get_permalink($post);
        if ($url === false || $url === '') {
            return;
        }
        $settings = SeoModule::getSettings();
        if ($settings['indexnow_enabled']) {
            self::pingIndexNow($url);
        }
        if ($settings['google_indexing_enabled']) {
            self::notifyGoogle($url, self::GOOGLE_TYPE_UPDATED);
        }
    }

    /**
     * Notify Google that a published post or page was trashed.
     *
     * Hooked on `wp_trash_post`, which fires before the status
     * changes, so the post is still published here and
     * get_permalink() returns the real URL. A trashed post
     * answers 404 on the frontend, so its URL is deleted as far
     * as Google is concerned. Only published posts and pages are
     * notified, and only while no SEO plugin is active.
     *
     * @param int $postId Trashed post ID.
     * @return void
     */
    public static function maybeNotifyOnTrash(int $postId): void
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }
        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
            return;
        }
        self::notifyDeleted($post);
    }

    /**
     * Notify Google that a published post or page was permanently deleted.
     *
     * Hooked on `before_delete_post`. Only a post that is still
     * published at delete time is notified here: a post deleted
     * out of the trash was already notified when it was trashed
     * (its URL stopped existing then), and its slug carries the
     * `__trashed` suffix by then, so get_permalink() would return
     * a URL that never existed. Force-deleting a published post
     * (bypassing the trash) is notified here instead.
     *
     * @param int $postId Deleted post ID.
     * @return void
     */
    public static function maybeNotifyOnDelete(int $postId): void
    {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return;
        }
        if ($post->post_type !== 'post' && $post->post_type !== 'page') {
            return;
        }
        self::notifyDeleted($post);
    }

    /**
     * Send a URL_DELETED notification for one post or page.
     *
     * Shared by the trash and delete hooks. Re-checks the Google
     * toggle and the SEO plugin detection at run time, so a
     * notification is never sent while an SEO plugin is in
     * charge of indexing.
     *
     * @param \WP_Post $post Published post or page that stopped existing.
     * @return void
     */
    private static function notifyDeleted(\WP_Post $post): void
    {
        if (SeoDetector::hasSeoPlugin()) {
            return;
        }
        $settings = SeoModule::getSettings();
        if (!$settings['google_indexing_enabled']) {
            return;
        }
        $url = get_permalink($post);
        if ($url === false || $url === '') {
            return;
        }
        self::notifyGoogle($url, self::GOOGLE_TYPE_DELETED);
    }

    /**
     * Generate a fresh IndexNow key (32 lowercase hex characters).
     *
     * @return string
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Absolute URL of the IndexNow key file, or an empty string when no key is stored.
     *
     * @return string
     */
    public static function keyFileUrl(): string
    {
        $settings = SeoModule::getSettings();
        if ($settings['indexnow_key'] === '') {
            return '';
        }
        return home_url('/' . $settings['indexnow_key'] . '.txt');
    }

    /**
     * Ping every IndexNow endpoint for one URL and record the result.
     *
     * Each endpoint is fetched with a 10-second timeout; a network
     * failure records code 0 instead of failing the request. The
     * stored log holds only the time, the URL, and one status code
     * per endpoint - never the key.
     *
     * @param string $url Absolute URL to submit.
     * @return array{success: bool, endpoints: array<int, array{endpoint: string, code: int}>}
     */
    public static function pingIndexNow(string $url): array
    {
        $settings = SeoModule::getSettings();
        $key = $settings['indexnow_key'];
        $results = [];
        $allOk = false;
        if ($key !== '') {
            foreach (self::INDEXNOW_ENDPOINTS as $endpoint) {
                $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);
                if (!in_array($host, self::INDEXNOW_HOSTS, true)) {
                    continue;
                }
                $requestUrl = add_query_arg(
                    ['url' => $url, 'key' => $key],
                    $endpoint
                );
                $response = wp_remote_get($requestUrl, ['timeout' => 10]);
                $code = is_array($response)
                    ? (int) wp_remote_retrieve_response_code($response)
                    : 0;
                $results[] = [
                    'endpoint' => $endpoint,
                    'code' => $code,
                ];
                if ($code >= 200 && $code < 300) {
                    $allOk = true;
                }
            }
        }
        update_option(
            self::INDEXNOW_LOG_OPTION,
            [
                'time' => time(),
                'url' => $url,
                'endpoints' => $results,
                'success' => $allOk,
            ],
            false
        );
        return [
            'success' => $allOk,
            'endpoints' => $results,
        ];
    }

    /**
     * Last IndexNow ping record, or null when nothing was pinged yet.
     *
     * @return array{time: int, url: string, endpoints: array<int, array{endpoint: string, code: int}>, success: bool}|null
     */
    public static function getIndexNowLog(): ?array
    {
        $log = get_option(self::INDEXNOW_LOG_OPTION, null);
        if (!is_array($log) || !isset($log['time'], $log['url']) || !is_array($log['endpoints'] ?? null)) {
            return null;
        }
        return [
            'time' => (int) $log['time'],
            'url' => (string) $log['url'],
            'endpoints' => $log['endpoints'],
            'success' => (bool) ($log['success'] ?? false),
        ];
    }

    /**
     * Whether a Google service-account JSON is stored and valid.
     *
     * @return bool
     */
    public static function isGoogleConfigured(): bool
    {
        return self::getServiceAccount() !== null;
    }

    /**
     * Client email of the stored service account, or an empty string when unconfigured.
     *
     * The client email is an identifier, not a secret; it is shown
     * in the settings page so the admin can see which service
     * account is connected. The private key is never exposed.
     *
     * @return string
     */
    public static function googleClientEmail(): string
    {
        $account = self::getServiceAccount();
        if ($account === null) {
            return '';
        }
        return (string) $account['client_email'];
    }

    /**
     * Validate and store a pasted Google service-account JSON.
     *
     * An empty string clears the stored JSON. Otherwise the JSON
     * must decode to an object carrying client_email, private_key,
     * and token_uri, and the private key must look like a PEM key;
     * anything else is rejected and the stored value is left
     * untouched. The JSON is stored exactly as pasted (it is
     * needed verbatim for signing); it is never returned, printed,
     * or logged anywhere.
     *
     * @param string $raw Pasted JSON text.
     * @return array{success: bool, message: string}
     */
    public static function saveGoogleJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            delete_option(self::GOOGLE_OPTION);
            return [
                'success' => true,
                'message' => __('Google service account removed.', 'traydigita-core'),
            ];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('That is not valid JSON. Paste the service-account JSON file exactly as Google provides it.', 'traydigita-core'),
            ];
        }
        $account = GoogleServiceAccount::parse($raw);
        if ($account === null || $account['token_uri'] === '') {
            return [
                'success' => false,
                'message' => __('That JSON is not a Google service-account key: it must contain client_email, private_key, and token_uri.', 'traydigita-core'),
            ];
        }
        update_option(self::GOOGLE_OPTION, $raw, false);
        return [
            'success' => true,
            'message' => __('Google service account saved.', 'traydigita-core'),
        ];
    }

    /**
     * Notify the Google Indexing API about one URL and record the result.
     *
     * Fetches a short-lived access token with a signed JWT, then
     * posts the notification. Any failure (unconfigured account,
     * token failure, API error, network failure) records an error
     * log entry and returns false; the access token and the service
     * account JSON never enter the log.
     *
     * @param string $url Absolute URL to notify about.
     * @param string $type Notification type, one of URL_UPDATED or URL_DELETED.
     * @return bool True when Google accepted the notification (HTTP 200).
     */
    public static function notifyGoogle(string $url, string $type): bool
    {
        $token = self::fetchGoogleAccessToken();
        $success = false;
        $code = 0;
        if ($token !== null) {
            $response = wp_remote_post(
                self::GOOGLE_NOTIFY_URL,
                [
                    'timeout' => 15,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode(['url' => $url, 'type' => $type]),
                ]
            );
            if (is_array($response)) {
                $code = (int) wp_remote_retrieve_response_code($response);
                $success = $code === 200;
            }
        }
        update_option(
            self::GOOGLE_LOG_OPTION,
            [
                'time' => time(),
                'url' => $url,
                'type' => $type,
                'code' => $code,
                'success' => $success,
            ],
            false
        );
        return $success;
    }

    /**
     * Last Google notification record, or null when nothing was notified yet.
     *
     * @return array{time: int, url: string, type: string, code: int, success: bool}|null
     */
    public static function getGoogleLog(): ?array
    {
        $log = get_option(self::GOOGLE_LOG_OPTION, null);
        if (!is_array($log) || !isset($log['time'], $log['url'])) {
            return null;
        }
        return [
            'time' => (int) $log['time'],
            'url' => (string) $log['url'],
            'type' => (string) ($log['type'] ?? self::GOOGLE_TYPE_UPDATED),
            'code' => (int) ($log['code'] ?? 0),
            'success' => (bool) ($log['success'] ?? false),
        ];
    }

    /**
     * Read and validate the stored service-account JSON.
     *
     * Returns null when nothing is stored or the stored value is
     * not a valid service-account key. The private key is returned
     * here only for signing inside this class; callers outside
     * this class must use isGoogleConfigured() and
     * googleClientEmail() instead.
     *
     * @return array{client_email: string, private_key: string, token_uri: string}|null
     */
    private static function getServiceAccount(): ?array
    {
        $raw = get_option(self::GOOGLE_OPTION, '');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $account = GoogleServiceAccount::parse($raw);
        if ($account === null || $account['token_uri'] === '') {
            return null;
        }
        return [
            'client_email' => $account['client_email'],
            'private_key' => $account['private_key'],
            'token_uri' => $account['token_uri'],
        ];
    }

    /**
     * Fetch a short-lived Google OAuth access token.
     *
     * Delegates to the shared GoogleServiceAccount client with
     * the Indexing API scope. Returns null on any failure; the
     * JWT and the token are never logged.
     *
     * @return string|null Access token, or null when it could not be fetched.
     */
    private static function fetchGoogleAccessToken(): ?string
    {
        $account = self::getServiceAccount();
        if ($account === null) {
            return null;
        }
        $token = GoogleServiceAccount::getAccessToken($account, self::GOOGLE_SCOPE);
        if (is_wp_error($token)) {
            return null;
        }
        return $token;
    }
}
