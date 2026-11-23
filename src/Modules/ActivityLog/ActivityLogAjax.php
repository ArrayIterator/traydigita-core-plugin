<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ActivityLog;

use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function is_numeric;
use function sprintf;
use function wp_send_json_error;
use function wp_send_json_success;

/**
 * AJAX endpoints for the Activity Log viewer.
 *
 * Registered from `ActivityLogModule::boot()` (only when the
 * module is enabled), so the endpoints do not exist while the
 * module is disabled.
 *
 * - `traydigita_activity_log_delete`: delete one row.
 *   POST: `id` (row id). The row is removed from the DOM by
 *   the admin JavaScript on success.
 * - `traydigita_activity_log_clear`: delete all rows (the
 *   table itself stays). POST: no extra fields. The page
 *   reloads on success.
 *
 * Both require the `traydigita-admin` nonce (sent as
 * `_ajax_nonce`) and the `manage_options` capability, and both
 * answer JSON. The destructive buttons carry a `data-confirm`
 * message in the template, so the browser asks once more
 * before the request is sent.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\ActivityLog
 */
final class ActivityLogAjax
{
    /**
     * AJAX action name for deleting one row.
     */
    public const string ACTION_DELETE = 'traydigita_activity_log_delete';

    /**
     * AJAX action name for clearing the whole log.
     */
    public const string ACTION_CLEAR = 'traydigita_activity_log_clear';

    /**
     * Register the AJAX actions.
     *
     * Called from `ActivityLogModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_DELETE, [self::class, 'deleteRow']);
        add_action('wp_ajax_' . self::ACTION_CLEAR, [self::class, 'clearAll']);
    }

    /**
     * Delete one log row.
     *
     * POST: `id` (row id, integer).
     *
     * @return void
     */
    public static function deleteRow(): void
    {
        check_ajax_referer('traydigita-admin');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $id = isset($_POST['id']) && is_numeric($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Unknown log entry.', 'traydigita-core')], 400);
        }
        if (!ActivityLogModule::deleteRow($id)) {
            wp_send_json_error(['message' => __('The log entry could not be deleted.', 'traydigita-core')], 400);
        }
        wp_send_json_success(['message' => __('Log entry deleted.', 'traydigita-core')]);
    }

    /**
     * Delete all log rows.
     *
     * POST: no extra fields. The table itself stays in place.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        check_ajax_referer('traydigita-admin');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $deleted = ActivityLogModule::clearAll();
        wp_send_json_success([
            /* translators: %d is the number of deleted log entries. */
            'message' => sprintf(__('%d log entries deleted.', 'traydigita-core'), $deleted),
            'deleted' => $deleted,
        ]);
    }
}
