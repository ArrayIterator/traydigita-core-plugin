<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ActivityLog;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use WP_User;
use function __;
use function absint;
use function add_action;
use function array_merge;
use function array_slice;
use function current_user_can;
use function date_i18n;
use function dbDelta;
use function delete_transient;
use function esc_html__;
use function file_exists;
use function get_current_user_id;
use function get_option;
use function get_transient;
use function get_userdata;
use function implode;
use function is_admin;
use function is_array;
use function is_string;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function str_contains;
use function strtotime;
use function strtolower;
use function time;
use function trim;
use function update_option;
use function wp_json_encode;
use function wp_unslash;

/**
 * Activity Log module.
 *
 * A central, lightweight log of what happened across the other
 * modules: module enable/disable, settings saves, push sends,
 * mail sends, sign-ins, and plugin installs/activations. It is
 * deliberately not a Simple History clone: no per-pageview
 * logging, no postmeta, no option blobs — one dedicated table,
 * capped rows, auto-prune, and a viewer with filters.
 *
 * Table handling (the theme-wide pattern, final form):
 * - INSERT PATH (lazy, Stack-style): record() checks table
 *   existence through the static-cached $tableExistsCache (at
 *   most one cheap `SHOW TABLES LIKE` per request, zero on
 *   later inserts). Table exists → insert. Table missing → run
 *   ONE `CREATE TABLE IF NOT EXISTS` (idempotent, silent) and
 *   then insert — no log loss. Never dbDelta on the insert
 *   path, never error_log, never throw: a log-write failure
 *   returns false and the action being logged carries on.
 * - UPGRADE PATH (dbDelta, admin-only): ensureSchema() runs
 *   dbDelta only when the stored version differs from
 *   TABLE_VERSION. The check is throttled by the transient
 *   `traydigita_db_check_activity_log` (TTL 3600 seconds / 1
 *   hour): a fresh transient returns immediately with no DB
 *   query at all. A concurrent dbDelta is prevented by the
 *   lock transient `traydigita_db_delta_lock_activity_log`
 *   (TTL 60 seconds): when the lock exists the upgrade is
 *   skipped, and the lock is always released in a `finally`
 *   block. The Setup create-table button calls
 *   ensureSchema(true) and bypasses the version transient
 *   (but still respects the lock).
 * - SURFACING: the Setup checklist item and this module's
 *   settings page both show the table status (exists /
 *   missing, row count), so a missing table is visible and
 *   the admin can recreate it with one click.
 *
 * Secrets are never logged: no passwords, tokens, API keys,
 * or message bodies. record() strips meta keys that look like
 * secrets before encoding, and callers pass subjects and
 * titles only, never bodies or credentials.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\ActivityLog
 */
final class ActivityLogModule implements ModuleInterface
{
    /**
     * Table name suffix (appended to `$wpdb->prefix`).
     */
    public const string TABLE_SUFFIX = 'traydigita_activity_log';

    /**
     * Option name holding all activity-log settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_activity_log';

    /**
     * Option name recording the installed table schema version.
     */
    public const string TABLE_VERSION_OPTION = 'traydigita_activity_log_table_version';

    /**
     * Current table schema version.
     */
    public const int TABLE_VERSION = 1;

    /**
     * Transient throttling the version-gated dbDelta check.
     *
     * A fresh value means "checked within the last hour, table
     * was fine" — ensureSchema() then returns immediately with
     * no DB query at all.
     */
    public const string DB_CHECK_TRANSIENT = 'traydigita_db_check_activity_log';

    /**
     * TTL of the version-check transient, in seconds (1 hour).
     */
    public const int DB_CHECK_TTL = 3600;

    /**
     * Transient locking a running dbDelta upgrade.
     *
     * Separate from the version-check transient. When present
     * (younger than its 60-second TTL) a second concurrent
     * dbDelta is skipped instead of started.
     */
    public const string DB_DELTA_LOCK_TRANSIENT = 'traydigita_db_delta_lock_activity_log';

    /**
     * TTL of the dbDelta lock transient, in seconds.
     */
    public const int DB_DELTA_LOCK_TTL = 60;

    /**
     * Default maximum rows kept before auto-prune.
     */
    public const int DEFAULT_MAX_ROWS = 5000;

    /**
     * Default retention in days (0 = keep by row count only).
     */
    public const int DEFAULT_RETENTION_DAYS = 90;

    /**
     * Rows per viewer page.
     */
    public const int PER_PAGE = 20;

    /**
     * Static cache of the table-existence check.
     *
     * Null until the first check in this request; afterwards
     * record() reuses it, so at most one cheap `SHOW TABLES
     * LIKE` runs per request. PHP statics reset per request
     * automatically, so a dropped table is noticed again on
     * the next request at the latest.
     *
     * @var bool|null
     */
    private static ?bool $tableExistsCache = null;

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'activity-log';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'administration';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Activity Log', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A lightweight central log of module activity — toggles, settings saves, push and mail sends, sign-ins, plugin installs — with filters, retention, and auto-prune.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'history';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-activity-log';
    }

    /**
     * Whether the module can run at all.
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * The table is created lazily on first insert and from the
     * Setup page, so there is nothing to configure first.
     *
     * @return bool Always true.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * @return bool Always false.
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * In admin context the version-gated schema check runs
     * (throttled to once an hour by the version transient, so
     * this costs nothing on most requests). Sign-in hooks are
     * registered for the login events; the test/send/install
     * events are recorded by their own modules calling
     * record() directly. A disabled module registers nothing.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        if (is_admin()) {
            self::ensureSchema();
        }
        add_action('wp_login', [self::class, 'onLogin'], 10, 2);
        add_action('wp_login_failed', [self::class, 'onLoginFailed']);
        add_action('traydigita:core:two-factor:verified', [self::class, 'onTwoFactorEvent'], 10, 4);
        ActivityLogAjax::register();
    }

    /**
     * Full table name (prefix + suffix).
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * CREATE TABLE statement for the log table.
     *
     * Indexes: `created_at` (date-range + ordering), `module`,
     * `event_type`, `user_id`, and the composites
     * (`module`, `created_at`) and (`event_type`, `created_at`)
     * that the viewer queries filter and order by — so the
     * viewer never full-scans a big log. The `$ifNotExists`
     * form is for the lazy insert path (Stack pattern); the
     * plain form is for dbDelta upgrades.
     *
     * @param bool $ifNotExists Whether to add IF NOT EXISTS.
     * @return string
     */
    public static function tableSql(bool $ifNotExists): string
    {
        global $wpdb;
        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();
        $ifNotExistsSql = $ifNotExists ? 'IF NOT EXISTS ' : '';
        return "CREATE TABLE {$ifNotExistsSql}{$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            module varchar(50) NOT NULL DEFAULT '',
            event_type varchar(50) NOT NULL DEFAULT '',
            message varchar(255) NOT NULL DEFAULT '',
            meta longtext NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY module (module),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY module_created (module, created_at),
            KEY event_created (event_type, created_at)
        ) {$charset};";
    }

    /**
     * Whether the log table exists (static-cached per request).
     *
     * At most one cheap `SHOW TABLES LIKE` per request; later
     * calls reuse the cached result. Pass $refresh to re-check
     * after a CREATE (the lazy path does this).
     *
     * @param bool $refresh Whether to ignore the static cache.
     * @return bool
     */
    public static function tableExists(bool $refresh = false): bool
    {
        if (self::$tableExistsCache !== null && !$refresh) {
            return self::$tableExistsCache;
        }
        global $wpdb;
        $table = self::tableName();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        self::$tableExistsCache = $found;
        return $found;
    }

    /**
     * Stored table schema version.
     *
     * @return int 0 when the table was never created.
     */
    public static function tableVersion(): int
    {
        return absint(get_option(self::TABLE_VERSION_OPTION, 0));
    }

    /**
     * Lazy table creation for the insert path (Stack pattern).
     *
     * Creates the table with ONE `CREATE TABLE IF NOT EXISTS`
     * (cheap, idempotent, silent) when it is missing, then the
     * caller inserts. Never dbDelta here, never error_log,
     * never throws: any failure returns false and the caller
     * skips the insert.
     *
     * @return bool True when the table exists after this call.
     */
    public static function ensureTableForInsert(): bool
    {
        try {
            if (self::tableExists()) {
                return true;
            }
            global $wpdb;
            $wpdb->query(self::tableSql(true));
            $exists = self::tableExists(true);
            if ($exists && self::tableVersion() === 0) {
                update_option(self::TABLE_VERSION_OPTION, self::TABLE_VERSION, false);
            }
            return $exists;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Version-gated dbDelta upgrade path (admin-only).
     *
     * Gating, in order:
     * 1. Non-admin callers return immediately unless $force
     *    (the Setup button) — dbDelta never runs on frontend
     *    requests.
     * 2. A fresh `traydigita_db_check_activity_log` transient
     *    (TTL 3600s) returns immediately with no DB query.
     * 3. Table present AND stored version current → set the
     *    transient and return (no dbDelta).
     * 4. Otherwise acquire the `traydigita_db_delta_lock_activity_log`
     *    transient (TTL 60s); when it already exists a migration
     *    is running elsewhere, so skip. dbDelta runs inside
     *    try/finally so the lock is always released, even when
     *    dbDelta throws.
     *
     * @param bool $force Bypass the version transient (Setup button).
     * @return bool True when the table exists with the current version.
     */
    public static function ensureSchema(bool $force = false): bool
    {
        try {
            if (!$force && !is_admin()) {
                return false;
            }
            if (!$force && get_transient(self::DB_CHECK_TRANSIENT) !== false) {
                return true;
            }
            $exists = self::tableExists();
            $version = self::tableVersion();
            if ($exists && $version === self::TABLE_VERSION) {
                set_transient(self::DB_CHECK_TRANSIENT, '1', self::DB_CHECK_TTL);
                return true;
            }
            if (get_transient(self::DB_DELTA_LOCK_TRANSIENT) !== false) {
                return false;
            }
            set_transient(self::DB_DELTA_LOCK_TRANSIENT, time(), self::DB_DELTA_LOCK_TTL);
            try {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta(self::tableSql(false));
                $exists = self::tableExists(true);
                if ($exists) {
                    update_option(self::TABLE_VERSION_OPTION, self::TABLE_VERSION, false);
                }
            } catch (Throwable) {
                $exists = false;
            } finally {
                delete_transient(self::DB_DELTA_LOCK_TRANSIENT);
            }
            set_transient(self::DB_CHECK_TRANSIENT, '1', self::DB_CHECK_TTL);
            return $exists;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Record one activity event. Fail-open by design.
     *
     * The insert path never breaks or slows the action being
     * logged: when this module is disabled, when the table
     * cannot be created, or when the insert itself fails, this
     * returns false silently — no dbDelta on this path (the
     * lazy CREATE TABLE IF NOT EXISTS in ensureTableForInsert()
     * is the only DDL here), no error_log (it would spam on a
     * hot path), no exception ever escapes.
     *
     * Secrets are stripped from $meta before encoding: any key
     * containing password, token, secret, api-key, auth,
     * credential or private is dropped entirely.
     *
     * @param string $module Module id the event belongs to (e.g. 'smtp', 'pwa').
     * @param string $eventType Event type (e.g. 'settings-saved', 'mail-sent').
     * @param string $message Short human message (no bodies, no credentials).
     * @param int $userId User id, or 0 for the current user / nobody.
     * @param array<string, mixed> $meta Extra scalar data (never secrets).
     * @return bool True when the row was stored.
     */
    public static function record(
        string $module,
        string $eventType,
        string $message,
        int $userId = 0,
        array $meta = []
    ): bool {
        try {
            if (!ModuleRegistry::isEnabled(self::id())) {
                return false;
            }
            if (!self::ensureTableForInsert()) {
                return false;
            }
            global $wpdb;
            if ($userId <= 0) {
                $userId = get_current_user_id();
            }
            $cleanMeta = self::cleanMeta($meta);
            $inserted = $wpdb->insert(
                self::tableName(),
                [
                    'created_at' => date_i18n('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'module' => mb_substr(sanitize_key($module), 0, 50),
                    'event_type' => mb_substr(sanitize_key($eventType), 0, 50),
                    'message' => mb_substr(sanitize_text_field($message), 0, 255),
                    'meta' => $cleanMeta === [] ? null : (string) wp_json_encode($cleanMeta),
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s']
            );
            if ($inserted === false) {
                return false;
            }
            $insertId = (int) $wpdb->insert_id;
            if ($insertId > 0 && $insertId % 100 === 0) {
                self::prune();
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Strip secrets and non-scalars from meta before storage.
     *
     * @param array<string, mixed> $meta Raw meta.
     * @return array<string, string|int|float|bool> Clean meta (at most 2000 chars encoded).
     */
    private static function cleanMeta(array $meta): array
    {
        $clean = [];
        foreach ($meta as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $lower = strtolower($key);
            if (str_contains($lower, 'password')
                || str_contains($lower, 'token')
                || str_contains($lower, 'secret')
                || str_contains($lower, 'api_key')
                || str_contains($lower, 'api-key')
                || str_contains($lower, 'apikey')
                || str_contains($lower, 'auth')
                || str_contains($lower, 'credential')
                || str_contains($lower, 'private')
            ) {
                continue;
            }
            if (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 200);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $clean[$key] = $value;
            }
        }
        $encoded = json_encode($clean);
        if (!is_string($encoded) || mb_strlen($encoded) > 2000) {
            return array_slice($clean, 0, 5);
        }
        return $clean;
    }

    /**
     * Prune old rows: retention days first, then the row cap.
     *
     * Retention deletes rows older than the configured days
     * (0 = keep by count only). The cap then deletes the oldest
     * rows beyond max_rows. Both deletes are prepared; the
     * method is fail-open and never throws.
     *
     * @return void
     */
    public static function prune(): void
    {
        try {
            if (!self::tableExists()) {
                return;
            }
            global $wpdb;
            $table = self::tableName();
            $settings = self::getSettings();
            if ($settings['retention_days'] > 0) {
                $cutoff = date_i18n('Y-m-d H:i:s', time() - $settings['retention_days'] * 86400);
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
            }
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            $excess = $total - $settings['max_rows'];
            if ($excess > 0) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $excess));
            }
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Query log rows with filters, newest first.
     *
     * Every filter maps to an indexed column (`module`,
     * `event_type`, `user_id`, `created_at`); ordering is by
     * `created_at` + `id` so the index serves the sort. The
     * search term is the one LIKE path (message has no index);
     * it is escaped with esc_like and always combined with the
     * LIMIT, so a search can never return an unbounded set.
     *
     * @param array{module?: string, event_type?: string, user_id?: int, from?: string, to?: string, search?: string, page?: int, per_page?: int} $filters Filters.
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public static function query(array $filters = []): array
    {
        if (!self::tableExists()) {
            return ['rows' => [], 'total' => 0];
        }
        global $wpdb;
        $table = self::tableName();
        $where = [];
        $params = [];
        $module = sanitize_key((string) ($filters['module'] ?? ''));
        if ($module !== '') {
            $where[] = 'module = %s';
            $params[] = $module;
        }
        $event = sanitize_key((string) ($filters['event_type'] ?? ''));
        if ($event !== '') {
            $where[] = 'event_type = %s';
            $params[] = $event;
        }
        $userId = absint($filters['user_id'] ?? 0);
        if ($userId > 0) {
            $where[] = 'user_id = %d';
            $params[] = $userId;
        }
        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '' && strtotime($from) !== false) {
            $where[] = 'created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        $to = trim((string) ($filters['to'] ?? ''));
        if ($to !== '' && strtotime($to) !== false) {
            $where[] = 'created_at <= %s';
            $params[] = $to . ' 23:59:59';
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = 'message LIKE %s';
            $params[] = $like;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $countSql = "SELECT COUNT(*) FROM {$table}" . $whereSql;
        $total = (int) $wpdb->get_var($params !== [] ? $wpdb->prepare($countSql, $params) : $countSql);
        $page = max(1, absint($filters['page'] ?? 1));
        $perPage = min(100, max(1, absint($filters['per_page'] ?? self::PER_PAGE)));
        $offset = ($page - 1) * $perPage;
        $listSql = "SELECT * FROM {$table}" . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
        $listParams = array_merge($params, [$perPage, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($listSql, $listParams), ARRAY_A);
        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * Distinct event types present in the log, for the filter select.
     *
     * @return array<int, string>
     */
    public static function distinctEvents(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_col('SELECT DISTINCT event_type FROM ' . self::tableName() . " WHERE event_type <> '' ORDER BY event_type ASC");
        $events = [];
        if (is_array($rows)) {
            foreach ($rows as $event) {
                if (is_string($event) && $event !== '') {
                    $events[] = $event;
                }
            }
        }
        return $events;
    }

    /**
     * Distinct modules present in the log, for the filter select.
     *
     * @return array<int, string>
     */
    public static function distinctModules(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_col('SELECT DISTINCT module FROM ' . self::tableName() . " WHERE module <> '' ORDER BY module ASC");
        $modules = [];
        if (is_array($rows)) {
            foreach ($rows as $module) {
                if (is_string($module) && $module !== '') {
                    $modules[] = $module;
                }
            }
        }
        return $modules;
    }

    /**
     * Distinct user ids present in the log, for the filter select.
     *
     * @return array<int, int>
     */
    public static function distinctUserIds(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_col('SELECT DISTINCT user_id FROM ' . self::tableName() . ' WHERE user_id > 0 ORDER BY user_id ASC');
        $ids = [];
        if (is_array($rows)) {
            foreach ($rows as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Total rows in the log, or 0 when the table is missing.
     *
     * @return int
     */
    public static function countAll(): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::tableName());
    }

    /**
     * Delete one log row.
     *
     * @param int $id Row id.
     * @return bool True when a row was deleted.
     */
    public static function deleteRow(int $id): bool
    {
        if ($id <= 0 || !self::tableExists()) {
            return false;
        }
        global $wpdb;
        $deleted = $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
        return $deleted !== false && $deleted > 0;
    }

    /**
     * Delete all log rows (the table itself stays).
     *
     * @return int Number of rows deleted.
     */
    public static function clearAll(): int
    {
        if (!self::tableExists()) {
            return 0;
        }
        global $wpdb;
        $deleted = $wpdb->query('DELETE FROM ' . self::tableName());
        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Default retention settings.
     *
     * @return array{max_rows: int, retention_days: int}
     */
    public static function getDefaults(): array
    {
        return [
            'max_rows' => self::DEFAULT_MAX_ROWS,
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
        ];
    }

    /**
     * Read the stored retention settings merged over the defaults.
     *
     * @return array{max_rows: int, retention_days: int}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $maxRows = isset($settings['max_rows']) ? absint($settings['max_rows']) : self::DEFAULT_MAX_ROWS;
        if ($maxRows < 500) {
            $maxRows = 500;
        } elseif ($maxRows > 20000) {
            $maxRows = 20000;
        }
        $retention = isset($settings['retention_days']) ? absint($settings['retention_days']) : self::DEFAULT_RETENTION_DAYS;
        if ($retention > 365) {
            $retention = 365;
        }
        return [
            'max_rows' => $maxRows,
            'retention_days' => $retention,
        ];
    }

    /**
     * Validate and save the retention settings.
     *
     * Expected flat keys: `max_rows` (integer clamped to
     * 500–20000) and `retention_days` (integer clamped to
     * 0–365). Unknown keys are dropped. Saving runs a prune
     * immediately, so a lowered cap takes effect at once.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Activity Log settings.', 'traydigita-core'),
            ];
        }
        $maxRows = isset($data['max_rows']) ? absint($data['max_rows']) : self::DEFAULT_MAX_ROWS;
        if ($maxRows < 500) {
            $maxRows = 500;
        } elseif ($maxRows > 20000) {
            $maxRows = 20000;
        }
        $retention = isset($data['retention_days']) ? absint($data['retention_days']) : self::DEFAULT_RETENTION_DAYS;
        if ($retention > 365) {
            $retention = 365;
        }
        update_option(self::OPTION, [
            'max_rows' => $maxRows,
            'retention_days' => $retention,
        ], false);
        self::prune();
        return [
            'success' => true,
            'message' => __('Activity Log settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Framework sections for the left navigation.
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'viewer', 'label' => __('Log', 'traydigita-core'), 'icon' => 'history'],
            ['id' => 'retention', 'label' => __('Retention', 'traydigita-core'), 'icon' => 'schedule'],
        ];
    }

    /**
     * Record a successful sign-in (wp_login).
     *
     * @param string $userLogin Username used to sign in.
     * @param WP_User $user Signed-in user.
     * @return void
     */
    public static function onLogin(string $userLogin, WP_User $user): void
    {
        self::record(
            'login',
            'login-success',
            sprintf('User %s signed in.', sanitize_text_field($userLogin)),
            (int) $user->ID
        );
    }

    /**
     * Record a failed sign-in attempt (wp_login_failed).
     *
     * Only the attempted username is logged — never a password
     * (WordPress never passes one to this hook).
     *
     * @param string $username Attempted username.
     * @return void
     */
    public static function onLoginFailed(string $username): void
    {
        self::record(
            'login',
            'login-failed',
            sprintf('Failed sign-in attempt for username "%s".', mb_substr(sanitize_text_field($username), 0, 60)),
            0
        );
    }

    /**
     * Record a two-factor verification outcome.
     *
     * Listens to the TwoFactor module's hook point
     * (`traydigita:core:two-factor:verified`, fired by
     * TwoFactorModule::logEvent() for every outcome): a
     * successful TOTP or passkey verification on sign-in, a
     * failed code, a lockout, and TOTP enrolment confirmation.
     * Two-factor authentication fires no WordPress action of
     * its own, so without this hook point these events were
     * invisible to the log. The method and the reason code are
     * stored as meta; no secret or code is ever logged.
     *
     * @param int $userId User id.
     * @param string $method `totp`, `backup`, `passkey`, `login`, or `all`.
     * @param string $result `success`, `failed`, or `locked`.
     * @param string $reason Machine-readable reason code.
     * @return void
     */
    public static function onTwoFactorEvent(int $userId, string $method, string $result, string $reason): void
    {
        $eventType = match ($result) {
            'success' => '2fa-success',
            'locked' => '2fa-locked',
            default => '2fa-failed',
        };
        $methodLabel = match ($method) {
            'totp' => __('authenticator app (TOTP)', 'traydigita-core'),
            'backup' => __('backup code', 'traydigita-core'),
            'passkey' => __('passkey', 'traydigita-core'),
            'login' => __('sign-in', 'traydigita-core'),
            default => __('two-factor authentication', 'traydigita-core'),
        };
        $message = match ($reason) {
            'enrolled' => __('Two-factor authenticator app (TOTP) enrolled.', 'traydigita-core'),
            'registered' => __('Passkey registered for two-factor authentication.', 'traydigita-core'),
            'regenerated' => __('Two-factor backup codes regenerated.', 'traydigita-core'),
            'disabled', 'disabled_by_cli' => __('Two-factor authentication disabled.', 'traydigita-core'),
            'removed' => __('Passkey removed from two-factor authentication.', 'traydigita-core'),
            default => match ($result) {
                'success' => sprintf(
                    /* translators: %s is the two-factor method, e.g. "authenticator app (TOTP)". */
                    __('Two-factor verification succeeded via %s.', 'traydigita-core'),
                    $methodLabel
                ),
                'locked' => __('Two-factor verification locked out after too many failed attempts.', 'traydigita-core'),
                default => sprintf(
                    /* translators: %s is the two-factor method, e.g. "authenticator app (TOTP)". */
                    __('Two-factor verification failed via %s.', 'traydigita-core'),
                    $methodLabel
                ),
            },
        };
        self::record('two-factor', $eventType, $message, $userId, ['method' => $method, 'reason' => $reason]);
    }

    /**
     * Render the settings page (viewer + retention) inside the admin shell.
     *
     * Locates `templates/admin/modules/activity-log.php` and
     * requires it with the retention settings, the table status,
     * the filtered rows, and the filter values. The viewer
     * filters come from the URL query string, sanitized here.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        $filters = [
            'module' => isset($_GET['log_module']) ? sanitize_key(wp_unslash($_GET['log_module'])) : '',
            'event_type' => isset($_GET['log_event']) ? sanitize_key(wp_unslash($_GET['log_event'])) : '',
            'user_id' => isset($_GET['log_user']) && is_numeric($_GET['log_user']) ? absint($_GET['log_user']) : 0,
            'from' => isset($_GET['log_from']) ? sanitize_text_field(wp_unslash($_GET['log_from'])) : '',
            'to' => isset($_GET['log_to']) ? sanitize_text_field(wp_unslash($_GET['log_to'])) : '',
            'search' => isset($_GET['log_search']) ? sanitize_text_field(wp_unslash($_GET['log_search'])) : '',
            'page' => isset($_GET['log_page']) && is_numeric($_GET['log_page']) ? absint($_GET['log_page']) : 1,
            'per_page' => self::PER_PAGE,
        ];
        $result = self::query($filters);
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $activitySettings = self::getSettings();
        $activityTableExists = self::tableExists();
        $activityTableVersion = self::tableVersion();
        $activityRowCount = self::countAll();
        $activityRows = $result['rows'];
        $activityTotal = $result['total'];
        $activityPage = max(1, (int) $filters['page']);
        $activityPerPage = self::PER_PAGE;
        $activityFilters = $filters;
        $activityModules = self::distinctModules();
        $activityEvents = self::distinctEvents();
        $activityUserIds = self::distinctUserIds();
        $activityUsers = [];
        foreach ($activityUserIds as $userId) {
            $user = get_userdata($userId);
            $activityUsers[$userId] = $user instanceof WP_User ? $user->display_name : sprintf('User #%d', $userId);
        }
        $activityNonceAction = 'traydigita-admin';
        $activityCreateAction = 'traydigita_setup_create_activity_log';
        $activityDeleteAction = ActivityLogAjax::ACTION_DELETE;
        $activityClearAction = ActivityLogAjax::ACTION_CLEAR;
        $activitySaveAction = 'traydigita_admin_save';
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/activity-log.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }
}
