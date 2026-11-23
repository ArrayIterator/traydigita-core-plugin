<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\RelatedPosts;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Libs\ThemeImage;
use WP_Post;
use WP_Query;
use WP_Term;
use function __;
use function _prime_post_caches;
use function absint;
use function add_action;
use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function checked;
use function count;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function get_option;
use function get_post;
use function get_post_field;
use function get_post_timestamp;
use function get_posts;
use function get_the_category;
use function get_the_date;
use function get_the_ID;
use function get_the_terms;
use function get_the_title;
use function get_permalink;
use function get_transient;
use function in_the_loop;
use function is_admin;
use function is_array;
use function is_feed;
use function is_int;
use function is_main_query;
use function is_singular;
use function is_string;
use function sanitize_text_field;
use function set_transient;
use function strtotime;
use function update_object_term_cache;
use function update_option;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_nonce_field;

/**
 * Related Posts module.
 *
 * Appends a "Read also" block at the end of single articles:
 * posts related to the one being read, ranked by shared tags
 * (weight 3) + shared categories (weight 2) + same author
 * (weight 1), with the more recent post winning ties. The
 * ranking itself lives in RelatedPostsScorer (pure PHP, no
 * WordPress dependency); this class fetches the candidates,
 * applies the fallback chain, caches, renders, and owns the
 * settings page.
 *
 * Fallback chain, in order: (1) scored candidates — posts
 * sharing a tag, a category, or the author; (2) when fewer
 * than the requested count match, the latest posts in the
 * current post's primary (first) category; (3) when the post
 * has no tags or categories at all, step 1 is skipped and the
 * list is the latest posts overall; (4) any remaining slots
 * are filled with the latest posts overall. The current post
 * itself is never listed.
 *
 * Results are cached per post in a transient for 15 minutes,
 * so the scoring queries never run on every view. The cache
 * key carries a version number stored in its own option; any
 * post save or deletion bumps the version, which invalidates
 * every cached list at once (a saved post can change the
 * related list of any other post, not just its own). Stale
 * versioned transients simply expire after 15 minutes.
 *
 * Thumbnails go through ThemeImage (stack FeatureImage +
 * Photon) — never through get_the_post_thumbnail() directly.
 *
 * Placement: the block renders explicitly after the share row
 * in layouts/entry/footer.php (padangkita.com order: tags,
 * share, related), so boot() registers no content filter —
 * only the cache invalidation on post save/delete. The
 * content-filter order on a single post is therefore Ads at
 * 12, Author Box at 13, Share at 20 (or explicit, when its
 * position is 'below'), and Related Posts explicit, after the
 * share row. filterContent() stays available for a child theme
 * that prefers the filter placement, but the theme itself
 * never registers it.
 *
 * Settings live in the single option `traydigita_related_posts`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 */
final class RelatedPostsModule implements ModuleInterface
{
    /**
     * Option name for all related-posts settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_related_posts';

    /**
     * Option holding the cache version (autoload `no`).
     */
    public const string VERSION_OPTION = 'traydigita_related_posts_version';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_related_posts_save';

    /**
     * Default heading, matching the padangkita.com reference
     * ("Berita terkait" is the sidebar heading there; the block
     * below the article reads "Read also").
     */
    public const string DEFAULT_HEADING = 'Read also';

    /**
     * Default number of related posts.
     */
    public const int DEFAULT_COUNT = 4;

    /**
     * Minimum number of related posts.
     */
    public const int MIN_COUNT = 1;

    /**
     * Maximum number of related posts.
     */
    public const int MAX_COUNT = 12;

    /**
     * Transient lifetime in seconds (15 minutes).
     */
    public const int CACHE_TTL_SECONDS = 900;

    /**
     * Maximum candidates pulled for scoring in one query.
     */
    public const int CANDIDATE_LIMIT = 60;

    /**
     * Re-entrancy guard for the `the_content` filter.
     */
    private static bool $filtering = false;

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
        return 'related-posts';
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
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Related Posts', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Shows a “Read also” block at the end of single articles: posts ranked by shared tags, categories, and author, with the latest posts in the same category as a fallback. Results are cached for 15 minutes.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'networking';
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
        return 'traydigita-related-posts';
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
     * No setup is needed: the defaults render a working block,
     * so this is always true.
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
     * Render the settings page inside the admin shell content area.
     *
     * One form: post count, thumbnails toggle, heading text. The
     * form is submitted through the generic module-settings AJAX
     * dispatcher (see `data-traydigita-module-form`).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings related-posts-settings">
            <?php
            SettingsControls::intro(
                __('A “Read also” block at the end of single articles.', 'traydigita-core'),
                __('Posts are ranked by shared tags, then shared categories, then the same author; when too few match, the latest posts in the same category fill the remaining slots, and posts with no terms at all fall back to the latest posts overall. Results are cached for 15 minutes.', 'traydigita-core')
            );
            ?>

            <form class="related-posts-settings-form" data-traydigita-module-form data-traydigita-module="related-posts" data-module="related-posts" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="related-posts-field">
                    <label for="related-posts-count"><?php esc_html_e('Number of related posts', 'traydigita-core'); ?></label>
                    <input type="number" id="related-posts-count" name="count" value="<?php echo esc_attr((string) $settings['count']); ?>" min="<?php echo esc_attr((string) self::MIN_COUNT); ?>" max="<?php echo esc_attr((string) self::MAX_COUNT); ?>" step="1">
                    <span class="related-posts-field-help">
                        <?php esc_html_e('How many posts to show, from 1 to 12.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="related-posts-field">
                    <label class="traydigita-ui-toggle" for="related-posts-show-thumbnails"><input class="traydigita-ui-toggle-input" type="checkbox" id="related-posts-show-thumbnails" name="show_thumbnails" value="1" <?php checked($settings['show_thumbnails']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show thumbnails', 'traydigita-core'); ?></span></label>
                    <span class="related-posts-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Thumbnails come from the featured image (or the first image in the post) through the theme image helper, with Photon serving when enabled.', 'traydigita-core'),
                            __('Posts without any image show no thumbnail.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="related-posts-field">
                    <label for="related-posts-heading"><?php esc_html_e('Heading', 'traydigita-core'); ?></label>
                    <input type="text" id="related-posts-heading" name="heading" value="<?php echo esc_attr($settings['heading']); ?>" maxlength="120">
                    <span class="related-posts-field-help">
                        <?php esc_html_e('Heading above the list. Empty resets to the default.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="related-posts-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `count` (integer, clamped
     * to 1–12), `show_thumbnails` (`1` when checked; an absent
     * key means the checkbox was unchecked), `heading` (plain
     * text; empty resets to the default). Every value is
     * re-sanitized for its own type; unknown keys are dropped.
     * Changing the count needs no cache flush: the cached list
     * always holds up to the maximum count and is sliced at
     * render time.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Related Posts settings.', 'traydigita-core'),
            ];
        }
        $count = isset($data['count']) ? absint($data['count']) : self::DEFAULT_COUNT;
        if ($count < self::MIN_COUNT) {
            $count = self::MIN_COUNT;
        } elseif ($count > self::MAX_COUNT) {
            $count = self::MAX_COUNT;
        }
        $heading = isset($data['heading']) && is_string($data['heading'])
            ? sanitize_text_field($data['heading'])
            : '';
        if ($heading === '') {
            $heading = self::DEFAULT_HEADING;
        }
        update_option(self::OPTION, [
            'count' => $count,
            'show_thumbnails' => !empty($data['show_thumbnails']),
            'heading' => $heading,
        ], false);
        return [
            'success' => true,
            'message' => __('Related Posts settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Registers no content filter: the block renders explicitly
     * after the share row in layouts/entry/footer.php, so the
     * related block always lands below the share row, matching
     * the padangkita.com reference. Post saves and deletions
     * bump the cache version, invalidating every cached related
     * list. A disabled module registers nothing; the isEnabled()
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
        add_action('save_post', [self::class, 'invalidateCache']);
        add_action('deleted_post', [self::class, 'invalidateCache']);
    }

    /**
     * Append the related block to the post content.
     *
     * Only for the main query, inside the loop, on a single
     * post view. Feeds and admin screens never get a related
     * block. A coordinator that renders the block explicitly
     * (see the class docblock) should remove this filter, or
     * the block will appear twice.
     *
     * @param string $content The post content.
     * @return string The content with the related block added, or unchanged.
     */
    public static function filterContent(string $content): string
    {
        if (self::$filtering || is_admin() || is_feed() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!is_singular('post')) {
            return $content;
        }
        self::$filtering = true;
        try {
            $block = self::render(get_the_ID() ?: null);
            if ($block === '') {
                return $content;
            }
            return $content . $block;
        } finally {
            self::$filtering = false;
        }
    }

    /**
     * Render the related block for a post, or an empty string when it must not show.
     *
     * Only on a single post view, and only when at least one
     * related post exists. Thumbnails are rendered through
     * ThemeImage (stack FeatureImage + Photon); a related post
     * with no image at all renders no thumbnail element. The
     * global post is never touched: titles, permalinks, dates,
     * and thumbnails are all read from the related post objects
     * directly.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Related block markup, or '' when there is nothing to show.
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
        $ids = self::getRelatedPostIds($postId, $settings['count']);
        if ($ids === []) {
            return '';
        }
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post__in' => $ids,
            'orderby' => 'post__in',
            'posts_per_page' => count($ids),
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ]);
        if ($posts === []) {
            return '';
        }
        $thumbClass = $settings['show_thumbnails'] ? '' : ' related-posts--no-thumbs';
        $html = '<section class="related-posts' . $thumbClass . '"';
        $html .= ' aria-label="' . esc_attr($settings['heading']) . '">';
        $html .= '<h2 class="related-posts-heading">' . esc_html($settings['heading']) . '</h2>';
        $html .= '<ul class="related-posts-list">';
        foreach ($posts as $related) {
            if (!$related instanceof WP_Post) {
                continue;
            }
            $url = get_permalink($related);
            if ($url === false) {
                $url = '';
            }
            $html .= '<li class="related-posts-item"><article class="related-posts-card">';
            if ($settings['show_thumbnails']) {
                $thumb = ThemeImage::thumbnail(
                    $related,
                    ThemeImage::slotSize('card'),
                    ['class' => 'related-posts-thumb']
                );
                if ($thumb !== '') {
                    $html .= '<a class="related-posts-thumb-link" href="' . esc_url($url) . '"';
                    $html .= ' aria-hidden="true" tabindex="-1">' . $thumb . '</a>';
                }
            }
            $html .= '<div class="related-posts-body">';
            $html .= '<h3 class="related-posts-title"><a class="related-posts-title-link" href="';
            $html .= esc_url($url) . '">' . esc_html(get_the_title($related)) . '</a></h3>';
            $html .= '<p class="related-posts-meta"><time class="related-posts-date" datetime="';
            $html .= esc_attr((string) get_the_date('c', $related)) . '">';
            $html .= esc_html((string) get_the_date('', $related)) . '</time></p>';
            $html .= '</div></article></li>';
        }
        $html .= '</ul></section>';
        return $html;
    }

    /**
     * Related post ids for a post, in display order.
     *
     * Applies the fallback chain from the class docblock and
     * caches the full list (up to MAX_COUNT ids) in a transient
     * for 15 minutes; the returned list is sliced to $count, so
     * a later count change is served from the same cache entry.
     * The current post is excluded at every step.
     *
     * @param int $postId Post to find related posts for.
     * @param int $count Number of ids to return.
     * @return array<int, int> Related post ids, in display order.
     */
    public static function getRelatedPostIds(int $postId, int $count): array
    {
        $count = self::clampCount($count);
        $post = get_post($postId);
        if (!$post instanceof WP_Post || $post->post_type !== 'post') {
            return [];
        }
        $cached = get_transient(self::cacheKey($postId));
        if (is_array($cached)) {
            $ids = array_values(array_filter(array_map(
                static fn (mixed $id): int => absint($id),
                $cached
            ), static fn (int $id): bool => $id > 0 && $id !== $postId));
            if ($ids !== []) {
                return array_slice($ids, 0, $count);
            }
        }
        $tagIds = self::postTagIds($postId);
        $categoryIds = self::postCategoryIds($postId);
        $authorId = (int) $post->post_author;
        $ordered = [];
        if ($tagIds !== [] || $categoryIds !== []) {
            $ordered = RelatedPostsScorer::order(
                self::scoredCandidates($postId, $tagIds, $categoryIds, $authorId),
                $tagIds,
                $categoryIds,
                $authorId
            );
        }
        if (count($ordered) < self::MAX_COUNT) {
            $exclude = array_merge([$postId], $ordered);
            if ($categoryIds !== []) {
                $ordered = RelatedPostsScorer::fill(
                    $ordered,
                    self::latestPostIds($exclude, self::MAX_COUNT - count($ordered), $categoryIds[0]),
                    self::MAX_COUNT
                );
                $exclude = array_merge([$postId], $ordered);
            }
            if (count($ordered) < self::MAX_COUNT) {
                $ordered = RelatedPostsScorer::fill(
                    $ordered,
                    self::latestPostIds($exclude, self::MAX_COUNT - count($ordered), null),
                    self::MAX_COUNT
                );
            }
        }
        set_transient(self::cacheKey($postId), array_values($ordered), self::CACHE_TTL_SECONDS);
        return array_slice(array_values($ordered), 0, $count);
    }

    /**
     * Invalidate every cached related list.
     *
     * Hooked to `save_post` and `deleted_post`: any post save
     * or deletion can change the related list of any other
     * post, so the cache version is bumped and all versioned
     * transient keys stop matching. Autosaves and revisions
     * never bump the version, and saves of other post types
     * (pages, attachments) do not either. Stale transients
     * under the old version expire on their own after 15
     * minutes.
     *
     * @param int $postId Id of the saved or deleted post.
     * @return void
     */
    public static function invalidateCache(int $postId): void
    {
        if ($postId <= 0 || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        $post = get_post($postId);
        if ($post instanceof WP_Post && $post->post_type !== 'post') {
            return;
        }
        $version = (int) get_option(self::VERSION_OPTION, 0);
        update_option(self::VERSION_OPTION, $version + 1, false);
    }

    /**
     * Fetch and describe the scoring candidates for a post.
     *
     * Candidates come from two queries, merged and deduplicated:
     * posts sharing a tag or a category (one tax query with an
     * OR relation), plus posts by the same author (a separate
     * query, because an author condition cannot sit inside the
     * OR tax query). Each candidate carries its own tag ids,
     * category ids, author id, and publish timestamp for the
     * scorer. The pool is capped at CANDIDATE_LIMIT ids per
     * query; on a site with very many matches, older matches
     * beyond the cap are never scored.
     *
     * The candidate queries use `fields => ids`, which does
     * NOT prime the term caches — so the per-candidate tag
     * and category lookups below would each fire their own
     * query (an N+1: ~215 queries on a cold cache). The term
     * relationships for every candidate are therefore primed
     * in one bulk call (update_object_term_cache) before the
     * loop, so wp_get_post_tags() / get_the_category() below
     * are cache hits; the post objects themselves are primed
     * by the id queries, so get_post_timestamp() and
     * get_post_field() are cache hits too.
     *
     * @param int $postId Current post id.
     * @param array<int, int> $tagIds Tag ids of the current post.
     * @param array<int, int> $categoryIds Category ids of the current post.
     * @param int $authorId Author id of the current post.
     * @return array<int, array{id: int, tags: array<int, int>, categories: array<int, int>,
     *     author: int, date: int}> Candidates for the scorer.
     */
    private static function scoredCandidates(int $postId, array $tagIds, array $categoryIds, int $authorId): array
    {
        $taxQuery = ['relation' => 'OR'];
        if ($tagIds !== []) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => $tagIds,
            ];
        }
        if ($categoryIds !== []) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => $categoryIds,
            ];
        }
        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => self::CANDIDATE_LIMIT,
            'post__not_in' => [$postId],
            'tax_query' => $taxQuery,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'fields' => 'ids',
        ]);
        $ids = is_array($query->posts) ? $query->posts : [];
        if ($authorId > 0) {
            $authorQuery = new WP_Query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => self::CANDIDATE_LIMIT,
                'post__not_in' => array_merge([$postId], $ids),
                'author' => $authorId,
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'fields' => 'ids',
            ]);
            if (is_array($authorQuery->posts)) {
                $ids = array_merge($ids, $authorQuery->posts);
            }
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => absint($id),
            $ids
        ), static fn (int $id): bool => $id > 0 && $id !== $postId)));
        if ($ids !== []) {
            // Bulk-prime the caches the scoring loop reads, in
            // two queries instead of two per candidate: the
            // post objects (the id queries do not prime them)
            // and every candidate's term relationships (the id
            // queries do not prime those either). Without this
            // the loop below is an N+1: one post query plus one
            // tag query per candidate (~120 queries on a cold
            // cache, ~215 before the term priming landed).
            _prime_post_caches($ids);
            update_object_term_cache($ids, 'post');
        }
        $candidates = [];
        foreach ($ids as $id) {
            $date = get_post_timestamp($id);
            if (!is_int($date)) {
                $rawDate = get_post_field('post_date', $id);
                $date = is_string($rawDate) && $rawDate !== '' ? (int) strtotime($rawDate) : 0;
            }
            $author = get_post_field('post_author', $id);
            $candidates[] = [
                'id' => $id,
                'tags' => self::postTagIds($id),
                'categories' => self::postCategoryIds($id),
                'author' => is_int($author) || is_string($author) ? (int) $author : 0,
                'date' => $date,
            ];
        }
        return $candidates;
    }

    /**
     * Latest published post ids, newest first, excluding given ids.
     *
     * Used for both fallback steps: with a category id it is the
     * latest posts in that category, without one the latest posts
     * overall.
     *
     * @param array<int, int> $exclude Post ids to exclude (current post first).
     * @param int $limit Number of ids to fetch.
     * @param int|null $categoryId Category to limit to, or null for all posts.
     * @return array<int, int> Post ids, newest first.
     */
    private static function latestPostIds(array $exclude, int $limit, ?int $categoryId): array
    {
        if ($limit <= 0) {
            return [];
        }
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array_values(array_unique($exclude)),
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'fields' => 'ids',
        ];
        if ($categoryId !== null && $categoryId > 0) {
            $args['category__in'] = [$categoryId];
        }
        $query = new WP_Query($args);
        if (!is_array($query->posts)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => absint($id),
            $query->posts
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * Tag ids of a post, as integers.
     *
     * Reads through get_the_terms(), not wp_get_post_tags():
     * wp_get_post_tags() queries the database directly, while
     * get_the_terms() reads the term-relationship cache that
     * scoredCandidates() primes in bulk for every candidate.
     *
     * @param int $postId Post id.
     * @return array<int, int>
     */
    private static function postTagIds(int $postId): array
    {
        $terms = get_the_terms($postId, 'post_tag');
        if (!is_array($terms)) {
            return [];
        }
        $ids = [];
        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $ids[] = $term->term_id;
            }
        }
        return array_values(array_filter(array_map(
            static fn (mixed $id): int => absint($id),
            $ids
        ), static fn (int $id): bool => $id > 0));
    }

    /**
     * Category ids of a post, as integers, in term order.
     *
     * The first id is the post's primary category for the
     * fallback step.
     *
     * @param int $postId Post id.
     * @return array<int, int>
     */
    private static function postCategoryIds(int $postId): array
    {
        $ids = [];
        foreach (get_the_category($postId) as $term) {
            $ids[] = absint($term->term_id);
        }
        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    /**
     * Versioned transient key for a post's cached related list.
     *
     * @param int $postId Post id.
     * @return string
     */
    private static function cacheKey(int $postId): string
    {
        $version = (int) get_option(self::VERSION_OPTION, 0);
        return 'traydigita_related_posts_' . $version . '_' . $postId;
    }

    /**
     * Clamp a requested count to the allowed range.
     *
     * @param int $count Requested count.
     * @return int
     */
    private static function clampCount(int $count): int
    {
        if ($count < self::MIN_COUNT) {
            return self::MIN_COUNT;
        }
        if ($count > self::MAX_COUNT) {
            return self::MAX_COUNT;
        }
        return $count;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{count: int, show_thumbnails: bool, heading: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $heading = isset($settings['heading']) && is_string($settings['heading']) ? $settings['heading'] : '';
        if ($heading === 'Baca juga') {
            // Legacy stored value (pre-i18n default): migrate to the English default.
            $heading = self::DEFAULT_HEADING;
        }
        if ($heading === '') {
            $heading = self::DEFAULT_HEADING;
        }
        return [
            'count' => self::clampCount(isset($settings['count']) ? absint($settings['count']) : self::DEFAULT_COUNT),
            'show_thumbnails' => (bool) ($settings['show_thumbnails'] ?? false),
            'heading' => $heading,
        ];
    }

    /**
     * Default settings.
     *
     * Four posts, thumbnails on, heading "Read also".
     *
     * @return array{count: int, show_thumbnails: bool, heading: string}
     */
    public static function getDefaults(): array
    {
        return [
            'count' => self::DEFAULT_COUNT,
            'show_thumbnails' => true,
            'heading' => self::DEFAULT_HEADING,
        ];
    }
}
