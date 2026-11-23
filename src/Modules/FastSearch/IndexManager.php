<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FastSearch;

use function array_keys;
use function array_values;
use function count;
use function delete_transient;
use function get_option;
use function get_transient;
use function is_array;
use function is_string;
use function set_transient;
use function time;
use function update_option;
use function wp_generate_password;

/**
 * Index definitions, status checks, and chunked index builds.
 *
 * Own implementation of the index design from the reference gist
 * (TrayDigita WP Fast Search): a FULLTEXT index
 * `idx_td_posts_fulltext` on (post_title, post_content,
 * post_excerpt), a composite `type_status_date` index on posts, a
 * FULLTEXT index on terms.name, and two join indexes on the term
 * relationship / taxonomy tables.
 *
 * All DDL is fixed SQL built here from the definitions — table
 * names come from `$wpdb` itself, index names and columns are
 * literals in this file, and no user input ever reaches DDL.
 * Index builds run one index per AJAX request (see
 * FastSearchAjax) so a large posts table cannot time out the
 * admin page; a transient lock plus a job transient guarantee
 * only one build runs at a time.
 *
 * The "ready" flag (both FULLTEXT indexes installed) is cached
 * in an option so the frontend `posts_search` filter never has
 * to query information_schema on a search request.
 */
final class IndexManager
{
    /**
     * Option caching whether the FULLTEXT indexes are ready.
     *
     * Stores '1' or '0' (autoload `no`, like every module option).
     * Refreshed by checkStatus() and after every build.
     */
    public const string OPTION_READY = 'traydigita_fast_search_ready';

    /**
     * Transient holding the build lock: the active job token.
     */
    public const string LOCK_TRANSIENT = 'traydigita_fast_search_lock';

    /**
     * Transient holding the running build job state.
     */
    public const string JOB_TRANSIENT = 'traydigita_fast_search_job';

    /**
     * Seconds a build lock lives without progress.
     *
     * Ten minutes is generous for a single ALTER TABLE and lets a
     * crashed browser tab release the lock on its own; the job
     * transient shares the same lifetime.
     */
    public const int LOCK_TTL = 600;

    /**
     * Index definitions, in build order.
     *
     * The posts FULLTEXT index goes first (it is the one the
     * search filter needs), then the composite posts index, then
     * the term indexes. Each entry carries the table, index name,
     * human-readable columns, type, and the exact fixed SQL used
     * to create it. Table names are resolved from `$wpdb` at call
     * time so a custom table prefix works.
     *
     * @return array<string, array{table: string, name: string, columns: string, type: string, sql: string}>
     */
    public static function definitions(): array
    {
        global $wpdb;
        return [
            'posts_ft' => [
                'table' => $wpdb->posts,
                'name' => 'idx_td_posts_fulltext',
                'columns' => 'post_title, post_content, post_excerpt',
                'type' => 'FULLTEXT',
                'sql' => "ALTER TABLE `{$wpdb->posts}` ADD FULLTEXT INDEX `idx_td_posts_fulltext` (`post_title`, `post_content`, `post_excerpt`);",
            ],
            'posts_tsd' => [
                'table' => $wpdb->posts,
                'name' => 'type_status_date',
                'columns' => 'post_type, post_status, post_date, ID',
                'type' => 'INDEX',
                'sql' => "ALTER TABLE `{$wpdb->posts}` ADD INDEX `type_status_date` (`post_type`, `post_status`, `post_date`, `ID`);",
            ],
            'terms_ft' => [
                'table' => $wpdb->terms,
                'name' => 'idx_td_terms_name_ft',
                'columns' => 'name',
                'type' => 'FULLTEXT',
                'sql' => "ALTER TABLE `{$wpdb->terms}` ADD FULLTEXT INDEX `idx_td_terms_name_ft` (`name`);",
            ],
            'tr_tax_obj' => [
                'table' => $wpdb->term_relationships,
                'name' => 'idx_td_tr_tax_obj',
                'columns' => 'term_taxonomy_id, object_id',
                'type' => 'INDEX',
                'sql' => "ALTER TABLE `{$wpdb->term_relationships}` ADD INDEX `idx_td_tr_tax_obj` (`term_taxonomy_id`, `object_id`);",
            ],
            'tt_tax_term' => [
                'table' => $wpdb->term_taxonomy,
                'name' => 'idx_td_tt_tax_term',
                'columns' => 'taxonomy, term_id, term_taxonomy_id',
                'type' => 'INDEX',
                'sql' => "ALTER TABLE `{$wpdb->term_taxonomy}` ADD INDEX `idx_td_tt_tax_term` (`taxonomy`, `term_id`, `term_taxonomy_id`);",
            ],
        ];
    }

    /**
     * Definition keys, in build order.
     *
     * @return array<int, string>
     */
    public static function definitionKeys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Check which indexes exist, and refresh the cached ready flag.
     *
     * The FULLTEXT search is ready when both FULLTEXT indexes
     * (`posts_ft` and `terms_ft`) exist; the composite and join
     * indexes only speed the query up. The ready flag is stored
     * so the frontend filter can decide with a single option read.
     *
     * @return array<string, bool> Installed state per definition key.
     */
    public static function checkStatus(): array
    {
        global $wpdb;
        $definitions = self::definitions();
        $statuses = [];
        foreach ($definitions as $key => $definition) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
                    $definition['table'],
                    $definition['name']
                )
            );
            $statuses[$key] = (int) $exists > 0;
        }
        $ready = !empty($statuses['posts_ft']) && !empty($statuses['terms_ft']);
        update_option(self::OPTION_READY, $ready ? '1' : '0', false);
        return $statuses;
    }

    /**
     * Whether the FULLTEXT search is ready, from the cached flag.
     *
     * Cheap by design: the frontend filter calls this on every
     * search request, so it must never touch information_schema.
     * The flag is refreshed whenever the status page is rendered
     * and after every index build.
     *
     * @return bool
     */
    public static function isReady(): bool
    {
        return get_option(self::OPTION_READY, '0') === '1';
    }

    /**
     * Definition keys whose index is missing.
     *
     * @param array<string, bool> $statuses Output of checkStatus().
     * @return array<int, string>
     */
    public static function missingKeys(array $statuses): array
    {
        $missing = [];
        foreach (self::definitionKeys() as $key) {
            if (empty($statuses[$key])) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * Whether one index exists right now.
     *
     * @param string $key Definition key.
     * @return bool False for an unknown key.
     */
    public static function indexExists(string $key): bool
    {
        $definitions = self::definitions();
        if (!isset($definitions[$key])) {
            return false;
        }
        $statuses = self::checkStatus();
        return !empty($statuses[$key]);
    }

    /**
     * Create one index with its fixed DDL, or skip it when present.
     *
     * The `$key` is validated against the definition allowlist
     * first, so an unknown or crafted key can never reach DDL.
     * The SQL itself is the fixed string from definitions() — no
     * user input is involved at any point. The index is re-checked
     * afterwards so a "created" result means the index is really
     * there, not just that the query returned without error.
     *
     * @param string $key Definition key.
     * @return array{success: bool, skipped: bool, key: string, name: string, message: string}
     */
    public static function installIndex(string $key): array
    {
        global $wpdb;
        $definitions = self::definitions();
        if (!isset($definitions[$key])) {
            return [
                'success' => false,
                'skipped' => false,
                'key' => $key,
                'name' => '',
                'message' => 'Unknown index.',
            ];
        }
        $definition = $definitions[$key];
        if (self::indexExists($key)) {
            return [
                'success' => true,
                'skipped' => true,
                'key' => $key,
                'name' => $definition['name'],
                'message' => 'Already installed.',
            ];
        }
        $wpdb->query($definition['sql']);
        if ($wpdb->last_error !== '') {
            return [
                'success' => false,
                'skipped' => false,
                'key' => $key,
                'name' => $definition['name'],
                'message' => $wpdb->last_error,
            ];
        }
        if (!self::indexExists($key)) {
            return [
                'success' => false,
                'skipped' => false,
                'key' => $key,
                'name' => $definition['name'],
                'message' => 'The index could not be verified after creation.',
            ];
        }
        self::checkStatus();
        return [
            'success' => true,
            'skipped' => false,
            'key' => $key,
            'name' => $definition['name'],
            'message' => 'Installed.',
        ];
    }

    /**
     * Start a build job for every missing index, or null when busy.
     *
     * Acquires the transient lock and stores the job (queue of
     * missing keys, done/failed lists, owner, start time). When
     * nothing is missing there is no job to run and null is
     * returned with no lock taken. When a lock is already held,
     * null is returned so the caller can report "already running"
     * instead of starting a second build.
     *
     * @param int $userId Id of the administrator starting the build.
     * @return array{token: string, queue: array<int, string>, total: int}|null
     */
    public static function startJob(int $userId): ?array
    {
        if (get_transient(self::LOCK_TRANSIENT) !== false) {
            return null;
        }
        $missing = self::missingKeys(self::checkStatus());
        if ($missing === []) {
            return null;
        }
        $token = wp_generate_password(24, false);
        $job = [
            'token' => $token,
            'queue' => array_values($missing),
            'total' => count($missing),
            'done' => [],
            'failed' => [],
            'results' => [],
            'started' => time(),
            'started_by' => $userId,
        ];
        set_transient(self::LOCK_TRANSIENT, $token, self::LOCK_TTL);
        set_transient(self::JOB_TRANSIENT, $job, self::LOCK_TTL);
        return [
            'token' => $token,
            'queue' => array_values($missing),
            'total' => count($missing),
        ];
    }

    /**
     * Read the running job state, or null when no job is running.
     *
     * A job whose lock has expired is treated as gone (the lock
     * and the job share a lifetime, so a stale job cannot linger
     * past its lock).
     *
     * @return array{token: string, queue: array<int, string>, total: int, done: array<int, string>, failed: array<int, string>, results: array<int, array{key: string, name: string, installed: bool, message: string}>, started: int, started_by: int}|null
     */
    public static function getJob(): ?array
    {
        if (get_transient(self::LOCK_TRANSIENT) === false) {
            return null;
        }
        $job = get_transient(self::JOB_TRANSIENT);
        if (!is_array($job) || !isset($job['token']) || !is_string($job['token'])) {
            return null;
        }
        /** @var array{token: string, queue: array<int, string>, total: int, done: array<int, string>, failed: array<int, string>, results: array<int, array{key: string, name: string, installed: bool, message: string}>, started: int, started_by: int} $job */
        return $job;
    }

    /**
     * Current build progress without running anything.
     *
     * @return array{running: bool, total: int, completed: int, failed: int, done: array<int, string>, failed_keys: array<int, string>, results: array<int, array{key: string, name: string, installed: bool, message: string}>}|null Null when no job is running.
     */
    public static function jobProgress(): ?array
    {
        $job = self::getJob();
        if ($job === null) {
            return null;
        }
        return self::progressFromJob($job);
    }

    /**
     * Run the next index of a job (one index per call).
     *
     * The token must match the running job, so a stale browser
     * tab cannot resume somebody else's build. When the queue is
     * empty the lock and job are released and the ready flag is
     * refreshed; the returned progress then reports done.
     *
     * @param string $token Job token from startJob().
     * @return array{running: bool, done: bool, total: int, completed: int, failed: int, current: array{key: string, name: string, installed: bool, message: string}|null, results: array<int, array{key: string, name: string, installed: bool, message: string}>}|null Null when there is no such job.
     */
    public static function runNextChunk(string $token): ?array
    {
        $job = self::getJob();
        if ($job === null || $job['token'] !== $token) {
            return null;
        }
        $current = null;
        $next = array_shift($job['queue']);
        if (is_string($next) && $next !== '') {
            $result = self::installIndex($next);
            $current = [
                'key' => $result['key'],
                'name' => $result['name'],
                'installed' => $result['success'],
                'message' => $result['message'],
            ];
            $job['results'][] = $current;
            if ($result['success']) {
                $job['done'][] = $next;
            } else {
                $job['failed'][] = $next;
            }
        }
        $finished = $job['queue'] === [];
        if ($finished) {
            self::checkStatus();
            delete_transient(self::LOCK_TRANSIENT);
            delete_transient(self::JOB_TRANSIENT);
        } else {
            set_transient(self::JOB_TRANSIENT, $job, self::LOCK_TTL);
            set_transient(self::LOCK_TRANSIENT, $token, self::LOCK_TTL);
        }
        $progress = self::progressFromJob($job);
        $progress['done'] = $finished;
        $progress['current'] = $current;
        return $progress;
    }

    /**
     * Release a stuck build lock and job (administrator recovery).
     *
     * Only used when a build died mid-flight and the lock would
     * otherwise block a retry until its TTL expires; the lock
     * normally clears itself when the job finishes.
     *
     * @return void
     */
    public static function releaseJob(): void
    {
        delete_transient(self::LOCK_TRANSIENT);
        delete_transient(self::JOB_TRANSIENT);
    }

    /**
     * Build the shared progress shape from a stored job array.
     *
     * @param array<string, mixed> $job Stored job state.
     * @return array{running: bool, total: int, completed: int, failed: int, done: array<int, string>, failed_keys: array<int, string>, results: array<int, array{key: string, name: string, installed: bool, message: string}>}
     */
    private static function progressFromJob(array $job): array
    {
        $done = is_array($job['done'] ?? null) ? array_values($job['done']) : [];
        $failed = is_array($job['failed'] ?? null) ? array_values($job['failed']) : [];
        $results = is_array($job['results'] ?? null) ? array_values($job['results']) : [];
        return [
            'running' => true,
            'total' => (int) ($job['total'] ?? 0),
            'completed' => count($done) + count($failed),
            'failed' => count($failed),
            'done' => $done,
            'failed_keys' => $failed,
            'results' => $results,
        ];
    }
}
