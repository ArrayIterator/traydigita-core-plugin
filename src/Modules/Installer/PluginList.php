<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Installer;

use TrayDigita\WP\Plugin\Core\Modules\Cache\CacheModule;
use TrayDigita\WP\Plugin\Core\Admin\ServerDetector;
use function __;
use function class_exists;
use function defined;
use function file_exists;
use function function_exists;
use function get_plugins;
use function is_array;
use function is_plugin_active;
use function is_string;

/**
 * Curated list of recommended plugins for the Plugin Suggestions module.
 *
 * Trust rule: a plugin is installable from here only when its package
 * comes from the wordpress.org plugin directory. Every entry below
 * is a wordpress.org slug — except traydigita-core, which is the
 * theme's own required companion plugin and is NOT on wordpress.org:
 * it is listed for its required-notice and status only, and
 * InstallerAjax refuses to install it (see isInstallable()).
 *
 * The list is deliberately short and fits a news site. Seven entries
 * overlap a theme module on purpose and say so in their reason:
 * the SEO entries overlap the theme SEO module (the theme defers
 * to any of them when active), and EWWW Image Optimizer overlaps
 * the theme Image Optimizer module (basic on-upload WebP
 * conversion); all are offered only for the extra features they add.
 *
 * WP Rocket is deliberately absent: it is a premium plugin with no
 * wordpress.org package, so it can never be installed from here
 * and must never be added to this list. CacheDetector still
 * detects it (for purge support) — detection and installation
 * are separate concerns.
 *
 * The cache recommendation is server-aware, not static, and it
 * is not decided here: it comes from the shared mapping in
 * CacheModule::recommendedPlugin() (see cacheRecommendation()),
 * the same mapping Site Health, Setup, and the Cache module
 * show, so the badge can never disagree across surfaces.
 * LiteSpeed Cache is recommended on LiteSpeed and OpenLiteSpeed,
 * W3 Total Cache on Apache and nginx; a development site gets no
 * cache recommendation at all, and an unidentified server gets
 * W3 Total Cache as the safe general choice without the badge.
 * Site Kit keeps its recommended badge regardless of server, and
 * so does Rank Math for SEO. The badge is applied lazily: all()
 * layers it on top of the static definitions(), and only the
 * renders that show the badge call all() — status checks read
 * definitions() and never detect anything (see all()).
 *
 * Status detection mirrors SiteKitDetector: get_plugins() for the
 * installed signal, is_plugin_active() for the active signal, with
 * the admin plugin API loaded on demand. This class only reads
 * state; it never installs, activates, or modifies anything.
 */
final class PluginList
{
    /**
     * Plugin file of the required traydigita-core companion plugin.
     *
     * The theme gates its boot on this plugin (functions.php), so a
     * site running the theme normally always has it active. The row
     * still exists so the dependency is visible and its status is
     * checked, not assumed.
     */
    public const string CORE_PLUGIN_FILE = 'traydigita-core/traydigita-core.php';

    /**
     * Plugin slug of the required traydigita-core companion plugin.
     */
    public const string CORE_SLUG = 'traydigita-core';

    /**
     * Plugin file of the legacy traydigita-stack companion plugin.
     *
     * No longer the theme's required dependency — that is
     * traydigita-core (see CORE_SLUG). The Setup flow
     * (SetupAjax/SetupPage) still installs and checks the stack
     * plugin, so the constants stay until that flow is updated.
     */
    public const string STACK_PLUGIN_FILE = 'traydigita-stack/traydigita-stack.php';

    /**
     * Plugin slug of the legacy traydigita-stack companion plugin.
     *
     * Kept for the Setup flow (see STACK_PLUGIN_FILE).
     */
    public const string STACK_SLUG = 'traydigita-stack';

    /**
     * Category: the required companion plugin.
     */
    public const string CATEGORY_REQUIRED = 'required';

    /**
     * Category: page-cache plugins.
     */
    public const string CATEGORY_CACHE = 'cache';

    /**
     * Category: SEO plugins.
     */
    public const string CATEGORY_SEO = 'seo';

    /**
     * Category: analytics plugins.
     */
    public const string CATEGORY_ANALYTICS = 'analytics';

    /**
     * Category: security plugins.
     */
    public const string CATEGORY_SECURITY = 'security';

    /**
     * Category: image optimisation plugins.
     */
    public const string CATEGORY_IMAGE = 'image';

    /**
     * Status: the plugin directory is not present.
     */
    public const string STATUS_NOT_INSTALLED = 'not-installed';

    /**
     * Status: installed, but not active.
     */
    public const string STATUS_INSTALLED = 'installed';

    /**
     * Status: installed and active.
     */
    public const string STATUS_ACTIVE = 'active';

    /**
     * Curated recommended plugins, in display order, with the
     * server-aware cache badge applied.
     *
     * This is the render-facing list: it layers the badge from
     * cacheRecommendation() (which detects the server) on top of
     * definitions(). Only the renders that show the badge call
     * it — the Plugin Suggestions page and the Setup checklist —
     * so detection runs only where a badge is actually shown.
     * Status checks (status(), statuses(), get(), isInstallable())
     * use definitions() instead and never detect anything, which
     * matters because ModuleRegistry::moduleListData() calls
     * isConfigured() for every module on the modules page.
     *
     * Each entry: slug (wordpress.org slug, except the core slug),
     * file (plugin file as used by is_plugin_active() /
     * activate_plugin()), name (product name, intentionally not
     * translated), reason (one line, translatable), category
     * (group the settings page renders the entry under),
     * recommended (top recommendation badge — from the shared
     * cache mapping for cache plugins, static for the rest),
     * required (theme dependency badge), installable (false when
     * no wordpress.org package exists — the core plugin),
     * duplicates (theme module id this plugin overlaps, or null;
     * shown as a disclosure, never hidden).
     *
     * @return array<int, array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null}>
     */
    public static function all(): array
    {
        $entries = self::definitions();
        $recommendation = self::cacheRecommendation();
        if ($recommendation['badged'] && $recommendation['slug'] !== null) {
            foreach ($entries as &$entry) {
                if ($entry['slug'] === $recommendation['slug']) {
                    $entry['recommended'] = true;
                }
            }
            unset($entry);
        }
        return $entries;
    }

    /**
     * Curated recommended plugins as static data, no detection.
     *
     * The same entries all() returns, except the cache badge is
     * never computed here: every cache entry carries
     * 'recommended' => false. This is what status checks read —
     * it runs no detection, touches no server state, and is safe
     * to call from isConfigured() or any other helper that runs
     * on pages where no badge is shown.
     *
     * @return array<int, array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null}>
     */
    private static function definitions(): array
    {
        return [
            [
                'slug' => 'google-site-kit',
                'file' => 'google-site-kit/google-site-kit.php',
                'name' => 'Google Site Kit',
                'reason' => __(
                    'Top recommendation: Analytics, Search Console, AdSense and PageSpeed Insights in one official Google dashboard — and the theme’s Popular Posts widget reads its data when Site Kit is connected.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_ANALYTICS,
                'recommended' => true,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'seo-by-rank-math',
                'file' => 'seo-by-rank-math/rank-math.php',
                'name' => 'Rank Math',
                'reason' => __(
                    'Top recommended SEO plugin: full SEO toolkit with built-in schema, sitemaps and instant indexing. When it is active, the theme’s own SEO module steps aside and outputs no duplicate meta tags.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SEO,
                'recommended' => true,
                'required' => false,
                'installable' => true,
                'duplicates' => 'seo',
            ],
            [
                'slug' => self::CORE_SLUG,
                'file' => self::CORE_PLUGIN_FILE,
                'name' => 'TrayDigita Core',
                'reason' => __(
                    'Required by the theme: the theme does not boot without it. It provides the image, popular-posts and messaging helpers the theme uses.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_REQUIRED,
                'recommended' => false,
                'required' => true,
                'installable' => false,
                'duplicates' => null,
            ],
            [
                'slug' => 'wordpress-seo',
                'file' => 'wordpress-seo/wp-seo.php',
                'name' => 'Yoast SEO',
                'reason' => __(
                    'The standard SEO plugin for a news site. When it is active, the theme’s own SEO module steps aside and outputs no duplicate meta tags.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SEO,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => 'seo',
            ],
            [
                'slug' => 'wp-seopress',
                'file' => 'wp-seopress/seopress.php',
                'name' => 'SEOPress',
                'reason' => __(
                    'White-label SEO toolkit — titles, sitemaps and schema — with no ads in the admin. When it is active, the theme’s own SEO module steps aside and outputs no duplicate meta tags.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SEO,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => 'seo',
            ],
            [
                'slug' => 'all-in-one-seo-pack',
                'file' => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
                'name' => 'All in One SEO',
                'reason' => __(
                    'Long-running SEO plugin with titles, sitemaps and schema for a news site. When it is active, the theme’s own SEO module steps aside and outputs no duplicate meta tags.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SEO,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => 'seo',
            ],
            [
                'slug' => 'slim-seo',
                'file' => 'slim-seo/slim-seo.php',
                'name' => 'Slim SEO',
                'reason' => __(
                    'Lightweight SEO plugin that stays out of the way — automatic meta tags with almost no settings. When it is active, the theme’s own SEO module steps aside and outputs no duplicate meta tags.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SEO,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => 'seo',
            ],
            [
                'slug' => 'autodescription',
                'file' => 'autodescription/autodescription.php',
                'name' => 'The SEO Framework',
                'reason' => __(
                    'Privacy-focused SEO plugin with automated meta tags and no ads or upsells. When it is active, the theme’s own SEO module steps aside and outputs no duplicate meta tags.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SEO,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => 'seo',
            ],
            [
                'slug' => 'litespeed-cache',
                'file' => 'litespeed-cache/litespeed-cache.php',
                'name' => 'LiteSpeed Cache',
                'reason' => __(
                    'Page cache and image optimisation built for LiteSpeed servers, with server-level cache integration.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_CACHE,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'w3-total-cache',
                'file' => 'w3-total-cache/w3-total-cache.php',
                'name' => 'W3 Total Cache',
                'reason' => __(
                    'Full caching suite — page, object and browser cache with CDN support — that runs on any server. Powerful, but needs careful setup to run well.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_CACHE,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'wp-super-cache',
                'file' => 'wp-super-cache/wp-cache.php',
                'name' => 'WP Super Cache',
                'reason' => __(
                    'Simple .htaccess-based page cache — the easiest to set up, serving static HTML files straight from disk.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_CACHE,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'wp-fastest-cache',
                'file' => 'wp-fastest-cache/wpFastestCache.php',
                'name' => 'WP Fastest Cache',
                'reason' => __(
                    'Static HTML cache with a simple setup and ready-made rewrite rules for Apache and nginx.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_CACHE,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'cache-enabler',
                'file' => 'cache-enabler/cache-enabler.php',
                'name' => 'Cache Enabler',
                'reason' => __(
                    'Lightweight disk cache — simple static HTML caching with almost nothing to configure.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_CACHE,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'wordfence',
                'file' => 'wordfence/wordfence.php',
                'name' => 'Wordfence Security',
                'reason' => __(
                    'Firewall and malware scanner. It complements the theme’s Security Monitor module, which only checks settings and never scans files.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_SECURITY,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => null,
            ],
            [
                'slug' => 'ewww-image-optimizer',
                'file' => 'ewww-image-optimizer/ewww-image-optimizer.php',
                'name' => 'EWWW Image Optimizer',
                'reason' => __(
                    'Compresses uploads and converts them to WebP/AVIF. The theme’s Image Optimizer module already covers basic on-upload WebP conversion — install this only if you need its advanced compression and cloud options.',
                    'traydigita-core'
                ),
                'category' => self::CATEGORY_IMAGE,
                'recommended' => false,
                'required' => false,
                'installable' => true,
                'duplicates' => 'image-optimizer',
            ],
        ];
    }

    /**
     * Cache recommendation for the detected server.
     *
     * Delegates to the shared mapping in
     * CacheModule::recommendedPlugin() — the same mapping Site
     * Health, Setup, and the Cache module show — so the badge and
     * the first card in the cache group can never disagree across
     * surfaces. `slug` is the plugin to list first in the cache
     * group (null while developing, when no cache plugin is
     * suggested at all); `badged` says whether it carries the
     * Recommended badge (false for the server-neutral choice on
     * an unidentified server); `note` is the shared sentence for
     * the cache group, used as-is by the settings page.
     *
     * When the Cache module class is unavailable the fallback
     * degrades the same way CacheModule does for an unidentified
     * server — the server-neutral choice, never badged — except
     * while developing, where nothing is suggested. The two
     * fallback notes repeat CacheModule's strings verbatim, so
     * they share its translations.
     *
     * @return array{slug: string|null, badged: bool, note: string}
     */
    public static function cacheRecommendation(): array
    {
        if (class_exists(CacheModule::class)) {
            $recommended = CacheModule::recommendedPlugin();
            $slug = isset($recommended['id']) && is_string($recommended['id']) && $recommended['id'] !== ''
                ? $recommended['id']
                : null;
            return [
                'slug' => $slug,
                'badged' => $slug !== null && !empty($recommended['specific']),
                'note' => isset($recommended['note']) && is_string($recommended['note'])
                    ? $recommended['note']
                    : '',
            ];
        }
        if (ServerDetector::slug() === ServerDetector::SERVER_DEVELOPMENT) {
            return [
                'slug' => null,
                'badged' => false,
                'note' => __(
                    'This looks like a development site, so no server-specific recommendation can be made — page caching is not needed while developing. Install a cache plugin when the site goes live.',
                    'traydigita-core'
                ),
            ];
        }
        return [
            'slug' => 'w3-total-cache',
            'badged' => false,
            'note' => __(
                'The server could not be identified, so no server-specific recommendation can be made. W3 Total Cache works on any server, so it is a safe general choice.',
                'traydigita-core'
            ),
        ];
    }

    /**
     * Material Icons glyph for a curated plugin, by category.
     *
     * Generic glyphs only — never a brand logo or emoji: the card
     * icon tile shows the plugin's category (analytics for Site
     * Kit, search for SEO plugins, and so on). Unknown slugs fall
     * back to the generic 'extension' glyph.
     *
     * @param string $slug Plugin slug, already sanitized with sanitize_key().
     * @return string Material Icons ligature name.
     */
    public static function iconFor(string $slug): string
    {
        return match ($slug) {
            'google-site-kit' => 'analytics',
            'seo-by-rank-math', 'wordpress-seo', 'wp-seopress', 'all-in-one-seo-pack', 'slim-seo', 'autodescription' => 'search',
            self::CORE_SLUG => 'layers',
            'litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'cache-enabler' => 'speed',
            'wordfence' => 'shield',
            'ewww-image-optimizer' => 'image',
            default => 'extension',
        };
    }

    /**
     * Find one curated entry by slug.
     *
     * Reads definitions(), not all(): the entry carries the static
     * flags only — the server-aware cache badge is not applied —
     * so this never runs detection. Callers that show the badge
     * use all().
     *
     * @param string $slug Plugin slug, already sanitized with sanitize_key().
     * @return array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null}|null
     */
    public static function get(string $slug): ?array
    {
        foreach (self::definitions() as $plugin) {
            if ($plugin['slug'] === $slug) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * Whether a slug may be installed from here.
     *
     * True only for a curated entry that has a wordpress.org
     * package. Unknown slugs and the traydigita-core slug (no
     * wordpress.org package) are not installable — InstallerAjax
     * refuses them instead of fetching anything.
     *
     * @param string $slug Plugin slug, already sanitized with sanitize_key().
     * @return bool
     */
    public static function isInstallable(string $slug): bool
    {
        $plugin = self::get($slug);
        return $plugin !== null && $plugin['installable'];
    }

    /**
     * Install status of one curated plugin.
     *
     * One of STATUS_NOT_INSTALLED, STATUS_INSTALLED (installed but
     * inactive), STATUS_ACTIVE. An unknown slug is reported as not
     * installed; callers that need to distinguish "unknown" use
     * get() first.
     *
     * @param string $slug Plugin slug, already sanitized with sanitize_key().
     * @return string
     */
    public static function status(string $slug): string
    {
        $plugin = self::get($slug);
        if ($plugin === null) {
            return self::STATUS_NOT_INSTALLED;
        }
        return self::statusForFile($plugin['file']);
    }

    /**
     * Install status of every curated plugin, slug => status.
     *
     * Reads definitions(), not all(): status checks never run
     * detection.
     *
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        $statuses = [];
        foreach (self::definitions() as $plugin) {
            $statuses[$plugin['slug']] = self::statusForFile($plugin['file']);
        }
        return $statuses;
    }

    /**
     * Install status for one plugin file.
     *
     * Active wins over installed: a network-activated plugin on
     * multisite reports active, matching what the admin sees.
     *
     * @param string $file Plugin file, e.g. 'google-site-kit/google-site-kit.php'.
     * @return string
     */
    public static function statusForFile(string $file): string
    {
        self::ensurePluginApi();
        if (function_exists('is_plugin_active') && is_plugin_active($file)) {
            return self::STATUS_ACTIVE;
        }
        if (function_exists('get_plugins')) {
            $plugins = get_plugins();
            if (is_array($plugins) && isset($plugins[$file])) {
                return self::STATUS_INSTALLED;
            }
        }
        return self::STATUS_NOT_INSTALLED;
    }

    /**
     * Load the admin plugin API on demand.
     *
     * is_plugin_active() and get_plugins() live in
     * wp-admin/includes/plugin.php, which is loaded in the admin
     * but not always on the frontend, so it is included when the
     * functions do not exist yet. Outside WordPress (no ABSPATH)
     * there is nothing to load.
     *
     * @return void
     */
    private static function ensurePluginApi(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }
        if (function_exists('is_plugin_active') && function_exists('get_plugins')) {
            return;
        }
        $file = ABSPATH . 'wp-admin/includes/plugin.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
}
