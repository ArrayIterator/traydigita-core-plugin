<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Installer;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function admin_url;
use function checked;
use function count;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_option;
use function is_array;
use function sprintf;
use function strtolower;
use function update_option;
use function usort;
use function wp_nonce_field;
use function _n;

/**
 * Plugin Suggestions module (TGMPA-style recommended-plugins page).
 *
 * Its own admin page lists a short curated set of plugins for a
 * news site, with Google Site Kit as the top recommendation and
 * traydigita-core flagged as required - the theme does not boot
 * without the core plugin (functions.php gates on it), so the
 * core row carries a "required" notice and its status is checked,
 * not assumed. Each plugin shows its status (not installed /
 * installed / active) with per-plugin Install and Activate
 * buttons that run live over AJAX with progress; installing never
 * activates anything - activation always takes a separate click.
 *
 * Trust rules (see PluginList and InstallerAjax): only curated
 * wordpress.org slugs can be installed, the package must come
 * from downloads.wordpress.org, and the core plugin cannot be
 * installed from here at all because it has no wordpress.org
 * package. Installs use core's Plugin_Upgrader, require the
 * `install_plugins` capability (`activate_plugins` for
 * activation), and refuse with a clear error when direct
 * filesystem access is unavailable instead of asking for FTP
 * credentials.
 *
 * The page has one real setting - whether to hide plugins that
 * are already active from the list - saved in the single option
 * `traydigita_installer` (autoload `no`) through the generic
 * admin AJAX dispatcher. Everything else on the page is status
 * and actions, not settings.
 *
 * Coordinator wiring (not done here): add this class to
 * ModuleRegistry::DEFAULT_CLASSES and 'installer' to
 * DEFAULT_ENABLED, and import the admin JS in resources/js/
 * admin.ts with `import "./modules/admin-installer";`.
 */
final class InstallerModule implements ModuleInterface
{
    /**
     * Option name for the module's display setting (autoload `no`).
     */
    public const string OPTION = 'traydigita_installer';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_installer_save';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'installer';
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
        return __('Plugin Suggestions', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Curated plugin suggestions for a news site — Google Site Kit first — installed straight from wordpress.org. Installing never activates anything; activation always takes a separate click.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'download';
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
            __('Curated plugin suggestions for a news site, Google Site Kit first.', 'traydigita-core'),
            __('Installs straight from wordpress.org.', 'traydigita-core'),
            __('Installing never activates anything — activation takes a separate click.', 'traydigita-core'),
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
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-installer';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * Always true: the module only lists and installs plugins, so
     * it works whether or not any given recommended plugin is
     * present. The core plugin's absence is a status to show, not
     * a missing dependency of this module.
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
     * Configured means the required TrayDigita Core plugin is
     * active. In practice the theme does not boot without it, so
     * this is normally true whenever the page can be reached at
     * all; it stays a real check so a deactivated Core plugin is
     * surfaced as "needs setup" instead of silently assumed.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return PluginList::status(PluginList::CORE_SLUG) === PluginList::STATUS_ACTIVE;
    }

    /**
     * Whether this is a core module.
     *
     * True: core modules are always enabled and cannot be
     * deactivated, so the Plugin Suggestions page is always
     * available. Disabling it would only hide the page; installed
     * plugins keep working either way.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return true;
    }

    /**
     * Whether the module appears in the TrayDigita submenu.
     *
     * True: the Plugin Suggestions page is opened often, so the
     * module opts into the data-driven submenu
     * (ModuleRegistry::showInSubmenu() default is false). Its
     * open link is its own settings page (no openLink()
     * override), so the submenu entry is a real submenu entry
     * for that page.
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
     * -10: the module is a setup companion, so it sorts before
     * Site Health (position 0) and before other opt-in submenu
     * entries.
     *
     * @return int
     */
    public static function submenuPosition(): int
    {
        return -10;
    }

    /**
     * Render the installer page inside the admin shell content area.
     *
     * A required-plugin notice for traydigita-core, a short "how
     * installing works" note, then the curated plugins grouped by
     * category - Required, Cache, SEO, Analytics, Security, Image
     * - with the recommended plugin first in its group. The cache
     * group carries the shared server-aware note from
     * CacheModule::recommendedPlugin() (via PluginList): the
     * recommended plugin on a known server, no recommendation
     * while developing, and W3 Total Cache as the safe general
     * choice - without the badge - when the server cannot be
     * identified. Each card uses the same card
     * language as the integrations hub - icon tile, name, one-line
     * reason, icon+text status pill, and Install / Activate
     * buttons - with a search box, status filter chips and a
     * Refresh status button above the list (see
     * resources/js/modules/admin-installer.ts). The buttons are
     * driven by that module, which reads the translatable strings
     * from this root element's data-msg-* attributes and posts to
     * the module-owned AJAX endpoints. A card holds the one
     * display setting (hide plugins that are already active) at
     * the bottom; when it hides every plugin, an honest empty
     * state with an icon is shown instead of an empty list.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $statuses = PluginList::statuses();
        $plugins = PluginList::all();
        $coreStatus = $statuses[PluginList::CORE_SLUG] ?? PluginList::STATUS_NOT_INSTALLED;
        $counts = [
            'all' => count($plugins),
            'active' => 0,
            'installed' => 0,
            'not-installed' => 0,
        ];
        $hiddenActive = 0;
        $visible = 0;
        foreach ($plugins as $plugin) {
            $status = $statuses[$plugin['slug']] ?? PluginList::STATUS_NOT_INSTALLED;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if ($settings['hide_active'] && $status === PluginList::STATUS_ACTIVE) {
                $hiddenActive++;
                continue;
            }
            $visible++;
        }
        $filters = [
            'all' => __('All', 'traydigita-core'),
            'active' => __('Active', 'traydigita-core'),
            'installed' => __('Installed', 'traydigita-core'),
            'not-installed' => __('Not installed', 'traydigita-core'),
        ];
        $groups = self::visibleGroups($plugins, $statuses, $settings);
        $cacheRecommendation = PluginList::cacheRecommendation();
        ?>
        <div class="traydigita-module-settings installer-settings" data-installer-admin
            data-msg-installing="<?php echo esc_attr(__('Installing…', 'traydigita-core')); ?>"
            data-msg-activating="<?php echo esc_attr(__('Activating…', 'traydigita-core')); ?>"
            data-msg-install="<?php echo esc_attr(__('Install', 'traydigita-core')); ?>"
            data-msg-activate="<?php echo esc_attr(__('Activate', 'traydigita-core')); ?>"
            data-msg-installed="<?php echo esc_attr(__('Installed', 'traydigita-core')); ?>"
            data-msg-active="<?php echo esc_attr(__('Active', 'traydigita-core')); ?>"
            data-msg-not-installed="<?php echo esc_attr(__('Not installed', 'traydigita-core')); ?>"
            data-msg-manual="<?php echo esc_attr(__('Not available from wordpress.org — install it from the project’s own release.', 'traydigita-core')); ?>"
            data-msg-refresh="<?php echo esc_attr(__('Refresh status', 'traydigita-core')); ?>"
            data-msg-refreshing="<?php echo esc_attr(__('Refreshing…', 'traydigita-core')); ?>"
            data-msg-refreshed="<?php echo esc_attr(__('Statuses refreshed.', 'traydigita-core')); ?>"
            data-msg-error="<?php echo esc_attr(__('Request failed. Please try again.', 'traydigita-core')); ?>">
            <?php if ($coreStatus !== PluginList::STATUS_ACTIVE) : ?>
                <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                    <p>
                        <?php esc_html_e('TrayDigita Core is required by the theme and is not active. The theme does not boot without it — install it from the project’s own release, then activate it on the Plugins screen.', 'traydigita-core'); ?>
                    </p>
                    <p>
                        <a class="traydigita-admin-button" href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                            <?php esc_html_e('Open Plugins', 'traydigita-core'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
            <div class="traydigita-admin-notice">
                <p>
                    <?php
                    SettingsControls::helpText(
                        __('Plugins are installed only from the wordpress.org plugin directory — never from any '
                            . 'other source — and installing never activates anything.', 'traydigita-core'),
                        __('Click Activate separately when you are ready. If WordPress cannot write plugin files directly on this site, the install is refused with an explanation instead of asking for FTP credentials here.', 'traydigita-core')
                    );
                    ?>
                </p>
            </div>

            <?php if ($visible > 0) : ?>
                <div class="traydigita-integrations-toolbar installer-toolbar">
                    <label class="traydigita-integrations-search">
                        <span class="material-icons" aria-hidden="true">search</span>
                        <span class="screen-reader-text">
                            <?php esc_html_e('Search plugins', 'traydigita-core'); ?>
                        </span>
                        <input type="search" class="traydigita-ui-input" data-installer-search
                            placeholder="<?php echo esc_attr__('Search plugins…', 'traydigita-core'); ?>"
                            aria-label="<?php echo esc_attr__('Search plugins', 'traydigita-core'); ?>">
                    </label>
                    <div class="traydigita-integrations-filters" role="group"
                        aria-label="<?php echo esc_attr__('Filter by status', 'traydigita-core'); ?>">
                        <?php foreach ($filters as $status => $label) : ?>
                            <button type="button"
                                class="traydigita-integrations-chip<?php echo $status === 'all' ? ' is-active' : ''; ?>"
                                data-installer-filter="<?php echo esc_attr($status); ?>"
                                aria-pressed="<?php echo $status === 'all' ? 'true' : 'false'; ?>">
                                <?php echo esc_html($label); ?>
                                <span class="traydigita-integrations-chip-count">
                                    <?php echo esc_html((string) $counts[$status]); ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="traydigita-admin-button installer-refresh-button"
                        data-installer-refresh>
                        <span class="material-icons" aria-hidden="true">refresh</span>
                        <span data-installer-refresh-label><?php esc_html_e('Refresh status', 'traydigita-core'); ?></span>
                    </button>
                </div>
                <p class="installer-refresh-status" data-installer-refresh-status role="status"></p>
            <?php endif; ?>

            <?php if ($visible === 0) : ?>
                <div class="traydigita-admin-empty">
                    <span class="traydigita-admin-empty-icon material-icons" aria-hidden="true">check_circle</span>
                    <p class="traydigita-admin-empty-title">
                        <?php echo esc_html(__('All suggested plugins are already active.', 'traydigita-core')); ?>
                    </p>
                    <p>
                        <?php
                        echo esc_html(
                            __('Turn off “Hide plugins that are already active” below to list them again.', 'traydigita-core')
                        );
                        ?>
                    </p>
                </div>
            <?php else : ?>
                <div class="installer-plugin-list">
                    <?php foreach ($groups as $group) : ?>
                        <section class="installer-plugin-group" id="traydigita-installer-group-<?php echo esc_attr($group['slug']); ?>" data-installer-group="<?php echo esc_attr($group['slug']); ?>">
                            <h2 class="traydigita-ui-section-title installer-plugin-group-title"><?php echo esc_html($group['label']); ?></h2>
                            <?php if ($group['slug'] === PluginList::CATEGORY_CACHE) : ?>
                                <p class="traydigita-admin-card-note installer-server-note">
                                    <?php echo esc_html($cacheRecommendation['note']); ?>
                                </p>
                            <?php endif; ?>
                            <div class="traydigita-integrations-grid">
                                <?php foreach ($group['plugins'] as $plugin) : ?>
                                    <?php
                                    $status = $statuses[$plugin['slug']] ?? PluginList::STATUS_NOT_INSTALLED;
                                    $badgeClass = $status === PluginList::STATUS_ACTIVE
                                        ? 'traydigita-admin-badge-success'
                                        : ($status === PluginList::STATUS_INSTALLED
                                            ? 'traydigita-admin-badge-warning'
                                            : 'traydigita-admin-badge-muted');
                                    $badgeLabel = $status === PluginList::STATUS_ACTIVE
                                        ? __('Active', 'traydigita-core')
                                        : ($status === PluginList::STATUS_INSTALLED
                                            ? __('Installed', 'traydigita-core')
                                            : __('Not installed', 'traydigita-core'));
                                    $badgeIcon = $status === PluginList::STATUS_ACTIVE
                                        ? 'check_circle'
                                        : ($status === PluginList::STATUS_INSTALLED
                                            ? 'download_done'
                                            : 'download');
                                    $searchText = esc_attr(
                                        strtolower($plugin['name'] . ' ' . $plugin['reason'])
                                    );
                                    ?>
                                    <article class="traydigita-admin-card traydigita-integrations-card installer-plugin"
                                        data-installer-plugin data-slug="<?php echo esc_attr($plugin['slug']); ?>"
                                        data-installable="<?php echo $plugin['installable'] ? '1' : '0'; ?>"
                                        data-plugin-status="<?php echo esc_attr($status); ?>"
                                        data-installer-search-text="<?php echo $searchText; ?>">
                                        <div class="traydigita-admin-card-head">
                                            <span class="traydigita-admin-card-icon material-icons" aria-hidden="true">
                                                <?php echo esc_html(PluginList::iconFor($plugin['slug'])); ?>
                                            </span>
                                            <div class="traydigita-admin-card-titles">
                                                <h2 class="traydigita-admin-card-name"><?php echo esc_html($plugin['name']); ?></h2>
                                                <?php if ($plugin['recommended'] || $plugin['required']) : ?>
                                                    <span class="installer-plugin-flags">
                                                        <?php if ($plugin['recommended']) : ?>
                                                            <span class="traydigita-admin-badge traydigita-admin-badge-success"><?php esc_html_e('Recommended', 'traydigita-core'); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($plugin['required']) : ?>
                                                            <span class="traydigita-admin-badge traydigita-admin-badge-warning"><?php esc_html_e('Required', 'traydigita-core'); ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="traydigita-admin-card-description-wrap"><p class="traydigita-admin-card-description"><?php echo esc_html($plugin['reason']); ?></p></div>
                                        <?php if ($plugin['duplicates'] !== null) : ?>
                                            <p class="traydigita-admin-card-note">
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        /* translators: %s is the name of the theme module this plugin overlaps, e.g. "SEO". */
                                                        __('Note: this overlaps the theme’s %s module, which steps aside '
                                                            . 'while the plugin is active.', 'traydigita-core'),
                                                        $plugin['duplicates'] === 'seo' ? __('SEO', 'traydigita-core') : __('Image Optimizer', 'traydigita-core')
                                                    )
                                                );
                                                ?>
                                            </p>
                                        <?php endif; ?>
                                        <div class="traydigita-admin-card-foot">
                                            <span class="traydigita-admin-badge installer-status-badge <?php echo esc_attr($badgeClass); ?>" data-installer-badge>
                                                <span class="material-icons" aria-hidden="true">
                                                    <?php echo esc_html($badgeIcon); ?>
                                                </span>
                                                <span data-badge-label><?php echo esc_html($badgeLabel); ?></span>
                                            </span>
                                            <div class="installer-plugin-actions">
                                                <?php if ($status === PluginList::STATUS_NOT_INSTALLED && $plugin['installable']) : ?>
                                                    <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-installer-install>
                                                        <?php esc_html_e('Install', 'traydigita-core'); ?>
                                                    </button>
                                                <?php elseif ($status === PluginList::STATUS_NOT_INSTALLED) : ?>
                                                    <span class="installer-plugin-manual">
                                                        <?php esc_html_e('Not available from wordpress.org — install it from the project’s own release.', 'traydigita-core'); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($status === PluginList::STATUS_INSTALLED) : ?>
                                                    <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-installer-activate>
                                                        <?php esc_html_e('Activate', 'traydigita-core'); ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <p class="installer-plugin-status" data-installer-status role="status"></p>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
                <div class="traydigita-admin-empty installer-no-results" data-installer-no-results hidden>
                    <span class="traydigita-admin-empty-icon material-icons" aria-hidden="true">search_off</span>
                    <p class="traydigita-admin-empty-title">
                        <?php echo esc_html(__('No plugins match your search or filter.', 'traydigita-core')); ?>
                    </p>
                    <p><?php echo esc_html(__('Try a different search term or filter.', 'traydigita-core')); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($hiddenActive > 0) : ?>
                <p class="installer-hidden-note">
                    <span class="material-icons" aria-hidden="true">visibility_off</span>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d is the number of active plugins hidden from the list. */
                            _n('%d plugin is already active and hidden from this list.', '%d plugins are already active and hidden from this list.', $hiddenActive, 'traydigita-core'),
                            $hiddenActive
                        )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <form class="traydigita-admin-card installer-settings-form" data-traydigita-module-form data-traydigita-module="installer" data-module="installer" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>
                <div class="traydigita-admin-card-head">
                    <span class="traydigita-admin-card-icon material-icons" aria-hidden="true">settings</span>
                    <div class="traydigita-admin-card-titles">
                        <h2 class="traydigita-admin-card-name"><?php esc_html_e('Display settings', 'traydigita-core'); ?></h2>
                    </div>
                </div>
                <p class="installer-field">
                    <label class="traydigita-ui-toggle" for="installer-hide-active"><input class="traydigita-ui-toggle-input" type="checkbox" id="installer-hide-active" name="hide_active" value="1" <?php checked($settings['hide_active']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Hide plugins that are already active', 'traydigita-core'); ?></span></label>
                    <span class="installer-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Only plugins that still need installing or activating are listed.', 'traydigita-core'),
                            __('The setting only changes this page — it never installs, activates, or hides a plugin itself.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>
                <?php
                SettingsControls::saveBar(
                    null,
                    false,
                    '<span class="traydigita-admin-card-note">'
                        . esc_html__('Only changes this page.', 'traydigita-core') . '</span>'
                );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save the display setting from a submitted form.
     *
     * The page has one setting: `hide_active` (`1` when checked;
     * an absent key means the checkbox was unchecked). It only
     * changes which rows this page lists. Unknown keys are
     * dropped. There are no secrets on this page.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change installer settings.', 'traydigita-core'),
            ];
        }
        $settings = [
            'hide_active' => !empty($data['hide_active']),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Plugin Suggestions settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's admin hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The module has no frontend hooks: it only
     * registers its three AJAX endpoints (install / activate /
     * status), which each verify their own nonce and capability.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        InstallerAjax::register();
    }

    /**
     * Curated plugins grouped for the settings page, in group order.
     *
     * Groups with no visible card are left out entirely - a group
     * whose plugins are all hidden by "hide active plugins" has
     * no heading to show. Inside a group the recommended plugin
     * sorts first, then the shared cache pick when it carries no
     * badge (an unidentified server still lists W3 Total Cache
     * first, as the safe general choice); the rest keep their
     * PluginList::all() order (the sort is stable).
     *
     * @param array<int, array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null}> $plugins Curated entries from PluginList::all().
     * @param array<string, string> $statuses Plugin slug => status from PluginList::statuses().
     * @param array{hide_active: bool} $settings Display settings from getSettings().
     * @return array<int, array{slug: string, label: string, plugins: array<int, array{slug: string, file: string, name: string, reason: string, category: string, recommended: bool, required: bool, installable: bool, duplicates: string|null}>}>
     */
    private static function visibleGroups(array $plugins, array $statuses, array $settings): array
    {
        $order = [
            PluginList::CATEGORY_REQUIRED => __('Required', 'traydigita-core'),
            PluginList::CATEGORY_CACHE => __('Cache', 'traydigita-core'),
            PluginList::CATEGORY_SEO => __('SEO', 'traydigita-core'),
            PluginList::CATEGORY_ANALYTICS => __('Analytics', 'traydigita-core'),
            PluginList::CATEGORY_SECURITY => __('Security', 'traydigita-core'),
            PluginList::CATEGORY_IMAGE => __('Image', 'traydigita-core'),
        ];
        $grouped = [];
        foreach ($plugins as $plugin) {
            $status = $statuses[$plugin['slug']] ?? PluginList::STATUS_NOT_INSTALLED;
            if ($settings['hide_active'] && $status === PluginList::STATUS_ACTIVE) {
                continue;
            }
            $grouped[$plugin['category']][] = $plugin;
        }
        $groups = [];
        $leadingCache = PluginList::cacheRecommendation()['slug'];
        foreach ($order as $slug => $label) {
            if (empty($grouped[$slug])) {
                continue;
            }
            $entries = $grouped[$slug];
            usort($entries, static function (array $a, array $b) use ($leadingCache): int {
                $byRecommended = (int) $b['recommended'] <=> (int) $a['recommended'];
                if ($byRecommended !== 0) {
                    return $byRecommended;
                }
                // Only a cache slug can ever match the leading
                // pick, so this is inert for every other group.
                if ($leadingCache !== null) {
                    if ($a['slug'] === $leadingCache) {
                        return -1;
                    }
                    if ($b['slug'] === $leadingCache) {
                        return 1;
                    }
                }
                return 0;
            });
            $groups[] = [
                'slug' => $slug,
                'label' => $label,
                'plugins' => $entries,
            ];
        }
        return $groups;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{hide_active: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'hide_active' => (bool) ($settings['hide_active'] ?? false),
        ];
    }

    /**
     * Default settings.
     *
     * All plugins are listed by default, including ones that are
     * already active.
     *
     * @return array{hide_active: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'hide_active' => false,
        ];
    }
}
