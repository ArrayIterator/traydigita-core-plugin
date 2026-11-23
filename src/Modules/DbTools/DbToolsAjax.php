<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DbTools;

use function __;
use function add_action;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function delete_transient;
use function get_transient;
use function implode;
use function is_array;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the DB Tools module.
 *
 * Registered from `DbToolsModule::boot()` (only when the module is
 * enabled), so no endpoint exists while the module is disabled.
 *
 * Every endpoint requires the `traydigita-admin` nonce sent as
 * `_ajax_nonce` (the field `check_ajax_referer()` reads) plus the
 * `manage_options` capability, and refuses to run while the
 * module itself is disabled.
 *
 * Endpoints (all POST to `admin-ajax.php`):
 * - `traydigita_db_tools_tables`: table list (engine / rows /
 *   sizes / overhead / FULLTEXT indexes) for refreshing the page.
 * - `traydigita_db_tools_counts`: cleaner counts per category.
 * - `traydigita_db_tools_clean`: delete the posted cleaner
 *   categories. POST `categories[]` (category keys) and
 *   `confirm` — the typed confirmation word, which must match
 *   the module's configured word exactly.
 * - `traydigita_db_tools_convert`: convert one table MyISAM to
 *   InnoDB. POST `table`. Refuses with the safety-check reasons
 *   when the conversion is unsafe.
 * - `traydigita_db_tools_optimize`: optimize one table, or all
 *   tables when POST `table` is `all`. Optimizing all tables
 *   requires the typed confirmation word in POST `confirm`.
 *
 * Table names are accepted only when they are on the
 * TableInspector allowlist; category keys only when they are
 * known Cleaner categories. Destructive work (clean, convert,
 * optimize-all) runs under a transient lock so only one such
 * job runs at a time; the lock is released in a `finally` block.
 * Responses are JSON with a `message` key, and the clean /
 * optimize responses also carry the per-category / per-table
 * numbers so the page can show exactly what happened.
 */
final class DbToolsAjax
{
    /**
     * AJAX action for the table list.
     */
    public const string ACTION_TABLES = 'traydigita_db_tools_tables';

    /**
     * AJAX action for the cleaner counts.
     */
    public const string ACTION_COUNTS = 'traydigita_db_tools_counts';

    /**
     * AJAX action for running the cleaner.
     */
    public const string ACTION_CLEAN = 'traydigita_db_tools_clean';

    /**
     * AJAX action for converting one table to InnoDB.
     */
    public const string ACTION_CONVERT = 'traydigita_db_tools_convert';

    /**
     * AJAX action for optimizing tables.
     */
    public const string ACTION_OPTIMIZE = 'traydigita_db_tools_optimize';

    /**
     * Transient key for the single-job lock.
     */
    public const string LOCK_TRANSIENT = 'traydigita_db_tools_lock';

    /**
     * Lock lifetime in seconds (a stuck job frees itself).
     */
    public const int LOCK_TTL_SECONDS = 300;

    /**
     * Register the module's AJAX actions.
     *
     * Called from `DbToolsModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_TABLES, [self::class, 'listTables']);
        add_action('wp_ajax_' . self::ACTION_COUNTS, [self::class, 'listCounts']);
        add_action('wp_ajax_' . self::ACTION_CLEAN, [self::class, 'clean']);
        add_action('wp_ajax_' . self::ACTION_CONVERT, [self::class, 'convert']);
        add_action('wp_ajax_' . self::ACTION_OPTIMIZE, [self::class, 'optimize']);
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
        check_ajax_referer(DbToolsModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!DbToolsModule::isEnabledForAjax()) {
            wp_send_json_error(['message' => __('The DB Tools module is disabled.', 'traydigita-core')], 403);
        }
        return true;
    }

    /**
     * Take the single-job lock, or refuse when a job is running.
     *
     * @return bool True when the lock was taken.
     */
    private static function acquireLock(): bool
    {
        if (false !== get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, 1, self::LOCK_TTL_SECONDS);
        return true;
    }

    /**
     * Release the single-job lock.
     *
     * @return void
     */
    private static function releaseLock(): void
    {
        delete_transient(self::LOCK_TRANSIENT);
    }

    /**
     * Send the "another job is running" error.
     *
     * @return void
     */
    private static function sendBusyError(): void
    {
        wp_send_json_error(
            ['message' => __('Another database job is already running. Please wait for it to finish and try again.', 'traydigita-core')],
            409
        );
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
     * Read a POST field as a list of sanitized keys.
     *
     * @param string $key Field name.
     * @return array<int, string>
     */
    private static function postKeyList(string $key): array
    {
        $value = $_POST[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $keys = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $clean = sanitize_key(wp_unslash($item));
            if ($clean !== '') {
                $keys[] = $clean;
            }
        }
        return $keys;
    }

    /**
     * Check the typed confirmation word against the setting.
     *
     * The comparison is exact and case-sensitive: the word is a
     * deliberate speed bump before bulk deletes, so a near miss
     * must not pass.
     *
     * @param string $typed Word typed by the admin.
     * @return bool
     */
    private static function confirmMatches(string $typed): bool
    {
        return $typed !== '' && $typed === DbToolsModule::getSettings()['confirm_word'];
    }

    /**
     * Return the table list for refreshing the settings page.
     *
     * @return void
     */
    public static function listTables(): void
    {
        if (!self::authorize()) {
            return;
        }
        wp_send_json_success([
            'tables' => TableInspector::getTables(),
            'server_version' => TableInspector::serverVersion(),
        ]);
    }

    /**
     * Return the cleaner counts per category.
     *
     * @return void
     */
    public static function listCounts(): void
    {
        if (!self::authorize()) {
            return;
        }
        wp_send_json_success(['counts' => Cleaner::counts()]);
    }

    /**
     * Run the cleaner for the posted categories.
     *
     * POST `categories[]` (known category keys only) and
     * `confirm` (the typed confirmation word). Nothing is
     * deleted when no category is selected, when the
     * confirmation word does not match, or when another job
     * holds the lock.
     *
     * @return void
     */
    public static function clean(): void
    {
        if (!self::authorize()) {
            return;
        }
        $categories = [];
        foreach (self::postKeyList('categories') as $category) {
            if (Cleaner::isKnownCategory($category)) {
                $categories[] = $category;
            }
        }
        if ($categories === []) {
            wp_send_json_error(
                ['message' => __('Select at least one category to clean.', 'traydigita-core')],
                400
            );
        }
        if (!self::confirmMatches(self::postString('confirm'))) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        /* translators: %s is the confirmation word the admin must type. */
                        __('Type %s to confirm this delete.', 'traydigita-core'),
                        DbToolsModule::getSettings()['confirm_word']
                    ),
                ],
                400
            );
        }
        if (!self::acquireLock()) {
            self::sendBusyError();
            return;
        }
        try {
            $result = Cleaner::clean($categories);
        } finally {
            self::releaseLock();
        }
        $labels = Cleaner::categoryLabels();
        $parts = [];
        foreach ($result['deleted'] as $category => $count) {
            $parts[] = sprintf(
                /* translators: %1$s is the category label, %2$d is the number of rows deleted. */
                __('%1$s: %2$d rows', 'traydigita-core'),
                $labels[$category] ?? $category,
                $count
            );
        }
        wp_send_json_success([
            'message' => sprintf(
                /* translators: %1$d is the total number of rows deleted, %2$s is the per-category breakdown. */
                __('Deleted %1$d rows from %2$s.', 'traydigita-core'),
                $result['total'],
                implode('; ', $parts)
            ),
            'deleted' => $result['deleted'],
            'total' => $result['total'],
            'counts' => Cleaner::counts(),
        ]);
    }

    /**
     * Convert one table from MyISAM to InnoDB.
     *
     * POST `table`. The safety check runs first; an unsafe
     * conversion is refused with the reasons, and nothing is
     * altered. A successful conversion returns the updated table
     * list so the page can refresh the row in place.
     *
     * @return void
     */
    public static function convert(): void
    {
        if (!self::authorize()) {
            return;
        }
        $table = self::postString('table');
        if ($table === '' || !TableInspector::isAllowedTable($table)) {
            wp_send_json_error(
                ['message' => __('Unknown table.', 'traydigita-core')],
                400
            );
        }
        if (!self::acquireLock()) {
            self::sendBusyError();
            return;
        }
        try {
            $result = TableInspector::convertTable($table);
        } finally {
            self::releaseLock();
        }
        if (!$result['success']) {
            $message = $result['message'];
            if ($result['reasons'] !== []) {
                $message .= ' ' . implode(' ', $result['reasons']);
            }
            wp_send_json_error(['message' => $message], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'tables' => TableInspector::getTables(),
        ]);
    }

    /**
     * Optimize one table, or all tables when `table` is `all`.
     *
     * POST `table` (an allowlisted table name, or the literal
     * `all`). Optimizing all tables is a bulk action and requires
     * the typed confirmation word in POST `confirm`.
     *
     * @return void
     */
    public static function optimize(): void
    {
        if (!self::authorize()) {
            return;
        }
        $table = self::postString('table');
        if ($table === 'all') {
            if (!self::confirmMatches(self::postString('confirm'))) {
                wp_send_json_error(
                    [
                        'message' => sprintf(
                            /* translators: %s is the confirmation word the admin must type. */
                            __('Type %s to confirm optimizing all tables.', 'traydigita-core'),
                            DbToolsModule::getSettings()['confirm_word']
                        ),
                    ],
                    400
                );
            }
            if (!self::acquireLock()) {
                self::sendBusyError();
                return;
            }
            try {
                $result = TableInspector::optimizeAll();
            } finally {
                self::releaseLock();
            }
            if ($result['failed'] !== []) {
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: %1$d is the number of tables optimized, %2$d is the number that failed. */
                        __('Optimized %1$d tables; %2$d tables could not be optimized: %3$s.', 'traydigita-core'),
                        $result['optimized'],
                        count($result['failed']),
                        implode(', ', $result['failed'])
                    ),
                    'optimized' => $result['optimized'],
                    'failed' => $result['failed'],
                    'tables' => TableInspector::getTables(),
                ]);
            }
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d is the number of tables optimized. */
                    __('Optimized %d tables.', 'traydigita-core'),
                    $result['optimized']
                ),
                'optimized' => $result['optimized'],
                'failed' => [],
                'tables' => TableInspector::getTables(),
            ]);
        }
        if ($table === '' || !TableInspector::isAllowedTable($table)) {
            wp_send_json_error(
                ['message' => __('Unknown table.', 'traydigita-core')],
                400
            );
        }
        $result = TableInspector::optimizeTable($table);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'tables' => TableInspector::getTables(),
        ]);
    }
}
