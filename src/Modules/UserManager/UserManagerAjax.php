<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\UserManager;

use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function absint;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function in_array;
use function is_array;
use function is_string;
use function sanitize_key;
use function sprintf;
use function wp_get_current_user;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the User Manager module.
 *
 * Registered from `UserManagerModule::boot()` (only when the
 * module is enabled), so no endpoint exists while the module is
 * disabled. Every endpoint verifies the `traydigita-admin` nonce
 * (sent as `_ajax_nonce`, the field `check_ajax_referer()` reads)
 * plus the `promote_users` capability, and refuses to run while
 * the module itself is disabled.
 *
 * Endpoints (all POST to `admin-ajax.php`):
 * - `traydigita_user_manager_change_role`: quick role change for
 *   one user. POST `user_id`, `role`. The manage_options
 *   protection rule (see UserManagerModule::canChangeRole()) is
 *   enforced inside changeUserRole(); a refused change answers
 *   with a 403 and the reason.
 * - `traydigita_user_manager_bulk_role`: bulk role change. POST
 *   `user_ids[]` (at most 100 ids), `role`. Each user is handled
 *   independently through the same changeUserRole() logic, so a
 *   refused (protected, unknown, or invalid-role) user is
 *   reported per user and never aborts the rest of the batch.
 *
 * Responses carry only safe user summaries (see
 * UserManagerModule::safeUserSummary()): id, login, display
 * name, email, roles, last login. Password hashes are never
 * included in any response.
 */
final class UserManagerAjax
{
    /**
     * Nonce action shared by every admin endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * AJAX action for the single-user role change.
     */
    public const string ACTION_CHANGE_ROLE = 'traydigita_user_manager_change_role';

    /**
     * AJAX action for the bulk role change.
     */
    public const string ACTION_BULK_ROLE = 'traydigita_user_manager_bulk_role';

    /**
     * Maximum number of users in one bulk role-change request.
     */
    public const int MAX_BULK_USERS = 100;

    /**
     * Register the module's AJAX actions.
     *
     * Called from `UserManagerModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_CHANGE_ROLE, [self::class, 'changeRole']);
        add_action('wp_ajax_' . self::ACTION_BULK_ROLE, [self::class, 'bulkRole']);
    }

    /**
     * Authorize a request: nonce, capability, module enabled.
     *
     * Sends a JSON error and returns false when the request is
     * not authorized; `check_ajax_referer()` dies with -1 on a
     * bad nonce before this returns.
     *
     * @return bool True when the request may proceed.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('promote_users')) {
            wp_send_json_error(
                ['message' => __('You are not allowed to change user roles.', 'traydigita-core')],
                403
            );
        }
        if (!ModuleRegistry::isEnabled(UserManagerModule::id())) {
            wp_send_json_error(
                ['message' => __('The User Manager module is disabled.', 'traydigita-core')],
                403
            );
        }
        return true;
    }

    /**
     * Change one user's role (quick role control on the Users screen).
     *
     * POST: `user_id`, `role`.
     *
     * @return void
     */
    public static function changeRole(): void
    {
        if (!self::authorize()) {
            return;
        }
        $userId = isset($_POST['user_id']) ? absint(wp_unslash($_POST['user_id'])) : 0;
        $role = isset($_POST['role']) && is_string($_POST['role'])
            ? sanitize_key(wp_unslash($_POST['role']))
            : '';
        $result = UserManagerModule::changeUserRole($userId, $role, wp_get_current_user());
        if (!$result['success']) {
            $status = ($result['code'] ?? '') === 'unknown_user' ? 404 : 403;
            wp_send_json_error(['message' => $result['message']], $status);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'user' => $result['user'] ?? null,
        ]);
    }

    /**
     * Change the role of several users at once (module page bulk panel).
     *
     * POST: `user_ids[]` (user ids), `role`. At most
     * MAX_BULK_USERS ids are processed; extras are ignored and
     * counted as skipped. Each user goes through
     * changeUserRole() on its own, so protected or unknown users
     * are reported individually and never stop the batch.
     *
     * @return void
     */
    public static function bulkRole(): void
    {
        if (!self::authorize()) {
            return;
        }
        $role = isset($_POST['role']) && is_string($_POST['role'])
            ? sanitize_key(wp_unslash($_POST['role']))
            : '';
        $rawIds = $_POST['user_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $userIds = [];
        foreach ($rawIds as $rawId) {
            $id = absint($rawId);
            if ($id > 0 && !in_array($id, $userIds, true)) {
                $userIds[] = $id;
            }
        }
        if ($userIds === []) {
            wp_send_json_error(
                ['message' => __('Choose at least one user first.', 'traydigita-core')],
                400
            );
        }
        if ($role === '') {
            wp_send_json_error(
                ['message' => __('Choose a role first.', 'traydigita-core')],
                400
            );
        }
        $actor = wp_get_current_user();
        $changed = [];
        $refused = [];
        $skipped = 0;
        foreach ($userIds as $index => $userId) {
            if ($index >= self::MAX_BULK_USERS) {
                $skipped++;
                continue;
            }
            $result = UserManagerModule::changeUserRole($userId, $role, $actor);
            if ($result['success']) {
                $changed[] = $result['user'];
            } else {
                $refused[] = [
                    'user_id' => $userId,
                    'code' => $result['code'] ?? 'refused',
                    'message' => $result['message'],
                ];
            }
        }
        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$d is the number of users whose role changed, %2$d is the number refused. */
                __('Changed the role of %1$d user(s); %2$d refused.', 'traydigita-core'),
                count($changed),
                count($refused)
            ),
            'changed' => $changed,
            'refused' => $refused,
            'skipped' => $skipped,
        ]);
    }
}
