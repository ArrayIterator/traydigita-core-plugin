<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FormBuilder;

use TrayDigita\WP\Plugin\Core\Libs\SchemaTable;
use function array_keys;
use function array_map;
use function current_time;
use function fclose;
use function fopen;
use function fputcsv;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rewind;
use function stream_get_contents;

/**
 * Form submission storage.
 *
 * Moved here from the Contact Form module (MUST-8): the store
 * belongs to FormBuilder, so every FormBuilder consumer stores
 * its submissions instead of silently losing them. The table
 * name (`{$wpdb->prefix}traydigita_contact_submissions`) and
 * the schema-version option are unchanged, so submissions
 * stored by the old Contact Form module stay readable with no
 * data migration.
 *
 * Submissions live in their OWN table
 * (`{$wpdb->prefix}traydigita_contact_submissions`), never in
 * postmeta or an option, so a submission is never lost when the
 * notification email fails to send: the insert happens FIRST,
 * and only then is the mail attempted (the result is recorded
 * in the `email_sent` column).
 *
 * Schema handling follows the dbDelta FINAL pattern (see
 * `Libs\SchemaTable`):
 * - The table is created lazily on the insert path
 *   (ensureTableForInsert(), ONE `CREATE TABLE IF NOT EXISTS`,
 *   no dbDelta, no error_log, never throws), so the first
 *   submission can never fail with "table does not exist".
 * - Schema upgrades are version-gated: SCHEMA_VERSION is
 *   compared with the `traydigita_contact_form_db_version`
 *   option, and ensureTable() (dbDelta) runs again when the
 *   stored version is older — throttled by a 3600s check
 *   transient, serialised by a 60s lock, admin-only. Bump
 *   SCHEMA_VERSION when the schema changes.
 * - Retention is enforced by prune(): rows older than the
 *   configured retention days are deleted on insert and on the
 *   daily `traydigita_contact_form_prune` cron event. A
 *   retention of 0 keeps submissions forever.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\FormBuilder
 */
final class SubmissionStore
{
    /**
     * Schema version. Bump when the table definition changes.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * Option holding the installed schema version.
     */
    public const string DB_VERSION_OPTION = 'traydigita_contact_form_db_version';

    /**
     * Transient throttling the schema-upgrade check (1 hour).
     */
    public const string VERSION_CHECK_TRANSIENT = 'traydigita_contact_submissions_db_version_check';

    /**
     * How long the version-check throttle lives, in seconds.
     */
    public const int VERSION_CHECK_TTL = 3600;

    /**
     * Transient serialising concurrent schema upgrades.
     */
    public const string DELTA_LOCK_TRANSIENT = 'traydigita_db_delta_lock_contact_submissions';

    /**
     * How long the upgrade lock lives, in seconds.
     */
    public const int DELTA_LOCK_TTL = 60;

    /**
     * Cron hook for the daily retention prune.
     */
    public const string PRUNE_CRON_HOOK = 'traydigita_contact_form_prune';

    /**
     * Shared table kernel for this class's table.
     */
    private static ?SchemaTable $schema = null;

    /**
     * Full table name.
     *
     * @global wpdb $wpdb
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'traydigita_contact_submissions';
    }

    /**
     * Shared table kernel, built once from this class's constants.
     *
     * The constants above stay the single definition of the
     * table's stored state (version option, check/lock transient
     * names and TTLs); SchemaTable only executes the pattern.
     *
     * @return SchemaTable
     */
    private static function schema(): SchemaTable
    {
        if (self::$schema === null) {
            self::$schema = new SchemaTable(
                'traydigita_contact_submissions',
                self::SCHEMA_VERSION,
                self::DB_VERSION_OPTION,
                self::VERSION_CHECK_TRANSIENT,
                self::VERSION_CHECK_TTL,
                self::DELTA_LOCK_TRANSIENT,
                self::DELTA_LOCK_TTL,
                self::schemaSql(...)
            );
        }
        return self::$schema;
    }

    /**
     * Table SQL for the shared kernel.
     *
     * The DDL is a FIXED string: only the table name (prefix +
     * constant suffix) is interpolated, and no user input of any
     * kind reaches it.
     *
     * @param bool $ifNotExists Add IF NOT EXISTS (lazy insert path).
     * @return string
     */
    private static function schemaSql(bool $ifNotExists): string
    {
        global $wpdb;
        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();
        $guard = $ifNotExists ? 'IF NOT EXISTS ' : '';
        return "CREATE TABLE {$guard}{$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id varchar(100) NOT NULL DEFAULT '',
            form_title varchar(200) NOT NULL DEFAULT '',
            name varchar(200) NOT NULL DEFAULT '',
            email varchar(200) NOT NULL DEFAULT '',
            subject varchar(300) NOT NULL DEFAULT '',
            fields longtext NOT NULL,
            ip varchar(45) NOT NULL DEFAULT '',
            user_agent varchar(255) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'unread',
            spam tinyint(1) NOT NULL DEFAULT 0,
            email_sent tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";
    }

    /**
     * Lazy table creation for the insert path.
     *
     * Creates the table with ONE `CREATE TABLE IF NOT EXISTS`
     * when it is missing, then the caller inserts. Never dbDelta
     * here, never error_log, never throws.
     *
     * @return bool True when the table exists after this call.
     */
    public static function ensureTableForInsert(): bool
    {
        return self::schema()->ensureForInsert();
    }

    /**
     * Version-gated dbDelta upgrade path (admin-only).
     *
     * See `SchemaTable::ensureSchema()` for the gating: dbDelta
     * runs at most once an hour behind the version-check
     * transient, a separate transient lock (60s) serialises
     * concurrent upgrades and is released in a `finally` block.
     * Pass $force to bypass the hourly check (the lock is still
     * respected). Table creation itself never happens here: the
     * lazy insert path (ensureTableForInsert(), called from
     * insert()) creates a missing table with CREATE TABLE IF NOT
     * EXISTS.
     *
     * @param bool $force Bypass the hourly version-check throttle.
     * @return bool True when the table exists with the current version,
     *     or when the check was legitimately skipped.
     */
    public static function ensureTable(bool $force = false): bool
    {
        return self::schema()->ensureSchema($force);
    }

    /**
     * Store one submission. The table is created lazily here.
     *
     * @param array<string, mixed> $submission Submission fields.
     * @return int Inserted row id, or 0 on failure.
     */
    public static function insert(array $submission): int
    {
        self::ensureTableForInsert();
        global $wpdb;
        $fields = isset($submission['fields']) && is_array($submission['fields']) ? $submission['fields'] : [];
        $encoded = json_encode($fields);
        if (!is_string($encoded)) {
            $encoded = '{}';
        }
        $result = $wpdb->insert(
            self::tableName(),
            [
                'form_id' => isset($submission['form_id']) && is_string($submission['form_id']) ? $submission['form_id'] : '',
                'form_title' => isset($submission['form_title']) && is_string($submission['form_title']) ? $submission['form_title'] : '',
                'name' => isset($submission['name']) && is_string($submission['name']) ? $submission['name'] : '',
                'email' => isset($submission['email']) && is_string($submission['email']) ? $submission['email'] : '',
                'subject' => isset($submission['subject']) && is_string($submission['subject']) ? $submission['subject'] : '',
                'fields' => $encoded,
                'ip' => isset($submission['ip']) && is_string($submission['ip']) ? $submission['ip'] : '',
                'user_agent' => isset($submission['user_agent']) && is_string($submission['user_agent']) ? $submission['user_agent'] : '',
                'status' => 'unread',
                'spam' => !empty($submission['spam']) ? 1 : 0,
                'email_sent' => !empty($submission['email_sent']) ? 1 : 0,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']
        );
        if ($result === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Mark the email-sent flag of a submission.
     *
     * @param int $id Submission id.
     * @param bool $sent Whether the notification mail was sent.
     * @return bool True when a row was updated.
     */
    public static function markEmailSent(int $id, bool $sent): bool
    {
        global $wpdb;
        $result = $wpdb->update(
            self::tableName(),
            ['email_sent' => $sent ? 1 : 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
        return $result !== false && $result > 0;
    }

    /**
     * Mark a submission read or unread.
     *
     * @param int $id Submission id.
     * @param bool $read True for read, false for unread.
     * @return bool True when a row was updated.
     */
    public static function markRead(int $id, bool $read): bool
    {
        global $wpdb;
        $result = $wpdb->update(
            self::tableName(),
            ['status' => $read ? 'read' : 'unread'],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
        return $result !== false && $result > 0;
    }

    /**
     * Delete one submission.
     *
     * @param int $id Submission id.
     * @return bool True when a row was deleted.
     */
    public static function delete(int $id): bool
    {
        global $wpdb;
        $result = $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
        return $result !== false && $result > 0;
    }

    /**
     * Read one submission by id, or null.
     *
     * @param int $id Submission id.
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tableName() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        return self::decodeRow($row);
    }

    /**
     * Count submissions matching the filters.
     *
     * @param array<string, mixed> $filters form_id, status, from, to, search.
     * @return int
     */
    public static function count(array $filters = []): int
    {
        global $wpdb;
        [$where, $params] = self::buildWhere($filters);
        $sql = 'SELECT COUNT(*) FROM ' . self::tableName() . $where;
        $result = $params === [] ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $params));
        return (int) $result;
    }

    /**
     * List submissions, newest first.
     *
     * @param array<string, mixed> $filters form_id, status, from, to, search.
     * @param int $page Page number (1-based).
     * @param int $perPage Rows per page.
     * @return array<int, array<string, mixed>>
     */
    public static function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        global $wpdb;
        [$where, $params] = self::buildWhere($filters);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $sql = 'SELECT * FROM ' . self::tableName() . $where . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        $params[] = $perPage;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $decoded = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $decoded[] = self::decodeRow($row);
            }
        }
        return $decoded;
    }

    /**
     * Build the WHERE clause and its parameters for list/count.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        global $wpdb;
        $clauses = [];
        $params = [];
        if (isset($filters['form_id']) && is_string($filters['form_id']) && $filters['form_id'] !== '') {
            $clauses[] = 'form_id = %s';
            $params[] = $filters['form_id'];
        }
        if (isset($filters['status']) && in_array($filters['status'], ['read', 'unread'], true)) {
            $clauses[] = 'status = %s';
            $params[] = $filters['status'];
        }
        if (isset($filters['from']) && is_string($filters['from']) && $filters['from'] !== '') {
            $clauses[] = 'created_at >= %s';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (isset($filters['to']) && is_string($filters['to']) && $filters['to'] !== '') {
            $clauses[] = 'created_at <= %s';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        if (isset($filters['search']) && is_string($filters['search']) && $filters['search'] !== '') {
            $clauses[] = '(name LIKE %s OR email LIKE %s OR subject LIKE %s OR fields LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($clauses === []) {
            return ['', []];
        }
        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * Decode the JSON fields column of one row.
     *
     * @param array<string, mixed> $row Raw database row.
     * @return array<string, mixed>
     */
    private static function decodeRow(array $row): array
    {
        $fields = isset($row['fields']) && is_string($row['fields']) ? json_decode($row['fields'], true) : null;
        $row['fields'] = is_array($fields) ? $fields : [];
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['spam'] = (bool) ($row['spam'] ?? false);
        $row['email_sent'] = (bool) ($row['email_sent'] ?? false);
        return $row;
    }

    /**
     * Export matching submissions as CSV.
     *
     * Columns: id, date, form, name, email, subject, status,
     * email sent, then one column per distinct field id found in
     * the export (in first-seen order).
     *
     * @param array<string, mixed> $filters Same filters as list().
     * @return string CSV text (UTF-8, with BOM for Excel).
     */
    public static function exportCsv(array $filters = []): string
    {
        $rows = self::list($filters, 1, 10000);
        $fieldIds = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['fields']) as $fieldId) {
                if (is_string($fieldId) && !in_array($fieldId, $fieldIds, true)) {
                    $fieldIds[] = $fieldId;
                }
            }
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        $header = ['ID', 'Date', 'Form', 'Name', 'Email', 'Subject', 'Status', 'Email sent'];
        foreach ($fieldIds as $fieldId) {
            $header[] = $fieldId;
        }
        fputcsv($handle, $header);
        foreach ($rows as $row) {
            $line = [
                (string) $row['id'],
                (string) ($row['created_at'] ?? ''),
                (string) ($row['form_title'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['subject'] ?? ''),
                (string) ($row['status'] ?? ''),
                !empty($row['email_sent']) ? 'yes' : 'no',
            ];
            foreach ($fieldIds as $fieldId) {
                $value = $row['fields'][$fieldId] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }
                $line[] = (string) $value;
            }
            fputcsv($handle, $line);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        if (!is_string($csv)) {
            return '';
        }
        // UTF-8 BOM so Excel opens the file as UTF-8.
        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * Delete submissions older than the retention days.
     *
     * A retention of 0 keeps submissions forever.
     *
     * @param int $retentionDays Days to keep (0 = forever).
     * @return int Number of deleted rows.
     */
    public static function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * DAY_IN_SECONDS);
        $result = $wpdb->query(
            $wpdb->prepare('DELETE FROM ' . self::tableName() . ' WHERE created_at < %s', $cutoff)
        );
        return $result === false ? 0 : (int) $result;
    }
}
