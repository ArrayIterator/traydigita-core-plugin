<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use TrayDigita\WP\Plugin\Core\Stack\Libs\Data\FeatureImage;
use function __;
use function add_filter;
use function add_rewrite_rule;
use function array_reverse;
use function class_exists;
use function count;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_author_posts_url;
use function get_bloginfo;
use function get_category_link;
use function get_day_link;
use function get_locale;
use function get_month_link;
use function get_permalink;
use function get_post;
use function get_post_ancestors;
use function get_post_meta;
use function get_post_time;
use function get_post_type_archive_link;
use function get_posts;
use function get_query_var;
use function get_queried_object;
use function get_queried_object_id;
use function get_term_link;
use function get_the_author_meta;
use function get_the_category;
use function get_the_date;
use function get_the_modified_date;
use function get_the_title;
use function get_theme_mod;
use function get_year_link;
use function header;
use function home_url;
use function is_404;
use function is_admin;
use function is_array;
use function is_author;
use function is_category;
use function is_day;
use function is_feed;
use function is_front_page;
use function is_home;
use function is_month;
use function is_post_type_archive;
use function is_search;
use function is_singular;
use function is_string;
use function is_tag;
use function is_tax;
use function is_wp_error;
use function is_year;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;
use function nocache_headers;
use function preg_replace;
use function status_header;
use function str_contains;
use function strtolower;
use function substr;
use function trim;
use function wp_get_attachment_image_url;
use function wp_get_document_title;
use function wp_json_encode;
use function wp_strip_all_tags;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_UNESCAPED_UNICODE;

/**
 * Frontend SEO output: meta tags, structured data, and the Google News sitemap.
 *
 * Compatibility first: every public method bails out when a known
 * SEO plugin is active (see SeoDetector), so the theme never emits
 * a duplicate title, meta description, canonical, Open Graph or
 * Twitter tag, duplicate structured data, or a duplicate sitemap.
 * No tag is ever printed without real data behind it: a missing
 * description, image, or author simply means that tag is skipped,
 * never invented.
 *
 * All output goes through wp_head (meta at priority 20, JSON-LD at
 * 21) or the news-sitemap template_redirect handler. Nothing here
 * makes network calls, runs raw SQL, or reads options other than
 * the module's own settings and the active-plugin lists.
 */
final class SeoOutput
{
    /**
     * Query variable that triggers the Google News sitemap.
     */
    public const string SITEMAP_QUERY_VAR = 'traydigita_news_sitemap';

    /**
     * Rewrite rule matching the news sitemap URL.
     */
    public const string SITEMAP_REGEX = '^news-sitemap\\.xml$';

    /**
     * News sitemap file name, as served from the site root.
     */
    public const string SITEMAP_FILE = 'news-sitemap.xml';

    /**
     * Absolute URL of the news sitemap.
     *
     * @return string
     */
    public static function sitemapUrl(): string
    {
        return home_url('/' . self::SITEMAP_FILE);
    }

    /**
     * Maximum number of URLs in the news sitemap (Google News limit).
     */
    public const int SITEMAP_MAX_URLS = 1000;

    /**
     * How far back the news sitemap reaches, in hours.
     */
    public const int SITEMAP_MAX_AGE_HOURS = 48;

    /**
     * Maximum meta description length in characters.
     */
    public const int DESCRIPTION_MAX_LENGTH = 160;

    /**
     * Post meta key for a per-post title override (AI suggestion or manual).
     */
    public const string META_TITLE = '_traydigita_seo_title';

    /**
     * Post meta key for a per-post description override (AI suggestion or manual).
     */
    public const string META_DESCRIPTION = '_traydigita_seo_description';

    /**
     * Replace the document title parts with the templated title.
     *
     * Hooked on `document_title_parts`. Only when no SEO plugin is
     * active and the meta toggle is on: the parts are replaced with
     * a single `title` part holding the fully rendered template
     * (SeoTemplates), so WordPress appends nothing else to it —
     * the site name and separator already live inside the
     * template. A per-post title override (see META_TITLE) wins
     * over the template on singular posts and pages. Search and
     * 404 pages keep WordPress's own title, because the theme
     * outputs no description or canonical for them either.
     *
     * @param array<string, string> $parts Document title parts.
     * @return array<string, string>
     */
    public static function filterDocumentTitle(array $parts): array
    {
        $settings = SeoModule::getSettings();
        if (!$settings['meta_output'] || SeoDetector::hasSeoPlugin()) {
            return $parts;
        }
        if (is_search() || is_404()) {
            return $parts;
        }
        // E-Paper views own their title (see EPaperFrontend):
        // the archive gets "E-Paper" and a single issue gets its
        // title, so the templated title must not overwrite them.
        if (get_query_var('traydigita_epaper_archive') !== ''
            || get_query_var('traydigita_epaper_issue') !== ''
        ) {
            return $parts;
        }
        if (is_singular()) {
            $override = self::getPostOverride(self::META_TITLE);
            if ($override !== '') {
                return ['title' => $override];
            }
        }
        $title = SeoTemplates::titleForRequest($settings);
        if ($title === '') {
            return $parts;
        }
        return ['title' => $title];
    }

    /**
     * Per-post SEO override for the current singular post, or an empty string when none is stored.
     *
     * Overrides are written by the AI suggestions panel (or any
     * future editor) and are read only in native mode — the
     * callers of this method are all gated on the SEO plugin
     * detection, so an override can never leak into a page an SEO
     * plugin owns.
     *
     * @param string $metaKey One of the META_* constants.
     * @return string
     */
    private static function getPostOverride(string $metaKey): string
    {
        if (!is_singular()) {
            return '';
        }
        $post = get_post();
        if ($post === null) {
            return '';
        }
        $value = get_post_meta($post->ID, $metaKey, true);
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }

    /**
     * Register the news sitemap rewrite rule and query variable.
     *
     * Called on `init` from SeoModule::boot(). The rule is only
     * registered while the sitemap toggle is on; the handler
     * (maybeServeNewsSitemap) re-checks the toggle and the SEO
     * plugin detection at request time, so a stale rule left in
     * the database after the toggle was switched off can never
     * serve a sitemap — it answers 404 instead.
     *
     * @return void
     */
    public static function registerNewsSitemap(): void
    {
        // The query var is registered even while the news sitemap
        // is switched off, so maybeServeNewsSitemap() still sees the
        // request and answers 404 explicitly instead of letting
        // WordPress serve the homepage for a stale rewrite rule.
        // Only the canonical filter and the rewrite rule are gated
        // on the toggle.
        add_filter('query_vars', [self::class, 'addNewsSitemapQueryVar']);
        $settings = SeoModule::getSettings();
        if (!$settings['news_sitemap']) {
            return;
        }
        add_filter('redirect_canonical', [self::class, 'noCanonicalForNewsSitemap'], 10, 2);
        add_rewrite_rule(
            self::SITEMAP_REGEX,
            'index.php?' . self::SITEMAP_QUERY_VAR . '=1',
            'top'
        );
    }

    /**
     * Never let WordPress canonical-redirect the news sitemap URL.
     *
     * redirect_canonical appends a trailing slash to
     * /news-sitemap.xml, so the sitemap would answer 301 instead of
     * serving XML directly. Same pattern as the push plugin uses for
     * /firebase-messaging-sw.js. Only the sitemap URL is exempted;
     * every other request keeps its normal canonical redirect.
     *
     * @param string|false $redirectUrl Canonical URL WordPress wants to redirect to.
     * @param string $requestedUrl URL that was requested.
     * @return string|false False for the sitemap URL, the redirect URL otherwise.
     */
    public static function noCanonicalForNewsSitemap(string|false $redirectUrl, string $requestedUrl): string|false
    {
        if (str_contains($requestedUrl, 'news-sitemap.xml')) {
            return false;
        }
        return $redirectUrl;
    }

    /**
     * Add the news sitemap query variable to the allowlist.
     *
     * @param array<int, string> $vars Registered public query variables.
     * @return array<int, string>
     */
    public static function addNewsSitemapQueryVar(array $vars): array
    {
        $vars[] = self::SITEMAP_QUERY_VAR;
        return $vars;
    }

    /**
     * Serve the Google News sitemap, or answer 404 when it must not be served.
     *
     * Hooked on `template_redirect`. Only requests that matched the
     * news-sitemap rewrite rule carry the query variable; every
     * other request returns immediately. When the sitemap is
     * requested but the toggle is off or an SEO plugin is active,
     * the request is marked 404 and WordPress renders the 404
     * template instead of the home page.
     *
     * @return void
     */
    public static function maybeServeNewsSitemap(): void
    {
        if (get_query_var(self::SITEMAP_QUERY_VAR) === '') {
            return;
        }
        if (!self::canServeNewsSitemap()) {
            $GLOBALS['wp_query']->set_404();
            status_header(404);
            return;
        }
        nocache_headers();
        header('Content-Type: text/xml; charset=utf-8');
        echo self::buildNewsSitemapXml();
        exit;
    }

    /**
     * Whether the news sitemap may be served for this request.
     *
     * Both conditions must hold: the toggle is on, and no SEO
     * plugin is active (an active SEO plugin may serve its own
     * news sitemap, so the theme must not serve a second one).
     *
     * @return bool
     */
    public static function canServeNewsSitemap(): bool
    {
        $settings = SeoModule::getSettings();
        return $settings['news_sitemap'] && !SeoDetector::hasSeoPlugin();
    }

    /**
     * Render the theme's own meta tags in wp_head.
     *
     * Outputs, only when no SEO plugin is active and the meta
     * toggle is on: robots, meta description, canonical, Open
     * Graph, and Twitter card tags. Search and 404 pages get a
     * `noindex, nofollow` robots tag and nothing else — there is
     * no description or canonical worth publishing for them.
     *
     * @return void
     */
    public static function renderMeta(): void
    {
        $settings = SeoModule::getSettings();
        if (!$settings['meta_output'] || SeoDetector::hasSeoPlugin()) {
            return;
        }
        if (is_admin() || is_feed()) {
            return;
        }
        if (is_search() || is_404()) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n";
            return;
        }
        echo '<meta name="robots" content="index, follow, max-image-preview:large">' . "\n";
        $description = self::getDescription();
        if ($description !== '') {
            echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        }
        $canonical = self::getCanonicalUrl();
        if ($canonical !== '') {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        }
        $title = wp_get_document_title();
        if ($title !== '') {
            echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        }
        if ($description !== '') {
            echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        }
        echo '<meta property="og:type" content="' . (is_singular('post') ? 'article' : 'website') . '">' . "\n";
        if ($canonical !== '') {
            echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
        }
        $siteName = get_bloginfo('name');
        if ($siteName !== '') {
            echo '<meta property="og:site_name" content="' . esc_attr($siteName) . '">' . "\n";
        }
        $image = self::getImageData();
        if ($image !== null) {
            echo '<meta property="og:image" content="' . esc_url($image['url']) . '">' . "\n";
            if ($image['width'] > 0) {
                echo '<meta property="og:image:width" content="' . $image['width'] . '">' . "\n";
            }
            if ($image['height'] > 0) {
                echo '<meta property="og:image:height" content="' . $image['height'] . '">' . "\n";
            }
            if (is_singular('post')) {
                $published = get_the_date('c');
                if (is_string($published) && $published !== '') {
                    echo '<meta property="article:published_time" content="' . esc_attr($published) . '">' . "\n";
                }
                $modified = get_the_modified_date('c');
                if (is_string($modified) && $modified !== '') {
                    echo '<meta property="article:modified_time" content="' . esc_attr($modified) . '">' . "\n";
                }
            }
        }
        $twitterCard = $image !== null ? 'summary_large_image' : 'summary';
        echo '<meta name="twitter:card" content="' . $twitterCard . '">' . "\n";
        if ($title !== '') {
            echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        }
        if ($description !== '') {
            echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        }
        if ($image !== null) {
            echo '<meta name="twitter:image" content="' . esc_url($image['url']) . '">' . "\n";
        }
    }

    /**
     * Render the theme's own JSON-LD structured data in wp_head.
     *
     * Outputs, only when no SEO plugin is active: a NewsArticle
     * block on single posts (when the JSON-LD toggle is on) and a
     * BreadcrumbList block on singular posts and pages (when the
     * breadcrumb toggle is on). Every field is included only when
     * the underlying data exists.
     *
     * @return void
     */
    public static function renderJsonLd(): void
    {
        $settings = SeoModule::getSettings();
        if (SeoDetector::hasSeoPlugin()) {
            return;
        }
        if (is_admin() || is_feed()) {
            return;
        }
        if ($settings['json_ld'] && is_singular('post')) {
            $article = self::buildNewsArticleData();
            if ($article !== null) {
                self::printJsonLd($article);
            }
        }
        if ($settings['breadcrumbs'] && is_singular()) {
            $breadcrumbs = self::buildBreadcrumbData();
            if ($breadcrumbs !== null) {
                self::printJsonLd($breadcrumbs);
            }
        }
    }

    /**
     * Meta description for the current request, or an empty string when there is none.
     *
     * A per-post description override (see META_DESCRIPTION) wins
     * on singular posts and pages. Otherwise the description
     * template for the current context is expanded
     * (SeoTemplates): post excerpt, page excerpt, or term
     * description via %excerpt%, site tagline via %sitetagline%.
     * The text is stripped of tags, collapsed to single spaces,
     * and cut to 160 characters at a word boundary. A description
     * that expands to nothing yields an empty string, so no meta
     * description tag is printed.
     *
     * @return string
     */
    private static function getDescription(): string
    {
        $override = self::getPostOverride(self::META_DESCRIPTION);
        $text = $override !== ''
            ? $override
            : SeoTemplates::descriptionForRequest(SeoModule::getSettings());
        $text = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($text)));
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text) > self::DESCRIPTION_MAX_LENGTH) {
            $cut = mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH);
            $lastSpace = mb_strrpos($cut, ' ');
            if ($lastSpace !== false && $lastSpace > 0) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
            $text = trim($cut);
        }
        return $text;
    }

    /**
     * Canonical URL for the current request, or an empty string when there is none.
     *
     * Covers singular posts/pages, the home page, term archives,
     * author archives, post-type archives, and date archives.
     * Anything else (search, 404, and other unhandled views) has
     * no canonical URL.
     *
     * @return string
     */
    private static function getCanonicalUrl(): string
    {
        if (is_singular()) {
            $url = get_permalink();
            return $url === false ? '' : $url;
        }
        if (is_front_page() || is_home()) {
            return home_url('/');
        }
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof \WP_Term) {
                $url = get_term_link($term);
                return is_wp_error($url) ? '' : $url;
            }
            return '';
        }
        if (is_author()) {
            return get_author_posts_url(get_queried_object_id());
        }
        if (is_post_type_archive()) {
            $postType = (string) get_query_var('post_type');
            if ($postType === '') {
                return '';
            }
            $url = get_post_type_archive_link($postType);
            return $url === false ? '' : $url;
        }
        if (is_day()) {
            return get_day_link(
                (int) get_query_var('year'),
                (int) get_query_var('monthnum'),
                (int) get_query_var('day')
            );
        }
        if (is_month()) {
            return get_month_link(
                (int) get_query_var('year'),
                (int) get_query_var('monthnum')
            );
        }
        if (is_year()) {
            return get_year_link((int) get_query_var('year'));
        }
        return '';
    }

    /**
     * Featured image data for the current singular post, or null when there is none.
     *
     * Resolved through the traydigita-stack FeatureImage helper
     * (guarded by class_exists, because the stack plugin could be
     * unavailable in a partial load). The helper falls back to the
     * first content image when no featured image is set, and
     * returns null when the post has no image at all — in that
     * case no image tag is printed anywhere.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    private static function getImageData(): ?array
    {
        if (!is_singular()) {
            return null;
        }
        if (!class_exists(FeatureImage::class)) {
            return null;
        }
        $post = get_post();
        if ($post === null) {
            return null;
        }
        $image = new FeatureImage($post);
        if (!$image->isFound()) {
            return null;
        }
        $thumbnail = $image->getThumbnail('full');
        if (!is_array($thumbnail) || empty($thumbnail['url']) || !is_string($thumbnail['url'])) {
            return null;
        }
        return [
            'url' => $thumbnail['url'],
            'width' => isset($thumbnail['width']) ? (int) $thumbnail['width'] : 0,
            'height' => isset($thumbnail['height']) ? (int) $thumbnail['height'] : 0,
        ];
    }

    /**
     * NewsArticle structured data for the current single post, or null when it has no headline.
     *
     * Headline, dates, author, publisher, and image are included
     * only when the data exists: a post without an author name
     * gets no author block, and a site without a custom logo gets
     * a publisher without a logo — never a placeholder.
     *
     * @return array<string, mixed>|null
     */
    private static function buildNewsArticleData(): ?array
    {
        $post = get_post();
        if ($post === null) {
            return null;
        }
        $headline = get_the_title($post);
        if ($headline === '') {
            return null;
        }
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $headline,
        ];
        $published = get_the_date('c', $post);
        if ($published !== '') {
            $data['datePublished'] = $published;
        }
        $modified = get_the_modified_date('c', $post);
        if ($modified !== '') {
            $data['dateModified'] = $modified;
        }
        $author = get_the_author_meta('display_name', (int) $post->post_author);
        if ($author !== '') {
            $data['author'] = [
                '@type' => 'Person',
                'name' => $author,
            ];
        }
        $publisher = [
            '@type' => 'Organization',
            'name' => get_bloginfo('name'),
        ];
        $logoId = (int) get_theme_mod('custom_logo');
        if ($logoId > 0) {
            $logoUrl = wp_get_attachment_image_url($logoId, 'full');
            if ($logoUrl !== false) {
                $publisher['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $logoUrl,
                ];
            }
        }
        $data['publisher'] = $publisher;
        $image = self::getImageData();
        if ($image !== null) {
            $data['image'] = [$image['url']];
        }
        return $data;
    }

    /**
     * BreadcrumbList structured data for the current singular post or page.
     *
     * Trail: Home, then the first category (posts) or the page
     * ancestors (pages), then the current post or page. Returns
     * null when the trail would be a single item, because a
     * one-item breadcrumb carries no information.
     *
     * @return array<string, mixed>|null
     */
    private static function buildBreadcrumbData(): ?array
    {
        $post = get_post();
        if ($post === null) {
            return null;
        }
        $items = [
            [
                'name' => __('Home', 'traydigita-core'),
                'url' => home_url('/'),
            ],
        ];
        if ($post->post_type === 'post') {
            $categories = get_the_category($post->ID);
            if ($categories !== []) {
                $category = $categories[0];
                $link = get_category_link($category);
                if (!is_wp_error($link)) {
                    $items[] = [
                        'name' => $category->name,
                        'url' => $link,
                    ];
                }
            }
        } elseif ($post->post_type === 'page') {
            $ancestors = array_reverse(get_post_ancestors($post));
            foreach ($ancestors as $ancestorId) {
                $link = get_permalink($ancestorId);
                $title = get_the_title($ancestorId);
                if ($link !== false && $title !== '') {
                    $items[] = [
                        'name' => $title,
                        'url' => $link,
                    ];
                }
            }
        }
        $currentUrl = get_permalink($post);
        $currentTitle = get_the_title($post);
        if ($currentUrl !== false && $currentTitle !== '') {
            $items[] = [
                'name' => $currentTitle,
                'url' => $currentUrl,
            ];
        }
        if (count($items) < 2) {
            return null;
        }
        $elements = [];
        foreach ($items as $index => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item['name'],
                'item' => $item['url'],
            ];
        }
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    /**
     * Print one JSON-LD block, safely encoded.
     *
     * The JSON_HEX_* flags escape angle brackets, ampersands, and
     * quotes, so a `<` in a post title can never break out of the
     * script element. A failed encode prints nothing.
     *
     * @param array<string, mixed> $data Structured data.
     * @return void
     */
    private static function printJsonLd(array $data): void
    {
        $json = wp_json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return;
        }
        echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    /**
     * Build the Google News sitemap XML for posts from the last 48 hours.
     *
     * Up to 1000 published posts, newest first. Every URL, title,
     * publication name, and date is escaped for XML; a post
     * missing a permalink, a title, or a date is skipped rather
     * than emitted with invented data. The publication language
     * is the two-letter language code of the site locale.
     *
     * @return string
     */
    private static function buildNewsSitemapXml(): string
    {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => self::SITEMAP_MAX_URLS,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_query' => [
                [
                    'column' => 'post_date_gmt',
                    'after' => self::SITEMAP_MAX_AGE_HOURS . ' hours ago',
                ],
            ],
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $siteName = get_bloginfo('name');
        $language = strtolower(substr(get_locale(), 0, 2));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= ' xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
        foreach ($posts as $post) {
            $url = get_permalink($post);
            $title = get_the_title($post);
            $date = get_post_time('c', true, $post);
            if ($url === false || $title === '' || !is_string($date) || $date === '') {
                continue;
            }
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . esc_url($url) . '</loc>' . "\n";
            $xml .= '    <news:news>' . "\n";
            $xml .= '      <news:publication><news:name>' . esc_html($siteName) . '</news:name>';
            $xml .= '<news:language>' . esc_html($language) . '</news:language></news:publication>' . "\n";
            $xml .= '      <news:publication_date>' . esc_html($date) . '</news:publication_date>' . "\n";
            $xml .= '      <news:title>' . esc_html($title) . '</news:title>' . "\n";
            $xml .= '    </news:news>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";
        return $xml;
    }
}
