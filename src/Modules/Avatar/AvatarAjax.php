<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Avatar;

use function absint;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_url_raw;
use function get_current_user_id;
use function get_user_by;
use function is_array;
use function wp_get_current_user;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function __;

/**
 * AJAX endpoints for the Custom Avatar module.
 *
 * Registered from `AvatarModule::boot()` (only when the module is
 * enabled), so the endpoints do not exist while the module is
 * disabled. Every endpoint verifies the `traydigita-admin` nonce
 * (sent as `_ajax_nonce`, the field `check_ajax_referer()` reads)
 * and resolves the target user before doing anything:
 *
 * - A posted `user_id` that is not the current user is an admin
 *   action: it requires `manage_options` AND `edit_user` for that
 *   user, and the user must exist - an unknown user id is refused
 *   with a 404 error, never acted on.
 * - No `user_id` (or the current user's own id) is self-service:
 *   the caller must be logged in, must be able to `read`, and
 *   self-service must be enabled in the module settings (unless
 *   the caller is an administrator).
 *
 * Endpoints (all POST to `admin-ajax.php`):
 * - `traydigita_avatar_set_url`: `avatar_url` (remote image URL,
 *   verified as a real image by AvatarStorage).
 * - `traydigita_avatar_upload`: `avatar_file` (uploaded file,
 *   verified and centre-cropped to 256x256 by AvatarStorage).
 * - `traydigita_avatar_delete`: removes the user's avatar.
 *
 * Responses are JSON via `wp_send_json_success()` /
 * `wp_send_json_error()` with a `message` key; successful
 * set/upload responses also carry `avatar_url` and `user_id`.
 */
final class AvatarAjax
{
    /**
     * Nonce action shared by every admin endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Register the three avatar AJAX actions.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_avatar_set_url', [self::class, 'setUrl']);
        add_action('wp_ajax_traydigita_avatar_upload', [self::class, 'upload']);
        add_action('wp_ajax_traydigita_avatar_delete', [self::class, 'delete']);
    }

    /**
     * Verify the nonce and resolve the target user for this request.
     *
     * Pure logic (no JSON output) so it can be unit-tested: the
     * caller sends the error when one is returned.
     *
     * @return array{user: \WP_User|null, error: string|null, status: int}
     */
    public static function resolveTargetUser(): array
    {
        check_ajax_referer(self::NONCE_ACTION);
        $postedId = isset($_POST['user_id']) ? absint(wp_unslash($_POST['user_id'])) : 0;
        $currentId = get_current_user_id();
        if ($postedId > 0 && $postedId !== $currentId) {
            if (!current_user_can('manage_options') || !current_user_can('edit_user', $postedId)) {
                return [
                    'user' => null,
                    'error' => __('You are not allowed to change this user\'s avatar.', 'traydigita-core'),
                    'status' => 403,
                ];
            }
            $user = get_user_by('id', $postedId);
            if ($user === false) {
                return [
                    'user' => null,
                    'error' => __('Unknown user.', 'traydigita-core'),
                    'status' => 404,
                ];
            }
            return ['user' => $user, 'error' => null, 'status' => 200];
        }
        $user = wp_get_current_user();
        if ($user === null || $user->ID === 0) {
            return [
                'user' => null,
                'error' => __('You must be logged in to change an avatar.', 'traydigita-core'),
                'status' => 401,
            ];
        }
        if (!current_user_can('read')) {
            return [
                'user' => null,
                'error' => __('You are not allowed to change an avatar.', 'traydigita-core'),
                'status' => 403,
            ];
        }
        if (!current_user_can('manage_options') && !AvatarModule::isSelfServiceAllowed()) {
            return [
                'user' => null,
                'error' => __('Setting your own avatar is disabled by the site administrator.', 'traydigita-core'),
                'status' => 403,
            ];
        }
        return ['user' => $user, 'error' => null, 'status' => 200];
    }

    /**
     * Set the target user's avatar from a remote image URL.
     *
     * POST: `user_id` (optional, admin only), `avatar_url`.
     * Refused when remote URLs are disabled in the module settings.
     *
     * @return void
     */
    public static function setUrl(): void
    {
        $target = self::resolveTargetUser();
        if ($target['error'] !== null || $target['user'] === null) {
            wp_send_json_error(['message' => $target['error']], $target['status']);
        }
        if (!AvatarModule::getSettings()['allow_remote_url']) {
            wp_send_json_error(
                ['message' => __('Setting an avatar by URL is disabled by the site administrator.', 'traydigita-core')],
                403
            );
        }
        $url = isset($_POST['avatar_url']) ? esc_url_raw(wp_unslash($_POST['avatar_url'])) : '';
        $result = AvatarStorage::setUrlAvatar($target['user']->ID, $url);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'avatar_url' => $result['url'] ?? '',
            'user_id' => $target['user']->ID,
        ]);
    }

    /**
     * Upload an avatar file for the target user.
     *
     * POST (multipart): `user_id` (optional, admin only),
     * `avatar_file` (the uploaded image).
     *
     * @return void
     */
    public static function upload(): void
    {
        $target = self::resolveTargetUser();
        if ($target['error'] !== null || $target['user'] === null) {
            wp_send_json_error(['message' => $target['error']], $target['status']);
        }
        $file = $_FILES['avatar_file'] ?? null;
        if (!is_array($file)) {
            wp_send_json_error(
                ['message' => __('No avatar file was uploaded.', 'traydigita-core')],
                400
            );
        }
        $result = AvatarStorage::storeUploadedFile($target['user']->ID, $file);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'avatar_url' => $result['url'] ?? '',
            'user_id' => $target['user']->ID,
        ]);
    }

    /**
     * Delete the target user's avatar.
     *
     * POST: `user_id` (optional, admin only).
     *
     * @return void
     */
    public static function delete(): void
    {
        $target = self::resolveTargetUser();
        if ($target['error'] !== null || $target['user'] === null) {
            wp_send_json_error(['message' => $target['error']], $target['status']);
        }
        AvatarStorage::deleteAvatar($target['user']->ID);
        wp_send_json_success([
            'message' => __('Avatar deleted.', 'traydigita-core'),
            'user_id' => $target['user']->ID,
        ]);
    }
}
