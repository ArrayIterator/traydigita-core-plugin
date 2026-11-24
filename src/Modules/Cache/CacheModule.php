<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Cache;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Installer\InstallerModule;
use TrayDigita\WP\Plugin\Core\Admin\ServerDetector;
use function __;
use function _n;
use function admin_url;
use function array_merge;
use function class_exists;
use function current_user_can;
use function disabled;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function function_exists;
use function get_option;
use function is_array;
use function is_string;
use function method_exists;
use function number_format_i18n;
use function opcache_get_status;
use function opcache_reset;
use function preg_match;
use function round;
use function sanitize_text_field;
use function size_format;
use function sprintf;
use function trim;
use function update_option;
use function wp_cache_flush;
use function wp_create_nonce;
use function wp_using_ext_object_cache;

/**
 * Cache module (disabled by default).
 *
 * The theme never implements its own page cache. This module is
 * a status and management card for the caching that already
 * exists on the site:
 *
 * - Cache-plugin detection: when a cache plugin is active (for
 *   example LiteSpeed Cache or WP Rocket) the module reports it
 *   and purges through that plugin's own API. The plugin stays
 *   the single owner of the page cache; the theme duplicates
 *   none of it.
 * - OPcache status (memory usage and hit rate) and reset, with
 *   an on-screen warning that resetting on a busy site causes a
 *   reload spike while every script recompiles at once.
 * - Object-cache flush through wp_cache_flush(), with a note
 *   that on a persistent object-cache setup this flushes the
 *   shared cache.
 * - Transient flush (every transient and its timeout row) behind
 *   the same typed confirmation word the DB Tools module uses
 *   for its bulk deletes — exact, case-sensitive, default
 *   `DELETE`.
 * - Optional Cloudflare purge-everything for one zone, using an
 *   API token and zone id stored in the module option. The token
 *   is never printed, never logged, and never returned by any
 *   endpoint; the whole Cloudflare section stays disabled until
 *   both fields are saved.
 *
 * Boundaries, stated on the page as well as in code:
 * - Browser-cache headers live in the Custom Headers module —
 *   this module links there instead of duplicating it.
 * - The DB Tools module owns the database side of transients
 *   (its cleaner deletes expired transients and orphaned timeout
 *   rows as part of database hygiene); this module's transient
 *   action is a cache flush, not a cleanup, and says so.
 *
 * Settings live in the single option `traydigita_cache`
 * (autoload `no`). Every action runs through the module's AJAX
 * endpoints, which verify the `traydigita-admin` nonce and the
 * `manage_options` capability and refuse to run while the module
 * is disabled. The settings page is rendered inline here (no
 * template partial), following the Turnstile module, so the
 * module stays self-contained in its own directory; the action
 * forms use the admin shell's generic `data-action` handling, so
 * no module JavaScript file is needed.
 */
final class CacheModule implements ModuleInterface
{
    /**
     * Settings-page tabs shown by the admin shell.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function tabs(): array
    {
        return [
            ['id' => 'cache', 'label' => __('Caches', 'traydigita-core')],
            ['id' => 'cloudflare', 'label' => __('Cloudflare', 'traydigita-core')],
        ];
    }

    /**
     * Option name for all Cache settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_cache';

    /**
     * Shared nonce action for the admin AJAX endpoints.
     *
     * The nonce itself must be sent as `_ajax_nonce` (see
     * `check_ajax_referer()`), never as a field named `nonce`.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Default confirmation word for flushing all transients.
     *
     * Same default as the DB Tools module, so an administrator
     * who already knows one typed confirmation knows this one.
     */
    public const string DEFAULT_CONFIRM_WORD = 'DELETE';

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'cache';
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
        return 'performance';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Cache', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('See which cache plugin is active and purge through it, check OPcache status, flush the object cache and transients, and optionally purge a Cloudflare zone. The theme never runs its own page cache. Disabled by default.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'performance';
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
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Shows which cache plugin is active and purges through it.', 'traydigita-core'),
            __('OPcache status, object-cache and transient flushing.', 'traydigita-core'),
            __('Optional Cloudflare zone purge.', 'traydigita-core'),
            __('The theme never runs its own page cache.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-cache';
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
     * The OPcache, object-cache, and transient actions need no
     * configuration, and Cloudflare is optional, so the module is
     * configured as soon as it is enabled.
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
     * False: the module is optional and toggleable — and disabled
     * by default, because it flushes caches other parts of the
     * site depend on.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the module is enabled, for the AJAX handlers.
     *
     * @return bool
     */
    public static function isEnabledForAjax(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Whether the OPcache functions are available on this server.
     *
     * @return bool
     */
    public static function isOpcacheAvailable(): bool
    {
        return function_exists('opcache_get_status') && function_exists('opcache_reset');
    }

    /**
     * Read the OPcache status, or null when it is not running.
     *
     * opcache_get_status() returns false when the OPcache is
     * disabled in php.ini even though the functions exist, so a
     * null return means "not running", not "unknown".
     *
     * @return array{enabled: bool, memory_used: int, memory_free: int, memory_wasted: int, hit_rate: float, cache_full: bool, cached_scripts: int}|null
     */
    public static function getOpcacheStatus(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }
        $status = opcache_get_status(false);
        if (!is_array($status)) {
            return null;
        }
        $memory = is_array($status['memory_usage'] ?? null) ? $status['memory_usage'] : [];
        $statistics = is_array($status['opcache_statistics'] ?? null) ? $status['opcache_statistics'] : [];
        $hits = (int) ($statistics['hits'] ?? 0);
        $misses = (int) ($statistics['misses'] ?? 0);
        $total = $hits + $misses;
        return [
            'enabled' => (bool) ($status['opcache_enabled'] ?? false),
            'memory_used' => (int) ($memory['used_memory'] ?? 0),
            'memory_free' => (int) ($memory['free_memory'] ?? 0),
            'memory_wasted' => (int) ($memory['wasted_memory'] ?? 0),
            'hit_rate' => $total > 0 ? round($hits / $total * 100, 1) : 0.0,
            'cache_full' => (bool) ($status['cache_full'] ?? false),
            'cached_scripts' => (int) ($statistics['num_cached_scripts'] ?? 0),
        ];
    }

    /**
     * Reset the OPcache.
     *
     * @return bool False when the OPcache is not available.
     */
    public static function resetOpcache(): bool
    {
        if (!function_exists('opcache_reset')) {
            return false;
        }
        return opcache_reset();
    }

    /**
     * Whether a persistent object cache is in use.
     *
     * When true, wp_cache_flush() flushes the shared cache (for
     * example Redis or Memcached), not just this request's
     * in-memory cache — the settings page says so next to the
     * flush button.
     *
     * @return bool
     */
    public static function isPersistentObjectCache(): bool
    {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    /**
     * Flush the object cache.
     *
     * Thin wrapper around wp_cache_flush() so the AJAX handler
     * does not call the global directly.
     *
     * @return bool
     */
    public static function flushObjectCache(): bool
    {
        return wp_cache_flush();
    }

    /**
     * Count the transients stored in the options table.
     *
     * Timeout rows (`_transient_timeout_*`) are not counted: the
     * number is the number of transients, not the number of rows
     * the flush deletes.
     *
     * @return int
     */
    public static function countTransients(): int
    {
        /** @var \wpdb|null $wpdb */
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ($wpdb === null || !method_exists($wpdb, 'esc_like')) {
            return 0;
        }
        $pattern = $wpdb->esc_like('_transient_') . '%';
        $timeoutPattern = $wpdb->esc_like('_transient_timeout_') . '%';
        $sitePattern = $wpdb->esc_like('_site_transient_') . '%';
        $siteTimeoutPattern = $wpdb->esc_like('_site_transient_timeout_') . '%';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE (option_name LIKE %s AND option_name NOT LIKE %s) OR (option_name LIKE %s AND option_name NOT LIKE %s)",
            $pattern,
            $timeoutPattern,
            $sitePattern,
            $siteTimeoutPattern
        ));
        return $count === null ? 0 : (int) $count;
    }

    /**
     * Delete every transient and its timeout row.
     *
     * Deletes both regular transients (`_transient_*`) and
     * network transients (`_site_transient_*`), including their
     * `_timeout_` rows. This is a cache flush, not a cleanup: it
     * removes live transients too, which is why the AJAX endpoint
     * requires the typed confirmation word. Transients held only
     * in a persistent object cache are not touched here — use the
     * object-cache flush for those.
     *
     * @return int Number of option rows deleted.
     */
    public static function flushTransients(): int
    {
        /** @var \wpdb|null $wpdb */
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if ($wpdb === null || !method_exists($wpdb, 'esc_like')) {
            return 0;
        }
        $pattern = $wpdb->esc_like('_transient_') . '%';
        $sitePattern = $wpdb->esc_like('_site_transient_') . '%';
        $sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $pattern,
            $sitePattern
        );
        if ($sql === null) {
            return 0;
        }
        $deleted = $wpdb->query($sql);
        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * The cache plugin recommended for this server, if any.
     *
     * The recommendation follows the web server, read through
     * ServerDetector (which parses $_SERVER['SERVER_SOFTWARE'],
     * with a filter override): LiteSpeed Cache on LiteSpeed and
     * OpenLiteSpeed — it is built for that server — and W3 Total
     * Cache on Apache and nginx, the full-featured free plugin
     * that runs on any server. A development site gets no plugin
     * recommendation (page caching is not needed while
     * developing), and an unidentified server gets the
     * server-neutral choice (W3 Total Cache) with `specific`
     * false, so callers can say so instead of pretending the
     * pick was matched to the server.
     *
     * The `note` is a complete sentence callers can use as-is:
     * the Site Health test, the Setup checklist, and the empty
     * state on this module's own settings page all show it.
     *
     * @return array{id: string, name: string, server: string, server_label: string, specific: bool, note: string}
     */
    public static function recommendedPlugin(): array
    {
        $server = self::detectedServer();
        $slug = $server['slug'];
        $label = $server['label'];
        switch ($slug) {
            case 'litespeed':
            case 'openlitespeed':
                return [
                    'id' => 'litespeed-cache',
                    'name' => 'LiteSpeed Cache',
                    'server' => $slug,
                    'server_label' => $label,
                    'specific' => true,
                    'note' => sprintf(
                        /* translators: 1: server label, e.g. LiteSpeed. 2: recommended cache plugin name. */
                        __('This site runs on %1$s, so %2$s is the recommended cache plugin — '
                            . 'it is built for this server.', 'traydigita-core'),
                        $label,
                        'LiteSpeed Cache'
                    ),
                ];
            case 'apache':
            case 'nginx':
                return [
                    'id' => 'w3-total-cache',
                    'name' => 'W3 Total Cache',
                    'server' => $slug,
                    'server_label' => $label,
                    'specific' => true,
                    'note' => sprintf(
                        /* translators: 1: server label, e.g. Apache. 2: recommended cache plugin name. */
                        __('This site runs on %1$s, so %2$s is the recommended cache plugin — the '
                            . 'full-featured free option that runs on any server.', 'traydigita-core'),
                        $label,
                        'W3 Total Cache'
                    ),
                ];
            case 'development':
                return [
                    'id' => '',
                    'name' => '',
                    'server' => $slug,
                    'server_label' => $label,
                    'specific' => false,
                    'note' => __(
                        'This looks like a development site, so no server-specific recommendation can be made — page caching is not needed while developing. Install a cache plugin when the site goes live.',
                        'traydigita-core'
                    ),
                ];
            default:
                return [
                    'id' => 'w3-total-cache',
                    'name' => 'W3 Total Cache',
                    'server' => 'unknown',
                    'server_label' => $label,
                    'specific' => false,
                    'note' => __(
                        'The server could not be identified, so no server-specific recommendation can be made. W3 Total Cache works on any server, so it is a safe general choice.',
                        'traydigita-core'
                    ),
                ];
        }
    }

    /**
     * URL of the cache group on the Plugin Suggestions page.
     *
     * The group itself (id traydigita-installer-group-cache) is
     * rendered by the Installer module; this only builds the
     * link to it, so the Site Health test, the Setup checklist,
     * and this module's own empty state all point at the same
     * anchor instead of each hardcoding it.
     *
     * @return string
     */
    public static function installerCacheGroupUrl(): string
    {
        return admin_url('admin.php?page=' . InstallerModule::settingsPageSlug() . '#traydigita-installer-group-cache');
    }

    /**
     * The detected web server (slug + label) via ServerDetector.
     *
     * The detector is added in the same round as this method, so
     * a missing class or method falls back to 'unknown' instead
     * of fataling — callers treat that as "no server-specific
     * recommendation can be made". The label falls back to a
     * fixed name per slug when the detector returns none.
     *
     * @return array{slug: string, label: string}
     */
    private static function detectedServer(): array
    {
        $slug = 'unknown';
        $label = '';
        if (class_exists(ServerDetector::class)) {
            $detected = ServerDetector::detect();
            if (isset($detected['slug']) && is_string($detected['slug']) && $detected['slug'] !== '') {
                $slug = $detected['slug'];
            }
            if (isset($detected['label']) && is_string($detected['label'])) {
                $label = $detected['label'];
            }
        }
        if ($label === '') {
            $label = match ($slug) {
                'litespeed' => 'LiteSpeed',
                'openlitespeed' => 'OpenLiteSpeed',
                'apache' => 'Apache',
                'nginx' => 'nginx',
                default => '',
            };
        }
        return ['slug' => $slug, 'label' => $label];
    }

    /**
     * Default settings.
     *
     * @return array{api_token: string, zone_id: string, confirm_word: string}
     */
    public static function getDefaults(): array
    {
        return [
            'api_token' => '',
            'zone_id' => '',
            'confirm_word' => self::DEFAULT_CONFIRM_WORD,
        ];
    }

    /**
     * Read the stored settings, merged over the defaults and normalized.
     *
     * The returned array includes the Cloudflare API token;
     * callers must never print, log, or return it.
     * `renderSettingsPage()` strips it before the settings reach
     * any markup.
     *
     * @return array{api_token: string, zone_id: string, confirm_word: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $zoneId = sanitize_text_field(self::asString($settings['zone_id'] ?? ''));
        if ($zoneId !== '' && !self::isValidZoneId($zoneId)) {
            $zoneId = '';
        }
        $word = sanitize_text_field(self::asString($settings['confirm_word'] ?? ''));
        if (preg_match('/^[A-Za-z0-9]{3,20}$/', $word) !== 1) {
            $word = self::DEFAULT_CONFIRM_WORD;
        }
        return [
            'api_token' => self::asString($settings['api_token'] ?? ''),
            'zone_id' => $zoneId,
            'confirm_word' => $word,
        ];
    }

    /**
     * Whether Cloudflare purging is configured (token + zone id).
     *
     * @return bool
     */
    public static function isCloudflareConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['api_token'] !== '' && $settings['zone_id'] !== '';
    }

    /**
     * Check a zone id against Cloudflare's format.
     *
     * Zone ids are 32 hexadecimal characters.
     *
     * @param string $zoneId Submitted zone id.
     * @return bool
     */
    public static function isValidZoneId(string $zoneId): bool
    {
        return preg_match('/^[0-9a-f]{32}$/i', $zoneId) === 1;
    }

    /**
     * Register the module's AJAX endpoints. Called only when enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The module has no frontend hooks: it only acts
     * when an administrator uses its settings page.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        CacheAjax::register();
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Follows the Turnstile pattern: the markup is rendered inline
     * here, no template partial. The Cloudflare API token is
     * stripped before the settings reach the markup — the token
     * field is always rendered empty, and an empty submission
     * keeps the stored token. Action forms use the shell's generic
     * `data-action` handling, which shows the result as a toast;
     * each form also carries a live region for the result.
     *
     * @return void
     */


    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the inline markup below, in the same scope.
        $cachePlugins = CacheDetector::detect();
        $cacheRecommended = self::recommendedPlugin();
        $cacheInstallerCacheUrl = self::installerCacheGroupUrl();
        $cacheOpcache = self::getOpcacheStatus();
        $cacheOpcacheAvailable = self::isOpcacheAvailable();
        $cachePersistentObjectCache = self::isPersistentObjectCache();
        $cacheTransientCount = self::countTransients();
        $cacheSettings = self::getSettings();
        $cacheCloudflareConfigured = self::isCloudflareConfigured();
        $cacheHasToken = $cacheSettings['api_token'] !== '';
        $cacheZoneId = $cacheSettings['zone_id'];
        $cacheConfirmWord = $cacheSettings['confirm_word'];
        $cacheNonceAction = self::NONCE_ACTION;
        $cacheNonce = wp_create_nonce($cacheNonceAction);
        $cacheHeadersUrl = admin_url('admin.php?page=traydigita-headers');
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        ?>
        <div class="traydigita-module-settings cache-settings">
            <?php
            // Settings Redesign: one-line summary up front; the
            // DB Tools boundary lives in the Transients section,
            // where the flush itself is explained.
            SettingsControls::intro(
                __('This theme never runs its own page cache.', 'traydigita-core'),
                __('When a cache plugin is active, the purge below goes through that plugin’s own API — the plugin stays the single owner of the page cache. The other actions here cover only the OPcache, the object cache, and transients.', 'traydigita-core')
            );
            ?>
            <p class="cache-boundaries">
                <?php esc_html_e('Boundaries: browser-cache headers are set by the Custom Headers module, not here.', 'traydigita-core'); ?>
                <a href="<?php echo esc_url($cacheHeadersUrl); ?>"><?php esc_html_e('Open Custom Headers', 'traydigita-core'); ?></a>
            </p>

            <div data-traydigita-panel="cache" role="tabpanel" aria-labelledby="traydigita-tab-cache">
            <h2 class="cache-section-title"><?php esc_html_e('Cache plugins', 'traydigita-core'); ?></h2>
            <?php if ($cachePlugins === []) : ?>
                <p class="cache-empty">
                    <?php esc_html_e('No cache plugin detected. The actions below still cover the OPcache, the object cache, and transients.', 'traydigita-core'); ?>
                    <?php
                    // Settings Redesign: the recommendation note is
                    // dynamic (it names the detected server), so it
                    // is split at runtime instead of reworded.
                    [$cacheNoteShort, $cacheNoteLong] = SettingsControls::splitHelp($cacheRecommended['note']);
                    echo SettingsControls::helpBlock($cacheNoteShort, $cacheNoteLong); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helpBlock() escapes its text; the toggle markup is built by this class.
                    ?>
                    <a href="<?php echo esc_url($cacheInstallerCacheUrl); ?>"><?php esc_html_e('See the recommended cache plugins', 'traydigita-core'); ?></a>
                </p>
            <?php else : ?>
                <table class="widefat striped cache-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Plugin', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Purge', 'traydigita-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cachePlugins as $cachePlugin) : ?>
                            <tr>
                                <td><?php echo esc_html($cachePlugin['name']); ?></td>
                                <td><?php esc_html_e('Active', 'traydigita-core'); ?></td>
                                <td>
                                    <?php if ($cachePlugin['purge_supported']) : ?>
                                        <form class="cache-action-form" method="post" data-action="<?php echo esc_attr(CacheAjax::ACTION_PURGE_PLUGIN); ?>">
                                            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($cacheNonce); ?>">
                                            <input type="hidden" name="action" value="<?php echo esc_attr(CacheAjax::ACTION_PURGE_PLUGIN); ?>">
                                            <input type="hidden" name="plugin" value="<?php echo esc_attr($cachePlugin['id']); ?>">
                                            <button type="submit" class="traydigita-admin-button traydigita-admin-button-secondary">
                                                <?php
                                                /* translators: %s is the cache plugin name. */
                                                echo esc_html(sprintf(__('Purge %s cache', 'traydigita-core'), $cachePlugin['name']));
                                                ?>
                                            </button>
                                        </form>
                                    <?php else : ?>
                                        <span class="description">
                                            <?php
                                            /* translators: %s is the cache plugin name. */
                                            echo esc_html(sprintf(__('No purge action this module can call — purge from %s’s own settings screen.', 'traydigita-core'), $cachePlugin['name']));
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 class="cache-section-title"><?php esc_html_e('OPcache', 'traydigita-core'); ?></h2>
            <?php if ($cacheOpcache === null) : ?>
                <p class="cache-empty">
                    <?php
                    if ($cacheOpcacheAvailable) {
                        esc_html_e('OPcache is installed but disabled on this server (opcache.enable is off), so there is nothing to reset.', 'traydigita-core');
                    } else {
                        esc_html_e('OPcache is not available on this server.', 'traydigita-core');
                    }
                    ?>
                </p>
            <?php else : ?>
                <table class="widefat striped cache-table">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                            <td><?php echo esc_html($cacheOpcache['enabled'] ? __('Enabled', 'traydigita-core') : __('Disabled', 'traydigita-core')); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Memory used', 'traydigita-core'); ?></th>
                            <td><?php echo esc_html((string) size_format($cacheOpcache['memory_used'])); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Memory free', 'traydigita-core'); ?></th>
                            <td><?php echo esc_html((string) size_format($cacheOpcache['memory_free'])); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Memory wasted', 'traydigita-core'); ?></th>
                            <td><?php echo esc_html((string) size_format($cacheOpcache['memory_wasted'])); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Hit rate', 'traydigita-core'); ?></th>
                            <td><?php echo esc_html(number_format_i18n($cacheOpcache['hit_rate'], 1) . '%'); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Cached scripts', 'traydigita-core'); ?></th>
                            <td><?php echo esc_html(number_format_i18n($cacheOpcache['cached_scripts'])); ?></td>
                        </tr>
                        <?php if ($cacheOpcache['cache_full']) : ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Cache full', 'traydigita-core'); ?></th>
                                <td><?php esc_html_e('Yes — the OPcache is full and is evicting scripts. Consider raising opcache.memory_consumption.', 'traydigita-core'); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <form class="cache-action-form" method="post" data-action="<?php echo esc_attr(CacheAjax::ACTION_OPCACHE_RESET); ?>">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($cacheNonce); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(CacheAjax::ACTION_OPCACHE_RESET); ?>">
                <p class="cache-warning">
                    <?php
                    SettingsControls::helpText(
                        __('Warning: resetting the OPcache on a busy site causes a reload spike.', 'traydigita-core'),
                        __('Every PHP file is recompiled on its next request at the same time, which can briefly slow the site down or stampede the server. Reset only when you need to (for example after a deployment that did not take effect).', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="cache-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-secondary" <?php disabled(!$cacheOpcacheAvailable); ?>>
                        <?php esc_html_e('Reset OPcache', 'traydigita-core'); ?>
                    </button>
                </p>
                <div class="cache-result" data-cache-result aria-live="polite"></div>
            </form>

            <h2 class="cache-section-title"><?php esc_html_e('Object cache', 'traydigita-core'); ?></h2>
            <form class="cache-action-form" method="post" data-action="<?php echo esc_attr(CacheAjax::ACTION_OBJECT_FLUSH); ?>">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($cacheNonce); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(CacheAjax::ACTION_OBJECT_FLUSH); ?>">
                <p class="cache-note">
                    <?php
                    if ($cachePersistentObjectCache) {
                        SettingsControls::helpText(
                            __('A persistent object cache is in use (for example '
                                . 'Redis or Memcached).', 'traydigita-core'),
                            __('Flushing it clears the shared cache for the whole site, not just this request — every cached object is rebuilt on its next use.', 'traydigita-core')
                        );
                    } else {
                        SettingsControls::helpText(
                            __('No persistent object cache is in use: WordPress is using its default in-memory '
                                . 'object cache, which only lives for one request.', 'traydigita-core'),
                            __('Flushing it still clears anything cached so far in this request.', 'traydigita-core')
                        );
                    }
                    ?>
                </p>
                <p class="cache-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-secondary"><?php esc_html_e('Flush object cache', 'traydigita-core'); ?></button>
                </p>
                <div class="cache-result" data-cache-result aria-live="polite"></div>
            </form>

            <h2 class="cache-section-title"><?php esc_html_e('Transients', 'traydigita-core'); ?></h2>
            <form class="cache-action-form" method="post" data-action="<?php echo esc_attr(CacheAjax::ACTION_TRANSIENTS_FLUSH); ?>">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($cacheNonce); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(CacheAjax::ACTION_TRANSIENTS_FLUSH); ?>">
                <p class="cache-note">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s is the number of transients currently stored. */
                        _n('%s transient is currently stored.', '%s transients are currently stored.', $cacheTransientCount, 'traydigita-core'),
                        number_format_i18n($cacheTransientCount)
                    ));
                    ?>
                    <?php
                    SettingsControls::helpText(
                        __('This flush deletes every transient, including live ones — '
                            . 'not just expired ones.', 'traydigita-core'),
                        __('Cached data (for example widget output and API responses) is rebuilt on its next use, which can briefly slow the site down. This is a cache flush, not a database cleanup: expired-transient cleanup belongs to the DB Tools module. Transients held only in a persistent object cache are not touched here — use the object-cache flush above for those.', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="cache-field">
                    <label class="cache-label" for="cache-transients-confirm">
                        <?php
                        /* translators: %s is the confirmation word the admin must type. */
                        echo esc_html(sprintf(__('Type %s to confirm', 'traydigita-core'), $cacheConfirmWord));
                        ?>
                    </label>
                    <input type="text" id="cache-transients-confirm" class="cache-confirm-input" name="confirm" autocomplete="off" autocapitalize="off" spellcheck="false">
                </p>
                <p class="cache-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-secondary"><?php esc_html_e('Flush all transients', 'traydigita-core'); ?></button>
                </p>
                <div class="cache-result" data-cache-result aria-live="polite"></div>
            </form>

            </div>
            <div data-traydigita-panel="cloudflare" role="tabpanel" aria-labelledby="traydigita-tab-cloudflare">
            <h2 class="cache-section-title"><?php esc_html_e('Cloudflare', 'traydigita-core'); ?></h2>
            <?php if (!$cacheCloudflareConfigured) : ?>
                <p class="cache-note">
                    <?php
                    SettingsControls::helpText(
                        __('Optional. Save a Cloudflare API token and the zone ID below to enable '
                            . 'purge-everything for this site’s Cloudflare zone.', 'traydigita-core'),
                        __('Until both are saved, the purge button stays disabled and nothing is sent to Cloudflare.', 'traydigita-core')
                    );
                    ?>
                </p>
            <?php endif; ?>
            <form class="traydigita-module-form cache-settings-form" data-traydigita-module-form data-traydigita-module="cache" data-module="cache" method="post" action="">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($cacheNonce); ?>">
                <input type="hidden" name="module" value="cache">

                <?php
                SettingsControls::input([
                    'type' => 'password',
                    'id' => 'cache-api-token',
                    'name' => 'api_token',
                    'label' => __('Cloudflare API token', 'traydigita-core'),
                    'value' => '',
                    'mono' => true,
                    'attrs' => [
                        'autocomplete' => 'new-password',
                        'placeholder' => $cacheHasToken ? __('•••••••• (saved)', 'traydigita-core') : __('Enter API token', 'traydigita-core'),
                    ],
                    'help' => __('A token with the Zone → Cache Purge permission for this zone (create one under My Profile → API Tokens in the Cloudflare dashboard).', 'traydigita-core'),
                    'help_long' => __('Leave empty to keep the saved token. The token is never shown, never logged, and never returned by any endpoint.', 'traydigita-core'),
                ]);

                SettingsControls::input([
                    'id' => 'cache-zone-id',
                    'name' => 'zone_id',
                    'label' => __('Zone ID', 'traydigita-core'),
                    'value' => $cacheZoneId,
                    'attrs' => [
                        'autocomplete' => 'off',
                        'spellcheck' => 'false',
                        'placeholder' => __('32 hexadecimal characters', 'traydigita-core'),
                    ],
                    'help' => __('Found on the Overview page of the zone in the Cloudflare dashboard, at the bottom right.', 'traydigita-core'),
                ]);

                SettingsControls::input([
                    'id' => 'cache-confirm-word',
                    'name' => 'confirm_word',
                    'label' => __('Confirmation word', 'traydigita-core'),
                    'value' => $cacheConfirmWord,
                    'attrs' => [
                        'pattern' => '[A-Za-z0-9]{3,20}',
                        'maxlength' => '20',
                        'required' => true,
                    ],
                    'help' => __('The word the transient flush asks you to type.', 'traydigita-core'),
                    'help_long' => __('3–20 letters or digits, no spaces; the check is case-sensitive.', 'traydigita-core'),
                ]);
                ?>
                <span class="cache-field-error traydigita-field-error" data-field-error="confirm_word" hidden></span>

                <?php SettingsControls::saveBar(); ?>
            </form>

            <form class="cache-action-form" method="post" data-action="<?php echo esc_attr(CacheAjax::ACTION_CLOUDFLARE_PURGE); ?>">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($cacheNonce); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(CacheAjax::ACTION_CLOUDFLARE_PURGE); ?>">
                <p class="cache-note">
                    <?php
                    SettingsControls::helpText(
                        __('Purge-everything clears the whole Cloudflare cache for this zone.', 'traydigita-core'),
                        __('Every visitor’s next request goes back to the origin until the cache rebuilds, so use it after a deployment, not routinely. Limited to one purge every 30 seconds.', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="cache-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-secondary" <?php disabled(!$cacheCloudflareConfigured); ?>>
                        <?php esc_html_e('Purge Cloudflare cache', 'traydigita-core'); ?>
                    </button>
                </p>
                <div class="cache-result" data-cache-result aria-live="polite"></div>
            </form>
            </div>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Only keys present in `$data` are changed; missing keys keep
     * their stored values. The API token is only overwritten when
     * a non-empty value is submitted; an empty or missing token
     * field keeps the stored token. The zone id must be empty or
     * 32 hexadecimal characters; the confirmation word must be
     * 3–20 letters or digits. On failure the returned message
     * lists every invalid field and nothing is saved. No secret is
     * ever included in the returned array.
     *
     * @param array<string, mixed> $data Submitted settings.
     * @return array{success: bool, message: string, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Cache settings.', 'traydigita-core'),
            ];
        }
        $current = self::getSettings();
        $new = $current;
        $errors = [];

        if (isset($data['api_token'])) {
            // Stored as-is (no sanitize_text_field: a token is an
            // opaque credential, validated only by Cloudflare).
            // Only a non-empty submission overwrites the stored value.
            $token = trim(self::asString($data['api_token']));
            if ($token !== '') {
                $new['api_token'] = $token;
            }
        }

        if (isset($data['zone_id'])) {
            $zoneId = sanitize_text_field(self::asString($data['zone_id']));
            if ($zoneId === '') {
                $new['zone_id'] = '';
            } elseif (!self::isValidZoneId($zoneId)) {
                $errors['zone_id'] = __('Use the 32-character zone ID from the Cloudflare dashboard.', 'traydigita-core');
            } else {
                $new['zone_id'] = $zoneId;
            }
        }

        if (isset($data['confirm_word'])) {
            $word = sanitize_text_field(self::asString($data['confirm_word']));
            if (preg_match('/^[A-Za-z0-9]{3,20}$/', $word) !== 1) {
                $errors['confirm_word'] = __('Use 3–20 letters or digits, no spaces.', 'traydigita-core');
            } else {
                $new['confirm_word'] = $word;
            }
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'message' => __('Please check the highlighted fields.', 'traydigita-core'),
                'errors' => $errors,
            ];
        }

        update_option(self::OPTION, $new, false);
        return [
            'success' => true,
            'message' => __('Cache settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Cast a stored or submitted value to a string (empty string
     * for anything that is not a string, int, float, or bool).
     *
     * Guards against a hand-edited option holding an array where a
     * string is expected, which a plain `(string)` cast would turn
     * into a warning and the word "Array".
     *
     * @param mixed $value Stored or submitted value.
     * @return string
     */
    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }
}
