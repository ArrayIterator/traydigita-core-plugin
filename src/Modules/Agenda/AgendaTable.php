<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Agenda;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TrayDigita\WP\Plugin\Core\Libs\SchemaTable;
use function __;
use function absint;
use function array_merge;
use function delete_option;
use function delete_transient;
use function esc_url_raw;
use function get_post;
use function implode;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function mb_substr;
use function min;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sprintf;
use function trim;
use function wp_timezone;

/**
 * Storage layer for agenda events.
 *
 * Events live in their own custom table
 * `$wpdb->prefix . 'traydigita_agenda'` - never in `wp_posts` - so
 * the agenda is independent of the post workflow (no post statuses,
 * no revisions, no trash). Table handling lives in the shared
 * `SchemaTable` kernel: the table is created lazily with
 * CREATE TABLE IF NOT EXISTS on the first write (see
 * ensureTableExists()) and upgraded with dbDelta() only when the
 * schema version changes (see ensureTable()).
 *
 * All datetimes are stored as `DATETIME` wall-clock values in the
 * site timezone (`wp_timezone()`), the same convention WordPress
 * uses for `post_date`. Conversion to UTC happens only at the
 * boundary that needs it (the iCal export, see IcsExport).
 *
 * Event status (`upcoming` / `ongoing` / `past`) is COMPUTED from
 * the datetimes, never stored: it is derived by computeStatus()
 * and by the SQL filters in list() / upcoming(), so a status can
 * never go stale.
 *
 * Security: every query uses $wpdb placeholders. Table and column
 * names come only from fixed constants and the $wpdb prefix -
 * never from user input - and no user input ever reaches DDL.
 */
final class AgendaTable
{
    /**
     * Table name suffix (appended to `$wpdb->prefix`).
     */
    public const string TABLE_SUFFIX = 'traydigita_agenda';

    /**
     * Current database schema version.
     *
     * Version 2 replaces the single-column `start_datetime`
     * and `category` keys with the composites `start_end`
     * (start_datetime, end_datetime) and `category_start`
     * (category, start_datetime): the status filters compare
     * both datetimes and the category filter orders by
     * start_datetime, so the composites serve filter + sort
     * from the index. Each old single is the leftmost prefix
     * of its composite, so no lookup is lost.
     * Version 3 drops the legacy single-column
     * `start_datetime` and `category` keys left behind by
     * version 2 (dbDelta never drops an index).
     */
    public const int DB_VERSION = 3;

    /**
     * Option flag recording the installed schema version.
     *
     * When this option equals DB_VERSION, the schema is current
     * and the upgrade check below does nothing.
     */
    public const string DB_VERSION_OPTION = 'traydigita_agenda_db_version';

    /**
     * Transient throttling the schema-upgrade check.
     *
     * When present, ensureTable() returns without touching the
     * database at all. Set for one hour after every check,
     * whatever the outcome.
     */
    public const string VERSION_CHECK_TRANSIENT = 'traydigita_agenda_db_version_check';

    /**
     * How long the version-check throttle lives, in seconds.
     */
    public const int VERSION_CHECK_TTL = 3600;

    /**
     * Transient serialising concurrent schema upgrades.
     *
     * Held while dbDelta runs; a second request that finds it
     * skips its own upgrade instead of racing the first.
     */
    public const string DELTA_LOCK_TRANSIENT = 'traydigita_db_delta_lock_agenda';

    /**
     * How long the upgrade lock lives, in seconds.
     */
    public const int DELTA_LOCK_TTL = 60;

    /**
     * Status: the event has not started yet.
     */
    public const string STATUS_UPCOMING = 'upcoming';

    /**
     * Status: the event is running right now.
     */
    public const string STATUS_ONGOING = 'ongoing';

    /**
     * Status: the event has finished.
     */
    public const string STATUS_PAST = 'past';

    /**
     * Statuses allowed as a list filter.
     *
     * @var array<int, string>
     */
    public const array STATUSES = [
        self::STATUS_UPCOMING,
        self::STATUS_ONGOING,
        self::STATUS_PAST,
    ];

    /**
     * Maximum length of the title, location, and category columns.
     */
    public const int TITLE_MAX_LENGTH = 255;

    /**
     * Maximum length of the link column.
     */
    public const int LINK_MAX_LENGTH = 2048;

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
     * Shared table kernel for this class's table.
     */
    private static ?SchemaTable $schema = null;

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
     * DDL for the agenda table.
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
                title varchar(255) NOT NULL DEFAULT \'\',
                description longtext NOT NULL,
                start_datetime datetime NOT NULL,
                end_datetime datetime DEFAULT NULL,
                location varchar(255) NOT NULL DEFAULT \'\',
                category varchar(100) NOT NULL DEFAULT \'\',
                cover_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
                link varchar(2048) NOT NULL DEFAULT \'\',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY start_end (start_datetime, end_datetime),
                KEY category_start (category, start_datetime)
            ) %s;',
            $guard,
            $table,
            $charset
        );
    }

    /**
     * Make sure the agenda table exists, lazily.
     *
     * Called from the write path (insert/update/delete) only -
     * never from a page load. When the table is missing it is
     * created with one idempotent CREATE TABLE IF NOT EXISTS
     * and the write then proceeds, so no event is ever lost to
     * a deleted table. No dbDelta, no error_log, no throw.
     *
     * @return bool True when the table exists after this call.
     */
    public static function ensureTableExists(): bool
    {
        return self::schema()->ensureForInsert();
    }

    /**
     * Upgrade the agenda table schema when the version changed.
     *
     * See `SchemaTable::ensureSchema()` for the gating: dbDelta
     * runs at most once an hour behind the version-check
     * transient, a separate transient lock (60s) serialises
     * concurrent upgrades and is released in a `finally` block,
     * and the path is admin-only. Pass $force to bypass the
     * hourly check (the Setup create-table button); the lock is
     * still respected. Table creation itself never happens here:
     * the lazy write path (ensureTableExists(), called from
     * insert/update/delete) creates a missing table with
     * CREATE TABLE IF NOT EXISTS.
     * After a successful version bump the legacy
     * single-column indexes left by version 2 are dropped
     * (see dropLegacyIndexes()).
     *
     * @param bool $force Bypass the hourly version-check throttle.
     * @return bool True when the table exists after this call.
     */
    public static function ensureTable(bool $force = false): bool
    {
        $before = self::schema()->storedVersion();
        $ok = self::schema()->ensureSchema($force);
        if ($ok && $before < self::DB_VERSION && self::schema()->isCurrentVersion()) {
            self::dropLegacyIndexes();
        }
        return $ok;
    }

    /**
     * Drop the legacy single-column indexes left by version 2.
     *
     * dbDelta adds indexes but never drops them, so installs
     * upgraded from version 1 kept the `start_datetime` and
     * `category` singles alongside the version 2 composites.
     * Each legacy key is dropped only when it still exists AND
     * the composite that covers it is present, so a failed
     * composite creation can never leave a query without an
     * index. The list queries were verified against this:
     * `start_datetime` is filtered (alone or with
     * `end_datetime`) and ordered by in every status filter,
     * so `start_end` covers it as its leftmost prefix, and
     * the category filter orders by `start_datetime`, so
     * `category_start` covers it. Never throws, never
     * error_logs - a leftover duplicate is harmless.
     *
     * @return void
     */
    private static function dropLegacyIndexes(): void
    {
        try {
            global $wpdb;
            $table = self::tableName();
            $existing = [];
            $rows = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (isset($row['Key_name']) && is_string($row['Key_name'])) {
                        $existing[$row['Key_name']] = true;
                    }
                }
            }
            $drops = [
                'start_datetime' => 'start_end',
                'category' => 'category_start',
            ];
            foreach ($drops as $legacy => $composite) {
                if (isset($existing[$legacy]) && isset($existing[$composite])) {
                    $wpdb->query("ALTER TABLE {$table} DROP INDEX {$legacy}");
                }
            }
        } catch (Throwable) {
            // Silent: a leftover duplicate index is harmless.
        }
    }

    /**
     * Whether the agenda table is installed.
     *
     * A single option read - no schema query - so the module grid
     * stays cheap.
     *
     * @return bool
     */
    public static function isInstalled(): bool
    {
        return self::schema()->isCurrentVersion();
    }

    /**
     * Compute an event's status from its datetimes.
     *
     * Boundaries (inclusive start, exclusive end): an event is
     * `ongoing` while `start <= now < end`, `upcoming` while
     * `now < start`, and `past` once `now >= end`. An event
     * without an end datetime ends when it starts, so it is
     * `past` from its start time onward and never `ongoing`.
     *
     * @param array<string, mixed> $event Event row (start_datetime /
     *     end_datetime as 'Y-m-d H:i:s' wall-clock strings in the site timezone).
     * @param DateTimeImmutable|null $now Reference time (site timezone); defaults to now.
     * @return string One of the STATUS_* constants.
     */
    public static function computeStatus(array $event, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now', self::siteTimezone());
        $start = self::parseStoredDatetime((string) ($event['start_datetime'] ?? ''));
        if ($start === null) {
            return self::STATUS_PAST;
        }
        if ($now < $start) {
            return self::STATUS_UPCOMING;
        }
        $endRaw = (string) ($event['end_datetime'] ?? '');
        if ($endRaw === '') {
            return self::STATUS_PAST;
        }
        $end = self::parseStoredDatetime($endRaw);
        if ($end === null) {
            return self::STATUS_PAST;
        }
        return $now < $end ? self::STATUS_ONGOING : self::STATUS_PAST;
    }

    /**
     * Site timezone object.
     *
     * @return DateTimeZone
     */
    public static function siteTimezone(): DateTimeZone
    {
        return wp_timezone();
    }

    /**
     * Parse a datetime-local form value (`Y-m-d\TH:i`) or a stored
     * value (`Y-m-d H:i:s` / `Y-m-d H:i`) in the site timezone.
     *
     * Strict: the value must round-trip exactly through the format,
     * so impossible dates such as 2026-02-30 or hour 25 are
     * rejected instead of silently overflowing into another date.
     *
     * @param string $value Raw datetime value.
     * @return DateTimeImmutable|null Parsed datetime, or null when invalid.
     */
    public static function parseDatetime(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $timezone = self::siteTimezone();
        $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            if ($parsed === false) {
                continue;
            }
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }
            if ($parsed->format($format) === $value) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * Parse a stored `Y-m-d H:i:s` wall-clock value in the site timezone.
     *
     * @param string $value Stored datetime value.
     * @return DateTimeImmutable|null
     */
    public static function parseStoredDatetime(string $value): ?DateTimeImmutable
    {
        return self::parseDatetime($value);
    }

    /**
     * Format a datetime for storage (`Y-m-d H:i:s`, site timezone).
     *
     * @param DateTimeImmutable $datetime Datetime to store.
     * @return string
     */
    public static function formatForStorage(DateTimeImmutable $datetime): string
    {
        return $datetime->setTimezone(self::siteTimezone())->format('Y-m-d H:i:s');
    }

    /**
     * Format a datetime for a `datetime-local` form input (`Y-m-d\TH:i`).
     *
     * @param DateTimeImmutable $datetime Datetime to format.
     * @return string
     */
    public static function formatForInput(DateTimeImmutable $datetime): string
    {
        return $datetime->setTimezone(self::siteTimezone())->format('Y-m-d\TH:i');
    }

    /**
     * Validate and sanitize raw event input.
     *
     * Every field is sanitized for its own type: title / location /
     * category with sanitize_text_field() and length caps,
     * description with sanitize_textarea_field() (newlines are
     * kept - callers must NOT route this through the generic admin
     * dispatcher, which collapses newlines; AgendaAjax reads the
     * raw POST for this reason), datetimes with strict parsing in
     * the site timezone, the cover image id with absint() plus an
     * attachment-existence check, and the link with esc_url_raw().
     *
     * @param array<string, mixed> $data Raw input (usually from $_POST).
     * @return array{event: array<string, mixed>, errors: array<string, string>}
     *     Clean event fields plus per-field errors (empty when valid).
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = __('Title is required.', 'traydigita-core');
        } elseif (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            $errors['title'] = __('Title is too long.', 'traydigita-core');
        }
        $description = sanitize_textarea_field((string) ($data['description'] ?? ''));
        $location = sanitize_text_field((string) ($data['location'] ?? ''));
        if (mb_strlen($location) > self::TITLE_MAX_LENGTH) {
            $errors['location'] = __('Location is too long.', 'traydigita-core');
        }
        $category = sanitize_text_field((string) ($data['category'] ?? ''));
        if (mb_strlen($category) > 100) {
            $errors['category'] = __('Category is too long.', 'traydigita-core');
        }
        $startRaw = trim((string) ($data['start_datetime'] ?? ''));
        $start = self::parseDatetime($startRaw);
        if ($start === null) {
            $errors['start_datetime'] = __('Start date and time are required and must be a valid date.', 'traydigita-core');
        }
        $endRaw = trim((string) ($data['end_datetime'] ?? ''));
        $end = null;
        if ($endRaw !== '') {
            $end = self::parseDatetime($endRaw);
            if ($end === null) {
                $errors['end_datetime'] = __('End date and time must be a valid date.', 'traydigita-core');
            } elseif ($start !== null && $end < $start) {
                $errors['end_datetime'] = __('End date and time must not be before the start.', 'traydigita-core');
            }
        }
        $coverImageId = absint($data['cover_image_id'] ?? 0);
        if ($coverImageId > 0) {
            $attachment = get_post($coverImageId);
            if ($attachment === null || $attachment->post_type !== 'attachment') {
                $errors['cover_image_id'] = __('Cover image must be an uploaded media file.', 'traydigita-core');
                $coverImageId = 0;
            }
        }
        $link = esc_url_raw(trim((string) ($data['link'] ?? '')));
        if (mb_strlen($link) > self::LINK_MAX_LENGTH) {
            $errors['link'] = __('Link is too long.', 'traydigita-core');
            $link = mb_substr($link, 0, self::LINK_MAX_LENGTH);
        }
        $event = [
            'title' => $title,
            'description' => $description,
            'start_datetime' => $start !== null ? self::formatForStorage($start) : '',
            'end_datetime' => $end !== null ? self::formatForStorage($end) : null,
            'location' => $location,
            'category' => $category,
            'cover_image_id' => $coverImageId,
            'link' => $link,
        ];
        return ['event' => $event, 'errors' => $errors];
    }

    /**
     * Fetch one event by id, or null when it does not exist.
     *
     * The returned row carries a computed `status` key.
     *
     * @param int $id Event id.
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tableName() . ' WHERE id = %d', $id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }
        $row['status'] = self::computeStatus($row);
        return $row;
    }

    /**
     * Insert a new event.
     *
     * @param array<string, mixed> $event Validated event fields (see validate()).
     * @return int Inserted event id, or 0 on failure.
     */
    public static function insert(array $event): int
    {
        if (!self::ensureTableExists()) {
            return 0;
        }
        global $wpdb;
        $now = self::formatForStorage(new DateTimeImmutable('now', self::siteTimezone()));
        $inserted = $wpdb->insert(
            self::tableName(),
            array_merge($event, ['created_at' => $now, 'updated_at' => $now]),
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        if ($inserted === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing event.
     *
     * @param int $id Event id.
     * @param array<string, mixed> $event Validated event fields (see validate()).
     * @return bool True when a row was updated.
     */
    public static function update(int $id, array $event): bool
    {
        if ($id <= 0 || !self::ensureTableExists()) {
            return false;
        }
        global $wpdb;
        $event['updated_at'] = self::formatForStorage(new DateTimeImmutable('now', self::siteTimezone()));
        $updated = $wpdb->update(
            self::tableName(),
            $event,
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
        return $updated !== false && $updated > 0;
    }

    /**
     * Delete one event.
     *
     * @param int $id Event id.
     * @return bool True when a row was deleted.
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0 || !self::ensureTableExists()) {
            return false;
        }
        global $wpdb;
        $deleted = $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
        return $deleted !== false && $deleted > 0;
    }

    /**
     * List events with status filter, search, and pagination.
     *
     * Status filtering happens in SQL against the stored
     * datetimes (same boundaries as computeStatus()): upcoming is
     * `start_datetime > now`, ongoing is
     * `start_datetime <= now AND end_datetime > now`, past is
     * `end_datetime <= now OR (end_datetime IS NULL AND
     * start_datetime <= now)`. Search matches title, location,
     * and category with a LIKE on an escaped term. Every row in
     * the result carries a computed `status` key.
     *
     * @param array{status?: string, search?: string, page?: int,
     *     per_page?: int, order?: string, exclude_past?: bool} $args Filters.
     * @return array{events: array<int, array<string, mixed>>, total: int}
     */
    public static function list(array $args = []): array
    {
        global $wpdb;
        $table = self::tableName();
        $status = (string) ($args['status'] ?? '');
        $search = trim((string) ($args['search'] ?? ''));
        $page = max(1, absint($args['page'] ?? 1));
        $perPage = min(100, max(1, absint($args['per_page'] ?? 10)));
        $order = strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $now = self::formatForStorage(new DateTimeImmutable('now', self::siteTimezone()));
        $where = [];
        $params = [];
        if ($status === self::STATUS_UPCOMING) {
            $where[] = 'start_datetime > %s';
            $params[] = $now;
        } elseif ($status === self::STATUS_ONGOING) {
            $where[] = 'start_datetime <= %s AND end_datetime > %s';
            $params[] = $now;
            $params[] = $now;
        } elseif ($status === self::STATUS_PAST) {
            $where[] = '(end_datetime <= %s OR (end_datetime IS NULL AND start_datetime <= %s))';
            $params[] = $now;
            $params[] = $now;
        } elseif (!empty($args['exclude_past'])) {
            $where[] = '(start_datetime > %s OR (start_datetime <= %s AND end_datetime > %s))';
            $params[] = $now;
            $params[] = $now;
            $params[] = $now;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(title LIKE %s OR location LIKE %s OR category LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $countSql = 'SELECT COUNT(*) FROM ' . $table . $whereSql;
        $total = (int) $wpdb->get_var($params !== [] ? $wpdb->prepare($countSql, $params) : $countSql);
        $offset = ($page - 1) * $perPage;
        $listSql = 'SELECT * FROM ' . $table . $whereSql
            . ' ORDER BY start_datetime ' . $order . ', id ' . $order
            . ' LIMIT %d OFFSET %d';
        $listParams = array_merge($params, [$perPage, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($listSql, $listParams), ARRAY_A);
        $events = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['status'] = self::computeStatus($row);
                $events[] = $row;
            }
        }
        return ['events' => $events, 'total' => $total];
    }

    /**
     * Upcoming (and currently ongoing) events, soonest first.
     *
     * Used by the shortcode and the widget. Past events are never
     * returned here; the archive page is where past events live.
     *
     * @param int $limit Maximum events to return.
     * @param string $category Optional category filter (exact match).
     * @return array<int, array<string, mixed>>
     */
    public static function upcoming(int $limit = 5, string $category = ''): array
    {
        global $wpdb;
        $table = self::tableName();
        $limit = min(20, max(1, $limit));
        $now = self::formatForStorage(new DateTimeImmutable('now', self::siteTimezone()));
        $category = trim($category);
        if ($category !== '') {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . $table
                    . ' WHERE (start_datetime > %s OR (start_datetime <= %s AND end_datetime > %s))'
                    . ' AND category = %s ORDER BY start_datetime ASC, id ASC LIMIT %d',
                    $now,
                    $now,
                    $now,
                    $category,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . $table
                    . ' WHERE (start_datetime > %s OR (start_datetime <= %s AND end_datetime > %s))'
                    . ' ORDER BY start_datetime ASC, id ASC LIMIT %d',
                    $now,
                    $now,
                    $now,
                    $limit
                ),
                ARRAY_A
            );
        }
        $events = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['status'] = self::computeStatus($row);
                $events[] = $row;
            }
        }
        return $events;
    }

    /**
     * Distinct non-empty categories, for the admin filter and the widget form.
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        global $wpdb;
        $rows = $wpdb->get_col(
            'SELECT DISTINCT category FROM ' . self::tableName()
            . " WHERE category <> '' ORDER BY category ASC"
        );
        $categories = [];
        if (is_array($rows)) {
            foreach ($rows as $category) {
                if (is_string($category) && $category !== '') {
                    $categories[] = $category;
                }
            }
        }
        return $categories;
    }

    /**
     * Delete ALL agenda data: drop the table and remove the version flag.
     *
     * Only ever called from AgendaAjax::deleteAll() after the
     * administrator typed the confirmation word. The DROP TABLE
     * statement is fixed - the table name comes from the prefix
     * and a constant suffix, never from user input. Deactivation
     * alone never calls this: data is kept by default.
     *
     * @return void
     */
    public static function deleteAllData(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . self::tableName());
        delete_option(self::DB_VERSION_OPTION);
        delete_transient(self::VERSION_CHECK_TRANSIENT);
        self::schema()->invalidate();
    }
}
