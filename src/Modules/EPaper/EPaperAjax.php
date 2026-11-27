<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\EPaper;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function is_array;
use function is_string;
use function sanitize_text_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the E-Paper module.
 *
 * Registered from `EPaperModule::boot()` (only when the module is
 * enabled), so the endpoints do not exist while the module is
 * disabled. Every endpoint verifies the `traydigita-admin` nonce
 * (sent as `_ajax_nonce`, the field `check_ajax_referer()` reads)
 * and requires the `manage_options` capability before doing
 * anything; the module-enabled flag is re-checked too, so a
 * request sent after the module was disabled is refused.
 *
 * Endpoints (all POST to `admin-ajax.php`):
 * - `traydigita_epaper_upload`: multipart upload of one PDF
 *   (`epaper_file`) with `title`, `date` (`Y-m-d`), and
 *   `cover_id` (media-library attachment id, 0 for none). The
 *   PDF is MIME-verified by EPaperStorage, never trusted on its
 *   extension or client-sent MIME type.
 * - `traydigita_epaper_update`: `issue_id`, `title`, `date`,
 *   `cover_id` - metadata only, the PDF file is never replaced
 *   here.
 * - `traydigita_epaper_delete`: `issue_id` - deletes the issue
 *   record and its PDF file. The admin UI asks for confirmation
 *   (shell `data-confirm` handler) before the request is sent.
 *
 * Responses are JSON via `wp_send_json_success()` /
 * `wp_send_json_error()` with a `message` key; successful
 * upload/update responses also carry the stored `issue` record
 * (public URLs only - never an absolute server path).
 */
final class EPaperAjax
{
    /**
     * Register the three E-Paper AJAX actions.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_epaper_upload', [self::class, 'upload']);
        add_action('wp_ajax_traydigita_epaper_update', [self::class, 'update']);
        add_action('wp_ajax_traydigita_epaper_delete', [self::class, 'delete']);
    }

    /**
     * Verify the nonce, the capability, and the module-enabled flag.
     *
     * check_ajax_referer() dies with -1 on a bad nonce; a missing
     * capability or a disabled module is answered with a JSON 403.
     *
     * @return bool True when the request is authorized.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => __('You are not allowed to manage E-Paper issues.', 'traydigita-core')],
                403
            );
        }
        if (!ModuleRegistry::isEnabled(EPaperModule::id())) {
            wp_send_json_error(
                ['message' => __('The E-Paper module is disabled.', 'traydigita-core')],
                403
            );
        }
        return true;
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
     * Upload one PDF as a new issue.
     *
     * POST (multipart): `title`, `date`, `cover_id`,
     * `epaper_file` (the PDF).
     *
     * @return void
     */
    public static function upload(): void
    {
        if (!self::authorize()) {
            return;
        }
        $file = $_FILES['epaper_file'] ?? null;
        if (!is_array($file)) {
            wp_send_json_error(
                ['message' => __('No PDF file was uploaded.', 'traydigita-core')],
                400
            );
        }
        $result = EPaperStorage::storeUploadedPdf(
            $file,
            self::postString('title'),
            self::postString('date'),
            absint(self::postString('cover_id'))
        );
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'issue' => $result['issue'] ?? null,
        ]);
    }

    /**
     * Update one issue's metadata (title, date, cover).
     *
     * POST: `issue_id`, `title`, `date`, `cover_id`.
     *
     * @return void
     */
    public static function update(): void
    {
        if (!self::authorize()) {
            return;
        }
        $result = EPaperStorage::updateIssue(
            self::postString('issue_id'),
            self::postString('title'),
            self::postString('date'),
            absint(self::postString('cover_id'))
        );
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'issue' => $result['issue'] ?? null,
        ]);
    }

    /**
     * Delete one issue (record + PDF file).
     *
     * POST: `issue_id`.
     *
     * @return void
     */
    public static function delete(): void
    {
        if (!self::authorize()) {
            return;
        }
        $result = EPaperStorage::deleteIssue(self::postString('issue_id'));
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success(['message' => $result['message']]);
    }
}
