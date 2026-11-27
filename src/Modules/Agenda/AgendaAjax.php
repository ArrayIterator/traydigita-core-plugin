<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Agenda;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use function absint;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for agenda event management.
 *
 * Registered from AgendaModule::boot() (only when the module is
 * enabled), so no endpoint exists while the module is disabled.
 *
 * Every endpoint requires the `traydigita-admin` nonce sent as
 * `_ajax_nonce` (the field check_ajax_referer() reads) plus the
 * `manage_options` capability, and refuses to run while the module
 * itself is disabled.
 *
 * Endpoints (all POST to `admin-ajax.php`):
 * - `traydigita_agenda_save`: create or update one event. POST
 *   `id` (0 for a new event) plus the event fields. The raw POST
 *   is read here - NOT through the generic dispatcher - because
 *   the description is multi-line text and the dispatcher's
 *   recursive sanitize_text_field() would collapse its newlines.
 *   AgendaTable::validate() sanitizes every field for its own
 *   type.
 * - `traydigita_agenda_delete`: delete one event. POST `id`.
 * - `traydigita_agenda_delete_all`: drop the agenda table and
 *   delete all agenda data. POST `confirm` must be exactly the
 *   word `DELETE` (typed confirmation, rendered by the admin
 *   template). This is the ONLY way agenda data is ever deleted
 *   in bulk; deactivating the module keeps the data.
 */
final class AgendaAjax
{
    /**
     * AJAX action saving one event.
     */
    public const string AJAX_SAVE = 'traydigita_agenda_save';

    /**
     * AJAX action deleting one event.
     */
    public const string AJAX_DELETE = 'traydigita_agenda_delete';

    /**
     * AJAX action deleting ALL agenda data.
     */
    public const string AJAX_DELETE_ALL = 'traydigita_agenda_delete_all';

    /**
     * Typed confirmation word for deleting all agenda data.
     */
    public const string DELETE_ALL_CONFIRM_WORD = 'DELETE';

    /**
     * Register the three AJAX actions (authenticated admin only).
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_SAVE, [self::class, 'saveEvent']);
        add_action('wp_ajax_' . self::AJAX_DELETE, [self::class, 'deleteEvent']);
        add_action('wp_ajax_' . self::AJAX_DELETE_ALL, [self::class, 'deleteAll']);
    }

    /**
     * Create or update one event.
     *
     * @return void
     */
    public static function saveEvent(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        if (!AgendaTable::ensureTable()) {
            wp_send_json_error(
                ['message' => esc_html__('The agenda table could not be created.', 'traydigita-core')],
                500
            );
        }
        $raw = [];
        foreach ($_POST as $key => $value) {
            $field = sanitize_key((string) $key);
            if ($field === '' || !is_string($value)) {
                continue;
            }
            $raw[$field] = wp_unslash($value);
        }
        $validated = AgendaTable::validate($raw);
        if ($validated['errors'] !== []) {
            wp_send_json_error(
                [
                    'message' => esc_html__('Please fix the highlighted fields.', 'traydigita-core'),
                    'errors' => $validated['errors'],
                ],
                400
            );
        }
        $id = absint($raw['id'] ?? 0);
        if ($id > 0) {
            if (!AgendaTable::update($id, $validated['event'])) {
                wp_send_json_error(
                    ['message' => esc_html__('The event could not be updated.', 'traydigita-core')],
                    500
                );
            }
            wp_send_json_success([
                'message' => esc_html__('Event updated.', 'traydigita-core'),
                'id' => $id,
            ]);
        }
        $id = AgendaTable::insert($validated['event']);
        if ($id <= 0) {
            wp_send_json_error(
                ['message' => esc_html__('The event could not be saved.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'message' => esc_html__('Event added.', 'traydigita-core'),
            'id' => $id,
        ]);
    }

    /**
     * Delete one event.
     *
     * @return void
     */
    public static function deleteEvent(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $id = absint($_POST['id'] ?? 0);
        if ($id <= 0 || !AgendaTable::delete($id)) {
            wp_send_json_error(
                ['message' => esc_html__('The event could not be deleted.', 'traydigita-core')],
                400
            );
        }
        wp_send_json_success([
            'message' => esc_html__('Event deleted.', 'traydigita-core'),
            'id' => $id,
        ]);
    }

    /**
     * Delete ALL agenda data (drop the table + version flag).
     *
     * Requires the typed confirmation word `DELETE` in POST
     * `confirm`; anything else is refused with a 400 and nothing
     * is deleted.
     *
     * @return void
     */
    public static function deleteAll(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $confirm = sanitize_text_field(wp_unslash((string) ($_POST['confirm'] ?? '')));
        if ($confirm !== self::DELETE_ALL_CONFIRM_WORD) {
            wp_send_json_error(
                [
                    'message' => esc_html__('Type DELETE to confirm deleting all agenda data.', 'traydigita-core'),
                ],
                400
            );
        }
        AgendaTable::deleteAllData();
        wp_send_json_success([
            'message' => esc_html__('All agenda data has been deleted.', 'traydigita-core'),
        ]);
    }

    /**
     * Verify the nonce, then the capability, then the module state.
     *
     * check_ajax_referer() dies with -1 on a bad nonce; a missing
     * capability or a disabled module is answered by the caller
     * with a JSON 403.
     *
     * @return bool True when the request is authorized.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            return false;
        }
        return AgendaModule::isEnabledForRequest();
    }
}
