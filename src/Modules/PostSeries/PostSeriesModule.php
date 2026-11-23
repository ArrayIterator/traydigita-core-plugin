<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\PostSeries;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use WP_Post;
use WP_Query;
use WP_Term;
use function __;
use function _n;
use function _x;
use function absint;
use function add_action;
use function add_meta_box;
use function admin_url;
use function array_filter;
use function array_values;
use function count;
use function current_user_can;
use function delete_post_meta;
use function did_action;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function flush_rewrite_rules;
use function get_option;
use function get_permalink;
use function get_post;
use function get_post_meta;
use function get_posts;
use function get_term;
use function get_term_link;
use function get_terms;
use function get_the_ID;
use function get_the_terms;
use function get_the_title;
use function is_admin;
use function is_array;
use function is_string;
use function is_singular;
use function is_wp_error;
use function register_taxonomy;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_title;
use function selected;
use function sprintf;
use function update_option;
use function update_post_meta;
use function usort;
use function wp_insert_term;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_nonce_field;
use function wp_set_post_terms;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Post Series module.
 *
 * Groups posts into a series: a series is a term of the
 * `td_series` taxonomy (rewrite slug `series`, so a series
 * archive lives at /series/<slug>/), and the order of a post
 * inside its series is the `_traydigita_series_order` post
 * meta. A post belongs to one series at a time — the meta box
 * on the post screen is a single select, and saving it
 * replaces the post's series terms — because the order is
 * stored per post, not per (post, series) pair.
 *
 * Frontend: on a single post that belongs to a series,
 * render() prints the series navigation box (series title
 * linked to the archive, optional description, previous /
 * next links in series order, and the full ordered list when
 * the list is enabled). The box renders explicitly after the
 * related posts in layouts/entry/footer.php, so boot()
 * registers no content filter. The series archive is the
 * native taxonomy archive, ordered by the series order
 * (pre_get_posts) instead of by date.
 *
 * Ordering, in steps of 10, so an editor can slot a post
 * between two others without renumbering the series: a post
 * that gains a series without an order gets max + 10 (or 10
 * for the first post), whether the series came from the meta
 * box, the editor taxonomy panel, or the settings-page
 * ordering form. The settings page lists every series with
 * its posts in order, lets the administrator renumber them,
 * and offers a quick-create for new series; the full
 * create/edit/delete UI is the native taxonomy screen.
 *
 * Rewrite flush: the taxonomy rewrite rules are static, so
 * settings changes never need a flush. The one-time flush
 * after the module is first enabled is tracked by the
 * `traydigita_post_series_rewrites_flushed` option flag in
 * boot(); a flush on every request would be a performance
 * bug.
 *
 * Settings live in the single option `traydigita_post_series`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type. The ordering
 * form and the quick-create are classic POSTs handled by
 * handleClassicPost() (nonce + `manage_options`, like the SEO
 * module's classic forms).
 */
final class PostSeriesModule implements ModuleInterface
{
    /**
     * Taxonomy name for series.
     */
    public const string TAXONOMY = 'td_series';

    /**
     * Post meta key for the order of a post inside its series.
     */
    public const string ORDER_META = '_traydigita_series_order';

    /**
     * Option name for all post-series settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_post_series';

    /**
     * Option flag recording that the rewrite rules were flushed once.
     */
    public const string REWRITES_FLUSHED_OPTION = 'traydigita_post_series_rewrites_flushed';

    /**
     * Nonce action for the settings form (module dispatcher).
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_post_series_save';

    /**
     * Nonce action for the meta box on the post screen.
     */
    public const string META_BOX_NONCE_ACTION = 'traydigita_post_series_meta_box';

    /**
     * Nonce action for the classic POST forms on the settings page.
     */
    public const string CLASSIC_NONCE_ACTION = 'traydigita_post_series_classic';

    /**
     * Order step: new posts slot in 10 apart, so an editor can
     * insert a post between two others without renumbering.
     */
    public const int ORDER_STEP = 10;

    /**
     * Maximum posts fetched for one series list.
     */
    public const int SERIES_POST_LIMIT = 200;

    /**
     * Maximum order value accepted from a form.
     */
    public const int MAX_ORDER = 9999;

    /**
     * Whether boot() already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'post-series';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Post Series', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Group posts into a series with previous/next navigation on single posts, an ordered series archive, and an admin UI to create and order series.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'editor-ol';
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
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-post-series';
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
     * No setup is needed: the defaults render a working series
     * box, so this is always true.
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
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Framework sections for the left navigation.
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'general', 'label' => __('Display', 'traydigita-core'), 'icon' => 'visibility'],
            ['id' => 'series', 'label' => __('Series', 'traydigita-core'), 'icon' => 'editor-ol'],
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Registers the taxonomy on `init` (or immediately when
     * `init` already fired), the rewrite flush once, the archive
     * ordering, the post-screen meta box, and the order
     * auto-assignment for series gained outside the meta box.
     * A disabled module registers nothing; the isEnabled()
     * guard is belt and braces for a direct call.
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
        if (did_action('init') > 0) {
            self::registerTaxonomy();
        } else {
            add_action('init', [self::class, 'registerTaxonomy']);
        }
        if (!get_option(self::REWRITES_FLUSHED_OPTION, false)) {
            flush_rewrite_rules();
            update_option(self::REWRITES_FLUSHED_OPTION, 1, false);
        }
        add_action('pre_get_posts', [self::class, 'orderSeriesArchive']);
        add_action('add_meta_boxes', [self::class, 'registerMetaBox']);
        add_action('save_post', [self::class, 'saveMetaBox']);
        add_action('set_object_terms', [self::class, 'maybeAssignOrder'], 10, 6);
    }

    /**
     * Register the `td_series` taxonomy for posts.
     *
     * Hierarchical (a series can group sub-series, like
     * categories), public with the archive at /series/<slug>/,
     * shown in the editor (REST) and as an admin column, so the
     * native taxonomy screen is the full create/edit/delete UI.
     *
     * @return void
     */
    public static function registerTaxonomy(): void
    {
        register_taxonomy(
            self::TAXONOMY,
            ['post'],
            [
                'labels' => [
                    'name' => _x('Series', 'taxonomy general name', 'traydigita-core'),
                    'singular_name' => _x('Series', 'taxonomy singular name', 'traydigita-core'),
                    'search_items' => __('Search series', 'traydigita-core'),
                    'all_items' => __('All series', 'traydigita-core'),
                    'parent_item' => __('Parent series', 'traydigita-core'),
                    'parent_item_colon' => __('Parent series:', 'traydigita-core'),
                    'edit_item' => __('Edit series', 'traydigita-core'),
                    'update_item' => __('Update series', 'traydigita-core'),
                    'add_new_item' => __('Add new series', 'traydigita-core'),
                    'new_item_name' => __('New series name', 'traydigita-core'),
                    'menu_name' => __('Series', 'traydigita-core'),
                ],
                'hierarchical' => true,
                'public' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'show_in_nav_menus' => true,
                'show_in_rest' => true,
                'show_tagcloud' => false,
                'rewrite' => [
                    'slug' => 'series',
                    'with_front' => false,
                    'hierarchical' => true,
                ],
            ]
        );
    }

    /**
     * Order a series archive by the series order, not by date.
     *
     * Only the main query on a series taxonomy archive, only on
     * the frontend. The named EXISTS clause is what the
     * `series_order` orderby key refers to (a plain `meta_key`
     * would be wrapped by WordPress into AND(EXISTS, OR(...)),
     * silently dropping posts without an order value). The OR
     * meta query keeps posts without an order value in the
     * list — they sort first (NULL), before the ordered posts —
     * and WordPress turns every meta JOIN in the query into a
     * LEFT JOIN when any of them is one, so no post is lost.
     * Such posts are the exception: every post that gains a
     * series through the module UI gets an order.
     *
     * @param WP_Query $query Query to adjust.
     * @return void
     */
    public static function orderSeriesArchive(WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_tax(self::TAXONOMY)) {
            return;
        }
        $query->set('meta_query', [
            'relation' => 'OR',
            'series_order' => [
                'key' => self::ORDER_META,
                'compare' => 'EXISTS',
                'type' => 'NUMERIC',
            ],
            [
                'key' => self::ORDER_META,
                'compare' => 'NOT EXISTS',
            ],
        ]);
        $query->set('orderby', ['series_order' => 'ASC', 'date' => 'ASC']);
        $query->set('order', 'ASC');
    }

    // ------------------------------------------------------------------
    // Frontend: series navigation box
    // ------------------------------------------------------------------

    /**
     * Render the series navigation box for a post.
     *
     * Only on a single post view that belongs to a series, and
     * only when the navigation is enabled in the settings. The
     * box shows the series title (linked to the archive), the
     * series description when it has one, previous/next links
     * in series order, and — when the list is enabled — the
     * full ordered list with the current post highlighted.
     * Returns an empty string when there is nothing to show.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Series box markup, or '' when there is nothing to show.
     */
    public static function render(?int $postId = null): string
    {
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return '';
        }
        if (!is_singular('post')) {
            return '';
        }
        $post = get_post($postId);
        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return '';
        }
        $settings = self::getSettings();
        if (!$settings['show_nav']) {
            return '';
        }
        $term = self::postSeriesTerm($postId);
        if (!$term instanceof WP_Term) {
            return '';
        }
        $posts = self::getSeriesPosts((int) $term->term_id);
        if ($posts === []) {
            return '';
        }
        $currentIndex = null;
        foreach ($posts as $index => $seriesPost) {
            if ($seriesPost->ID === $postId) {
                $currentIndex = $index;
                break;
            }
        }
        if ($currentIndex === null) {
            return '';
        }
        $archiveUrl = get_term_link($term);
        if (is_wp_error($archiveUrl)) {
            $archiveUrl = '';
        }
        $html = '<nav class="post-series" aria-label="' . esc_attr(sprintf(
            /* translators: %s is the series name. */
            __('Part of the series: %s', 'traydigita-core'),
            $term->name
        )) . '">';
        $html .= '<p class="post-series-kicker">' . esc_html__('Part of the series', 'traydigita-core') . '</p>';
        $html .= '<h2 class="post-series-title">';
        if ($archiveUrl !== '') {
            $html .= '<a class="post-series-title-link" href="' . esc_url($archiveUrl) . '">'
                . esc_html($term->name) . '</a>';
        } else {
            $html .= esc_html($term->name);
        }
        $html .= '</h2>';
        if ($term->description !== '') {
            $html .= '<p class="post-series-description">' . esc_html($term->description) . '</p>';
        }
        $prev = $currentIndex > 0 ? $posts[$currentIndex - 1] : null;
        $next = $currentIndex + 1 < count($posts) ? $posts[$currentIndex + 1] : null;
        if ($prev instanceof WP_Post || $next instanceof WP_Post) {
            $html .= '<div class="post-series-pager">';
            if ($prev instanceof WP_Post) {
                $prevUrl = get_permalink($prev);
                $html .= '<a class="post-series-prev" href="' . esc_url($prevUrl === false ? '' : $prevUrl) . '" rel="prev">'
                    . '<span class="post-series-pager-label">' . esc_html__('Previous in series', 'traydigita-core') . '</span>'
                    . '<span class="post-series-pager-title">' . esc_html(get_the_title($prev)) . '</span></a>';
            }
            if ($next instanceof WP_Post) {
                $nextUrl = get_permalink($next);
                $html .= '<a class="post-series-next" href="' . esc_url($nextUrl === false ? '' : $nextUrl) . '" rel="next">'
                    . '<span class="post-series-pager-label">' . esc_html__('Next in series', 'traydigita-core') . '</span>'
                    . '<span class="post-series-pager-title">' . esc_html(get_the_title($next)) . '</span></a>';
            }
            $html .= '</div>';
        }
        if ($settings['show_list']) {
            $html .= '<ol class="post-series-list">';
            foreach ($posts as $index => $seriesPost) {
                $itemUrl = get_permalink($seriesPost);
                $isCurrent = $index === $currentIndex;
                $html .= '<li class="post-series-item' . ($isCurrent ? ' post-series-item-current' : '') . '">';
                if ($isCurrent) {
                    $html .= '<span class="post-series-item-title" aria-current="true">'
                        . esc_html(get_the_title($seriesPost)) . '</span>';
                } else {
                    $html .= '<a class="post-series-item-link" href="'
                        . esc_url($itemUrl === false ? '' : $itemUrl) . '">'
                        . esc_html(get_the_title($seriesPost)) . '</a>';
                }
                $html .= '</li>';
            }
            $html .= '</ol>';
        }
        $html .= '</nav>';
        return $html;
    }

    /**
     * The series term of a post, or null when it has none.
     *
     * A post belongs to one series; when it somehow carries
     * several terms (set through the editor taxonomy panel),
     * the first one wins.
     *
     * @param int $postId Post id.
     * @return WP_Term|null
     */
    public static function postSeriesTerm(int $postId): ?WP_Term
    {
        $terms = get_the_terms($postId, self::TAXONOMY);
        if (!is_array($terms) || $terms === []) {
            return null;
        }
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                return $term;
            }
        }
        return null;
    }

    /**
     * Posts of a series, in series order.
     *
     * Fetched in one query (date order) with the post-meta
     * cache primed by the query itself, then sorted in PHP by
     * the series-order meta, then by date, then by id. Posts
     * without an order value sort last, in date order. The
     * list is capped at SERIES_POST_LIMIT posts.
     *
     * @param int $termId Series term id.
     * @return array<int, WP_Post> Series posts, in order.
     */
    public static function getSeriesPosts(int $termId): array
    {
        if ($termId <= 0) {
            return [];
        }
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => self::SERIES_POST_LIMIT,
            'tax_query' => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id',
                    'terms' => [$termId],
                ],
            ],
            'orderby' => 'date',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ]);
        $posts = array_values(array_filter(
            $posts,
            static fn (mixed $post): bool => $post instanceof WP_Post
        ));
        usort(
            $posts,
            static function (WP_Post $a, WP_Post $b): int {
                $orderA = self::postOrder($a->ID);
                $orderB = self::postOrder($b->ID);
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }
                if ($a->post_date !== $b->post_date) {
                    return $a->post_date <=> $b->post_date;
                }
                return $a->ID <=> $b->ID;
            }
        );
        return $posts;
    }

    /**
     * Order value of a post inside its series.
     *
     * Posts without a stored order sort last (PHP_INT_MAX), in
     * date order among themselves.
     *
     * @param int $postId Post id.
     * @return int Order value, or PHP_INT_MAX when unset.
     */
    public static function postOrder(int $postId): int
    {
        $order = get_post_meta($postId, self::ORDER_META, true);
        if ($order === '' || $order === null) {
            return PHP_INT_MAX;
        }
        return absint($order);
    }

    /**
     * All series terms, newest first.
     *
     * @return array<int, WP_Term>
     */
    public static function getAllSeries(): array
    {
        $terms = get_terms([
            'taxonomy' => self::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'term_id',
            'order' => 'DESC',
        ]);
        if (!is_array($terms)) {
            return [];
        }
        return array_values(array_filter(
            $terms,
            static fn (mixed $term): bool => $term instanceof WP_Term
        ));
    }

    // ------------------------------------------------------------------
    // Post screen: meta box + order auto-assignment
    // ------------------------------------------------------------------

    /**
     * Register the "Post Series" meta box on the post screen.
     *
     * @return void
     */
    public static function registerMetaBox(): void
    {
        add_meta_box(
            'traydigita-post-series',
            __('Post Series', 'traydigita-core'),
            [self::class, 'renderMetaBox'],
            'post',
            'side',
            'default'
        );
    }

    /**
     * Render the meta box: series select + order number.
     *
     * @param WP_Post $post Post being edited.
     * @return void
     */
    public static function renderMetaBox(WP_Post $post): void
    {
        $terms = self::getAllSeries();
        $current = self::postSeriesTerm((int) $post->ID);
        $currentId = $current instanceof WP_Term ? (int) $current->term_id : 0;
        $order = $currentId > 0 ? self::postOrder((int) $post->ID) : 0;
        wp_nonce_field(self::META_BOX_NONCE_ACTION, 'traydigita_post_series_nonce');
        ?>
        <p>
            <label for="traydigita-post-series-select"><?php esc_html_e('Series', 'traydigita-core'); ?></label><br>
            <select id="traydigita-post-series-select" name="traydigita_post_series" style="width: 100%; max-width: 100%;">
                <option value="0"><?php esc_html_e('— No series —', 'traydigita-core'); ?></option>
                <?php foreach ($terms as $term) : ?>
                    <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($currentId, (int) $term->term_id); ?>>
                        <?php echo esc_html($term->name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="traydigita-post-series-order"><?php esc_html_e('Order in series', 'traydigita-core'); ?></label><br>
            <input type="number" id="traydigita-post-series-order" name="traydigita_post_series_order"
                value="<?php echo $order > 0 && $order !== PHP_INT_MAX ? esc_attr((string) $order) : ''; ?>"
                min="0" max="<?php echo esc_attr((string) self::MAX_ORDER); ?>" step="10" style="width: 100%; max-width: 100%;">
            <span class="description"><?php esc_html_e('Leave empty to append at the end.', 'traydigita-core'); ?></span>
        </p>
        <?php
    }

    /**
     * Save the meta box: series assignment + order.
     *
     * Nonce + `edit_post` capability, autosaves and revisions
     * never save. Choosing a series replaces the post's series
     * terms (one series per post); choosing "No series" removes
     * them and drops the order. An empty order appends the post
     * at the end (max + ORDER_STEP).
     *
     * @param int $postId Id of the saved post.
     * @return void
     */
    public static function saveMetaBox(int $postId): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        $post = get_post($postId);
        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return;
        }
        if (!isset($_POST['traydigita_post_series_nonce'])
            || !is_string($_POST['traydigita_post_series_nonce'])
            || !wp_verify_nonce(
                sanitize_key(wp_unslash($_POST['traydigita_post_series_nonce'])),
                self::META_BOX_NONCE_ACTION
            )
        ) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }
        $termId = isset($_POST['traydigita_post_series']) ? absint($_POST['traydigita_post_series']) : 0;
        if ($termId <= 0) {
            wp_set_post_terms($postId, [], self::TAXONOMY);
            delete_post_meta($postId, self::ORDER_META);
            return;
        }
        $term = get_term($termId, self::TAXONOMY);
        if (!$term instanceof WP_Term) {
            return;
        }
        wp_set_post_terms($postId, [$termId], self::TAXONOMY);
        $order = isset($_POST['traydigita_post_series_order'])
            ? absint($_POST['traydigita_post_series_order'])
            : 0;
        if ($order <= 0) {
            $order = self::nextOrder($termId, $postId);
        } elseif ($order > self::MAX_ORDER) {
            $order = self::MAX_ORDER;
        }
        update_post_meta($postId, self::ORDER_META, $order);
    }

    /**
     * Give a post an order when it gains a series outside the meta box.
     *
     * Hooked to `set_object_terms`: the editor taxonomy panel
     * (and any code path) can put a post in a series without
     * touching the order meta, which would leave the post
     * unordered. When a post ends up in a series with no order
     * stored, it gets max + ORDER_STEP for that series. Runs
     * after the terms are set, so it reads the post's real
     * terms; updating post meta here cannot recurse (meta
     * writes do not fire `set_object_terms`).
     *
     * @param int $objectId Object id.
     * @param array<int, mixed> $terms Terms being set.
     * @param array<int, int> $ttIds Term-taxonomy ids.
     * @param string $taxonomy Taxonomy slug.
     * @param bool $append Whether terms are appended.
     * @param array<int, int> $oldTtIds Previous term-taxonomy ids.
     * @return void
     */
    public static function maybeAssignOrder(
        int $objectId,
        array $terms,
        array $ttIds,
        string $taxonomy,
        bool $append,
        array $oldTtIds
    ): void {
        if ($taxonomy !== self::TAXONOMY || $objectId <= 0) {
            return;
        }
        if (wp_is_post_autosave($objectId) || wp_is_post_revision($objectId)) {
            return;
        }
        $post = get_post($objectId);
        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return;
        }
        $term = self::postSeriesTerm($objectId);
        if (!$term instanceof WP_Term) {
            return;
        }
        $order = get_post_meta($objectId, self::ORDER_META, true);
        if ($order !== '' && $order !== null) {
            return;
        }
        update_post_meta($objectId, self::ORDER_META, self::nextOrder((int) $term->term_id, $objectId));
    }

    /**
     * Next free order value for a series: max + ORDER_STEP, or ORDER_STEP first.
     *
     * @param int $termId Series term id.
     * @param int $excludePostId Post id to exclude (the one being placed).
     * @return int
     */
    private static function nextOrder(int $termId, int $excludePostId): int
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post__not_in' => [$excludePostId],
            'tax_query' => [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field' => 'term_id',
                    'terms' => [$termId],
                ],
            ],
            'meta_key' => self::ORDER_META,
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);
        if (!is_array($posts) || $posts === []) {
            return self::ORDER_STEP;
        }
        $max = self::postOrder((int) $posts[0]);
        if ($max === PHP_INT_MAX) {
            return self::ORDER_STEP;
        }
        $next = $max + self::ORDER_STEP;
        return $next > self::MAX_ORDER ? self::MAX_ORDER : $next;
    }

    // ------------------------------------------------------------------
    // Settings page
    // ------------------------------------------------------------------

    /**
     * Handle the classic POST forms on the settings page.
     *
     * Two actions, both nonce-checked with
     * CLASSIC_NONCE_ACTION and gated on `manage_options`:
     * `save_order` (renumber every series' posts from the
     * ordering table) and `create_series` (quick-create one
     * series from a name + optional slug). Returns the notice
     * for renderSettingsPage() to show; a non-POST request or
     * an unknown action returns an empty notice.
     *
     * @return array{notice: string, error: bool}
     */
    private static function handleClassicPost(): array
    {
        $result = ['notice' => '', 'error' => false];
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $result;
        }
        $action = isset($_POST['traydigita_post_series_classic_action'])
            ? sanitize_key((string) wp_unslash($_POST['traydigita_post_series_classic_action']))
            : '';
        if ($action === '') {
            return $result;
        }
        $nonce = isset($_POST['_wpnonce']) ? sanitize_key((string) wp_unslash($_POST['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::CLASSIC_NONCE_ACTION)) {
            $result['notice'] = __('Security check failed. Please try again.', 'traydigita-core');
            $result['error'] = true;
            return $result;
        }
        if (!current_user_can('manage_options')) {
            $result['notice'] = __('You are not allowed to do that.', 'traydigita-core');
            $result['error'] = true;
            return $result;
        }
        if ($action === 'create_series') {
            $name = isset($_POST['new_series_name'])
                ? sanitize_text_field((string) wp_unslash($_POST['new_series_name']))
                : '';
            if ($name === '') {
                $result['notice'] = __('Enter a name for the new series.', 'traydigita-core');
                $result['error'] = true;
                return $result;
            }
            $slug = isset($_POST['new_series_slug'])
                ? sanitize_title((string) wp_unslash($_POST['new_series_slug']))
                : '';
            $created = wp_insert_term($name, self::TAXONOMY, $slug !== '' ? ['slug' => $slug] : []);
            if (is_wp_error($created)) {
                $result['notice'] = $created->get_error_message();
                $result['error'] = true;
                return $result;
            }
            $result['notice'] = sprintf(
                /* translators: %s is the series name. */
                __('Series "%s" created.', 'traydigita-core'),
                $name
            );
            return $result;
        }
        if ($action === 'save_order') {
            $saved = 0;
            $orders = isset($_POST['td_series_order']) && is_array($_POST['td_series_order'])
                ? wp_unslash($_POST['td_series_order'])
                : [];
            foreach ($orders as $termIdRaw => $postOrders) {
                $termId = absint($termIdRaw);
                if ($termId <= 0 || !is_array($postOrders)) {
                    continue;
                }
                $term = get_term($termId, self::TAXONOMY);
                if (!$term instanceof WP_Term) {
                    continue;
                }
                foreach ($postOrders as $postIdRaw => $orderRaw) {
                    $postId = absint($postIdRaw);
                    if ($postId <= 0) {
                        continue;
                    }
                    $post = get_post($postId);
                    if (!$post instanceof WP_Post || $post->post_type !== 'post') {
                        continue;
                    }
                    $termOfPost = self::postSeriesTerm($postId);
                    if (!$termOfPost instanceof WP_Term || (int) $termOfPost->term_id !== $termId) {
                        continue;
                    }
                    $order = absint($orderRaw);
                    if ($order > self::MAX_ORDER) {
                        $order = self::MAX_ORDER;
                    }
                    if ($order <= 0) {
                        $order = self::nextOrder($termId, $postId);
                    }
                    update_post_meta($postId, self::ORDER_META, $order);
                    $saved++;
                }
            }
            $result['notice'] = sprintf(
                /* translators: %d is the number of posts renumbered. */
                __('Series order saved for %d posts.', 'traydigita-core'),
                $saved
            );
            return $result;
        }
        return $result;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Framework layout: an intro, a display section (the two
     * toggles, saved through the module dispatcher), and a
     * series section with the ordering table and the
     * quick-create (classic POST, handled above). The full
     * create/edit/delete UI for series is the native taxonomy
     * screen, linked from the section.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $classic = self::handleClassicPost();
        $settings = self::getSettings();
        $series = self::getAllSeries();
        ?>
        <div class="traydigita-module-settings post-series-settings">
            <?php
            SettingsControls::intro(
                __('Group posts into a series with navigation between them.', 'traydigita-core'),
                __('A series is a group of posts in a fixed order. Readers get previous/next links and the full list on every post in the series, and the series archive lists the posts in that order.', 'traydigita-core')
            );
            ?>
            <?php if ($classic['notice'] !== '') : ?>
                <div class="traydigita-admin-notice <?php echo $classic['error'] ? 'traydigita-admin-notice-error' : 'traydigita-admin-notice-success'; ?>">
                    <p><?php echo esc_html($classic['notice']); ?></p>
                </div>
            <?php endif; ?>

            <form class="post-series-settings-form" data-traydigita-module-form data-traydigita-module="post-series" data-module="post-series" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php SettingsControls::sectionOpen('general', __('Display', 'traydigita-core')); ?>
                <?php
                SettingsControls::toggle([
                    'id' => 'post-series-show-nav',
                    'name' => 'show_nav',
                    'label' => __('Show series navigation on single posts', 'traydigita-core'),
                    'checked' => $settings['show_nav'],
                    'help' => __('Previous/next links in series order on every post that belongs to a series.', 'traydigita-core'),
                    'help_long' => __('The box renders after the related posts, below the article.', 'traydigita-core'),
                ]);
                SettingsControls::toggle([
                    'id' => 'post-series-show-list',
                    'name' => 'show_list',
                    'label' => __('Show the full series list', 'traydigita-core'),
                    'checked' => $settings['show_list'],
                    'help' => __('List every post of the series in order inside the navigation box, with the current post highlighted.', 'traydigita-core'),
                    'help_long' => __('With the list off, readers still get the previous/next links.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose(); ?>

                <?php SettingsControls::saveBar(); ?>
            </form>

            <form class="post-series-order-form" method="post" action="">
                <?php wp_nonce_field(self::CLASSIC_NONCE_ACTION); ?>
                <input type="hidden" name="traydigita_post_series_classic_action" value="save_order">

                <?php SettingsControls::sectionOpen('series', __('Series', 'traydigita-core')); ?>
                <?php if ($series === []) : ?>
                    <p><?php esc_html_e('No series yet. Create the first one below, or from the Series screen.', 'traydigita-core'); ?></p>
                <?php else : ?>
                    <?php foreach ($series as $term) : ?>
                        <?php $termPosts = self::getSeriesPosts((int) $term->term_id); ?>
                        <?php $termLink = get_term_link($term); ?>
                        <h3 class="post-series-admin-name">
                            <a href="<?php echo esc_url(is_wp_error($termLink) ? '' : (string) $termLink); ?>">
                                <?php echo esc_html($term->name); ?>
                            </a>
                            <span class="post-series-admin-count">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %d is the number of posts in the series. */
                                    _n('%d post', '%d posts', count($termPosts), 'traydigita-core'),
                                    count($termPosts)
                                ));
                                ?>
                            </span>
                            <a class="post-series-admin-edit" href="<?php echo esc_url(admin_url('term.php?taxonomy=' . self::TAXONOMY . '&tag_ID=' . (int) $term->term_id)); ?>">
                                <?php esc_html_e('Edit', 'traydigita-core'); ?>
                            </a>
                        </h3>
                        <?php if ($termPosts === []) : ?>
                            <p><?php esc_html_e('No posts in this series yet. Assign posts from the post screen.', 'traydigita-core'); ?></p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Order', 'traydigita-core'); ?></th>
                                        <th><?php esc_html_e('Post', 'traydigita-core'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($termPosts as $seriesPost) : ?>
                                        <?php $seriesOrder = self::postOrder((int) $seriesPost->ID); ?>
                                        <tr>
                                            <td>
                                                <input type="number" class="small-text"
                                                    name="td_series_order[<?php echo esc_attr((string) $term->term_id); ?>][<?php echo esc_attr((string) $seriesPost->ID); ?>]"
                                                    value="<?php echo $seriesOrder === PHP_INT_MAX ? '' : esc_attr((string) $seriesOrder); ?>"
                                                    min="0" max="<?php echo esc_attr((string) self::MAX_ORDER); ?>" step="10">
                                            </td>
                                            <td>
                                                <?php $seriesPermalink = get_permalink($seriesPost); ?>
                                                <a href="<?php echo esc_url($seriesPermalink === false ? '' : $seriesPermalink); ?>">
                                                    <?php echo esc_html(get_the_title($seriesPost)); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <p>
                        <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                            <?php esc_html_e('Save series order', 'traydigita-core'); ?>
                        </button>
                        <a class="traydigita-admin-button" href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=post')); ?>">
                            <?php esc_html_e('Manage series', 'traydigita-core'); ?>
                        </a>
                    </p>
                <?php endif; ?>
                <?php SettingsControls::sectionClose(); ?>
            </form>

            <form class="post-series-create-form" method="post" action="">
                <?php wp_nonce_field(self::CLASSIC_NONCE_ACTION); ?>
                <input type="hidden" name="traydigita_post_series_classic_action" value="create_series">
                <?php SettingsControls::sectionOpen('create', __('New series', 'traydigita-core')); ?>
                <?php
                SettingsControls::input([
                    'id' => 'post-series-new-name',
                    'name' => 'new_series_name',
                    'label' => __('Series name', 'traydigita-core'),
                    'value' => '',
                    'attrs' => ['maxlength' => '120', 'required' => true],
                ]);
                SettingsControls::input([
                    'id' => 'post-series-new-slug',
                    'name' => 'new_series_slug',
                    'label' => __('Slug (optional)', 'traydigita-core'),
                    'value' => '',
                    'help' => __('Leave empty to generate the slug from the name.', 'traydigita-core'),
                    'attrs' => ['maxlength' => '120'],
                ]);
                ?>
                <p>
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Create series', 'traydigita-core'); ?>
                    </button>
                </p>
                <?php SettingsControls::sectionClose(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `show_nav`, `show_list`
     * (checkboxes: `1` when checked, `0` when the framework
     * toggle's hidden zero input submits). Unknown keys are
     * dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Post Series settings.', 'traydigita-core'),
            ];
        }
        update_option(self::OPTION, [
            'show_nav' => !empty($data['show_nav']),
            'show_list' => !empty($data['show_list']),
        ], false);
        return [
            'success' => true,
            'message' => __('Post Series settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_nav: bool, show_list: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'show_nav' => (bool) ($settings['show_nav'] ?? false),
            'show_list' => (bool) ($settings['show_list'] ?? false),
        ];
    }

    /**
     * Default settings.
     *
     * Navigation box and full list both on.
     *
     * @return array{show_nav: bool, show_list: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'show_nav' => true,
            'show_list' => true,
        ];
    }
}
