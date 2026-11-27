<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Integrations;

use TrayDigita\WP\Plugin\Core\Admin\AdminMenu;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentsModule;
use TrayDigita\WP\Plugin\Core\Modules\Markets\MarketsModule;
use TrayDigita\WP\Plugin\Core\Modules\Push\PushModule;
use TrayDigita\WP\Plugin\Core\Modules\Seo\SeoModule;
use TrayDigita\WP\Plugin\Core\Modules\SiteKit\SiteKitModule;
use TrayDigita\WP\Plugin\Core\Modules\Turnstile\TurnstileModule;
use TrayDigita\WP\Plugin\Core\Modules\Weather\WeatherModule;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Customizer\Definitions;
use TrayDigita\WP\Plugin\Core\Customizer\Settings;
use TrayDigita\WP\Plugin\Core\Widgets\WeatherHelper;
use TrayDigita\WP\Plugin\Core\Stack\Stack;
use function __;
use function add_filter;
use function admin_url;
use function apply_filters;
use function class_exists;
use function current_user_can;
use function defined;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function file_exists;
use function function_exists;
use function get_plugins;
use function in_array;
use function is_array;
use function is_plugin_active;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Integrations hub: one page listing every service the theme connects to.
 *
 * The page itself owns no service. It applies the
 * `traydigita:core:integrations` filter and renders one row per
 * registered entry: the service name, a one-line description, a
 * live status badge, and a link to the service's own settings page
 * when the entry provides one. Status is computed live by the
 * registering module when the filter runs (at render time) - it is
 * never stored and never hardcoded here.
 *
 * Filter contract (hook convention `traydigita:core:<name>`):
 *
 *     add_filter(IntegrationsModule::FILTER, [MyModule::class, 'registerIntegrationEntry']);
 *
 * Each callback receives the entry list so far and must return it
 * with its ONE entry appended. Entry shape:
 *
 *     [
 *         'id'          => string, // Unique slug, e.g. 'site-kit'.
 *         'name'        => string, // Product name, not translated.
 *         'description' => string, // Translatable, one line.
 *         'status'      => string, // 'active', 'configured', 'not-configured' or 'module-disabled'.
 *         'url'         => string, // Admin URL, or '' when there is nowhere to link.
 *         'module'      => string, // Owning module id, e.g. 'site-kit'.
 *         'icon'        => string, // Optional Material Icons glyph, e.g. 'analytics'.
 *     ]
 *
 * Status semantics (used consistently, never faked):
 * - 'active': the integration is live and working right now
 *   (plugin active / provider responding / credentials verified by
 *   the owning module's existing detector).
 * - 'configured': credentials or settings are present but the
 *   service is not currently live (for example keys saved while
 *   the owning module is disabled, or a plugin installed but not
 *   active).
 * - 'not-configured': not installed, no credentials, or not set up.
 * - 'module-disabled': the owning module is disabled, so the
 *   integration is unavailable. The description must say so and
 *   the button links to the Modules page, never to the disabled
 *   module's settings. Only an owning module reports this status,
 *   and only after checking ModuleRegistry::isEnabled() itself.
 *
 * Registration (round 6): every entry is registered from ONE
 * place - IntegrationsModule::registerEntries(), called by
 * renderSettingsPage() before apply_filters() and guarded so it
 * runs once per request. Owning modules do NOT register in
 * boot(): ModuleRegistry::bootEnabled() only boots enabled
 * modules, so a boot()-registered entry would vanish the moment
 * its module is disabled. Central registration means every
 * service always appears; a disabled owner's callback reports
 * 'module-disabled' instead of disappearing. WordPress dedupes
 * identical callback+priority pairs and validateEntries() is
 * first-wins, so a service can never be listed twice.
 *
 * Built-in detectors: four seeded services have no owning module
 * whose boot() could register them, so this module registers them
 * itself (see registerPushEntry(), registerWeatherEntry(),
 * registerPhotonEntry(), registerMailPoetEntry()):
 * - Push (Firebase Cloud Messaging): owned by the Push
 *   module ('push'); the entry is built here from
 *   PushModule::isConfigured() because the module itself
 *   does not register integration entries in boot().
 * - Open-Meteo weather: owned by the Weather module
 *   ('weather'); the entry is built here from
 *   WeatherHelper::getDefaultLocation() and
 *   WeatherHelper::getCachedCurrent(), and reports
 *   'module-disabled' while the module is off.
 * - Photon: the toggle lives in the Customizer
 *   (`photon_enabled`) and serving lives in ThemeImage via the
 *   traydigita-stack helper; there is no Photon module, so the
 *   entry is built here from SettingsCustomizer and the presence
 *   of the Stack class.
 * - MailPoet: no MailPoet code exists anywhere in the theme (no
 *   owner module, no configured signal in the codebase), so the
 *   entry is built here from is_plugin_active() only, using the
 *   'active' / 'not-configured' statuses and no 'configured'
 *   state.
 *
 * isConfigured() is true because this module is a hub, not a
 * configured service: there is nothing to set up before the page
 * is useful. Entries from owning modules always appear: when the
 * owning module is disabled its callback reports
 * 'module-disabled' instead of the entry disappearing (see
 * registerEntries()). The built-in entries below always appear.
 */
final class IntegrationsModule implements ModuleInterface
{
    /**
     * Filter owning modules use to register their entry.
     *
     * Built from Core::HOOK_PREFIX so a custom hook prefix
     * applies, like the other module filters.
     */
    public const string FILTER = Core::HOOK_PREFIX . ':integrations';

    /**
     * MailPoet plugin file as used by is_plugin_active().
     */
    public const string MAILPOET_PLUGIN_FILE = 'mailpoet/mailpoet.php';

    /**
     * MailPoet admin page slug, used for the "open MailPoet" link
     * when the plugin is active.
     */
    public const string MAILPOET_PAGE = 'mailpoet-homepage';

    /**
     * Allowed entry statuses.
     *
     * @var array<int, string>
     */
    private const array STATUSES = ['active', 'configured', 'not-configured', 'module-disabled'];

    /**
     * Default icon glyph when an entry provides none or an invalid one.
     */
    private const string DEFAULT_ICON = 'extension';

    /**
     * Whether registerEntries() already ran this request.
     */
    private static bool $entriesRegistered = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'integrations';
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
        return 'integrations';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Integrations', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'A hub listing every service the theme connects to, with live status and a link to its settings.',
            'traydigita-core'
        );
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-plugins';
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
            __('One hub listing every service the theme connects to.', 'traydigita-core'),
            __('Live status per service.', 'traydigita-core'),
            __('Link to each service’s settings.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here - never invent earlier versions.
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
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-integrations';
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
     * Always true: the hub needs no credentials, no options and
     * no service state of its own. An empty hub (no entries at
     * all) still renders honestly instead of failing.
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
     * True: the hub cannot be deactivated, so the list of
     * connected services is always available.
     *
     * @return bool Always true.
     */
    public static function isCore(): bool
    {
        return true;
    }

    /**
     * Whether the module appears in the TrayDigita submenu.
     *
     * True: the hub is opened often, so it opts into the
     * data-driven submenu. Its open link is its own settings
     * page (no openLink() override).
     *
     * @return bool Always true.
     */
    public static function showInSubmenu(): bool
    {
        return true;
    }

    /**
     * Position of the module in the TrayDigita submenu.
     *
     * -5: after Plugin Suggestions (-10) and before Site Health
     * (0). AdminMenu orders the data-driven submenu entries by
     * this position ascending.
     *
     * @return int
     */
    public static function submenuPosition(): int
    {
        return -5;
    }

    /**
     * Render the integrations hub inside the admin shell content area.
     *
     * Registers every entry (see registerEntries()), applies the
     * `traydigita:core:integrations` filter, validates every entry
     * defensively (see validateEntries()), and renders one card per
     * entry in a responsive grid: icon tile, name, description,
     * status pill (icon + text, never colour alone) and a link
     * button to its settings URL. A search box and status filter
     * chips filter the cards client-side without a reload (see
     * resources/js/modules/admin-integrations.ts). When no entries
     * register at all, an honest empty state with an icon is shown
     * instead of a fake list.
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
        self::registerEntries();
        $entries = self::validateEntries(apply_filters(self::FILTER, []));
        $counts = [
            'all' => count($entries),
            'active' => 0,
            'configured' => 0,
            'not-configured' => 0,
            'module-disabled' => 0,
        ];
        foreach ($entries as $entry) {
            $counts[$entry['status']]++;
        }
        $filters = [
            'all' => __('All', 'traydigita-core'),
            'active' => __('Active', 'traydigita-core'),
            'configured' => __('Configured', 'traydigita-core'),
            'not-configured' => __('Not configured', 'traydigita-core'),
            'module-disabled' => __('Module disabled', 'traydigita-core'),
        ];
        $noResultsMessage = esc_attr__('No integrations match your search or filter.', 'traydigita-core');
        $noResultsHint = esc_attr__('Try a different search term or filter.', 'traydigita-core');
        ?>
        <div class="traydigita-integrations" data-integrations-admin
            data-msg-no-results="<?php echo $noResultsMessage; ?>"
            data-msg-no-results-hint="<?php echo $noResultsHint; ?>">
            <p>
                <?php
                echo esc_html(
                    __('Every service the theme connects to, with live status and a settings link.', 'traydigita-core')
                );
                ?>
            </p>
            <p>
                <?php
                echo esc_html(
                    __('Status is checked live when this page loads — nothing here is stored.', 'traydigita-core')
                );
                ?>
            </p>
            <?php if ($entries === []) : ?>
                <div class="traydigita-admin-empty">
                    <span class="traydigita-admin-empty-icon material-icons" aria-hidden="true">extension</span>
                    <p class="traydigita-admin-empty-title">
                        <?php echo esc_html(__('No integrations have registered yet.', 'traydigita-core')); ?>
                    </p>
                    <p>
                        <?php
                        echo esc_html(
                            __('Enable a module that connects to a service and it will appear here.', 'traydigita-core')
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="traydigita-integrations-toolbar">
                    <label class="traydigita-integrations-search">
                        <span class="material-icons" aria-hidden="true">search</span>
                        <span class="screen-reader-text">
                            <?php esc_html_e('Search integrations', 'traydigita-core'); ?>
                        </span>
                        <input type="search" class="traydigita-ui-input" data-integrations-search
                            placeholder="<?php echo esc_attr__('Search integrations…', 'traydigita-core'); ?>"
                            aria-label="<?php echo esc_attr__('Search integrations', 'traydigita-core'); ?>">
                    </label>
                    <div class="traydigita-integrations-filters" role="group"
                        aria-label="<?php echo esc_attr__('Filter by status', 'traydigita-core'); ?>">
                        <?php foreach ($filters as $status => $label) : ?>
                            <button type="button"
                                class="traydigita-integrations-chip<?php echo $status === 'all' ? ' is-active' : ''; ?>"
                                data-integrations-filter="<?php echo esc_attr($status); ?>"
                                aria-pressed="<?php echo $status === 'all' ? 'true' : 'false'; ?>">
                                <?php echo esc_html($label); ?>
                                <span class="traydigita-integrations-chip-count">
                                    <?php echo esc_html((string) $counts[$status]); ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="traydigita-integrations-grid">
                    <?php foreach ($entries as $entry) :
                        $badgeClass = self::badgeClass($entry['status']);
                        $badgeLabel = self::badgeLabel($entry['status']);
                        $badgeIcon = self::badgeIcon($entry['status']);
                        $isDisabled = $entry['status'] === 'module-disabled';
                        $buttonLabel = $isDisabled
                            ? __('Open modules', 'traydigita-core')
                            : __('Open settings', 'traydigita-core');
                        $cardClass = 'traydigita-admin-card traydigita-integrations-card';
                        if ($isDisabled) {
                            $cardClass .= ' traydigita-integrations-card--disabled';
                        }
                        $searchText = esc_attr(
                            strtolower($entry['name'] . ' ' . $entry['description'])
                        );
                        ?>
                        <article
                            class="<?php echo esc_attr($cardClass); ?>"
                            data-integration-status="<?php echo esc_attr($entry['status']); ?>"
                            data-integration-search="<?php echo $searchText; ?>">
                            <div class="traydigita-admin-card-head">
                                <span class="traydigita-admin-card-icon material-icons" aria-hidden="true">
                                    <?php echo esc_html($entry['icon']); ?>
                                </span>
                                <div class="traydigita-admin-card-titles">
                                    <h2 class="traydigita-admin-card-name"><?php echo esc_html($entry['name']); ?></h2>
                                </div>
                            </div>
                            <div class="traydigita-admin-card-description-wrap">
                                <p class="traydigita-admin-card-description">
                                    <?php echo esc_html($entry['description']); ?>
                                </p>
                            </div>
                            <div class="traydigita-admin-card-foot traydigita-integrations-card-foot">
                                <span class="traydigita-admin-badge <?php echo esc_html($badgeClass); ?>">
                                    <span class="material-icons" aria-hidden="true">
                                        <?php echo esc_html($badgeIcon); ?>
                                    </span>
                                    <?php echo esc_html($badgeLabel); ?>
                                </span>
                                <?php if ($entry['url'] !== '') : ?>
                                    <a class="traydigita-admin-button traydigita-admin-button-primary"
                                        href="<?php echo esc_url($entry['url']); ?>">
                                        <?php echo esc_html($buttonLabel); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="description">&mdash;</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="traydigita-admin-empty traydigita-integrations-no-results"
                    data-integrations-no-results hidden>
                    <span class="traydigita-admin-empty-icon material-icons" aria-hidden="true">search_off</span>
                    <p class="traydigita-admin-empty-title">
                        <?php echo esc_html(__('No integrations match your search or filter.', 'traydigita-core')); ?>
                    </p>
                    <p><?php echo esc_html(__('Try a different search term or filter.', 'traydigita-core')); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The hub has no theme-side settings to save.
     *
     * @param array<string, mixed> $data Sanitized form data (ignored).
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        return [
            'success' => true,
            'message' => esc_html__(
                'Integrations has no settings to save. This page is read-only status.',
                'traydigita-core'
            ),
        ];
    }

    /**
     * Register every integration entry on the filter, exactly once.
     *
     * The single registration path for the hub (round 6): the four
     * built-in entries (push, Open-Meteo, Photon, MailPoet) plus
     * the five owning modules' registerIntegrationEntry callbacks
     * (Site Kit, SEO, Turnstile, Comments, Markets). It runs from
     * renderSettingsPage() before apply_filters(), never from
     * boot(): ModuleRegistry::bootEnabled() only boots enabled
     * modules, so registering in an owning module's boot() would
     * hide its service the moment the module is disabled. A
     * disabled owner's callback reports 'module-disabled' instead.
     * Guarded by $entriesRegistered so a second render in the same
     * request cannot double-register (WordPress would dedupe the
     * identical callbacks anyway, and validateEntries() is
     * first-wins).
     *
     * @return void
     */
    private static function registerEntries(): void
    {
        if (self::$entriesRegistered) {
            return;
        }
        self::$entriesRegistered = true;
        add_filter(self::FILTER, [self::class, 'registerPushEntry']);
        add_filter(self::FILTER, [self::class, 'registerWeatherEntry']);
        add_filter(self::FILTER, [self::class, 'registerPhotonEntry']);
        add_filter(self::FILTER, [self::class, 'registerMailPoetEntry']);
        add_filter(self::FILTER, [SiteKitModule::class, 'registerIntegrationEntry']);
        add_filter(self::FILTER, [SeoModule::class, 'registerIntegrationEntry']);
        add_filter(self::FILTER, [TurnstileModule::class, 'registerIntegrationEntry']);
        add_filter(self::FILTER, [CommentsModule::class, 'registerIntegrationEntry']);
        add_filter(self::FILTER, [MarketsModule::class, 'registerIntegrationEntry']);
    }

    /**
     * Admin URL of the Modules page.
     *
     * The target of a 'module-disabled' entry's button: the admin
     * enables the owning module there. Kept here so the five owning
     * callbacks share one URL instead of hardcoding the menu slug.
     *
     * @return string
     */
    public static function modulesUrl(): string
    {
        return admin_url('admin.php?page=' . AdminMenu::MENU_SLUG);
    }

    /**
     * This module has no hooks of its own.
     *
     * Entry registration lives in registerEntries(), called from
     * renderSettingsPage() (see its docblock): registering here in
     * boot() would still work for the four built-ins, but the hub
     * keeps exactly one registration path for all nine services.
     *
     * @return void
     */
    public static function boot(): void
    {
    }

    /**
     * Built-in entry: Firebase Cloud Messaging (push).
     *
     * Registered here because the Push module does not register
     * integration entries in boot(). Status comes from the
     * module: 'active' when a valid push config is saved,
     * 'not-configured' otherwise (including when the module is
     * disabled - the entry then links to the Modules page via
     * the module id, per the integrations contract).
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the push entry appended.
     */
    public static function registerPushEntry(array $entries): array
    {
        $active = PushModule::isConfigured();
        $description = $active
            ? __(
                'Manual push notifications to subscribers, configured in the Push module.',
                'traydigita-core'
            )
            : __(
                'Manual push notifications to subscribers — save a Firebase config in the Push module.',
                'traydigita-core'
            );
        $entries[] = [
            'id' => 'push',
            'name' => 'Firebase Cloud Messaging',
            'description' => $description,
            'status' => $active ? 'active' : 'not-configured',
            'url' => admin_url('admin.php?page=' . PushModule::settingsPageSlug()),
            'module' => PushModule::id(),
            'icon' => 'notifications',
        ];
        return $entries;
    }

    /**
     * Built-in entry: Open-Meteo weather.
     *
     * Open-Meteo is free and keyless with a built-in default location
     * (Padang), so there is no 'not-configured' state: 'active'
     * when a fresh forecast is cached (WeatherHelper caches a
     * successful fetch for 30 minutes, so a cache hit proves the
     * provider responded recently), 'configured' otherwise, and
     * 'module-disabled' while the Weather module is off (same
     * pattern as MarketsModule::registerIntegrationEntry()).
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the weather entry appended.
     */
    public static function registerWeatherEntry(array $entries): array
    {
        $location = WeatherHelper::getDefaultLocation();
        $cached = WeatherHelper::getCachedCurrent($location['latitude'], $location['longitude']);
        $active = $cached !== null;
        $description = $active
            ? sprintf(
                /* translators: %s is the default weather city, e.g. "Padang". */
                __('Free forecast for %s from Open-Meteo — no API key. Fresh '
                    . 'data fetched recently.', 'traydigita-core'),
                $location['name']
            )
            : sprintf(
                /* translators: %s is the default weather city, e.g. "Padang". */
                __('Free forecast for %s from Open-Meteo — no API key. No fresh data cached yet.', 'traydigita-core'),
                $location['name']
            );
        if (!WeatherModule::isEnabled()) {
            $description = __('The Weather module is disabled — enable it in Modules to use the weather integration.', 'traydigita-core');
            $status = 'module-disabled';
            $url = self::modulesUrl();
        } else {
            $status = $active ? 'active' : 'configured';
            $url = admin_url('admin.php?page=' . WeatherModule::settingsPageSlug());
        }
        $entries[] = [
            'id' => 'open-meteo',
            'name' => 'Open-Meteo',
            'description' => $description,
            'status' => $status,
            'url' => $url,
            'module' => WeatherModule::id(),
            'icon' => 'cloud',
        ];
        return $entries;
    }

    /**
     * Built-in entry: Jetpack Photon image serving.
     *
     * Registered here because Photon has no module: the toggle
     * lives in the Customizer (`photon_enabled`) and serving lives
     * in ThemeImage via the traydigita-stack helper. Reuses
     * Settings::get('photon_enabled') and the same Stack
     * availability signal ThemeImage uses (the Stack class being
     * loaded). 'active' when the toggle is on and the stack helper
     * is available, 'configured' when the toggle is on but the
     * stack plugin is not active (the setting is saved but images
     * are still served locally), 'not-configured' when off.
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the Photon entry appended.
     */
    public static function registerPhotonEntry(array $entries): array
    {
        $enabled = Settings::sanitizeCheckbox(Settings::get('photon_enabled'));
        if (!$enabled) {
            $status = 'not-configured';
            $description = __(
                'Serve images via Jetpack Photon (i0.wp.com) — switch it on in the Customizer.',
                'traydigita-core'
            );
        } elseif (!class_exists(Stack::class)) {
            $status = 'configured';
            $description = __(
                'Photon is on, but the traydigita-stack plugin is inactive, so images are served locally.',
                'traydigita-core'
            );
        } else {
            $status = 'active';
            $description = __(
                'Images are served via Jetpack Photon (i0.wp.com) through the traydigita-stack helper.',
                'traydigita-core'
            );
        }
        $entries[] = [
            'id' => 'photon',
            'name' => 'Jetpack Photon',
            'description' => $description,
            'status' => $status,
            'url' => admin_url('customize.php?autofocus[section]=' . Definitions::SECTION_IMAGES),
            'module' => self::id(),
            'icon' => 'image',
        ];
        return $entries;
    }

    /**
     * Built-in entry: MailPoet.
     *
     * Registered here because no MailPoet code exists anywhere in
     * the theme: there is no owner module and no configured signal
     * in the codebase to reuse. Detection is the plugin file only,
     * so only 'active' (plugin active) and 'not-configured'
     * (plugin not active) are used - 'configured' is never
     * reported because no real configured signal exists.
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the MailPoet entry appended.
     */
    public static function registerMailPoetEntry(array $entries): array
    {
        $active = self::isPluginActive(self::MAILPOET_PLUGIN_FILE);
        $installed = $active || self::isPluginInstalled(self::MAILPOET_PLUGIN_FILE);
        if ($active) {
            $description = __('Email marketing and newsletters via the MailPoet plugin.', 'traydigita-core');
            $url = admin_url('admin.php?page=' . self::MAILPOET_PAGE);
        } elseif ($installed) {
            $description = __(
                'MailPoet is installed but not active — activate it to use it for email marketing and newsletters.',
                'traydigita-core'
            );
            $url = admin_url('plugins.php');
        } else {
            $description = __(
                'MailPoet is not installed — install it to use it for email marketing and newsletters.',
                'traydigita-core'
            );
            $url = admin_url('admin.php?page=plugin-install.php&tab=plugin-information&plugin=mailpoet');
        }
        $entries[] = [
            'id' => 'mailpoet',
            'name' => 'MailPoet',
            'description' => $description,
            'status' => $active ? 'active' : 'not-configured',
            'url' => $url,
            'module' => self::id(),
            'icon' => 'mail',
        ];
        return $entries;
    }

    /**
     * Validate filter entries defensively.
     *
     * An entry is kept only when it is an array with a non-empty
     * string id and name and a status in 'active', 'configured',
     * 'not-configured' or 'module-disabled'; anything else is
     * dropped. The first entry wins when two callbacks register
     * the same id, so a service is never listed twice. Description,
     * url and module default to '' when missing or not a string,
     * and the url is kept only when it is a valid admin URL (see
     * isAdminUrl()). The optional 'icon' must be a Material Icons
     * ligature name (`^[a-z0-9_]+$`); anything else falls back to
     * 'extension', so a bad glyph can never print raw text as an
     * icon or break the icon font lookup.
     *
     * @param mixed $entries Raw filter value.
     * @return array<int, array{id: string, name: string, description: string, status: string,
     *     url: string, module: string, icon: string}>
     */
    private static function validateEntries(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }
        $valid = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = isset($entry['id']) && is_string($entry['id']) ? trim($entry['id']) : '';
            $name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
            $status = isset($entry['status']) && is_string($entry['status']) ? trim($entry['status']) : '';
            if ($id === '' || $name === '' || !in_array($status, self::STATUSES, true)) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $description = isset($entry['description']) && is_string($entry['description'])
                ? $entry['description']
                : '';
            $url = isset($entry['url']) && is_string($entry['url']) ? trim($entry['url']) : '';
            $module = isset($entry['module']) && is_string($entry['module']) ? trim($entry['module']) : '';
            $icon = isset($entry['icon']) && is_string($entry['icon']) ? trim($entry['icon']) : '';
            if ($icon === '' || preg_match('/^[a-z0-9_]+$/', $icon) !== 1) {
                $icon = self::DEFAULT_ICON;
            }
            $valid[] = [
                'id' => $id,
                'name' => $name,
                'description' => $description,
                'status' => $status,
                'url' => self::isAdminUrl($url) ? $url : '',
                'module' => $module,
                'icon' => $icon,
            ];
        }
        return $valid;
    }

    /**
     * Whether a URL is a valid admin URL for a settings link.
     *
     * Accepts absolute URLs under this site's wp-admin (as built
     * by admin_url()) and admin-relative targets naming a WordPress
     * admin file. Anything else - external URLs, javascript:,
     * protocol-relative URLs - is rejected, so a bad entry can
     * never turn its link button into an off-site link.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    private static function isAdminUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, admin_url())) {
            return true;
        }
        return str_starts_with($url, 'admin.php?')
            || str_starts_with($url, 'customize.php')
            || str_starts_with($url, 'plugins.php')
            || str_starts_with($url, 'plugin-install.php');
    }

    /**
     * Badge modifier class for a status.
     *
     * @param string $status Validated entry status.
     * @return string
     */
    private static function badgeClass(string $status): string
    {
        return match ($status) {
            'active' => 'traydigita-admin-badge-success',
            'configured' => 'traydigita-admin-badge-warning',
            'module-disabled' => 'traydigita-admin-badge-disabled',
            default => 'traydigita-admin-badge-muted',
        };
    }

    /**
     * Badge label for a status.
     *
     * @param string $status Validated entry status.
     * @return string
     */
    private static function badgeLabel(string $status): string
    {
        return match ($status) {
            'active' => __('Active', 'traydigita-core'),
            'configured' => __('Configured', 'traydigita-core'),
            'module-disabled' => __('Module disabled', 'traydigita-core'),
            default => __('Not configured', 'traydigita-core'),
        };
    }

    /**
     * Material Icons glyph for a status pill.
     *
     * The pill is icon + text, never colour alone: every status
     * has a distinct glyph as well as a distinct colour.
     *
     * @param string $status Validated entry status.
     * @return string
     */
    private static function badgeIcon(string $status): string
    {
        return match ($status) {
            'active' => 'check_circle',
            'configured' => 'tune',
            'module-disabled' => 'block',
            default => 'info',
        };
    }

    /**
     * Whether a plugin file is active.
     *
     * Mirrors SiteKitDetector: is_plugin_active() lives in
     * wp-admin/includes/plugin.php, which is loaded in the admin
     * but not always on the frontend, so it is included on demand
     * when the function does not exist yet.
     *
     * @param string $file Plugin file, e.g. 'mailpoet/mailpoet.php'.
     * @return bool
     */
    private static function isPluginActive(string $file): bool
    {
        self::ensurePluginApi();
        if (!function_exists('is_plugin_active')) {
            return false;
        }
        return is_plugin_active($file);
    }

    /**
     * Whether a plugin file is installed (active or not).
     *
     * Returns false when the admin plugin API cannot be loaded,
     * for example outside WordPress.
     *
     * @param string $file Plugin file, e.g. 'mailpoet/mailpoet.php'.
     * @return bool
     */
    private static function isPluginInstalled(string $file): bool
    {
        self::ensurePluginApi();
        if (!function_exists('get_plugins')) {
            return false;
        }
        $plugins = get_plugins();
        return is_array($plugins) && isset($plugins[$file]);
    }

    /**
     * Load the admin plugin API on demand.
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
