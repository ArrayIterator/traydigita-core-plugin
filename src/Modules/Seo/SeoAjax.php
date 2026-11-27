<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use function __;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function get_current_user_id;
use function get_permalink;
use function get_post;
use function get_transient;
use function set_transient;
use function sprintf;
use function wp_send_json_error;
use function wp_send_json_success;
use WP_Post;

/**
 * Extra AJAX endpoints for the SEO module.
 *
 * Registered from SeoModule::boot() (only when the module is
 * enabled), so the endpoints do not exist while the module is
 * disabled. All endpoints require the shared `traydigita-admin`
 * nonce (sent as `_ajax_nonce`) and the `manage_options`
 * capability, following the TurnstileAjax pattern, and all are
 * rate-limited per user. Responses carry a `message` key only -
 * never keys, tokens, or service-account material.
 *
 * - traydigita_seo_indexnow_ping: POST post_id; pings IndexNow
 *   now for one published post or page. Requires IndexNow enabled
 *   and no SEO plugin active.
 * - traydigita_seo_google_notify: POST post_id; notifies the
 *   Google Indexing API now for one published post or page
 *   (URL_UPDATED). Requires the Google Indexing API enabled and
 *   configured, and no SEO plugin active.
 * - traydigita_seo_sc_refresh: POST with no fields; clears the
 *   cached Search Console dashboard data and refetches it from
 *   Site Kit. Requires Site Kit connected with Search Console
 *   active.
 *
 * The settings form itself, the AI suggestions, and the Google
 * service-account JSON go through other paths on purpose: the
 * settings form uses the generic traydigita_admin_save endpoint,
 * while the AI suggestions and the service-account JSON use
 * classic POST forms handled inside SeoModule::renderSettingsPage
 * (the admin shell leaves forms without a data-action marker
 * alone), because the JSON must not pass through the generic
 * dispatcher's sanitize_text_field() and the AI result needs a
 * full page render to display.
 */
final class SeoAjax
{
    /**
     * AJAX action name for the manual IndexNow ping.
     */
    public const string ACTION_INDEXNOW_PING = 'traydigita_seo_indexnow_ping';

    /**
     * AJAX action name for the manual Google Indexing API notification.
     */
    public const string ACTION_GOOGLE_NOTIFY = 'traydigita_seo_google_notify';

    /**
     * AJAX action name for the Search Console refresh.
     */
    public const string ACTION_SC_REFRESH = 'traydigita_seo_sc_refresh';

    /**
     * Minimum seconds between manual IndexNow pings from the same user.
     */
    public const int PING_RATE_LIMIT_SECONDS = 30;

    /**
     * Minimum seconds between manual Google notifications from the same user.
     */
    public const int GOOGLE_NOTIFY_RATE_LIMIT_SECONDS = 30;

    /**
     * Minimum seconds between Search Console refreshes from the same user.
     */
    public const int REFRESH_RATE_LIMIT_SECONDS = 60;

    /**
     * Register all AJAX actions.
     *
     * Called from SeoModule::boot(); do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_INDEXNOW_PING, [self::class, 'pingIndexNow']);
        add_action('wp_ajax_' . self::ACTION_GOOGLE_NOTIFY, [self::class, 'notifyGoogleNow']);
        add_action('wp_ajax_' . self::ACTION_SC_REFRESH, [self::class, 'refreshSearchConsole']);
    }

    /**
     * Ping IndexNow now for one published post or page.
     *
     * POST: post_id. Answers with a JSON success or error carrying
     * a `message` key only. The IndexNow key never appears in any
     * response.
     *
     * @return void
     */
    public static function pingIndexNow(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $settings = SeoModule::getSettings();
        if (!$settings['indexnow_enabled'] || SeoDetector::hasSeoPlugin()) {
            wp_send_json_error(
                ['message' => __('IndexNow is not available right now. Enable it in the SEO settings, and note it is unavailable while an SEO plugin is active.', 'traydigita-core')],
                400
            );
        }
        $userId = get_current_user_id();
        $rateLimitKey = 'traydigita_seo_ping_' . $userId;
        if (false !== get_transient($rateLimitKey)) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %d is the number of seconds to wait before the next IndexNow ping. */
                        __('Please wait %d seconds before pinging IndexNow again.', 'traydigita-core'),
                        self::PING_RATE_LIMIT_SECONDS
                    ),
                ],
                429
            );
        }
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $post = $postId > 0 ? get_post($postId) : null;
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            wp_send_json_error(
                ['message' => __('That post could not be found, or it is not published.', 'traydigita-core')],
                400
            );
        }
        $url = get_permalink($post);
        if ($url === false || $url === '') {
            wp_send_json_error(
                ['message' => __('That post has no permalink to ping.', 'traydigita-core')],
                400
            );
        }
        set_transient($rateLimitKey, 1, self::PING_RATE_LIMIT_SECONDS);
        $result = SeoIndexing::pingIndexNow($url);
        if ($result['success']) {
            wp_send_json_success(
                ['message' => __('IndexNow ping sent for this post.', 'traydigita-core')]
            );
        }
        wp_send_json_error(
            ['message' => __('IndexNow did not accept the ping. Check the last-ping log below and try again later.', 'traydigita-core')],
            502
        );
    }

    /**
     * Notify the Google Indexing API now for one published post or page.
     *
     * POST: post_id. Sends a URL_UPDATED notification for the
     * post's permalink. Answers with a JSON success or error
     * carrying a `message` key only. The service-account JSON and
     * the access token never appear in any response.
     *
     * @return void
     */
    public static function notifyGoogleNow(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $settings = SeoModule::getSettings();
        if (!$settings['google_indexing_enabled'] || SeoDetector::hasSeoPlugin()) {
            wp_send_json_error(
                ['message' => __('The Google Indexing API is not available right now. Enable it in the SEO settings, '
                    . 'and note it is unavailable while an SEO plugin is active.', 'traydigita-core')],
                400
            );
        }
        if (!SeoIndexing::isGoogleConfigured()) {
            wp_send_json_error(
                ['message' => __('The Google Indexing API is not configured yet. Save a service-account JSON '
                    . 'in the SEO settings first.', 'traydigita-core')],
                400
            );
        }
        $userId = get_current_user_id();
        $rateLimitKey = 'traydigita_seo_google_notify_' . $userId;
        if (false !== get_transient($rateLimitKey)) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %d is the number of seconds to wait before notifying Google again. */
                        __('Please wait %d seconds before notifying Google again.', 'traydigita-core'),
                        self::GOOGLE_NOTIFY_RATE_LIMIT_SECONDS
                    ),
                ],
                429
            );
        }
        $postId = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $post = $postId > 0 ? get_post($postId) : null;
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            wp_send_json_error(
                ['message' => __('That post could not be found, or it is not published.', 'traydigita-core')],
                400
            );
        }
        $url = get_permalink($post);
        if ($url === false || $url === '') {
            wp_send_json_error(
                ['message' => __('That post has no permalink to notify.', 'traydigita-core')],
                400
            );
        }
        set_transient($rateLimitKey, 1, self::GOOGLE_NOTIFY_RATE_LIMIT_SECONDS);
        $notified = SeoIndexing::notifyGoogle($url, SeoIndexing::GOOGLE_TYPE_UPDATED);
        if ($notified) {
            wp_send_json_success(
                ['message' => __('Google was notified about this post.', 'traydigita-core')]
            );
        }
        wp_send_json_error(
            ['message' => __('Google did not accept the notification. Check the last-notification log below '
                . 'and try again later.', 'traydigita-core')],
            502
        );
    }

    /**
     * Clear the cached Search Console data and refetch it from Site Kit.
     *
     * POST with no fields. Answers with a JSON success or error
     * carrying a `message` key only.
     *
     * @return void
     */
    public static function refreshSearchConsole(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $userId = get_current_user_id();
        $rateLimitKey = 'traydigita_seo_sc_refresh_' . $userId;
        if (false !== get_transient($rateLimitKey)) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %d is the number of seconds to wait before refreshing the Search Console data again. */
                        __('Please wait %d seconds before refreshing the Search Console data again.', 'traydigita-core'),
                        self::REFRESH_RATE_LIMIT_SECONDS
                    ),
                ],
                429
            );
        }
        set_transient($rateLimitKey, 1, self::REFRESH_RATE_LIMIT_SECONDS);
        $data = SeoSearchConsole::getData(true);
        if (!$data['available']) {
            wp_send_json_error(
                ['message' => __('Search Console data is not available through Site Kit right now.', 'traydigita-core')],
                400
            );
        }
        wp_send_json_success(
            [
                'message' => sprintf(
                    /* translators: %d is the number of top search queries shown. */
                    __('Search Console data refreshed: %d top queries.', 'traydigita-core'),
                    count($data['queries'])
                ),
            ]
        );
    }
}
