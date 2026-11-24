<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Pwa;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Push\PushModule;
use function __;
use function admin_url;
use function esc_html_e;
use function esc_url;

/**
 * PWA module (installable web app only).
 *
 * The push half that was folded into this module in 2.0.0 is
 * a module of its own again ('push', see PushModule): the
 * settings, the token table, the send code, the AJAX/REST
 * endpoints, the subscribe shortcode, and the shared service
 * worker all live there now. This module keeps only the PWA
 * half: the phase-1 behaviour behind the enabled flag —
 * manifest link + theme-color meta (gated in functions.php),
 * install sheet + "Install App" buttons (gated in the header /
 * footer / mobile sidebar templates), `initPwa()` in app.ts.
 *
 * The module id stays 'pwa', so the stored enabled-modules
 * option needs no migration for this module itself. Sites
 * that had 'pwa' enabled keep it enabled; the one-time
 * migration that enables the split-out 'push' module for
 * those sites lives in PushModule::migrateEnabledFromPwa()
 * and runs from boot() below.
 *
 * This module has no settings of its own: the settings page
 * is a read-only summary of what the PWA does (and what
 * disabling removes), with a link to the Push module for
 * notifications. It registers no hooks — the PWA frontend
 * behaviour lives in the theme — so boot() only runs the
 * push-enabled migration.
 */
final class PwaModule implements ModuleInterface
{
    /**
     * Whether boot() already ran.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'pwa';
    }

    /**
     * Default category slug for the module list.
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
        return __('PWA', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Let visitors install the site as an app (web manifest, theme colour, store-style install sheet). Push notifications live in the Push module. Disable to remove every trace of it.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * Kept as 'smartphone': the grid maps Dashicons slugs to
     * Material ligatures (see admin-ui.ts), and 'smartphone'
     * maps cleanly.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'smartphone';
    }

    /**
     * Module version.
     *
     * 3.0.0: the push half was split out into its own Push
     * module (was folded in, in 2.0.0); this module is
     * PWA-only again.
     *
     * @return string
     */
    public static function version(): string
    {
        return '3.0.0';
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
            __('Installable web app: manifest, theme colour, store-style install sheet.', 'traydigita-core'),
            __('Store-style install sheet (Play Store variant, App Store variant on macOS and iOS).', 'traydigita-core'),
            __('Disabling removes every trace.', 'traydigita-core'),
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
                    __('Installable web app with manifest and service worker.', 'traydigita-core'),
                ],
            ],
            [
                'version' => '2.0.0',
                'changes' => [
                    __('Push notifications merged in: manual Firebase Cloud '
                        . 'Messaging sends to subscribers.', 'traydigita-core'),
                    __('One service worker now serves both install and push.', 'traydigita-core'),
                ],
            ],
            [
                'version' => '3.0.0',
                'changes' => [
                    __('Push notifications split out into their own Push module; this module is PWA-only again.', 'traydigita-core'),
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
        return 'traydigita-pwa';
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
     * The PWA needs no setup: there are no keys, no
     * credentials, and no settings to fill in.
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
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Whether the PWA module is currently enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Whether a valid push config is saved.
     *
     * Backwards-compatible alias for PushModule::isConfigured():
     * SetupPage and SiteHealth still call this method, and the
     * push config now lives in the Push module.
     *
     * @return bool
     */
    public static function isPushConfigured(): bool
    {
        return PushModule::isConfigured();
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Read-only: the PWA has no settings to save, so the page
     * is a visible info page in three sections — what the PWA
     * does, what disabling removes, and where push
     * notifications live — with nothing hidden behind a Help
     * toggle.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $enabled = self::isEnabled();
        ?>
        <div class="traydigita-module-settings pwa-settings">
            <?php if (!$enabled) : ?>
                <div class="traydigita-admin-notice traydigita-admin-notice-warning">
                    <p>
                        <?php esc_html_e('PWA is disabled. The site is not installable: no manifest link, theme-color meta tag, install sheet, or "Install App" buttons are served. Enable the module in the module grid first.', 'traydigita-core'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="traydigita-ui-section td-settings-section">
                <h2 class="traydigita-ui-section-title"><?php esc_html_e('What the PWA does', 'traydigita-core'); ?></h2>
                <p>
                    <?php esc_html_e('Visitors can install the site as an app.', 'traydigita-core'); ?>
                </p>
                <ul class="pwa-status-list">
                    <li><?php esc_html_e('Links the web manifest (manifest.webmanifest) from every page head, so browsers know the app name, theme colour, icons, start URL, and scope.', 'traydigita-core'); ?></li>
                    <li><?php esc_html_e('Prints a theme-color meta tag in the page head, using the brand primary colour from the Customizer.', 'traydigita-core'); ?></li>
                    <li><?php esc_html_e('Shows a store-style install sheet (Play Store variant, App Store variant on macOS and iOS) with an Install button and install instructions.', 'traydigita-core'); ?></li>
                </ul>
            </div>

            <div class="traydigita-ui-section td-settings-section">
                <h2 class="traydigita-ui-section-title"><?php esc_html_e('What disabling removes', 'traydigita-core'); ?></h2>
                <p>
                    <?php esc_html_e('Disabling this module in the module grid removes the manifest link, the theme-color meta tag, the install sheet, and the "Install App" buttons entirely.', 'traydigita-core'); ?>
                </p>
            </div>

            <div class="traydigita-ui-section td-settings-section">
                <h2 class="traydigita-ui-section-title"><?php esc_html_e('Push notifications', 'traydigita-core'); ?></h2>
                <p>
                    <?php esc_html_e('Push notifications live in the Push module.', 'traydigita-core'); ?>
                    <a class="traydigita-admin-button traydigita-admin-button-secondary"
                        href="<?php echo esc_url(admin_url('admin.php?page=' . PushModule::settingsPageSlug())); ?>">
                        <?php esc_html_e('Open Push Notifications', 'traydigita-core'); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Save the module settings from a submitted form.
     *
     * The PWA has no settings to save; like SiteInfoModule,
     * this is a no-op success, as the interface requires
     * saveSettings().
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        return [
            'success' => true,
            'message' => __('PWA has no settings to save.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's hooks. Called only when the module is enabled.
     *
     * The PWA registers no hooks of its own: the manifest link,
     * theme-color meta, install sheet, and "Install App"
     * buttons live in the theme and are gated on this module
     * being enabled. The only work here is the one-time
     * migration that enables the split-out Push module for
     * sites that had this module enabled (see
     * PushModule::migrateEnabledFromPwa()).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        PushModule::migrateEnabledFromPwa();
    }
}
