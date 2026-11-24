<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FastSearch;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use WP_CLI;
use WP_Query;
use function __;
use function add_action;
use function add_filter;
use function array_merge;
use function current_user_can;
use function defined;
use function esc_html__;
use function file_exists;
use function get_option;
use function implode;
use function is_admin;
use function is_array;
use function trim;
use function update_option;

/**
 * Fast Search module.
 *
 * The theme's own implementation of the index design from the
 * reference gist (TrayDigita WP Fast Search): a FULLTEXT index
 * `idx_td_posts_fulltext` on (post_title, post_content,
 * post_excerpt), a FULLTEXT index on terms.name, and join
 * indexes on the term relationship / taxonomy tables. The
 * post-type/status/date filtering is served by WordPress
 * core's own `type_status_date` index on `wp_posts` — it is
 * deliberately NOT redefined here. The frontend search then runs
 * `MATCH ... AGAINST (... IN BOOLEAN MODE)` (words cleaned of
 * boolean operators, minimum two characters, `+word*` tokens)
 * and also matches posts through their category / tag names.
 *
 * While the FULLTEXT indexes are missing, the module is a safe
 * no-op: the standard WordPress search runs untouched, so search
 * never breaks and never returns empty results because of a
 * missing index. Index builds run from the settings page, one
 * index per AJAX request behind a transient lock (see
 * IndexManager / FastSearchAjax), or from WP-CLI
 * (`wp td-search install`), which is the recommended route on
 * large sites. Index builds never run on a frontend request.
 *
 * Settings live in the single option `traydigita_fast_search`
 * (autoload `no`): `fulltext_search` (use the FULLTEXT search
 * once the indexes are ready) and `limit_to_posts` (frontend
 * search covers posts only). Both default to on.
 */
final class FastSearchModule implements ModuleInterface
{
    /**
     * Option name for all Fast Search settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_fast_search';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

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
        return 'fast-search';
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'performance';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Fast Search', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Speed up frontend search with FULLTEXT and composite database indexes, with a safe fallback to standard WordPress search while the indexes are missing.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'search';
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
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('FULLTEXT and composite database indexes speed up frontend search.', 'traydigita-core'),
            __('Safe fallback to standard WordPress search while indexes are missing.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-fast-search';
    }

    /**
     * Whether the module can run (no hard dependencies).
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
     * Configured means the FULLTEXT indexes are installed, i.e.
     * the fast search can actually run. The cached ready flag is
     * used here (a single option read), never a live
     * information_schema query, so the module grid stays cheap.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return IndexManager::isReady();
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'indexes', 'label' => __('Index status', 'traydigita-core'), 'icon' => 'search'],
            ['id' => 'sql', 'label' => __('Missing SQL statements', 'traydigita-core'), 'icon' => 'media-code'],
            ['id' => 'behaviour', 'label' => __('Search behaviour', 'traydigita-core'), 'icon' => 'admin-settings'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Locates `templates/admin/modules/fast-search.php` and
     * requires it with the settings, the index definitions and
     * their live statuses, the cached ready flag, the missing
     * indexes' SQL (for manual installation), any running build
     * job, and the nonce / AJAX action names.
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
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $fastSearchSettings = self::getSettings();
        $fastSearchDefinitions = IndexManager::definitions();
        $fastSearchStatuses = IndexManager::checkStatus();
        $fastSearchReady = IndexManager::isReady();
        $missingSql = [];
        foreach ($fastSearchDefinitions as $key => $definition) {
            if (empty($fastSearchStatuses[$key])) {
                $missingSql[] = $definition['sql'];
            }
        }
        $fastSearchMissingSql = implode("\n", $missingSql);
        $fastSearchJob = IndexManager::jobProgress();
        $fastSearchNonceAction = self::NONCE_ACTION;
        $fastSearchStartAction = FastSearchAjax::AJAX_START;
        $fastSearchChunkAction = FastSearchAjax::AJAX_CHUNK;
        $fastSearchStatusAction = FastSearchAjax::AJAX_STATUS;
        $fastSearchSaveAction = 'traydigita_admin_save';
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/fast-search.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `fulltext_search`,
     * `limit_to_posts` (each `1` when checked; the form always
     * submits a hidden `0` first, so an unchecked box arrives as
     * `0`). Every value is cast to boolean explicitly. Unknown
     * keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Fast Search settings.', 'traydigita-core'),
            ];
        }
        $settings = [
            'fulltext_search' => !empty($data['fulltext_search']),
            'limit_to_posts' => !empty($data['limit_to_posts']),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Fast Search settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Registers the frontend search hooks (`pre_get_posts` to
     * limit search to posts, `posts_search` for the FULLTEXT
     * clause), the chunked index-build AJAX endpoints, and the
     * `wp td-search` WP-CLI command when WP-CLI is running. Each
     * frontend callback re-checks the settings and the cached
     * ready flag at run time, so disabling the search or losing
     * the indexes takes effect immediately.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('pre_get_posts', [self::class, 'optimizeSearchQuery']);
        add_filter('posts_search', [self::class, 'filterPostsSearch'], 10, 2);
        FastSearchAjax::register();
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('td-search', FastSearchCli::class);
        }
    }

    /**
     * Limit frontend search queries to posts.
     *
     * Only the main frontend search query is touched, and only
     * when no post type was requested explicitly: a search that
     * already asks for pages or a custom post type keeps exactly
     * what it asked for. Admin queries are never touched.
     *
     * @param WP_Query $query The query being prepared.
     * @return void
     */
    public static function optimizeSearchQuery(WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }
        if (!self::getSettings()['limit_to_posts']) {
            return;
        }
        if (empty($query->get('post_type'))) {
            $query->set('post_type', ['post']);
        }
    }

    /**
     * Replace the search SQL with a FULLTEXT MATCH clause.
     *
     * Returns the original `$search` untouched whenever the fast
     * search cannot or should not run: the module or the
     * FULLTEXT toggle is off, the indexes are not ready, the
     * request is in the admin, the query is not the main frontend
     * search, the search string is empty, or nothing usable
     * remains after cleaning it. That fallback is what keeps
     * search working (standard WordPress LIKE search) while the
     * indexes are missing.
     *
     * When it runs, the clause is a MATCH ... AGAINST in boolean
     * mode over the posts FULLTEXT index, OR-ed with posts whose
     * category or tag name matched (capped at 500 ids).
     *
     * @param string $search Search SQL generated by WordPress.
     * @param WP_Query $query The query being prepared.
     * @return string Replacement search SQL, or the original.
     */
    public static function filterPostsSearch(string $search, WP_Query $query): string
    {
        if (!self::getSettings()['fulltext_search']) {
            return $search;
        }
        if (!IndexManager::isReady()) {
            return $search;
        }
        if ($search === '' || is_admin() || !$query->is_search() || !$query->is_main_query()) {
            return $search;
        }
        $rawTerm = trim((string) $query->get('s'));
        if ($rawTerm === '') {
            return $search;
        }
        $booleanQuery = QueryBuilder::buildBooleanQuery($rawTerm);
        if ($booleanQuery === '') {
            return $search;
        }
        global $wpdb;
        return QueryBuilder::buildSearchClause($wpdb, $booleanQuery);
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{fulltext_search: bool, limit_to_posts: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'fulltext_search' => (bool) ($settings['fulltext_search'] ?? true),
            'limit_to_posts' => (bool) ($settings['limit_to_posts'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * Both the FULLTEXT search and the posts-only limit are on:
     * with the indexes installed this is the fast path, and
     * without them the module falls back silently.
     *
     * @return array{fulltext_search: bool, limit_to_posts: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'fulltext_search' => true,
            'limit_to_posts' => true,
        ];
    }
}
