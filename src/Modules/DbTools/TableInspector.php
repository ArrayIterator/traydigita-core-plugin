<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DbTools;

use function array_unique;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sort;
use function sprintf;
use function str_starts_with;
use function stripos;
use function version_compare;

/**
 * Read-only inspector for the site's database tables.
 *
 * All table names the module ever touches come from the allowlist
 * built here: the union of `$wpdb->tables()` and the rows of
 * `SHOW TABLE STATUS`, filtered to names that start with the
 * site's table prefix. A table name supplied by the user (for a
 * convert or optimize request) is accepted only when it is on
 * that allowlist, and it is then quoted with backticks — user
 * input never reaches DDL unvalidated, and no DDL is ever built
 * from raw user input.
 *
 * The inspector also owns the MyISAM to InnoDB safety check:
 * a conversion is refused when the table is not MyISAM, when the
 * table is not on the allowlist, or when the table carries a
 * FULLTEXT index that the running server's InnoDB cannot carry
 * (InnoDB FULLTEXT needs MySQL >= 5.6.4 or MariaDB >= 10.0.5).
 * A table is never converted when doing so would lose an index.
 */
final class TableInspector
{
    /**
     * Minimum MySQL version with InnoDB FULLTEXT support.
     */
    public const string MYSQL_FULLTEXT_VERSION = '5.6.4';

    /**
     * Minimum MariaDB version with InnoDB FULLTEXT support.
     */
    public const string MARIADB_FULLTEXT_VERSION = '10.0.5';

    /**
     * Build the allowlist of table names this module may touch.
     *
     * Union of `$wpdb->tables()` (core tables) and every table
     * reported by `SHOW TABLE STATUS` (which also covers plugin
     * tables), keeping only names that start with the site's
     * table prefix, sorted for stable output.
     *
     * @return array<int, string>
     */
    public static function allowedTables(): array
    {
        global $wpdb;
        $allowed = [];
        $core = $wpdb->tables();
        if (is_array($core)) {
            foreach ($core as $table) {
                if (!is_string($table)) {
                    continue;
                }
                if (str_starts_with($table, $wpdb->prefix)) {
                    $allowed[] = $table;
                }
            }
        }
        foreach (self::tableStatus() as $row) {
            $name = isset($row['Name']) && is_string($row['Name']) ? $row['Name'] : '';
            if ($name !== '' && str_starts_with($name, $wpdb->prefix)) {
                $allowed[] = $name;
            }
        }
        $allowed = array_unique($allowed);
        sort($allowed);
        return $allowed;
    }

    /**
     * Whether a table name is on the allowlist.
     *
     * @param string $table Table name to check.
     * @return bool
     */
    public static function isAllowedTable(string $table): bool
    {
        return in_array($table, self::allowedTables(), true);
    }

    /**
     * Raw `SHOW TABLE STATUS` rows, keyed by table name.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function tableStatus(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SHOW TABLE STATUS', ARRAY_A);
        $status = [];
        if (!is_array($rows)) {
            return $status;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['Name']) || !is_string($row['Name'])) {
                continue;
            }
            $status[$row['Name']] = $row;
        }
        return $status;
    }

    /**
     * Table list for the settings page: engine, rows, sizes, overhead.
     *
     * Only tables on the allowlist are listed. Sizes are raw byte
     * counts; the template formats them for display.
     *
     * @return array<int, array{name: string, engine: string, rows: int, data_length: int, index_length: int, data_free: int, total_length: int, collation: string, fulltext_indexes: array<int, string>}>
     */
    public static function getTables(): array
    {
        $status = self::tableStatus();
        $tables = [];
        foreach (self::allowedTables() as $name) {
            $row = $status[$name] ?? [];
            $dataLength = (int) ($row['Data_length'] ?? 0);
            $indexLength = (int) ($row['Index_length'] ?? 0);
            $tables[] = [
                'name' => $name,
                'engine' => isset($row['Engine']) && is_string($row['Engine']) ? $row['Engine'] : '',
                'rows' => (int) ($row['Rows'] ?? 0),
                'data_length' => $dataLength,
                'index_length' => $indexLength,
                'data_free' => (int) ($row['Data_free'] ?? 0),
                'total_length' => $dataLength + $indexLength,
                'collation' => isset($row['Collation']) && is_string($row['Collation']) ? $row['Collation'] : '',
                'fulltext_indexes' => self::getFulltextIndexes($name),
            ];
        }
        return $tables;
    }

    /**
     * FULLTEXT index names on one allowlisted table.
     *
     * The table name comes from the allowlist, so backtick-quoting
     * it here is safe; no user input reaches this query.
     *
     * @param string $table Allowlisted table name.
     * @return array<int, string>
     */
    public static function getFulltextIndexes(string $table): array
    {
        global $wpdb;
        if (!self::isAllowedTable($table)) {
            return [];
        }
        $rows = $wpdb->get_results('SHOW INDEX FROM `' . $table . "` WHERE Index_type = 'FULLTEXT'", ARRAY_A);
        $indexes = [];
        if (!is_array($rows)) {
            return $indexes;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['Key_name']) || !is_string($row['Key_name'])) {
                continue;
            }
            if (!in_array($row['Key_name'], $indexes, true)) {
                $indexes[] = $row['Key_name'];
            }
        }
        return $indexes;
    }

    /**
     * Database server version string, e.g. '8.0.36' or '10.11.6-MariaDB'.
     *
     * @return string
     */
    public static function serverVersion(): string
    {
        global $wpdb;
        $version = $wpdb->db_version();
        return is_string($version) ? $version : '';
    }

    /**
     * Whether the server is MariaDB (as opposed to MySQL).
     *
     * @param string $version Server version string.
     * @return bool
     */
    public static function isMariaDb(string $version): bool
    {
        return stripos($version, 'mariadb') !== false;
    }

    /**
     * Whether this server's InnoDB can carry FULLTEXT indexes.
     *
     * InnoDB FULLTEXT needs MySQL >= 5.6.4 or MariaDB >= 10.0.5.
     * An unparseable or empty version string is treated as
     * unsupported: the conversion is refused rather than risking
     * an index loss on an unknown server.
     *
     * @param string $version Server version string.
     * @return bool
     */
    public static function supportsInnodbFulltext(string $version): bool
    {
        if ($version === '') {
            return false;
        }
        // Strip any server suffix (e.g. '-MariaDB', '-log') before
        // comparing: version_compare() sorts '10.0.5-MariaDB'
        // below '10.0.5', which would wrongly refuse a server
        // that does support InnoDB FULLTEXT.
        if (preg_match('/^(\d+(?:\.\d+){0,3})/', $version, $matches) !== 1) {
            return false;
        }
        $numeric = $matches[1];
        if (self::isMariaDb($version)) {
            return version_compare($numeric, self::MARIADB_FULLTEXT_VERSION, '>=');
        }
        return version_compare($numeric, self::MYSQL_FULLTEXT_VERSION, '>=');
    }

    /**
     * Safety check for converting one table from MyISAM to InnoDB.
     *
     * Refuses (safe = false) with a human-readable reason when the
     * table is not on the allowlist, when its engine is not MyISAM
     * (nothing to convert), or when it carries a FULLTEXT index
     * that this server's InnoDB cannot carry. A FULLTEXT index on
     * a server that supports InnoDB FULLTEXT is safe, but the
     * result still lists the index so the admin sees what moves.
     *
     * @param string $table Table name to check.
     * @return array{safe: bool, engine: string, fulltext_indexes: array<int, string>, server_version: string, reasons: array<int, string>}
     */
    public static function checkConversion(string $table): array
    {
        $version = self::serverVersion();
        $result = [
            'safe' => false,
            'engine' => '',
            'fulltext_indexes' => [],
            'server_version' => $version,
            'reasons' => [],
        ];
        if (!self::isAllowedTable($table)) {
            $result['reasons'][] = sprintf(
                /* translators: %s is the table name. */
                __('Table %s is not one of this site’s tables.', 'traydigita-core'),
                $table
            );
            return $result;
        }
        $status = self::tableStatus();
        $row = $status[$table] ?? [];
        $engine = isset($row['Engine']) && is_string($row['Engine']) ? $row['Engine'] : '';
        $result['engine'] = $engine;
        if ($engine !== 'MyISAM') {
            $result['reasons'][] = sprintf(
                /* translators: %1$s is the table name, %2$s is its storage engine. */
                __('Table %1$s uses the %2$s engine, not MyISAM, so there is nothing to convert.', 'traydigita-core'),
                $table,
                $engine === '' ? __('unknown', 'traydigita-core') : $engine
            );
            return $result;
        }
        $fulltext = self::getFulltextIndexes($table);
        $result['fulltext_indexes'] = $fulltext;
        if ($fulltext !== [] && !self::supportsInnodbFulltext($version)) {
            $result['reasons'][] = sprintf(
                /* translators: %1$s is the table name, %2$s is the FULLTEXT index name, %3$s is the database server version. */
                __('Table %1$s has a FULLTEXT index (%2$s) that InnoDB cannot carry on this database server (%3$s). Converting would lose the index, so the conversion was refused.', 'traydigita-core'),
                $table,
                implode(', ', $fulltext),
                $version === '' ? __('unknown version', 'traydigita-core') : $version
            );
            return $result;
        }
        $result['safe'] = true;
        return $result;
    }

    /**
     * Convert one allowlisted MyISAM table to InnoDB.
     *
     * Runs the safety check first and refuses when it fails; the
     * ALTER TABLE statement is built only from the allowlisted
     * table name, never from raw user input.
     *
     * @param string $table Table name to convert.
     * @return array{success: bool, message: string, reasons: array<int, string>}
     */
    public static function convertTable(string $table): array
    {
        $check = self::checkConversion($table);
        if (!$check['safe']) {
            return [
                'success' => false,
                'message' => __('Conversion refused.', 'traydigita-core'),
                'reasons' => $check['reasons'],
            ];
        }
        global $wpdb;
        $result = $wpdb->query('ALTER TABLE `' . $table . '` ENGINE = InnoDB');
        if ($result === false) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the table name. */
                    __('Could not convert table %s to InnoDB.', 'traydigita-core'),
                    $table
                ),
                'reasons' => [],
            ];
        }
        $message = sprintf(
            /* translators: %s is the table name. */
            __('Table %s was converted to InnoDB.', 'traydigita-core'),
            $table
        );
        if ($check['fulltext_indexes'] !== []) {
            $message .= ' ' . sprintf(
                /* translators: %s is a comma-separated list of FULLTEXT index names. */
                __('Its FULLTEXT indexes (%s) were carried over by InnoDB.', 'traydigita-core'),
                implode(', ', $check['fulltext_indexes'])
            );
        }
        return [
            'success' => true,
            'message' => $message,
            'reasons' => [],
        ];
    }

    /**
     * Run OPTIMIZE TABLE on one allowlisted table.
     *
     * @param string $table Table name to optimize.
     * @return array{success: bool, message: string}
     */
    public static function optimizeTable(string $table): array
    {
        if (!self::isAllowedTable($table)) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the table name. */
                    __('Table %s is not one of this site’s tables.', 'traydigita-core'),
                    $table
                ),
            ];
        }
        global $wpdb;
        $result = $wpdb->query('OPTIMIZE TABLE `' . $table . '`');
        if ($result === false) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the table name. */
                    __('Could not optimize table %s.', 'traydigita-core'),
                    $table
                ),
            ];
        }
        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s is the table name. */
                __('Table %s was optimized.', 'traydigita-core'),
                $table
            ),
        ];
    }

    /**
     * Run OPTIMIZE TABLE on every allowlisted table.
     *
     * Tables are optimized one by one; a failure on one table is
     * recorded and the rest still run. The caller (AJAX handler)
     * holds the single-job lock while this runs.
     *
     * @return array{optimized: int, failed: array<int, string>}
     */
    public static function optimizeAll(): array
    {
        $optimized = 0;
        $failed = [];
        foreach (self::allowedTables() as $table) {
            $result = self::optimizeTable($table);
            if ($result['success']) {
                $optimized++;
            } else {
                $failed[] = $table;
            }
        }
        return [
            'optimized' => $optimized,
            'failed' => $failed,
        ];
    }
}
