<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\SiteKit;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Integrations\IntegrationsModule;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function add_filter;
use function admin_url;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;

/**
 * Google Site Kit compatibility module.
 *
 * This is a status card, not a re-implementation: Site Kit itself
 * owns Analytics, Search Console, AdSense and PageSpeed Insights.
 * The module only reports, read-only, whether the plugin is
 * installed, active and connected, and which of its modules are
 * active (see SiteKitDetector). It never calls Site Kit's internal
 * APIs, never reads or stores its tokens, never modifies its
 * settings, and never installs or activates it.
 *
 * No-duplicate-tracking guard: the theme ships NO analytics or tag
 * code of its own at all — no `gtag`, `googletagmanager` or
 * `google-analytics` script is output anywhere in the theme, and
 * nothing in the theme strips Site Kit's verification meta tags
 * (verified by code search, see the coordinator's report). When
 * Site Kit is active, boot() additionally forces the
 * `traydigita:core:analytics_allowed` filter to false, so any
 * future theme feature that wants to output analytics must check
 * that filter (or SiteKitModule::isSiteKitActive()) first and stay
 * silent while Site Kit owns tracking.
 *
 * The phase-1 Popular Posts widget already reads Site Kit data
 * through the traydigita-stack collector
 * (`Stack::getPopularPosts()`) when Site Kit is connected; the
 * status card states that, and states the local fallback while
 * Site Kit is not connected.
 *
 * The module has no settings of its own: saveSettings() is a
 * no-op success, and the page is a status card plus short
 * explanations. There are no form fields and no toggles beyond
 * the module grid's enable/disable.
 */
final class SiteKitModule implements ModuleInterface
{
    /**
     * Filter any future theme analytics output must respect.
     *
     * Default (no callback registered): allowed. While Site Kit is
     * active, boot() registers a callback returning false, so
     * `apply_filters(self::ANALYTICS_ALLOWED_FILTER, true)` is
     * false and theme analytics code must output nothing. The
     * filter name follows Core::HOOK_PREFIX, like the module
     * registry filter.
     */
    public const string ANALYTICS_ALLOWED_FILTER = Core::HOOK_PREFIX . ':analytics_allowed';

    /**
     * Site Kit dashboard page slug, used for the "open Site Kit"
     * link when the plugin is active.
     */
    public const string PLUGIN_DASHBOARD_PAGE = 'googlesitekit-dashboard';

    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'site-kit';
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
        return 'seo-discovery';
    }

    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return __('Site Kit', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public static function description(): string
    {
        return __(
            'Google Site Kit compatibility: read-only status of the Site Kit plugin and a guard against duplicate analytics tracking.',
            'traydigita-core'
        );
    }

    /**
     * @inheritDoc
     */
    public static function icon(): string
    {
        return 'chart-area';
    }

    /**
     * @inheritDoc
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
            __('Read-only status of the Google Site Kit plugin.', 'traydigita-core'),
            __('Guard against duplicate analytics tracking.', 'traydigita-core'),
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
     * @inheritDoc
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
        return 'traydigita-site-kit';
    }

    /**
     * Whether the module can run.
     *
     * Always true: the module only reports status and guards
     * against duplicate tracking, so it works with or without
     * Site Kit installed. The plugin's absence is a status to
     * show, not a missing dependency.
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
     * Always true: there is nothing to set up. The module needs
     * no credentials, no options and no Site Kit state of its own
     * to report status and enforce the guard.
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
     * Always false: the module is optional and can be disabled
     * from the grid. Disabling it only hides the status card and
     * the guard; Site Kit itself keeps working either way.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the Site Kit plugin is active.
     *
     * Public helper for any future theme feature that wants to
     * output analytics or verification-related markup: check this
     * (or the `traydigita:core:analytics_allowed` filter) first
     * and stay silent while Site Kit owns tracking.
     *
     * @return bool
     */
    public static function isSiteKitActive(): bool
    {
        return SiteKitDetector::isPluginActive();
    }

    /**
     * Register the Site Kit entry on the Integrations hub.
     *
     * Registered centrally by IntegrationsModule::registerEntries()
     * (not from boot(), so the entry appears even while this
     * module is disabled). Status comes live from
     * SiteKitDetector::getStatus(): 'active' when Site Kit is
     * connected, 'configured' when the plugin is active but setup
     * is incomplete or installed but not active, 'not-configured'
     * when it is not installed; 'module-disabled' with a link to
     * the Modules page when this module is disabled. The link goes
     * to the Site Kit dashboard when the plugin is active, to the
     * Plugins screen when it is installed but inactive, and to
     * Plugin Suggestions otherwise.
     *
     * @param array<int, array<string, mixed>> $entries Entries so far.
     * @return array<int, array<string, mixed>> Entries with the Site Kit entry appended.
     */
    public static function registerIntegrationEntry(array $entries): array
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            $entries[] = [
                'id' => 'site-kit',
                'name' => 'Google Site Kit',
                'description' => __(
                    'The Site Kit module is disabled — enable it in Modules to connect Google services.',
                    'traydigita-core'
                ),
                'status' => 'module-disabled',
                'url' => IntegrationsModule::modulesUrl(),
                'module' => self::id(),
                'icon' => 'analytics',
            ];
            return $entries;
        }
        $status = SiteKitDetector::getStatus();
        if ($status['connected']) {
            $entryStatus = 'active';
            $description = __(
                'Analytics, Search Console, AdSense and PageSpeed Insights are owned by Site Kit.',
                'traydigita-core'
            );
            $url = admin_url('admin.php?page=' . self::PLUGIN_DASHBOARD_PAGE);
        } elseif ($status['active']) {
            $entryStatus = 'configured';
            $description = __(
                'Site Kit is active, but setup is not complete yet — finish setup to connect Google services.',
                'traydigita-core'
            );
            $url = admin_url('admin.php?page=' . self::PLUGIN_DASHBOARD_PAGE);
        } elseif ($status['installed']) {
            $entryStatus = 'configured';
            $description = __(
                'Site Kit is installed but not active — activate it to connect Analytics and Search Console.',
                'traydigita-core'
            );
            $url = admin_url('plugins.php');
        } else {
            $entryStatus = 'not-configured';
            $description = __(
                'Site Kit is not installed — install it to connect Analytics, Search Console and more.',
                'traydigita-core'
            );
            $url = admin_url('admin.php?page=traydigita-installer');
        }
        $entries[] = [
            'id' => 'site-kit',
            'name' => 'Google Site Kit',
            'description' => $description,
            'status' => $entryStatus,
            'url' => $url,
            'module' => self::id(),
            'icon' => 'analytics',
        ];
        return $entries;
    }

    /**
     * Render the Site Kit status card inside the shell content area.
     *
     * Four states, all read-only: connected, active but setup
     * incomplete, installed but inactive, not installed. Every
     * state shows the no-duplicate-tracking guard explanation and
     * the Popular Posts line; the connected state additionally
     * lists which Site Kit modules are active.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $status = SiteKitDetector::getStatus();
        $modules = $status['modules'];
        if ($status['connected']) {
            ?>
            <div class="traydigita-admin-notice traydigita-admin-notice-success">
                <p>
                    <?php esc_html_e(
                        'Google Site Kit is active and connected. Analytics, Search Console and the other Site Kit modules below are owned by Site Kit.',
                        'traydigita-core'
                    ); ?>
                </p>
                <ul>
                    <?php foreach (SiteKitDetector::TRACKED_MODULES as $slug => $label) : ?>
                        <li>
                            <?php echo esc_html($label); ?>
                            <?php if (isset($modules[$slug])) : ?>
                                <span class="traydigita-admin-badge traydigita-admin-badge-success"><?php esc_html_e('Active', 'traydigita-core'); ?></span>
                            <?php else : ?>
                                <span class="traydigita-admin-badge traydigita-admin-badge-muted"><?php esc_html_e('Inactive', 'traydigita-core'); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p>
                    <?php esc_html_e(
                        'Popular Posts reads Analytics data through the traydigita-stack collector (Stack::getPopularPosts()) while Site Kit is connected.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <a class="traydigita-admin-button traydigita-admin-button-primary"
                        href="<?php echo esc_url(admin_url('admin.php?page=' . self::PLUGIN_DASHBOARD_PAGE)); ?>">
                        <?php esc_html_e('Open Site Kit', 'traydigita-core'); ?>
                    </a>
                </p>
            </div>
            <?php
        } elseif ($status['active']) {
            ?>
            <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                <p>
                    <?php esc_html_e(
                        'Google Site Kit is active, but its setup is not complete yet. Finish the setup in Site Kit to connect Analytics, Search Console and the other modules.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <?php esc_html_e(
                        'Popular Posts falls back to local analytics and the latest posts while Site Kit is not connected.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <a class="traydigita-admin-button traydigita-admin-button-primary"
                        href="<?php echo esc_url(admin_url('admin.php?page=' . self::PLUGIN_DASHBOARD_PAGE)); ?>">
                        <?php esc_html_e('Open Site Kit', 'traydigita-core'); ?>
                    </a>
                </p>
            </div>
            <?php
        } elseif ($status['installed']) {
            ?>
            <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                <p>
                    <?php esc_html_e(
                        'Google Site Kit is installed but not active. Activate it on the Plugins screen to let it own Analytics, Search Console and the other modules.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <?php esc_html_e(
                        'Popular Posts falls back to local analytics and the latest posts while Site Kit is not connected.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <a class="traydigita-admin-button traydigita-admin-button-primary"
                        href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                        <?php esc_html_e('Open Plugins', 'traydigita-core'); ?>
                    </a>
                </p>
            </div>
            <?php
        } else {
            ?>
            <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                <p>
                    <?php esc_html_e(
                        'Google Site Kit is not installed. Install it from the WordPress plugin directory to let it own Analytics, Search Console and the other modules.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <?php esc_html_e(
                        'Popular Posts falls back to local analytics and the latest posts while Site Kit is not connected.',
                        'traydigita-core'
                    ); ?>
                </p>
                <p>
                    <a class="traydigita-admin-button traydigita-admin-button-primary"
                        href="<?php echo esc_url(admin_url('admin.php?page=plugin-install.php&tab=plugin-information&plugin=google-site-kit')); ?>">
                        <?php esc_html_e('View Site Kit in the plugin installer', 'traydigita-core'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
        ?>
        <div class="traydigita-admin-notice">
            <p>
                <?php
                SettingsControls::helpText(
                    __('No duplicate tracking: while Site Kit is active, the theme injects no analytics or tag code of its '
                        . 'own, and it never removes Site Kit’s verification meta tags.', 'traydigita-core'),
                    __('The theme ships no analytics code at all; any future theme feature that adds analytics must check the traydigita:core:analytics_allowed filter (or SiteKitModule::isSiteKitActive()) first.', 'traydigita-core')
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Site Kit has no theme-side settings to save.
     *
     * The module is a read-only status card plus a guard. There is
     * nothing to store, so saving is a no-op that reports success
     * instead of failing the shell's save flow.
     *
     * @param array<string, mixed> $data Sanitized form data (ignored).
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        return [
            'success' => true,
            'message' => esc_html__(
                'Site Kit has no theme settings to save. This page is read-only status.',
                'traydigita-core'
            ),
        ];
    }

    /**
     * Register the no-duplicate-tracking guard.
     *
     * Called only when the module is enabled. When Site Kit is
     * active, the `traydigita:core:analytics_allowed` filter is
     * forced to false, so any future theme code that applies that
     * filter before outputting analytics stays silent. When Site
     * Kit is not active, no callback is registered and the filter
     * keeps its default (allowed). No output, no settings, no
     * hooks that touch Site Kit itself.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::isSiteKitActive()) {
            add_filter(self::ANALYTICS_ALLOWED_FILTER, '__return_false');
        }
    }
}
