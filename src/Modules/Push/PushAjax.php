<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Push;

use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function esc_url_raw;
use function is_string;
use function is_wp_error;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sprintf;
use function trim;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Admin AJAX endpoints for the Push module.
 *
 * Every endpoint requires the shared 'traydigita-admin' nonce
 * (sent as `_ajax_nonce`, matching the other module AJAX
 * endpoints) and the `manage_options` capability. None of them
 * ever returns a secret: the test endpoint only reports whether
 * Google returned an access token, never the token itself.
 *
 * Endpoints (POST, admin only):
 * - traydigita_push_test: fetch a fresh FCM access token from
 *   the saved Service Account. No notification is sent.
 * - traydigita_push_send: title, body, link, image, target
 *   ('all' | 'test'), test_token. Manual send only.
 * - traydigita_push_delete_config: clear the stored push config.
 */
final class PushAjax
{
    /**
     * Nonce action shared by every admin endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Action name for the config test endpoint.
     */
    public const string ACTION_TEST = 'traydigita_push_test';

    /**
     * Action name for the manual send endpoint.
     */
    public const string ACTION_SEND = 'traydigita_push_send';

    /**
     * Action name for the delete-config endpoint.
     */
    public const string ACTION_DELETE_CONFIG = 'traydigita_push_delete_config';

    /**
     * Register every push AJAX action.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_TEST, [self::class, 'testConfig']);
        add_action('wp_ajax_' . self::ACTION_SEND, [self::class, 'send']);
        add_action('wp_ajax_' . self::ACTION_DELETE_CONFIG, [self::class, 'deleteConfig']);
    }

    /**
     * Verify the admin nonce and the manage_options capability.
     *
     * Sends a JSON error and stops the request when unauthorized.
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
     * Read a POST field as a sanitized string.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Refuse push work while the standalone plugin owns it.
     *
     * When the koperasi-kita-push plugin is active it serves the
     * root service worker and the token endpoint, so the module
     * must not test or send against its own (possibly different)
     * config at the same time. Config saving stays allowed - it
     * only stores options - but test and send refuse here.
     *
     * @return bool True when the plugin owns push right now.
     */
    private static function pluginOwnsPush(): bool
    {
        return PushModule::isPluginActive();
    }

    /**
     * Test the saved config by fetching a fresh access token.
     *
     * A token obtained is proof the Service Account JSON works.
     * No notification is sent. An invalid config fails with a
     * clear error, never a fatal.
     *
     * @return void
     */
    public static function testConfig(): void
    {
        self::authorizeAdmin();
        if (self::pluginOwnsPush()) {
            wp_send_json_error([
                'message' => esc_html__('The push plugin is active and owns push notifications. Deactivate it to test the theme module config.', 'traydigita-core'),
            ], 400);
        }
        if (!PushSender::isConfigured()) {
            wp_send_json_error([
                'message' => esc_html__('Push is not configured yet. Save a valid config on the Settings tab first.', 'traydigita-core'),
            ], 400);
        }
        $token = PushSender::getAccessToken(true);
        if (is_wp_error($token)) {
            wp_send_json_error(['message' => $token->get_error_message()], 400);
        }
        $summary = PushSender::serviceAccountSummary();
        wp_send_json_success([
            'message' => $summary !== null && $summary['project_id'] !== ''
                ? sprintf(
                    /* translators: %s is the Firebase project id. */
                    esc_html__('Connection OK — Google returned an access token for project %s. No notification was sent.', 'traydigita-core'),
                    esc_html($summary['project_id'])
                )
                : esc_html__('Connection OK — Google returned an access token. No notification was sent.', 'traydigita-core'),
        ]);
    }

    /**
     * Send one manual notification.
     *
     * POST fields: title (required), body (required), link,
     * image, target ('all' | 'test'), test_token (required when
     * target is 'test'). Sending is manual only - nothing here
     * runs on publish.
     *
     * @return void
     */
    public static function send(): void
    {
        self::authorizeAdmin();
        if (self::pluginOwnsPush()) {
            wp_send_json_error([
                'message' => esc_html__('The push plugin is active and owns push notifications. Send from the plugin instead.', 'traydigita-core'),
            ], 400);
        }
        if (!PushSender::isConfigured()) {
            wp_send_json_error([
                'message' => esc_html__('Push is not configured yet. Save a valid config on the Settings tab first.', 'traydigita-core'),
            ], 400);
        }
        $title = trim(sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
        $body = trim(sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')));
        $link = esc_url_raw(wp_unslash($_POST['link'] ?? ''));
        $image = esc_url_raw(wp_unslash($_POST['image'] ?? ''));
        $target = sanitize_key(self::postString('target'));
        $testToken = sanitize_text_field(wp_unslash($_POST['test_token'] ?? ''));
        $errors = [];
        if ($title === '') {
            $errors['title'] = esc_html__('Title is required.', 'traydigita-core');
        }
        if ($body === '') {
            $errors['body'] = esc_html__('Message is required.', 'traydigita-core');
        }
        if ($target === 'test' && $testToken === '') {
            $errors['test_token'] = esc_html__('A test token is required for a single-device send.', 'traydigita-core');
        }
        if ($errors !== []) {
            wp_send_json_error(['message' => esc_html__('Could not send the notification.', 'traydigita-core'), 'errors' => $errors], 400);
        }
        $result = PushSender::doSend($title, $body, $link, $image, $target === 'test' ? 'test' : 'all', $testToken);
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                'pwa',
                $result['success'] ? 'push-sent' : 'push-failed',
                $result['success']
                    ? sprintf('Push notification "%s" sent to %d subscriber(s).', $title, (int) ($result['sent'] ?? 0))
                    : sprintf('Push notification "%s" failed: %s', $title, (string) ($result['message'] ?? ''))
            );
        }
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'pruned' => $result['pruned'],
            'subscribers' => PushSender::subscriberCount(),
        ]);
    }

    /**
     * Clear the stored push config (options + cached token).
     *
     * Subscriber tokens and the send log are kept (see
     * PushSender::deleteConfig()).
     *
     * @return void
     */
    public static function deleteConfig(): void
    {
        self::authorizeAdmin();
        PushSender::deleteConfig();
        wp_send_json_success([
            'message' => esc_html__('Push config deleted. Subscriber tokens were kept.', 'traydigita-core'),
            'subscribers' => PushSender::subscriberCount(),
        ]);
    }
}
