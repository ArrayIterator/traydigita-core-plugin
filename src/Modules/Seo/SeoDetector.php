<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use function admin_url;
use function class_exists;
use function defined;
use function function_exists;
use function get_option;
use function in_array;
use function is_array;
use function is_string;

/**
 * Detects active SEO plugins so the theme never outputs duplicate SEO tags.
 *
 * Detection method (frontend-safe, no admin includes):
 *
 * 1. Runtime signals first: each known plugin defines a version
 *    constant, a main class, or a global helper function when it is
 *    loaded. These are checked with defined() / class_exists() /
 *    function_exists(), which work on the frontend without loading
 *    wp-admin/includes/plugin.php, so is_plugin_active() is never
 *    needed.
 * 2. Fallback: the `active_plugins` option (and, on multisite, the
 *    keys of `active_sitewide_plugins`) is scanned for the known
 *    plugin file paths. This catches a plugin that is active but
 *    whose classes have not been loaded yet at detection time
 *    (for example when detection runs before the plugin's own
 *    `plugins_loaded` bootstrap).
 *
 * Plugins are checked in a fixed priority order (Yoast SEO, Rank
 * Math, All in One SEO, SEOPress). When more than one SEO plugin
 * is active at the same time — which is a misconfiguration on the
 * site, not something the theme can fix — the first match in that
 * order is reported as the plugin in charge.
 */
final class SeoDetector
{
    /**
     * Known SEO plugins, in detection priority order.
     *
     * Each entry lists the runtime signals (version constant, main
     * class, helper function) and the plugin file paths as they
     * appear in the `active_plugins` option. Signal lists may be
     * empty for a plugin whose only reliable marker is its version
     * constant plus its file path (SEOPress). `settings_page` is
     * the plugin's own settings screen slug (admin.php?page=...),
     * used for the connector card link; these are the plugins'
     * documented menu slugs.
     *
     * @var array<int, array{slug: string, name: string, settings_page: string, constants: array<int, string>,
     *      classes: array<int, string>, functions: array<int, string>, files: array<int, string>}>
     */
    public const array PLUGINS = [
        [
            'slug' => 'yoast',
            'name' => 'Yoast SEO',
            'settings_page' => 'wpseo_dashboard',
            'constants' => ['WPSEO_VERSION'],
            'classes' => ['WPSEO_Options'],
            'functions' => ['YoastSEO'],
            'files' => [
                'wordpress-seo/wp-seo.php',
                'wordpress-seo-premium/wp-seo-premium.php',
            ],
        ],
        [
            'slug' => 'rank-math',
            'name' => 'Rank Math',
            'settings_page' => 'rank-math',
            'constants' => ['RANK_MATH_VERSION'],
            'classes' => ['RankMath'],
            'functions' => ['rank_math'],
            'files' => [
                'seo-by-rank-math/rank-math.php',
            ],
        ],
        [
            'slug' => 'aioseo',
            'name' => 'All in One SEO',
            'settings_page' => 'aioseo-settings',
            'constants' => ['AIOSEO_VERSION'],
            'classes' => ['AIOSEO\\Plugin\\AIOSEO'],
            'functions' => ['aioseo'],
            'files' => [
                'all-in-one-seo-pack/all_in_one_seo_pack.php',
                'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
            ],
        ],
        [
            'slug' => 'seopress',
            'name' => 'SEOPress',
            'settings_page' => 'seopress-option',
            'constants' => ['SEOPRESS_VERSION'],
            'classes' => [],
            'functions' => [],
            'files' => [
                'wp-seopress/seopress.php',
                'wp-seopress-pro/seopress-pro.php',
            ],
        ],
    ];

    /**
     * Detect the active SEO plugin, if any.
     *
     * @return array{slug: string, name: string, settings_url: string}|null The first matching plugin, or null when none is active.
     */
    public static function detectActivePlugin(): ?array
    {
        foreach (self::PLUGINS as $plugin) {
            if (self::isDetected($plugin)) {
                return [
                    'slug' => $plugin['slug'],
                    'name' => $plugin['name'],
                    'settings_url' => admin_url('admin.php?page=' . $plugin['settings_page']),
                ];
            }
        }
        return null;
    }

    /**
     * Settings URL of the SEO plugin with the given slug, or an empty string for an unknown slug.
     *
     * @param string $slug Plugin slug from the PLUGINS list, e.g. 'rank-math'.
     * @return string
     */
    public static function settingsUrl(string $slug): string
    {
        foreach (self::PLUGINS as $plugin) {
            if ($plugin['slug'] === $slug) {
                return admin_url('admin.php?page=' . $plugin['settings_page']);
            }
        }
        return '';
    }

    /**
     * Whether any known SEO plugin is active.
     *
     * @return bool
     */
    public static function hasSeoPlugin(): bool
    {
        return self::detectActivePlugin() !== null;
    }

    /**
     * Whether the SEO plugin with the given slug is active.
     *
     * @param string $slug Plugin slug from the PLUGINS list, e.g. 'yoast'.
     * @return bool
     */
    public static function isPluginActive(string $slug): bool
    {
        $detected = self::detectActivePlugin();
        return $detected !== null && $detected['slug'] === $slug;
    }

    /**
     * Check one plugin entry against its runtime signals and the active plugin lists.
     *
     * Runtime signals are checked first because they prove the
     * plugin code is actually loaded; the active plugin lists are
     * the fallback for plugins not loaded yet.
     *
     * @param array{slug: string, name: string, settings_page: string, constants: array<int, string>,
     *      classes: array<int, string>, functions: array<int, string>,
     *      files: array<int, string>} $plugin One PLUGINS entry.
     * @return bool
     */
    private static function isDetected(array $plugin): bool
    {
        foreach ($plugin['constants'] as $constant) {
            if (defined($constant)) {
                return true;
            }
        }
        foreach ($plugin['classes'] as $class) {
            if (class_exists($class)) {
                return true;
            }
        }
        foreach ($plugin['functions'] as $function) {
            if (function_exists($function)) {
                return true;
            }
        }
        $active = self::activePluginFiles();
        foreach ($plugin['files'] as $file) {
            if (in_array($file, $active, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plugin file paths from the active plugin lists.
     *
     * Merges `active_plugins` (per-site) with the keys of
     * `active_sitewide_plugins` (network-activated on multisite).
     * Both options are autoloaded and cached in memory by WordPress,
     * so reading them here costs no extra query.
     *
     * @return array<int, string>
     */
    private static function activePluginFiles(): array
    {
        $files = [];
        $active = get_option('active_plugins', []);
        if (is_array($active)) {
            foreach ($active as $file) {
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
        }
        $sitewide = get_option('active_sitewide_plugins', []);
        if (is_array($sitewide)) {
            foreach ($sitewide as $file => $timestamp) {
                if (is_string($file) && $file !== '') {
                    $files[] = $file;
                }
            }
        }
        return $files;
    }
}
