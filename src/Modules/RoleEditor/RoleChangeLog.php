<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\RoleEditor;

use function array_slice;
use function get_current_user_id;
use function get_option;
use function is_array;
use function is_int;
use function is_string;
use function time;
use function update_option;
use function wp_get_current_user;

/**
 * Change log for the Role Editor.
 *
 * Every role change (edit, clone, delete) is recorded in the
 * single option `traydigita_role_editor_log` (autoload `no`),
 * capped at the last 50 entries. Each entry records who made the
 * change, when, which role, what action, and a plain-language
 * summary of what changed (capabilities added/removed, clone
 * source, user count at deletion). The log is shown on the
 * module's list view and is never exposed through any AJAX
 * response - only the settings page reads it.
 *
 * The log is append-only from the module's point of view: there
 * is no UI to edit or delete entries, so the audit trail cannot
 * be wiped from the settings page.
 */
final class RoleChangeLog
{
    /**
     * Option name for the log (autoload `no`).
     */
    public const string OPTION = 'traydigita_role_editor_log';

    /**
     * Maximum number of entries kept; older entries are dropped.
     */
    public const int MAX_ENTRIES = 50;

    /**
     * Record one role change.
     *
     * @param string $action Action performed: `updated`, `created`
     *     (clone), or `deleted`.
     * @param string $role Role slug the change affected.
     * @param string $details Plain-language summary of the change.
     * @return void
     */
    public static function record(string $action, string $role, string $details): void
    {
        $userId = 0;
        $userLogin = '';
        if (function_exists('get_current_user_id')) {
            $userId = (int) get_current_user_id();
        }
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if (is_object($user) && isset($user->user_login) && is_string($user->user_login)) {
                $userLogin = $user->user_login;
            }
        }
        $entries = self::get();
        array_unshift($entries, [
            'time' => time(),
            'user_id' => $userId,
            'user' => $userLogin,
            'action' => $action,
            'role' => $role,
            'details' => $details,
        ]);
        update_option(self::OPTION, array_slice($entries, 0, self::MAX_ENTRIES), false);
    }

    /**
     * Read the log, newest first.
     *
     * Malformed stored values are ignored entry by entry rather
     * than wiping the whole log.
     *
     * @return array<int, array{time: int, user_id: int, user: string, action: string, role: string, details: string}>
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $entries = [];
        foreach ($stored as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'time' => isset($entry['time']) && is_int($entry['time']) ? $entry['time'] : 0,
                'user_id' => isset($entry['user_id']) && is_int($entry['user_id']) ? $entry['user_id'] : 0,
                'user' => isset($entry['user']) && is_string($entry['user']) ? $entry['user'] : '',
                'action' => isset($entry['action']) && is_string($entry['action']) ? $entry['action'] : '',
                'role' => isset($entry['role']) && is_string($entry['role']) ? $entry['role'] : '',
                'details' => isset($entry['details']) && is_string($entry['details']) ? $entry['details'] : '',
            ];
        }
        return $entries;
    }
}
