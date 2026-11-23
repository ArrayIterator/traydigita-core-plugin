<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use function __;
use function array_keys;
use function array_merge;
use function array_values;
use function get_bloginfo;
use function get_post;
use function get_queried_object;
use function get_query_var;
use function get_the_author_meta;
use function get_the_category;
use function get_the_date;
use function get_the_excerpt;
use function get_the_tags;
use function get_the_title;
use function is_archive;
use function is_author;
use function is_category;
use function is_date;
use function is_front_page;
use function is_home;
use function is_singular;
use function is_tag;
use function is_tax;
use function mb_strlen;
use function mb_substr;
use function preg_quote;
use function preg_replace;
use function sprintf;
use function str_replace;
use function trim;
use function wp_strip_all_tags;
use WP_Post;
use WP_Term;

/**
 * Meta title and description templates with %variable% placeholders.
 *
 * Supported variables (every template may use any of them; a
 * variable with no value for the current page expands to an empty
 * string and is cleaned up afterwards):
 * - %title% — post or page title, term name, author name, or the
 *   site name on the home page.
 * - %site% — site name.
 * - %sitetagline% — site tagline.
 * - %sep% — the configured title separator.
 * - %excerpt% — post excerpt, page excerpt, or term description.
 * - %category% — first category name (posts only).
 * - %tag% — first tag name (posts only).
 * - %author% — post author display name, or the author archive name.
 * - %date% — post date, or the date archive label.
 * - %page% — "Page N" on paged archives, empty otherwise.
 *
 * Expansion never invents data: unknown placeholders are left
 * untouched so a typo stays visible in the settings preview
 * instead of silently vanishing, and a template that expands to
 * nothing yields an empty string (the caller then falls back to
 * WordPress's own title or skips the tag).
 */
final class SeoTemplates
{
    /**
     * Maximum template length in characters (admin input is cut to this).
     */
    public const int TEMPLATE_MAX_LENGTH = 200;

    /**
     * Supported template variables, placeholder => description.
     *
     * @var array<string, string>
     */
    public const array VARIABLES = [
        '%title%' => 'Post, page, term, or author title',
        '%site%' => 'Site name',
        '%sitetagline%' => 'Site tagline',
        '%sep%' => 'Title separator',
        '%excerpt%' => 'Post excerpt or term description',
        '%category%' => 'First category name',
        '%tag%' => 'First tag name',
        '%author%' => 'Author display name',
        '%date%' => 'Post or archive date',
        '%page%' => 'Page number on paged archives',
    ];

    /**
     * Default templates, keyed by setting name.
     *
     * @return array{title_post: string, title_page: string, title_home: string, title_archive: string, title_sep: string, description_post: string, description_home: string, description_archive: string}
     */
    public static function defaults(): array
    {
        return [
            'title_post' => '%title% %sep% %site%',
            'title_page' => '%title% %sep% %site%',
            'title_home' => '%site% %sep% %sitetagline%',
            'title_archive' => '%title% %sep% %site%',
            'title_sep' => '|',
            'description_post' => '%excerpt%',
            'description_home' => '%sitetagline%',
            'description_archive' => '%excerpt%',
        ];
    }

    /**
     * Rendered meta title for the current request, or an empty string when there is none.
     *
     * Picks the template by context (single post, page, home page,
     * or archive) and expands it against the current request. An
     * empty result means the caller should fall back to
     * WordPress's own document title.
     *
     * @param array<string, mixed> $settings Module settings (must carry the template keys).
     * @return string
     */
    public static function titleForRequest(array $settings): string
    {
        $context = self::contextForRequest();
        $context['sep'] = (string) ($settings['title_sep'] ?? self::defaults()['title_sep']);
        if (is_singular('post')) {
            $template = (string) ($settings['title_post'] ?? self::defaults()['title_post']);
        } elseif (is_singular('page')) {
            $template = (string) ($settings['title_page'] ?? self::defaults()['title_page']);
        } elseif (is_home() || is_front_page()) {
            $template = (string) ($settings['title_home'] ?? self::defaults()['title_home']);
        } elseif (is_archive()) {
            $template = (string) ($settings['title_archive'] ?? self::defaults()['title_archive']);
        } else {
            return '';
        }
        return self::expand($template, $context);
    }

    /**
     * Rendered meta description for the current request, or an empty string when there is none.
     *
     * Picks the template by context and expands it. A description
     * that expands to nothing (for example an archive with no term
     * description) yields an empty string, so the caller prints no
     * meta description tag at all.
     *
     * @param array<string, mixed> $settings Module settings (must carry the template keys).
     * @return string
     */
    public static function descriptionForRequest(array $settings): string
    {
        $context = self::contextForRequest();
        $context['sep'] = (string) ($settings['title_sep'] ?? self::defaults()['title_sep']);
        if (is_singular()) {
            $template = (string) ($settings['description_post'] ?? self::defaults()['description_post']);
        } elseif (is_home() || is_front_page()) {
            $template = (string) ($settings['description_home'] ?? self::defaults()['description_home']);
        } elseif (is_archive()) {
            $template = (string) ($settings['description_archive'] ?? self::defaults()['description_archive']);
        } else {
            return '';
        }
        return self::expand($template, $context);
    }

    /**
     * Template context for the current request.
     *
     * Every key is always present (empty string when the value does
     * not exist for this page), so expand() never has to guess.
     *
     * @return array{title: string, site: string, sitetagline: string, sep: string, excerpt: string, category: string, tag: string, author: string, date: string, page: string}
     */
    public static function contextForRequest(): array
    {
        $context = [
            'title' => '',
            'site' => (string) get_bloginfo('name'),
            'sitetagline' => (string) get_bloginfo('description'),
            'sep' => '',
            'excerpt' => '',
            'category' => '',
            'tag' => '',
            'author' => '',
            'date' => '',
            'page' => '',
        ];
        $paged = (int) get_query_var('paged');
        if ($paged === 0) {
            $paged = (int) get_query_var('page');
        }
        if ($paged > 1) {
            /* translators: %d is the page number of a paged archive. */
            $context['page'] = sprintf(__('Page %d', 'traydigita-core'), $paged);
        }
        if (is_singular()) {
            $post = get_post();
            if ($post instanceof WP_Post) {
                $context = array_merge($context, self::contextForPost($post));
            }
            return $context;
        }
        if (is_home() || is_front_page()) {
            $context['title'] = (string) get_bloginfo('name');
            $context['excerpt'] = (string) get_bloginfo('description');
            return $context;
        }
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $context['title'] = $term->name;
                $context['excerpt'] = (string) $term->description;
                if (is_category()) {
                    $context['category'] = $term->name;
                }
                if (is_tag()) {
                    $context['tag'] = $term->name;
                }
            }
            return $context;
        }
        if (is_author()) {
            $authorId = (int) get_query_var('author');
            if ($authorId > 0) {
                $name = get_the_author_meta('display_name', $authorId);
                $context['title'] = $name;
                $context['author'] = $name;
            }
            return $context;
        }
        if (is_date()) {
            $date = (string) get_the_date();
            $context['title'] = $date;
            $context['date'] = $date;
            return $context;
        }
        return $context;
    }

    /**
     * Template context for one post or page.
     *
     * @param WP_Post $post Post or page.
     * @return array{title: string, excerpt: string, category: string, tag: string, author: string, date: string}
     */
    public static function contextForPost(WP_Post $post): array
    {
        $context = [
            'title' => get_the_title($post),
            'excerpt' => get_the_excerpt($post),
            'category' => '',
            'tag' => '',
            'author' => get_the_author_meta('display_name', (int) $post->post_author),
            'date' => (string) get_the_date('', $post),
        ];
        if ($post->post_type === 'post') {
            $categories = get_the_category($post->ID);
            if ($categories !== []) {
                $context['category'] = $categories[0]->name;
            }
            $tags = get_the_tags($post->ID);
            if ($tags !== false && !is_wp_error($tags) && $tags !== []) {
                $context['tag'] = $tags[0]->name;
            }
        }
        return $context;
    }

    /**
     * Expand a template against a context.
     *
     * Each known placeholder is replaced with its context value
     * (whitespace-collapsed, tags stripped); unknown placeholders
     * are left untouched. The result is then tidied: collapsed to
     * single spaces, trimmed, and stripped of a separator left
     * dangling at either end (or doubled in the middle) when its
     * neighbouring variable was empty.
     *
     * @param string $template Template text with %variable% placeholders.
     * @param array<string, string> $context Placeholder values without the % signs.
     * @return string
     */
    public static function expand(string $template, array $context): string
    {
        $sep = $context['sep'] ?? '';
        $replacements = [];
        foreach ($context as $key => $value) {
            $clean = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($value)));
            $replacements['%' . $key . '%'] = $clean;
        }
        $expanded = str_replace(array_keys($replacements), array_values($replacements), $template);
        $expanded = trim((string) preg_replace('/\s+/', ' ', $expanded));
        if ($expanded === '') {
            return '';
        }
        if ($sep !== '') {
            $escaped = preg_quote($sep, '/');
            $expanded = (string) preg_replace('/(\s*' . $escaped . '\s*){2,}/', ' ' . $sep . ' ', $expanded);
            $expanded = trim((string) preg_replace('/^' . $escaped . '\s*|\s*' . $escaped . '$/', '', $expanded));
            $expanded = trim((string) preg_replace('/\s+/', ' ', $expanded));
        }
        return $expanded;
    }

    /**
     * Cut a template to the stored maximum length.
     *
     * @param string $template Raw admin input.
     * @return string
     */
    public static function sanitizeTemplate(string $template): string
    {
        $template = trim($template);
        if (mb_strlen($template) > self::TEMPLATE_MAX_LENGTH) {
            $template = trim(mb_substr($template, 0, self::TEMPLATE_MAX_LENGTH));
        }
        return $template;
    }
}
