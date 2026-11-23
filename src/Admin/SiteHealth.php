<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use TrayDigita\WP\Plugin\Core\Modules\Cache\CacheDetector;
use TrayDigita\WP\Plugin\Core\Modules\Cache\CacheModule;
use TrayDigita\WP\Plugin\Core\Modules\Pwa\PwaModule;
use function __;
use function add_filter;
use function count;
use function esc_html;
use function esc_html__;
use function esc_url;
use function file_exists;
use function function_exists;
use function get_bloginfo;
use function get_locale;
use function get_stylesheet_directory;
use function implode;
use function is_dir;
use function is_plugin_active;
use function is_string;
use function is_writable;
use function sprintf;
use function version_compare;
use function wp_get_theme;
use function wp_upload_dir;
use function wp_using_ext_object_cache;

/**
 * TrayDigita checks for WordPress core Site Health.
 *
 * The theme has no custom health page: the "Site Health"
 * submenu links straight to WordPress core Site Health
 * (site-health.php), and this class adds the
 * theme's environment checks there through the
 * `site_status_tests` filter (direct tests only, no async
 * tests). Each test reuses the environment logic the old
 * custom page used, so the values match what the theme
 * itself needs to run.
 *
 * Statuses follow WordPress semantics: `good` means the
 * check passed, `recommended` means it deserves attention
 * but the site still works, `critical` means a hard
 * requirement is missing (PHP below 8.3, or the Stack
 * plugin inactive — the theme cannot boot without it).
 * The push half of the PWA module is never `critical`: push is
 * optional, so an unconfigured push half is `recommended`.
 * A missing cache plugin is likewise only `recommended`:
 * the site works without one, just slower.
 *
 * Read-only: nothing is written, changed or deleted. Every
 * value is read from the running site when the test runs,
 * and every dynamic value is escaped before it reaches the
 * (HTML) description.
 */
final class SiteHealth
{
    /**
     * Minimum PHP version the theme requires.
     */
    public const string PHP_REQUIRED = '8.3.0';

    /**
     * WordPress version the theme recommends.
     */
    public const string WP_RECOMMENDED = '6.0';

    /**
     * Register the theme's Site Health tests.
     *
     * Called from AdminMenu::init(): the filter only matters
     * on the (admin) Site Health screen.
     *
     * @return void
     */
    public static function register(): void
    {
        add_filter('site_status_tests', [self::class, 'addTests']);
    }

    /**
     * Add the TrayDigita direct tests to core Site Health.
     *
     * @param array<string, mixed> $tests Core test groups.
     * @return array<string, mixed> Core test groups with the TrayDigita tests added.
     */
    public static function addTests(array $tests): array
    {
        $tests['direct']['traydigita_php_version'] = [
            'label' => __('PHP version', 'traydigita-core'),
            'test' => [self::class, 'phpVersionTest'],
        ];
        $tests['direct']['traydigita_wordpress_version'] = [
            'label' => __('WordPress version', 'traydigita-core'),
            'test' => [self::class, 'wordpressVersionTest'],
        ];
        $tests['direct']['traydigita_active_theme'] = [
            'label' => __('Active theme', 'traydigita-core'),
            'test' => [self::class, 'activeThemeTest'],
        ];
        $tests['direct']['traydigita_stack_plugin'] = [
            'label' => __('TrayDigita Stack plugin', 'traydigita-core'),
            'test' => [self::class, 'stackPluginTest'],
        ];
        $tests['direct']['traydigita_push_plugin'] = [
            'label' => __('Push notifications', 'traydigita-core'),
            'test' => [self::class, 'pushPluginTest'],
        ];
        $tests['direct']['traydigita_cache_plugin'] = [
            'label' => __('Cache plugin', 'traydigita-core'),
            'test' => [self::class, 'cachePluginTest'],
        ];
        $tests['direct']['traydigita_uploads_writable'] = [
            'label' => __('Uploads directory', 'traydigita-core'),
            'test' => [self::class, 'uploadsWritableTest'],
        ];
        $tests['direct']['traydigita_vite_manifest'] = [
            'label' => __('Vite manifest', 'traydigita-core'),
            'test' => [self::class, 'viteManifestTest'],
        ];
        $tests['direct']['traydigita_object_cache'] = [
            'label' => __('Object cache', 'traydigita-core'),
            'test' => [self::class, 'objectCacheTest'],
        ];
        $tests['direct']['traydigita_translation_file'] = [
            'label' => __('Translation file', 'traydigita-core'),
            'test' => [self::class, 'translationFileTest'],
        ];
        return $tests;
    }

    /**
     * PHP version test: the theme requires PHP 8.3 or newer.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function phpVersionTest(): array
    {
        $ok = version_compare(PHP_VERSION, self::PHP_REQUIRED, '>=');
        if ($ok) {
            /* translators: 1: running PHP version, 2: minimum required PHP version. */
            $description = sprintf(__('PHP %1$s (requires %2$s or newer).', 'traydigita-core'), PHP_VERSION, self::PHP_REQUIRED);
        } else {
            /* translators: 1: running PHP version, 2: minimum required PHP version. */
            $description = sprintf(__('PHP %1$s is below the required %2$s.', 'traydigita-core'), PHP_VERSION, self::PHP_REQUIRED);
        }
        return self::result(
            __('PHP version', 'traydigita-core'),
            $ok ? 'good' : 'critical',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_php_version'
        );
    }

    /**
     * WordPress version test: 6.0 or newer is recommended.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function wordpressVersionTest(): array
    {
        $wpVersion = (string) get_bloginfo('version');
        $ok = version_compare($wpVersion, self::WP_RECOMMENDED, '>=');
        if ($ok) {
            /* translators: %s is the running WordPress version. */
            $description = sprintf(__('WordPress %s.', 'traydigita-core'), $wpVersion);
        } else {
            /* translators: %s is the running WordPress version. */
            $description = sprintf(__('WordPress %s is older than the recommended 6.0.', 'traydigita-core'), $wpVersion);
        }
        return self::result(
            __('WordPress version', 'traydigita-core'),
            $ok ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_wordpress_version'
        );
    }

    /**
     * Active theme test: TrayDigita Theme must be active.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function activeThemeTest(): array
    {
        $theme = wp_get_theme();
        $themeName = (string) $theme->get('Name');
        $ok = $theme->get_template() === 'traydigita-theme';
        if ($ok) {
            $description = __('TrayDigita Theme is the active theme.', 'traydigita-core');
        } else {
            /* translators: %s is the name of the currently active theme. */
            $description = sprintf(__('The active theme is %s, not TrayDigita Theme.', 'traydigita-core'), $themeName);
        }
        return self::result(
            __('Active theme', 'traydigita-core'),
            $ok ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_active_theme'
        );
    }

    /**
     * Stack plugin test: the theme cannot boot without it.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function stackPluginTest(): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = is_plugin_active('traydigita-stack/traydigita-stack.php');
        $description = $active
            ? __('Installed and active.', 'traydigita-core')
            : __('Not active. The theme requires the TrayDigita Stack plugin.', 'traydigita-core');
        return self::result(
            __('TrayDigita Stack plugin', 'traydigita-core'),
            $active ? 'good' : 'critical',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_stack_plugin'
        );
    }

    /**
     * Push test: optional, so never `critical`.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function pushPluginTest(): array
    {
        $active = PwaModule::isPushConfigured();
        $description = $active
            ? __('Configured in the Push module.', 'traydigita-core')
            : __('Not configured yet. Push notifications are unavailable (optional).', 'traydigita-core');
        return self::result(
            __('Push notifications', 'traydigita-core'),
            $active ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_push_plugin'
        );
    }

    /**
     * Cache plugin test: a page-cache plugin is recommended.
     *
     * Optional, so never `critical` — the site works without
     * one, just slower. When a plugin is active the description
     * names it; otherwise it carries the server-aware
     * recommendation (CacheModule::recommendedPlugin()) and the
     * actions link to the cache group on the Plugin Suggestions
     * page, where the plugin can be installed.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function cachePluginTest(): array
    {
        $detected = CacheDetector::detect();
        if ($detected !== []) {
            $names = [];
            foreach ($detected as $plugin) {
                $names[] = $plugin['name'];
            }
            $description = count($names) === 1
                ? sprintf(
                    /* translators: %s is the name of the active cache plugin. */
                    __('Cache plugin active: %s.', 'traydigita-core'),
                    $names[0]
                )
                : sprintf(
                    /* translators: %s is a comma-separated list of active cache plugin names. */
                    __('Cache plugins active: %s.', 'traydigita-core'),
                    implode(', ', $names)
                );
            return self::result(
                __('Cache plugin', 'traydigita-core'),
                'good',
                sprintf('<p>%s</p>', esc_html($description)),
                'traydigita_cache_plugin'
            );
        }
        $recommended = CacheModule::recommendedPlugin();
        $description = sprintf(
            /* translators: %s is the server-aware cache plugin recommendation. */
            __('No cache plugin is active. %s', 'traydigita-core'),
            $recommended['note']
        );
        return self::result(
            __('Cache plugin', 'traydigita-core'),
            'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_cache_plugin',
            sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url(CacheModule::installerCacheGroupUrl()),
                esc_html__('See the recommended cache plugins', 'traydigita-core')
            )
        );
    }

    /**
     * Uploads directory test: it must exist and be writable.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function uploadsWritableTest(): array
    {
        $uploads = wp_upload_dir();
        $uploadsDir = isset($uploads['basedir']) && is_string($uploads['basedir'])
            ? $uploads['basedir']
            : '';
        $writable = $uploadsDir !== '' && is_dir($uploadsDir) && is_writable($uploadsDir);
        if ($writable) {
            $description = __('Uploads directory is writable.', 'traydigita-core');
        } else {
            /* translators: %s is the uploads directory path. */
            $description = sprintf(__('Uploads directory is not writable: %s', 'traydigita-core'), $uploadsDir);
        }
        return self::result(
            __('Uploads directory', 'traydigita-core'),
            $writable ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_uploads_writable'
        );
    }

    /**
     * Vite manifest test: the production build must exist.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function viteManifestTest(): array
    {
        $present = file_exists(get_stylesheet_directory() . '/dist/manifest.json');
        $description = $present
            ? __('Production manifest found (dist/manifest.json).', 'traydigita-core')
            : __('Production manifest missing. Run the asset build to regenerate it.', 'traydigita-core');
        return self::result(
            __('Vite manifest', 'traydigita-core'),
            $present ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_vite_manifest'
        );
    }

    /**
     * Object cache test: a persistent cache is recommended.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function objectCacheTest(): array
    {
        $active = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        $description = $active
            ? __('Persistent object cache is active.', 'traydigita-core')
            : __('No persistent object cache detected.', 'traydigita-core');
        return self::result(
            __('Object cache', 'traydigita-core'),
            $active ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_object_cache'
        );
    }

    /**
     * Translation file test: the locale .mo must exist.
     *
     * WordPress 6.5+ loads a theme's translations from the
     * locale-named file (languages/{locale}.mo), so that is
     * the file this test checks — not the legacy
     * traydigita-{locale}.mo name.
     *
     * @return array<string, mixed> Test result in the core Site Health shape.
     */
    public static function translationFileTest(): array
    {
        $locale = get_locale();
        $present = file_exists(get_stylesheet_directory() . '/languages/' . $locale . '.mo');
        if ($present) {
            /* translators: %s is the site locale, e.g. id_ID. */
            $description = sprintf(__('Translation file found for %s.', 'traydigita-core'), $locale);
        } else {
            /* translators: %s is the site locale, e.g. id_ID. */
            $description = sprintf(
                __('No translation file for %s. English strings are used as fallback.', 'traydigita-core'),
                $locale
            );
        }
        return self::result(
            __('Translation file', 'traydigita-core'),
            $present ? 'good' : 'recommended',
            sprintf('<p>%s</p>', esc_html($description)),
            'traydigita_translation_file'
        );
    }

    /**
     * Build one Site Health test result in the core shape.
     *
     * @param string $label Test label.
     * @param string $status 'good', 'recommended' or 'critical'.
     * @param string $description HTML description (already escaped).
     * @param string $testId Test slug (traydigita_*).
     * @param string $actions HTML actions (already escaped), '' when the test has none.
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
     */
    private static function result(string $label, string $status, string $description, string $testId, string $actions = ''): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => [
                'label' => __('TrayDigita', 'traydigita-core'),
                'color' => 'blue',
            ],
            'description' => $description,
            'actions' => $actions,
            'test' => $testId,
        ];
    }
}
