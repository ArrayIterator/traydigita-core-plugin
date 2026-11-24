<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use function add_filter;
use function add_rewrite_rule;
use function esc_html;
use function esc_url;
use function get_categories;
use function get_category_link;
use function get_permalink;
use function get_post_modified_time;
use function get_posts;
use function get_query_var;
use function header;
use function home_url;
use function is_wp_error;
use function nocache_headers;
use function preg_quote;
use function status_header;

/**
 * XML sitemaps (posts, pages, and categories) plus a sitemap index.
 *
 * Served at /sitemap.xml (index), /sitemap-posts.xml,
 * /sitemap-pages.xml, and /sitemap-categories.xml, only while the
 * XML sitemap toggle is on and no SEO plugin is active — an active
 * SEO plugin serves its own sitemaps, so the theme must not serve a
 * second set. Every public entry point re-checks both conditions
 * at request time, so a stale rewrite rule left in the database
 * after the toggle was switched off can never serve a sitemap:
 * it answers 404 instead.
 *
 * No sitemap URL is ever emitted with invented data: a post or
 * page without a permalink or a modification date is skipped, and
 * a category without a link is skipped. Nothing here makes network
 * calls or runs raw SQL.
 */
final class SeoSitemap
{
    /**
     * Query variable carrying the requested sitemap type.
     */
    public const string QUERY_VAR = 'traydigita_sitemap';

    /**
     * Sitemap type: the index listing the three sub-sitemaps.
     */
    public const string TYPE_INDEX = 'index';

    /**
     * Sitemap type: published posts.
     */
    public const string TYPE_POSTS = 'posts';

    /**
     * Sitemap type: published pages.
     */
    public const string TYPE_PAGES = 'pages';

    /**
     * Sitemap type: non-empty categories.
     */
    public const string TYPE_CATEGORIES = 'categories';

    /**
     * Maximum number of URLs in one sitemap file.
     */
    public const int MAX_URLS = 1000;

    /**
     * Sitemap file names, type => URL path.
     *
     * @var array<string, string>
     */
    public const array FILES = [
        self::TYPE_INDEX => 'sitemap.xml',
        self::TYPE_POSTS => 'sitemap-posts.xml',
        self::TYPE_PAGES => 'sitemap-pages.xml',
        self::TYPE_CATEGORIES => 'sitemap-categories.xml',
    ];

    /**
     * Register the sitemap rewrite rules and query variable.
     *
     * Called on `init` from SeoModule::boot(). The rules are only
     * registered while the toggle is on; the handler re-checks the
     * toggle and the SEO plugin detection at request time.
     *
     * @return void
     */
    public static function register(): void
    {
        // The query var is registered even while the sitemaps are
        // switched off, so maybeServe() still sees the request and
        // answers 404 explicitly instead of letting WordPress serve
        // the homepage for a stale rewrite rule. Only the rewrite
        // rules themselves are gated on the toggle.
        add_filter('query_vars', [self::class, 'addQueryVar']);
        $settings = SeoModule::getSettings();
        if (!$settings['xml_sitemap']) {
            return;
        }
        foreach (self::FILES as $type => $file) {
            add_rewrite_rule(
                '^' . preg_quote($file, '/') . '$',
                'index.php?' . self::QUERY_VAR . '=' . $type,
                'top'
            );
        }
    }

    /**
     * Add the sitemap query variable to the allowlist.
     *
     * @param array<int, string> $vars Registered public query variables.
     * @return array<int, string>
     */
    public static function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Serve a requested sitemap, or answer 404 when it must not be served.
     *
     * Hooked on `template_redirect`. Only requests that matched a
     * sitemap rewrite rule carry the query variable; every other
     * request returns immediately. An unknown type, a switched-off
     * toggle, or an active SEO plugin all answer 404.
     *
     * @return void
     */
    public static function maybeServe(): void
    {
        $type = get_query_var(self::QUERY_VAR);
        if ($type === '') {
            return;
        }
        if (!isset(self::FILES[$type]) || !self::canServe()) {
            $GLOBALS['wp_query']->set_404();
            status_header(404);
            return;
        }
        nocache_headers();
        header('Content-Type: text/xml; charset=utf-8');
        echo self::buildXml($type);
        exit;
    }

    /**
     * Whether any sitemap may be served for this request.
     *
     * Both conditions must hold: the toggle is on, and no SEO
     * plugin is active (an active SEO plugin serves its own
     * sitemaps, so the theme must not serve a second set).
     *
     * @return bool
     */
    public static function canServe(): bool
    {
        $settings = SeoModule::getSettings();
        return $settings['xml_sitemap'] && !SeoDetector::hasSeoPlugin();
    }

    /**
     * Absolute URL of one sitemap file, or an empty string for an unknown type.
     *
     * @param string $type Sitemap type from the FILES list.
     * @return string
     */
    public static function url(string $type): string
    {
        if (!isset(self::FILES[$type])) {
            return '';
        }
        return home_url('/' . self::FILES[$type]);
    }

    /**
     * Build the XML for one sitemap type.
     *
     * @param string $type Sitemap type from the FILES list.
     * @return string
     */
    public static function buildXml(string $type): string
    {
        if ($type === self::TYPE_INDEX) {
            return self::buildIndexXml();
        }
        if ($type === self::TYPE_POSTS) {
            return self::buildPostsXml();
        }
        if ($type === self::TYPE_PAGES) {
            return self::buildPagesXml();
        }
        return self::buildCategoriesXml();
    }

    /**
     * Build the sitemap index listing the three sub-sitemaps.
     *
     * Each entry carries the sub-sitemap's last modification date
     * when one is known (newest item); the categories entry has
     * none, because a category list has no single modification
     * date worth publishing.
     *
     * @return string
     */
    private static function buildIndexXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ([self::TYPE_POSTS, self::TYPE_PAGES, self::TYPE_CATEGORIES] as $type) {
            $url = self::url($type);
            if ($url === '') {
                continue;
            }
            $xml .= '  <sitemap>' . "\n";
            $xml .= '    <loc>' . esc_url($url) . '</loc>' . "\n";
            $lastmod = self::newestModified($type);
            if ($lastmod !== '') {
                $xml .= '    <lastmod>' . esc_html($lastmod) . '</lastmod>' . "\n";
            }
            $xml .= '  </sitemap>' . "\n";
        }
        $xml .= '</sitemapindex>' . "\n";
        return $xml;
    }

    /**
     * Build the posts sitemap: up to 1000 published posts, newest first.
     *
     * @return string
     */
    private static function buildPostsXml(): string
    {
        return self::buildPostTypeXml('post');
    }

    /**
     * Build the pages sitemap: up to 1000 published pages, newest first.
     *
     * @return string
     */
    private static function buildPagesXml(): string
    {
        return self::buildPostTypeXml('page');
    }

    /**
     * Build a sitemap for one post type.
     *
     * @param string $postType Post type slug, 'post' or 'page'.
     * @return string
     */
    private static function buildPostTypeXml(string $postType): string
    {
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => self::MAX_URLS,
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($posts as $post) {
            $url = get_permalink($post);
            $modified = get_post_modified_time('c', true, $post);
            if ($url === false || !is_string($modified) || $modified === '') {
                continue;
            }
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . esc_url($url) . '</loc>' . "\n";
            $xml .= '    <lastmod>' . esc_html($modified) . '</lastmod>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    }

    /**
     * Build the categories sitemap: every non-empty category with a link.
     *
     * Categories carry no modification date, so entries list the
     * URL only. A category without a link is skipped rather than
     * emitted with invented data.
     *
     * @return string
     */
    private static function buildCategoriesXml(): string
    {
        $terms = get_categories([
            'hide_empty' => true,
            'number' => self::MAX_URLS,
        ]);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($terms as $term) {
            $url = get_category_link($term);
            if (is_wp_error($url)) {
                continue;
            }
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . esc_url($url) . '</loc>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    }

    /**
     * Newest modification date (ISO 8601) for a sitemap type, or an empty string when unknown.
     *
     * Posts and pages use the newest published item's modification
     * date; categories have no single date, so they return empty.
     *
     * @param string $type Sitemap type from the FILES list.
     * @return string
     */
    private static function newestModified(string $type): string
    {
        if ($type === self::TYPE_CATEGORIES) {
            return '';
        }
        $postType = $type === self::TYPE_PAGES ? 'page' : 'post';
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        if ($posts === []) {
            return '';
        }
        $modified = get_post_modified_time('c', true, $posts[0]);
        if ($modified === false) {
            return '';
        }
        return (string) $modified;
    }
}
