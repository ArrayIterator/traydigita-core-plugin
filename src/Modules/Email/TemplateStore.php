<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Email;

use TrayDigita\WP\Plugin\Core\Libs\SchemaTable;
use function absint;
use function function_exists;
use function get_current_user_id;
use function get_option;
use function gmdate;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function strtotime;
use function time;
use function update_option;
use function wp_date;

/**
 * Table storage for every template kind (email, maintenance,
 * login, form, ...).
 *
 * One generic table, post-type-like: `{$wpdb->prefix}traydigita_templates`.
 * The table name is ALWAYS built from `$wpdb->prefix` — never a
 * hardcoded prefix — so the plugin works on any prefix. Every
 * query in this class resolves the name through tableName().
 *
 * Columns:
 * - id bigint auto-increment PK
 * - type varchar(32): 'email' | 'maintenance' | 'login' | 'form' | ...
 * - slug varchar(200), UNIQUE KEY (type, slug)
 * - name varchar(200)
 * - status varchar(20): 'draft' | 'published' | 'trash' | 'autosave'
 * - blocks longtext JSON (the flat-block shape for email; other
 *   types store their own design JSON later)
 * - is_default tinyint(1): one default per type
 * - author_id bigint: last editor
 * - parent_id bigint NULL: autosave rows point at the template
 *   they autosave
 * - created_at / updated_at datetime (site-timezone wall clock)
 *
 * Behaviour (post-type-like, kept simple):
 * - Draft rows are editable but never sent or used live: the
 *   send paths only ever read 'published' rows.
 * - Autosave: one row per template (status 'autosave',
 *   parent_id = template id, slug '<slug>-autosave'), upserted
 *   by parent_id. Publishing promotes the autosave content
 *   into the main row and deletes the autosave row.
 * - Trash is a supported status for future use; deleteRow()
 *   hard-deletes (the historical TemplateBuilder behaviour).
 *
 * Schema handling follows the dbDelta FINAL pattern (see
 * `Libs\SchemaTable`): the table is created lazily on the write
 * path (ensureTableExists(), ONE `CREATE TABLE IF NOT EXISTS`,
 * no dbDelta, no error_log, never throws) and upgraded with
 * dbDelta() only when the schema version changes
 * (ensureTable(), version-gated, 3600s check transient, 60s
 * lock released in `finally`, admin-only).
 *
 * Migration: migrateFromOption() imports the old option store
 * ('traydigita_email_templates') into type='email' rows, one
 * time, idempotently. The old option is NEVER deleted or
 * written after migration — it stays as the rollback source.
 * Maintenance and Login designs are migrated by
 * DesignerEngine::migrateLegacyDesign() (MUST-10), not here.
 */
final class TemplateStore
{
    /**
     * Table name suffix (appended to `$wpdb->prefix`).
     */
    public const string TABLE_SUFFIX = 'traydigita_templates';

    /**
     * Current database schema version.
     */
    public const int DB_VERSION = 1;

    /**
     * Option flag recording the installed schema version.
     */
    public const string DB_VERSION_OPTION = 'traydigita_templates_db_version';

    /**
     * Transient throttling the schema-upgrade check (1 hour).
     */
    public const string VERSION_CHECK_TRANSIENT = 'traydigita_templates_db_version_check';

    /**
     * How long the version-check throttle lives, in seconds.
     */
    public const int VERSION_CHECK_TTL = 3600;

    /**
     * Transient serialising concurrent schema upgrades.
     */
    public const string DELTA_LOCK_TRANSIENT = 'traydigita_db_delta_lock_templates';

    /**
     * How long the upgrade lock lives, in seconds.
     */
    public const int DELTA_LOCK_TTL = 60;

    /**
     * Option flag marking the option-to-table migration as done.
     *
     * Set even when there was nothing to migrate, so the legacy
     * option is read at most once.
     */
    public const string TABLE_MIGRATION_FLAG_OPTION = 'traydigita_email_templates_table_migrated';

    /**
     * Template type: email templates (the only kind migrated here).
     */
    public const string TYPE_EMAIL = 'email';

    /**
     * Template type: maintenance page designs (MUST-10).
     */
    public const string TYPE_MAINTENANCE = 'maintenance';

    /**
     * Template type: login page designs (MUST-10).
     */
    public const string TYPE_LOGIN = 'login';

    /**
     * Status: editable, never sent or used live.
     */
    public const string STATUS_DRAFT = 'draft';

    /**
     * Status: live; the only status the send paths read.
     */
    public const string STATUS_PUBLISHED = 'published';

    /**
     * Status: trashed (supported by the column; no UI yet).
     */
    public const string STATUS_TRASH = 'trash';

    /**
     * Status: autosave row (parent_id points at its template).
     */
    public const string STATUS_AUTOSAVE = 'autosave';

    /**
     * Suffix appended to a template slug for its autosave row.
     */
    public const string AUTOSAVE_SLUG_SUFFIX = '-autosave';

    /**
     * Shared table kernel for this class's table.
     */
    private static ?SchemaTable $schema = null;

    /**
     * Full table name (prefix + suffix).
     *
     * The ONLY place the table name is built. Every query in
     * this class calls this method, so a site on any prefix
     * (wp_, pdkt_, or anything else) reads and writes the same
     * table its prefix resolves to.
     *
     * @global wpdb $wpdb
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
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
                self::TABLE_SUFFIX,
                self::DB_VERSION,
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
     * @param bool $ifNotExists Add IF NOT EXISTS (lazy write path).
     * @return string
     */
    private static function schemaSql(bool $ifNotExists): string
    {
        global $wpdb;
        return self::createTableSql(self::tableName(), $wpdb->get_charset_collate(), $ifNotExists);
    }

    /**
     * DDL for the templates table.
     *
     * The DDL is a FIXED string: only the table name (prefix +
     * constant suffix) is interpolated, and no user input of any
     * kind reaches it.
     *
     * @param string $table Full table name.
     * @param string $charset Charset/collate clause.
     * @param bool $ifNotExists Add IF NOT EXISTS (lazy write path).
     * @return string
     */
    private static function createTableSql(string $table, string $charset, bool $ifNotExists = false): string
    {
        $guard = $ifNotExists ? 'IF NOT EXISTS ' : '';
        return sprintf(
            'CREATE TABLE %s%s (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type varchar(32) NOT NULL DEFAULT \'email\',
                slug varchar(200) NOT NULL DEFAULT \'\',
                name varchar(200) NOT NULL DEFAULT \'\',
                status varchar(20) NOT NULL DEFAULT \'published\',
                blocks longtext NOT NULL,
                is_default tinyint(1) NOT NULL DEFAULT 0,
                author_id bigint(20) unsigned NOT NULL DEFAULT 0,
                parent_id bigint(20) unsigned DEFAULT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY type_slug (type, slug),
                KEY type_status (type, status),
                KEY parent_id (parent_id),
                KEY is_default (is_default)
            ) %s;',
            $guard,
            $table,
            $charset
        );
    }

    /**
     * Make sure the templates table exists, lazily.
     *
     * Called from the read and write paths — never from a page
     * load that does not touch templates. When the table is
     * missing it is created with one idempotent CREATE TABLE IF
     * NOT EXISTS and the operation then proceeds, so no template
     * is ever lost to a deleted table. No dbDelta, no error_log,
     * no throw.
     *
     * @return bool True when the table exists after this call.
     */
    public static function ensureTableExists(): bool
    {
        return self::schema()->ensureForInsert();
    }

    /**
     * Upgrade the templates table schema when the version changed.
     *
     * See `SchemaTable::ensureSchema()` for the gating: dbDelta
     * runs at most once an hour behind the version-check
     * transient, a separate transient lock (60s) serialises
     * concurrent upgrades and is released in a `finally` block,
     * and the path is admin-only. Pass $force to bypass the
     * hourly check; the lock is still respected. Table creation
     * itself never happens here: the lazy paths
     * (ensureTableExists()) create a missing table with CREATE
     * TABLE IF NOT EXISTS.
     *
     * @param bool $force Bypass the hourly version-check throttle.
     * @return bool True when the table exists after this call.
     */
    public static function ensureTable(bool $force = false): bool
    {
        return self::schema()->ensureSchema($force);
    }

    /**
     * Whether the templates table schema is installed.
     *
     * A single option read — no schema query — so the module grid
     * stays cheap.
     *
     * @return bool
     */
    public static function isInstalled(): bool
    {
        return self::schema()->isCurrentVersion();
    }

    /**
     * Whether the option-to-table migration has run.
     *
     * @return bool
     */
    public static function isTableMigrated(): bool
    {
        return get_option(self::TABLE_MIGRATION_FLAG_OPTION, '') === '1';
    }

    /**
     * Format a Unix timestamp as a site-timezone datetime string.
     *
     * @param int $timestamp Unix timestamp.
     * @return string 'Y-m-d H:i:s' wall-clock value.
     */
    public static function datetimeFromTimestamp(int $timestamp): string
    {
        if (function_exists('wp_date')) {
            $formatted = wp_date('Y-m-d H:i:s', $timestamp);
            if (is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Current datetime string (site-timezone wall clock).
     *
     * @return string 'Y-m-d H:i:s'.
     */
    public static function nowDatetime(): string
    {
        return self::datetimeFromTimestamp(time());
    }

    /**
     * Decode one database row.
     *
     * The blocks JSON becomes an array (empty when the column
     * holds nothing decodable); ids become ints; created_at /
     * updated_at stay datetime strings, with Unix siblings
     * (created_at_ts / updated_at_ts) for callers that compare
     * or display them.
     *
     * @param array<string, mixed> $row Raw database row.
     * @return array<string, mixed>
     */
    private static function decodeRow(array $row): array
    {
        $blocks = isset($row['blocks']) && is_string($row['blocks']) ? json_decode($row['blocks'], true) : null;
        $createdAt = is_string($row['created_at'] ?? null) ? (string) $row['created_at'] : '';
        $updatedAt = is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : '';
        return [
            'id' => (int) ($row['id'] ?? 0),
            'type' => (string) ($row['type'] ?? self::TYPE_EMAIL),
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? self::STATUS_PUBLISHED),
            'blocks' => is_array($blocks) ? $blocks : [],
            'is_default' => !empty($row['is_default']),
            'author_id' => (int) ($row['author_id'] ?? 0),
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'created_at_ts' => $createdAt !== '' ? (int) strtotime($createdAt) : 0,
            'updated_at_ts' => $updatedAt !== '' ? (int) strtotime($updatedAt) : 0,
        ];
    }

    /**
     * Read rows of one type, optionally filtered by status.
     *
     * A null status reads every row except autosaves (autosaves
     * are editor state, never template listings). Rows come back
     * keyed by slug, oldest first.
     *
     * @param string $type Template type.
     * @param string|null $status Status filter, or null for all non-autosave rows.
     * @return array<string, array<string, mixed>> Rows keyed by slug.
     */
    public static function getRows(string $type, ?string $status = null): array
    {
        if (!self::ensureTableExists()) {
            return [];
        }
        global $wpdb;
        if ($status === null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND status != %s ORDER BY id ASC',
                    $type,
                    self::STATUS_AUTOSAVE
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND status = %s ORDER BY id ASC',
                    $type,
                    $status
                ),
                ARRAY_A
            );
        }
        if (!is_array($rows)) {
            return [];
        }
        $decoded = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decodedRow = self::decodeRow($row);
            $decoded[$decodedRow['slug']] = $decodedRow;
        }
        return $decoded;
    }

    /**
     * Read one row by type + slug, optionally filtered by status.
     *
     * @param string $type Template type.
     * @param string $slug Row slug.
     * @param string|null $status Status filter, or null for any non-autosave status.
     * @return array<string, mixed>|null
     */
    public static function getRow(string $type, string $slug, ?string $status = null): ?array
    {
        if (!self::ensureTableExists()) {
            return null;
        }
        global $wpdb;
        if ($status === null) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND slug = %s AND status != %s',
                    $type,
                    $slug,
                    self::STATUS_AUTOSAVE
                ),
                ARRAY_A
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND slug = %s AND status = %s',
                    $type,
                    $slug,
                    $status
                ),
                ARRAY_A
            );
        }
        return is_array($row) ? self::decodeRow($row) : null;
    }

    /**
     * Slug of the default row of a type ('' when none).
     *
     * The default is always a published row: a draft is never
     * the live default.
     *
     * @param string $type Template type.
     * @return string
     */
    public static function getDefaultSlug(string $type): string
    {
        if (!self::ensureTableExists()) {
            return '';
        }
        global $wpdb;
        $slug = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT slug FROM ' . self::tableName()
                    . ' WHERE type = %s AND status = %s AND is_default = 1 ORDER BY id ASC LIMIT 1',
                $type,
                self::STATUS_PUBLISHED
            )
        );
        return is_string($slug) ? $slug : '';
    }

    /**
     * Insert or update one template row.
     *
     * Upsert is by (type, slug): an existing row keeps its id,
     * created_at and is_default flag unless the caller passes
     * them explicitly. The blocks value is JSON-encoded here,
     * so callers pass the plain array. Returns the stored row,
     * or null when the table is unavailable or the write failed.
     *
     * @param string $type Template type.
     * @param string $slug Row slug.
     * @param string $name Display name.
     * @param string $status Row status.
     * @param array<int|string, mixed> $blocks Block/design data.
     * @param bool|null $isDefault New default flag, or null to keep the stored one.
     * @param int|null $parentId Parent row id (autosaves), or null.
     * @param int $authorId Last editor (0 = unknown/system).
     * @return array<string, mixed>|null
     */
    public static function upsertRow(
        string $type,
        string $slug,
        string $name,
        string $status,
        array $blocks,
        ?bool $isDefault = null,
        ?int $parentId = null,
        int $authorId = 0
    ): ?array {
        if (!self::ensureTableExists()) {
            return null;
        }
        global $wpdb;
        $encoded = json_encode($blocks);
        if (!is_string($encoded)) {
            $encoded = '[]';
        }
        $now = self::nowDatetime();
        $existing = self::getRow($type, $slug, null);
        if ($existing === null) {
            // An autosave slug can exist for the same template;
            // getRow(null) excludes autosaves, so also check the
            // exact slug in case the caller upserts an autosave.
            $exact = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND slug = %s',
                    $type,
                    $slug
                ),
                ARRAY_A
            );
            if (is_array($exact)) {
                $existing = self::decodeRow($exact);
            }
        }
        if ($existing !== null) {
            $keepDefault = $isDefault ?? (bool) $existing['is_default'];
            $result = $wpdb->update(
                self::tableName(),
                [
                    'name' => $name,
                    'status' => $status,
                    'blocks' => $encoded,
                    'is_default' => $keepDefault ? 1 : 0,
                    'author_id' => $authorId,
                    'parent_id' => $parentId,
                    'updated_at' => $now,
                ],
                ['id' => $existing['id']],
                ['%s', '%s', '%s', '%d', '%d', '%d', '%s'],
                ['%d']
            );
            if ($result === false) {
                return null;
            }
            $stored = self::getRowById($existing['id']);
            return $stored ?? $existing;
        }
        $result = $wpdb->insert(
            self::tableName(),
            [
                'type' => $type,
                'slug' => $slug,
                'name' => $name,
                'status' => $status,
                'blocks' => $encoded,
                'is_default' => $isDefault === true ? 1 : 0,
                'author_id' => $authorId,
                'parent_id' => $parentId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
        );
        if ($result === false) {
            return null;
        }
        $stored = self::getRowById((int) $wpdb->insert_id);
        return $stored;
    }

    /**
     * Read one row by its numeric id.
     *
     * @param int $id Row id.
     * @return array<string, mixed>|null
     */
    private static function getRowById(int $id): ?array
    {
        if ($id <= 0 || !self::ensureTableExists()) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tableName() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        return is_array($row) ? self::decodeRow($row) : null;
    }

    /**
     * Replace the published rows of one type with a given set.
     *
     * This is the table form of the old whole-option save: every
     * template in $templates is upserted as published, published
     * rows of the type whose slug is missing from the set are
     * deleted, and is_default is set exactly from $defaultSlug.
     * Draft and autosave rows are never touched: they are not
     * part of the published set this method replaces.
     *
     * @param string $type Template type.
     * @param array<string, array<string, mixed>> $templates Templates keyed by slug (id, name, blocks, created_at).
     * @param string $defaultSlug Slug of the default row ('' clears it).
     * @return void
     */
    public static function replacePublishedRows(string $type, array $templates, string $defaultSlug): void
    {
        if (!self::ensureTableExists()) {
            return;
        }
        $authorId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $kept = [];
        foreach ($templates as $slug => $template) {
            if (!is_array($template)) {
                continue;
            }
            $slug = (string) $slug;
            if ($slug === '') {
                continue;
            }
            $blocks = isset($template['blocks']) && is_array($template['blocks']) ? $template['blocks'] : [];
            $name = isset($template['name']) && is_string($template['name']) ? $template['name'] : '';
            $stored = self::upsertRow(
                $type,
                $slug,
                $name,
                self::STATUS_PUBLISHED,
                $blocks,
                $slug === $defaultSlug,
                null,
                $authorId
            );
            if ($stored !== null) {
                $kept[] = $slug;
            }
        }
        global $wpdb;
        $published = self::getRows($type, self::STATUS_PUBLISHED);
        foreach ($published as $slug => $row) {
            if (!in_array($slug, $kept, true)) {
                $wpdb->delete(self::tableName(), ['id' => $row['id']], ['%d']);
            }
        }
        if ($defaultSlug === '') {
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE ' . self::tableName() . ' SET is_default = 0 WHERE type = %s',
                    $type
                )
            );
        }
    }

    /**
     * Mark one row the default of its type.
     *
     * Clears the flag on every other row of the type first, so
     * there is at most one default per type. Only a published
     * row can become the default.
     *
     * @param string $type Template type.
     * @param string $slug Row slug.
     * @return bool True when the row exists and was marked default.
     */
    public static function setDefault(string $type, string $slug): bool
    {
        if (!self::ensureTableExists()) {
            return false;
        }
        $row = self::getRow($type, $slug, self::STATUS_PUBLISHED);
        if ($row === null) {
            return false;
        }
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::tableName() . ' SET is_default = 0 WHERE type = %s',
                $type
            )
        );
        $result = $wpdb->update(
            self::tableName(),
            ['is_default' => 1],
            ['id' => $row['id']],
            ['%d'],
            ['%d']
        );
        return $result !== false && $result > 0;
    }

    /**
     * Delete one template row and its autosave row.
     *
     * A hard delete (the historical TemplateBuilder behaviour);
     * the 'trash' status exists for a future soft-delete UI.
     *
     * @param string $type Template type.
     * @param string $slug Row slug.
     * @return bool True when a row was deleted.
     */
    public static function deleteRow(string $type, string $slug): bool
    {
        if (!self::ensureTableExists()) {
            return false;
        }
        global $wpdb;
        $deleted = false;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id FROM ' . self::tableName() . ' WHERE type = %s AND slug = %s',
                $type,
                $slug
            ),
            ARRAY_A
        );
        if (is_array($row) && isset($row['id'])) {
            $result = $wpdb->delete(self::tableName(), ['id' => (int) $row['id']], ['%d']);
            $deleted = $result !== false && $result > 0;
            $wpdb->delete(
                self::tableName(),
                ['parent_id' => (int) $row['id'], 'status' => self::STATUS_AUTOSAVE],
                ['%d', '%s']
            );
        }
        return $deleted;
    }

    /**
     * Autosave slug for a template slug.
     *
     * @param string $slug Template slug.
     * @return string
     */
    public static function autosaveSlug(string $slug): string
    {
        return $slug . self::AUTOSAVE_SLUG_SUFFIX;
    }

    /**
     * Write (upsert) the autosave row of a template.
     *
     * One autosave row per template, found by parent_id: an
     * existing autosave is updated, otherwise a new row is
     * inserted with slug '<slug>-autosave'. Returns the stored
     * autosave row, or null when the parent row does not exist
     * or the write failed.
     *
     * @param string $type Template type.
     * @param int $parentId Template row id.
     * @param string $slug Template slug.
     * @param string $name Display name.
     * @param array<int|string, mixed> $blocks Block/design data.
     * @param int $authorId Last editor.
     * @return array<string, mixed>|null
     */
    public static function saveAutosave(
        string $type,
        int $parentId,
        string $slug,
        string $name,
        array $blocks,
        int $authorId = 0
    ): ?array {
        if ($parentId <= 0 || !self::ensureTableExists()) {
            return null;
        }
        global $wpdb;
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND parent_id = %d AND status = %s',
                $type,
                $parentId,
                self::STATUS_AUTOSAVE
            ),
            ARRAY_A
        );
        if (is_array($existing)) {
            $decoded = self::decodeRow($existing);
            return self::upsertRow(
                $type,
                $decoded['slug'],
                $name,
                self::STATUS_AUTOSAVE,
                $blocks,
                false,
                $parentId,
                $authorId
            );
        }
        return self::upsertRow(
            $type,
            self::autosaveSlug($slug),
            $name,
            self::STATUS_AUTOSAVE,
            $blocks,
            false,
            $parentId,
            $authorId
        );
    }

    /**
     * Read the autosave row of a template, or null.
     *
     * @param string $type Template type.
     * @param int $parentId Template row id.
     * @return array<string, mixed>|null
     */
    public static function getAutosave(string $type, int $parentId): ?array
    {
        if ($parentId <= 0 || !self::ensureTableExists()) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' WHERE type = %s AND parent_id = %d AND status = %s',
                $type,
                $parentId,
                self::STATUS_AUTOSAVE
            ),
            ARRAY_A
        );
        return is_array($row) ? self::decodeRow($row) : null;
    }

    /**
     * Delete the autosave row of a template.
     *
     * @param string $type Template type.
     * @param int $parentId Template row id.
     * @return bool True when a row was deleted.
     */
    public static function deleteAutosave(string $type, int $parentId): bool
    {
        if ($parentId <= 0 || !self::ensureTableExists()) {
            return false;
        }
        global $wpdb;
        $result = $wpdb->delete(
            self::tableName(),
            ['parent_id' => $parentId, 'status' => self::STATUS_AUTOSAVE],
            ['%d', '%s']
        );
        return $result !== false && $result > 0;
    }

    /**
     * Migrate the old option store into the table.
     *
     * One time, idempotent: guarded by the flag option (set
     * even when there was nothing to migrate) and by a
     * per-slug existence check, so a second run imports nothing
     * twice. Every template becomes a type='email',
     * status='published' row with slug = template id, and the
     * old default_id keeps its is_default flag. The old option
     * is NEVER deleted or written here — it stays as the
     * rollback source.
     *
     * Only email templates are migrated here; Maintenance and
     * Login designs are migrated by
     * DesignerEngine::migrateLegacyDesign().
     *
     * @param array<string, array<string, mixed>> $templates Option templates keyed by id.
     * @param string $defaultId Old default template id.
     * @return array{already_migrated: bool, imported: int, skipped: int}
     */
    public static function migrateFromOption(array $templates, string $defaultId): array
    {
        if (self::isTableMigrated()) {
            return ['already_migrated' => true, 'imported' => 0, 'skipped' => 0];
        }
        if (!self::ensureTableExists()) {
            return ['already_migrated' => false, 'imported' => 0, 'skipped' => 0];
        }
        $authorId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $imported = 0;
        $skipped = 0;
        foreach ($templates as $id => $template) {
            if (!is_string($id) || $id === '' || !is_array($template)) {
                $skipped++;
                continue;
            }
            $blocks = isset($template['blocks']) && is_array($template['blocks']) ? $template['blocks'] : [];
            $name = isset($template['name']) && is_string($template['name']) ? $template['name'] : '';
            if (self::getRow(self::TYPE_EMAIL, $id, null) !== null) {
                $skipped++;
                continue;
            }
            $createdAt = absint($template['created_at'] ?? 0);
            $stored = self::upsertRow(
                self::TYPE_EMAIL,
                $id,
                $name,
                self::STATUS_PUBLISHED,
                $blocks,
                $id === $defaultId,
                null,
                $authorId
            );
            if ($stored === null) {
                $skipped++;
                continue;
            }
            if ($createdAt > 0) {
                global $wpdb;
                $wpdb->update(
                    self::tableName(),
                    ['created_at' => self::datetimeFromTimestamp($createdAt)],
                    ['id' => $stored['id']],
                    ['%s'],
                    ['%d']
                );
            }
            $imported++;
        }
        update_option(self::TABLE_MIGRATION_FLAG_OPTION, '1', false);
        return ['already_migrated' => false, 'imported' => $imported, 'skipped' => $skipped];
    }
}
