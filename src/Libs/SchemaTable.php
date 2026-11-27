<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Libs;

use Closure;
use Throwable;
use function absint;
use function array_key_exists;
use function dbDelta;
use function delete_transient;
use function get_option;
use function get_transient;
use function is_admin;
use function set_transient;
use function time;
use function update_option;

/**
 * Shared kernel for the plugin's custom tables (the dbDelta FINAL pattern).
 *
 * Five tables used to carry their own copy of this pattern, almost
 * verbatim: ActivityLog, Agenda, Push tokens, Templates, and the
 * form submissions store. One class now owns it; each table keeps
 * its own SQL, version, option, and transient names and passes
 * them in through the constructor, so nothing about a table's
 * stored state changes by migrating.
 *
 * The two paths, kept strictly apart:
 * - INSERT PATH (`ensureForInsert()`): static-cached existence
 *   check, then ONE `CREATE TABLE IF NOT EXISTS` when the table
 *   is missing. No dbDelta here, no error_log, never throws - a
 *   write failure just returns false and the caller carries on.
 * - UPGRADE PATH (`ensureSchema()`): dbDelta only, version-gated
 *   and throttled by the check transient (default 3600s), with a
 *   separate lock transient (default 60s) serialising concurrent
 *   upgrades; the lock is released in a `finally` block.
 *   Admin-only: frontend requests never run dbDelta. Pass
 *   `$force` to bypass the check transient (the Setup
 *   create-table button); the lock is still respected.
 *
 * The existence cache is keyed by full table name, not a single
 * bool: a mid-request prefix change - multisite
 * `switch_to_blog()` does exactly that - re-checks the new
 * table instead of trusting the old prefix's verdict.
 */
final class SchemaTable
{
    /**
     * Default TTL of the version-check transient, in seconds (1 hour).
     */
    public const int CHECK_TTL = 3600;

    /**
     * Default TTL of the dbDelta lock transient, in seconds.
     */
    public const int LOCK_TTL = 60;

    /**
     * Whether each table was verified present in this request.
     *
     * @var array<string, bool>
     */
    private static array $existsCache = [];

    /**
     * @param string $tableSuffix Table name suffix (appended to `$wpdb->prefix`).
     * @param int $version Current table schema version.
     * @param string $versionOption Option recording the installed schema version.
     * @param string $checkTransient Transient throttling the upgrade check.
     * @param int $checkTtl TTL of the check transient, in seconds.
     * @param string $lockTransient Transient serialising concurrent upgrades.
     * @param int $lockTtl TTL of the lock transient, in seconds.
     * @param Closure $sql Table SQL builder: `fn(bool $ifNotExists): string`.
     */
    public function __construct(
        private readonly string $tableSuffix,
        private readonly int $version,
        private readonly string $versionOption,
        private readonly string $checkTransient,
        private readonly int $checkTtl,
        private readonly string $lockTransient,
        private readonly int $lockTtl,
        private readonly Closure $sql
    ) {
    }

    /**
     * Full table name (prefix + suffix).
     *
     * @return string
     */
    public function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . $this->tableSuffix;
    }

    /**
     * Whether the table exists (static-cached per request).
     *
     * At most one cheap `SHOW TABLES LIKE` per request per table;
     * later calls reuse the cached result. Pass $refresh to
     * re-check after a CREATE or a dbDelta run.
     *
     * @param bool $refresh Whether to ignore the static cache.
     * @return bool
     */
    public function exists(bool $refresh = false): bool
    {
        $table = $this->tableName();
        if (!$refresh && array_key_exists($table, self::$existsCache)) {
            return self::$existsCache[$table];
        }
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        self::$existsCache[$table] = $found;
        return $found;
    }

    /**
     * Forget the cached existence verdict for this table.
     *
     * Callers that drop the table (a "delete all data" action)
     * call this so the next check re-queries instead of trusting
     * the pre-drop verdict.
     *
     * @return void
     */
    public function invalidate(): void
    {
        unset(self::$existsCache[$this->tableName()]);
    }

    /**
     * Stored table schema version.
     *
     * @return int 0 when the table was never created.
     */
    public function storedVersion(): int
    {
        return absint(get_option($this->versionOption, 0));
    }

    /**
     * Whether the stored schema version is current.
     *
     * A single option read - no schema query - so module grids
     * and status checks stay cheap.
     *
     * @return bool
     */
    public function isCurrentVersion(): bool
    {
        return $this->storedVersion() === $this->version;
    }

    /**
     * Lazy table creation for the insert path.
     *
     * Creates the table with ONE `CREATE TABLE IF NOT EXISTS`
     * (cheap, idempotent, silent) when it is missing, then the
     * caller inserts. Never dbDelta here, never error_log,
     * never throws: any failure returns false and the caller
     * skips the insert. A table created here records the current
     * version when no version was stored yet, so the upgrade
     * path does not re-run dbDelta over an identical schema.
     *
     * @return bool True when the table exists after this call.
     */
    public function ensureForInsert(): bool
    {
        try {
            if ($this->exists()) {
                return true;
            }
            global $wpdb;
            $wpdb->query(($this->sql)(true));
            $exists = $this->exists(true);
            if ($exists && $this->storedVersion() === 0) {
                update_option($this->versionOption, $this->version, false);
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
     * 1. Non-admin callers return immediately unless $force -
     *    dbDelta never runs on frontend requests.
     * 2. A fresh check transient returns immediately with no DB
     *    query at all.
     * 3. Table present AND stored version current -> set the
     *    transient and return (no dbDelta).
     * 4. Otherwise acquire the lock transient; when it already
     *    exists an upgrade is running elsewhere, so skip.
     *    dbDelta runs inside try/finally so the lock is always
     *    released, even when dbDelta throws; a throwing dbDelta
     *    reports false instead of breaking the admin page.
     * The check transient is set after the attempt whatever the
     * outcome, so a failed upgrade is retried at most once an hour.
     *
     * @param bool $force Bypass the version transient (Setup button).
     * @return bool True when the table exists with the current version,
     *     or when the check was legitimately skipped (not admin,
     *     fresh transient, lock held).
     */
    public function ensureSchema(bool $force = false): bool
    {
        try {
            if (!$force && !is_admin()) {
                return true;
            }
            if (!$force && get_transient($this->checkTransient) !== false) {
                return true;
            }
            if ($this->exists() && $this->storedVersion() === $this->version) {
                set_transient($this->checkTransient, '1', $this->checkTtl);
                return true;
            }
            if (get_transient($this->lockTransient) !== false) {
                return true;
            }
            set_transient($this->lockTransient, time(), $this->lockTtl);
            $exists = false;
            try {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                dbDelta(($this->sql)(false));
                $exists = $this->exists(true);
                if ($exists) {
                    update_option($this->versionOption, $this->version, false);
                }
            } catch (Throwable) {
                $exists = false;
            } finally {
                delete_transient($this->lockTransient);
            }
            set_transient($this->checkTransient, '1', $this->checkTtl);
            return $exists;
        } catch (Throwable) {
            return false;
        }
    }
}
