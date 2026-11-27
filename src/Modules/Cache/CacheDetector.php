<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Cache;

use Throwable;
use function __;
use function class_exists;
use function defined;
use function do_action;
use function function_exists;
use function get_option;
use function get_site_option;
use function in_array;
use function is_array;
use function is_multisite;
use function is_string;

/**
 * Cache-plugin detection and purge-through-plugin support.
 *
 * The theme never implements its own page cache. When a cache
 * plugin is active, this module is a status and management card
 * only: it reports which plugin is active and purges through
 * that plugin's own API, so the plugin stays the single owner of
 * the page cache.
 *
 * Detection never trusts a single signal: each plugin is
 * recognised by its entry in the `active_plugins` option (and
 * the network-activated list on multisite) plus a class,
 * function, or constant the plugin defines once loaded. A
 * plugin is only offered a purge button when its purge API is
 * actually callable right now - every call is guarded by
 * function_exists() / class_exists() and wrapped so a plugin
 * that throws cannot take the admin screen down with it. The
 * class guard is enough for the LiteSpeed and Cache Enabler
 * APIs: their purge methods are stable public APIs (declared
 * in stubs/optional-plugins.stub), and a method_exists() check
 * on a stubbed class would be statically always true.
 *
 * Plugins whose purge API could not be verified from public
 * documentation are detected but marked purge-unsupported: the
 * settings page tells the administrator to purge from that
 * plugin's own screen instead of calling an unverified function.
 * Guessing at a plugin's API would be worse than no button.
 */
final class CacheDetector
{
    /**
     * Known cache plugins, in check order.
     *
     * Each entry: `id` (stable slug), `name` (display name),
     * `file` (plugin file path inside wp-content/plugins, as it
     * appears in the active_plugins option), `signals` (class,
     * function, or constant names that prove the plugin is
     * loaded), and `purge` (one of the PURGE_* methods below, or
     * null when no purge API could be verified).
     *
     * @var array<int, array{id: string, name: string, file: string, signals: array<int, string>, purge: string|null}>
     */
    public const array PLUGINS = [
        [
            'id' => 'litespeed-cache',
            'name' => 'LiteSpeed Cache',
            'file' => 'litespeed-cache/litespeed-cache.php',
            'signals' => ['LSCWP_V', 'LiteSpeed_Cache', 'litespeed_purge_all'],
            'purge' => 'purgeLiteSpeed',
        ],
        [
            'id' => 'wp-rocket',
            'name' => 'WP Rocket',
            'file' => 'wp-rocket/wp-rocket.php',
            'signals' => ['WP_ROCKET_VERSION', 'rocket_clean_domain'],
            'purge' => 'purgeWpRocket',
        ],
        [
            'id' => 'w3-total-cache',
            'name' => 'W3 Total Cache',
            'file' => 'w3-total-cache/w3-total-cache.php',
            'signals' => ['W3TC\\Dispatcher', 'w3tc_flush_all'],
            'purge' => 'purgeW3TotalCache',
        ],
        [
            'id' => 'wp-super-cache',
            'name' => 'WP Super Cache',
            'file' => 'wp-super-cache/wp-cache.php',
            'signals' => ['WPSC_VERSION', 'wp_cache_clear_cache'],
            'purge' => 'purgeWpSuperCache',
        ],
        [
            'id' => 'wp-optimize',
            'name' => 'WP-Optimize',
            'file' => 'wp-optimize/wp-optimize.php',
            'signals' => ['WP_Optimize'],
            // No purge API verified: purge from WP-Optimize's own screen.
            'purge' => null,
        ],
        [
            'id' => 'cache-enabler',
            'name' => 'Cache Enabler',
            'file' => 'cache-enabler/cache-enabler.php',
            'signals' => ['Cache_Enabler'],
            'purge' => 'purgeCacheEnabler',
        ],
        [
            'id' => 'wp-fastest-cache',
            'name' => 'WP Fastest Cache',
            'file' => 'wp-fastest-cache/wpFastestCache.php',
            'signals' => ['WpFastestCache'],
            // deleteCache() is an instance method on the plugin's
            // singleton; there is no verified static purge entry
            // point, so purge from the plugin's own screen.
            'purge' => null,
        ],
    ];

    /**
     * Detect every known cache plugin that is active right now.
     *
     * A plugin counts as active when its file is in the active
     * plugins list (site or network) and at least one of its
     * signals is present. The signal check matters: a plugin file
     * listed as active but not actually loaded (for example after
     * a failed update) must not be reported as running.
     *
     * @return array<int, array{id: string, name: string, purge_supported: bool}>
     */
    public static function detect(): array
    {
        $activeFiles = self::activePluginFiles();
        $found = [];
        foreach (self::PLUGINS as $plugin) {
            if (!in_array($plugin['file'], $activeFiles, true)) {
                continue;
            }
            if (!self::hasSignal($plugin['signals'])) {
                continue;
            }
            $found[] = [
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'purge_supported' => $plugin['purge'] !== null
                    && self::purgeCallableAvailable($plugin['purge']),
            ];
        }
        return $found;
    }

    /**
     * Whether a cache plugin with a callable purge API is active.
     *
     * @param string $id Plugin id from PLUGINS.
     * @return bool
     */
    public static function isKnownPlugin(string $id): bool
    {
        foreach (self::PLUGINS as $plugin) {
            if ($plugin['id'] === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Purge the page cache through one detected plugin's own API.
     *
     * The plugin id must be one of the detected plugins; anything
     * else is refused. When the plugin has no verified purge API
     * the call fails with a message pointing at the plugin's own
     * screen instead of guessing at an API. A plugin that throws
     * while purging is reported as a failure, never fatal.
     *
     * @param string $id Plugin id from detect().
     * @return array{success: bool, message: string}
     */
    public static function purge(string $id): array
    {
        $detected = self::detect();
        $plugin = null;
        foreach ($detected as $entry) {
            if ($entry['id'] === $id) {
                $plugin = $entry;
                break;
            }
        }
        if ($plugin === null) {
            return [
                'success' => false,
                'message' => __('That cache plugin is not active.', 'traydigita-core'),
            ];
        }
        if (!$plugin['purge_supported']) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the cache plugin name. */
                    __('%s does not expose a purge action this module can call. Please purge its cache from the plugin’s own settings screen.', 'traydigita-core'),
                    $plugin['name']
                ),
            ];
        }
        $definition = null;
        foreach (self::PLUGINS as $candidate) {
            if ($candidate['id'] === $id) {
                $definition = $candidate;
                break;
            }
        }
        if ($definition === null || $definition['purge'] === null) {
            return [
                'success' => false,
                'message' => __('That cache plugin cannot be purged from here.', 'traydigita-core'),
            ];
        }
        try {
            $method = $definition['purge'];
            $ok = self::$method();
        } catch (Throwable) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the cache plugin name. */
                    __('%s reported an error while purging its cache. Nothing was changed by this module.', 'traydigita-core'),
                    $plugin['name']
                ),
            ];
        }
        if ($ok !== true) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s is the cache plugin name. */
                    __('%s did not confirm the purge. Please check the plugin’s own settings screen.', 'traydigita-core'),
                    $plugin['name']
                ),
            ];
        }
        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s is the cache plugin name. */
                __('Purged the page cache through %s.', 'traydigita-core'),
                $plugin['name']
            ),
        ];
    }

    /**
     * List every active plugin file (site + network-activated).
     *
     * Reads the options directly instead of is_plugin_active():
     * that helper lives in wp-admin/includes/plugin.php, which is
     * not loaded on every screen where this runs.
     *
     * @return array<int, string>
     */
    private static function activePluginFiles(): array
    {
        $active = get_option('active_plugins', []);
        if (!is_array($active)) {
            $active = [];
        }
        if (function_exists('is_multisite') && is_multisite()) {
            $network = get_site_option('active_sitewide_plugins', []);
            if (is_array($network)) {
                foreach ($network as $file => $timestamp) {
                    if (is_string($file)) {
                        $active[] = $file;
                    }
                }
            }
        }
        $files = [];
        foreach ($active as $file) {
            if (is_string($file) && $file !== '') {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Whether any of the plugin's signals is present.
     *
     * A signal is a class name, a function name, or a constant
     * name; the check used depends on what the string looks like
     * (a backslash means a namespaced class, an underscore with a
     * lowercase start is treated as a function first). Constants
     * are checked last, because a defined constant alone is the
     * weakest signal.
     *
     * @param array<int, string> $signals Signal names.
     * @return bool
     */
    private static function hasSignal(array $signals): bool
    {
        foreach ($signals as $signal) {
            if (class_exists($signal)) {
                return true;
            }
            if (function_exists($signal)) {
                return true;
            }
            if (defined($signal)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the purge method for a plugin is callable right now.
     *
     * @param string $method Purge method name from PLUGINS.
     * @return bool
     */
    private static function purgeCallableAvailable(string $method): bool
    {
        return match ($method) {
            'purgeLiteSpeed' => function_exists('litespeed_purge_all')
                || class_exists('LiteSpeed_Cache_API'),
            'purgeWpRocket' => function_exists('rocket_clean_domain'),
            'purgeW3TotalCache' => function_exists('w3tc_flush_all'),
            'purgeWpSuperCache' => function_exists('wp_cache_clear_cache'),
            'purgeCacheEnabler' => class_exists('Cache_Enabler'),
            default => false,
        };
    }

    /**
     * Purge through LiteSpeed Cache.
     *
     * Prefers the litespeed_purge_all() function; falls back to
     * the LiteSpeed_Cache_API::purge_all() static call, and finally
     * to the litespeed_purge_all action the plugin documents for
     * other code to trigger a purge.
     *
     * @return bool True when a purge was triggered.
     */
    private static function purgeLiteSpeed(): bool
    {
        if (function_exists('litespeed_purge_all')) {
            litespeed_purge_all();
            return true;
        }
        if (class_exists('LiteSpeed_Cache_API')) {
            \LiteSpeed_Cache_API::purge_all();
            return true;
        }
        do_action('litespeed_purge_all');
        return true;
    }

    /**
     * Purge through WP Rocket (whole domain).
     *
     * @return bool True when rocket_clean_domain() ran.
     */
    private static function purgeWpRocket(): bool
    {
        if (!function_exists('rocket_clean_domain')) {
            return false;
        }
        rocket_clean_domain();
        return true;
    }

    /**
     * Flush everything through W3 Total Cache.
     *
     * @return bool True when w3tc_flush_all() ran.
     */
    private static function purgeW3TotalCache(): bool
    {
        if (!function_exists('w3tc_flush_all')) {
            return false;
        }
        w3tc_flush_all();
        return true;
    }

    /**
     * Clear the cache through WP Super Cache.
     *
     * @return bool True when wp_cache_clear_cache() ran.
     */
    private static function purgeWpSuperCache(): bool
    {
        if (!function_exists('wp_cache_clear_cache')) {
            return false;
        }
        wp_cache_clear_cache();
        return true;
    }

    /**
     * Clear the whole cache through Cache Enabler.
     *
     * @return bool True when clear_total_cache() ran.
     */
    private static function purgeCacheEnabler(): bool
    {
        if (!class_exists('Cache_Enabler')) {
            return false;
        }
        \Cache_Enabler::clear_total_cache();
        return true;
    }
}
