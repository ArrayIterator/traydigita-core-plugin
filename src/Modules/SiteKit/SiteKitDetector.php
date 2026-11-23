<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\SiteKit;

use function class_exists;
use function defined;
use function file_exists;
use function function_exists;
use function get_option;
use function is_array;
use function is_string;
use function sanitize_key;

/**
 * Read-only detection of the Google Site Kit plugin.
 *
 * This class only READS state; it never calls Site Kit's internal
 * APIs, never reads or stores its tokens or credentials, never
 * modifies its settings, and never installs or activates anything.
 *
 * Detection signals (Site Kit's own public identifiers, plugin
 * `google/site-kit` on wordpress.org):
 * - Plugin active: `is_plugin_active('google-site-kit/google-site-kit.php')`,
 *   with `class_exists('Google\Site_Kit\Plugin')` as a fallback when
 *   the admin plugin API is not loaded.
 * - Plugin installed but inactive: `get_plugins()` lists the plugin
 *   file.
 * - Connected / setup complete: Site Kit's own option
 *   `googlesitekit_setup_complete` is truthy.
 * - Active Site Kit modules: Site Kit's own option
 *   `googlesitekit_active_modules`, a plain list of module slugs
 *   (`analytics-4`, `search-console`, `adsense`,
 *   `pagespeed-insights`, ...). Only the four modules this card
 *   reports on are surfaced; any other slug is ignored.
 *
 * Deliberately NOT read here: `googlesitekit_credentials` and any
 * user meta holding OAuth tokens. Those are Site Kit's secrets and
 * this module has no use for them.
 */
final class SiteKitDetector
{
    /**
     * Plugin file as used by is_plugin_active() and get_plugins().
     */
    public const string PLUGIN_FILE = 'google-site-kit/google-site-kit.php';

    /**
     * Main plugin class, used as a fallback active signal when the
     * admin plugin API is not loaded.
     */
    public const string PLUGIN_CLASS = 'Google\\Site_Kit\\Plugin';

    /**
     * Option Site Kit sets to 1 when its setup wizard completed.
     */
    public const string OPTION_SETUP_COMPLETE = 'googlesitekit_setup_complete';

    /**
     * Option holding the slugs of the Site Kit modules the site
     * activated (a plain list of strings).
     */
    public const string OPTION_ACTIVE_MODULES = 'googlesitekit_active_modules';

    /**
     * Site Kit module slugs this card reports on, slug => label.
     *
     * Labels are product names and are intentionally not passed
     * through __(): they are the same in every locale.
     */
    public const array TRACKED_MODULES = [
        'analytics-4' => 'Analytics',
        'search-console' => 'Search Console',
        'adsense' => 'AdSense',
        'pagespeed-insights' => 'PageSpeed Insights',
    ];

    /**
     * Legacy Universal Analytics slug.
     *
     * Sites that connected Site Kit before the GA4 migration still
     * carry `analytics` in the stored list. It is reported as
     * Analytics instead of being silently dropped.
     */
    public const string LEGACY_ANALYTICS_SLUG = 'analytics';

    /**
     * Whether the Site Kit plugin is active.
     *
     * @return bool
     */
    public static function isPluginActive(): bool
    {
        self::ensurePluginApi();
        if (function_exists('is_plugin_active')) {
            return is_plugin_active(self::PLUGIN_FILE);
        }
        return class_exists(self::PLUGIN_CLASS);
    }

    /**
     * Whether the Site Kit plugin is installed (active or not).
     *
     * Returns false when the admin plugin API cannot be loaded,
     * for example outside WordPress; callers treat that as
     * "not installed" and offer the installer link.
     *
     * @return bool
     */
    public static function isPluginInstalled(): bool
    {
        self::ensurePluginApi();
        if (!function_exists('get_plugins')) {
            return false;
        }
        $plugins = get_plugins();
        return is_array($plugins) && isset($plugins[self::PLUGIN_FILE]);
    }

    /**
     * Whether Site Kit is active and its setup is complete.
     *
     * "Connected" here means the site owner finished Site Kit's
     * own setup wizard (the `googlesitekit_setup_complete` option
     * is truthy). It says nothing about which Google account is
     * connected, and this module never looks at that.
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        if (!self::isPluginActive()) {
            return false;
        }
        return !empty(get_option(self::OPTION_SETUP_COMPLETE));
    }

    /**
     * Tracked Site Kit modules that are currently active.
     *
     * Reads Site Kit's own `googlesitekit_active_modules` option
     * and returns the tracked slugs found in it, slug => label.
     * An inactive plugin, a missing option, or a malformed value
     * all yield an empty list, never a fatal.
     *
     * @return array<string, string>
     */
    public static function getActiveModules(): array
    {
        if (!self::isPluginActive()) {
            return [];
        }
        $stored = get_option(self::OPTION_ACTIVE_MODULES, []);
        if (!is_array($stored)) {
            return [];
        }
        $active = [];
        foreach ($stored as $slug) {
            if (!is_string($slug)) {
                continue;
            }
            $slug = sanitize_key($slug);
            if ($slug === self::LEGACY_ANALYTICS_SLUG) {
                $slug = 'analytics-4';
            }
            if (isset(self::TRACKED_MODULES[$slug]) && !isset($active[$slug])) {
                $active[$slug] = self::TRACKED_MODULES[$slug];
            }
        }
        return $active;
    }

    /**
     * One structured read of the whole Site Kit status.
     *
     * @return array{installed: bool, active: bool, connected: bool, modules: array<string, string>}
     */
    public static function getStatus(): array
    {
        $active = self::isPluginActive();
        return [
            'installed' => $active || self::isPluginInstalled(),
            'active' => $active,
            'connected' => self::isConnected(),
            'modules' => self::getActiveModules(),
        ];
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
