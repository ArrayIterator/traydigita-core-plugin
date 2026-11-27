<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DashboardDesigner;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function add_action;
use function current_user_can;
use function esc_attr;
use function esc_html_e;
use function file_exists;
use function get_option;
use function in_array;
use function is_string;
use function sanitize_hex_color;
use function selected;
use function update_option;
use function wp_add_inline_style;
use function wp_enqueue_style;
use function wp_nonce_field;

/**
 * Dashboard Designer module.
 *
 * Cosmetic-only styles for the WordPress admin: brand presets and
 * an optional custom accent colour. The hard rule of this module
 * is CSS ONLY:
 *
 * - Exactly one hook is registered, `admin_enqueue_scripts`, which
 *   enqueues one plain stylesheet (see enqueueAdminStyle()). No
 *   JavaScript, no menu changes, no capability changes, no filters
 *   on admin behaviour, and nothing is ever hidden - the preset
 *   stylesheets contain no `display: none`, `visibility: hidden`
 *   or any other hiding rule.
 * - Scope comes from the hook itself: `admin_enqueue_scripts`
 *   fires only inside wp-admin. It never fires on wp-login.php
 *   (that screen uses `login_enqueue_scripts`, owned by the Login
 *   Builder module) and never on the frontend (`wp_enqueue_scripts`),
 *   so the login page and the public site are untouched by design.
 * - The stylesheets restyle colours, backgrounds, borders and
 *   border-radius only. They never change layout in a way that
 *   moves or removes a functional control.
 *
 * Presets live as plain CSS files under
 * `resources/css/modules/dashboard-designer-<preset>.css` and are
 * enqueued directly by URL, so no Vite build step is needed. One
 * packaging caveat, for the release coordinator: `release.config.ts`
 * currently ignores `resources/**`, so these files do NOT ship in
 * the release zip until that ignore list gains an exception for
 * them. enqueueAdminStyle() guards with file_exists() and skips
 * silently when the file is absent, so a release install without
 * the files simply shows the WordPress default admin instead of a
 * broken stylesheet.
 *
 * The optional custom accent colour is applied as the CSS custom
 * property `--traydigita-admin-accent` through
 * wp_add_inline_style(), on top of the preset. Every accent rule
 * in the preset stylesheets reads
 * `var(--traydigita-admin-accent, <preset accent>)`, so the custom
 * colour recolours links, primary buttons and focus rings; the
 * admin bar and menu keep the preset's own colours.
 *
 * Settings live in the single option
 * `traydigita_dashboard_designer` (autoload `no`) and are saved
 * through the phase-2 admin AJAX dispatcher, which verifies the
 * nonce and the `manage_options` capability; saveSettings() still
 * checks the capability itself and re-sanitizes every field for
 * its own type.
 */
final class DashboardDesignerModule implements ModuleInterface
{
    /**
     * Option name for all dashboard designer settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_dashboard_designer';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_dashboard_designer_save';

    /**
     * Style handle for the preset stylesheet.
     */
    public const string STYLE_HANDLE = 'traydigita-dashboard-designer';

    /**
     * CSS custom property carrying the optional custom accent colour.
     */
    public const string ACCENT_VAR = '--traydigita-admin-accent';

    /**
     * Allowed preset ids. `default` is the WordPress default admin:
     * no stylesheet is enqueued at all.
     *
     * @var array<int, string>
     */
    public const array PRESETS = ['default', 'light', 'dark'];

    /**
     * Default accent colour of the Light preset (elegant blue).
     */
    public const string DEFAULT_ACCENT = '#1d4ed8';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'dashboard-designer';
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
        return 'design';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Dashboard Designer', 'traydigita-core');
    }

    /**
     * Module description. States the CSS-only guarantee in plain
     * language, because that guarantee is the whole point.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Cosmetic-only styles for the WordPress admin: brand presets and an accent colour. CSS only — it never changes menus, capabilities, behaviour, or the login page.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-appearance';
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
            __('Brand presets and an accent colour for wp-admin.', 'traydigita-core'),
            __('CSS only — never changes menus, capabilities, behaviour, or the login page.', 'traydigita-core'),
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
        return 'traydigita-dashboard-designer';
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
     * Configured means a styling preset is chosen: the `default`
     * preset enqueues nothing, so the module does nothing until an
     * admin picks Light or Dark.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::getSettings()['preset'] !== 'default';
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * A preset select, an optional custom accent colour, and the
     * CSS-only guarantee stated up front. The form is submitted
     * through the generic module-settings AJAX dispatcher (see
     * `data-traydigita-module-form`).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings dashboard-designer-settings">
            <?php
            SettingsControls::intro(
                __('This module only changes how wp-admin looks.', 'traydigita-core'),
                __('It adds one stylesheet on admin screens: no JavaScript, no menu changes, no capability changes, and nothing is hidden. The login page is never touched.', 'traydigita-core')
            );
            ?>

            <form class="dashboard-designer-settings-form" data-traydigita-module-form data-traydigita-module="dashboard-designer" data-module="dashboard-designer" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="dashboard-designer-field">
                    <label for="dashboard-designer-preset"><?php esc_html_e('Preset', 'traydigita-core'); ?></label>
                    <select id="dashboard-designer-preset" name="preset">
                        <option value="default" <?php selected($settings['preset'], 'default'); ?>><?php esc_html_e('WordPress default (no styling)', 'traydigita-core'); ?></option>
                        <option value="light" <?php selected($settings['preset'], 'light'); ?>><?php esc_html_e('TrayDigita Light', 'traydigita-core'); ?></option>
                        <option value="dark" <?php selected($settings['preset'], 'dark'); ?>><?php esc_html_e('TrayDigita Dark', 'traydigita-core'); ?></option>
                    </select>
                    <span class="dashboard-designer-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('WordPress default loads no stylesheet at all.', 'traydigita-core'),
                            __('Light restyles the admin bar, menu, links and buttons in elegant blue on a light chrome. Dark uses a dark admin bar and menu with blue accents; the content area keeps the light WordPress background so core screens stay readable.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="dashboard-designer-field">
                    <label for="dashboard-designer-accent"><?php esc_html_e('Custom accent colour', 'traydigita-core'); ?></label>
                    <input type="text" id="dashboard-designer-accent" name="accent" value="<?php echo esc_attr($settings['accent']); ?>" maxlength="7" placeholder="#1d4ed8">
                    <span class="dashboard-designer-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Optional hex colour, for example #1d4ed8.', 'traydigita-core'),
                            __('It recolours links, primary buttons and focus rings on top of the preset. The menu and admin bar keep the preset colours. Empty means the preset accent.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `preset` (allowlisted) and
     * `accent` (hex colour, empty allowed). Every value is
     * re-sanitized for its own type; unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change dashboard designer settings.', 'traydigita-core'),
            ];
        }
        $preset = isset($data['preset']) && is_string($data['preset']) ? $data['preset'] : '';
        if (!in_array($preset, self::PRESETS, true)) {
            $preset = 'default';
        }
        $accent = isset($data['accent']) && is_string($data['accent'])
            ? sanitize_hex_color($data['accent'])
            : '';
        if (!is_string($accent)) {
            $accent = '';
        }
        $settings = [
            'preset' => $preset,
            'accent' => $accent,
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Dashboard Designer settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's only hook. Called only when the module
     * is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Exactly one hook is registered - see the class
     * docblock for why the CSS-only rule allows nothing else.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminStyle']);
    }

    /**
     * Enqueue the preset stylesheet on admin screens.
     *
     * Runs on `admin_enqueue_scripts`, which WordPress fires only
     * inside wp-admin - never on wp-login.php and never on the
     * frontend - so the stylesheet cannot leak anywhere else. The
     * `default` preset enqueues nothing, and a missing preset file
     * (for example a release install that excluded `resources/`)
     * is skipped silently instead of producing a broken link.
     *
     * @return void
     */
    public static function enqueueAdminStyle(): void
    {
        $settings = self::getSettings();
        if ($settings['preset'] === 'default') {
            return;
        }
        $file = self::presetFile($settings['preset']);
        if (!file_exists($file['path'])) {
            return;
        }
        wp_enqueue_style(self::STYLE_HANDLE, $file['url'], [], self::version());
        if ($settings['accent'] !== '') {
            // The accent passed sanitize_hex_color() in
            // getSettings(), so it is a `#rgb` / `#rrggbb` literal
            // and safe to print inside the declaration.
            wp_add_inline_style(
                self::STYLE_HANDLE,
                ':root{' . self::ACCENT_VAR . ':' . $settings['accent'] . ';}'
            );
        }
    }

    /**
     * Filesystem path and URL of a preset stylesheet.
     *
     * The preset is allowlisted by getSettings() before this is
     * called, so the file name can never escape the preset
     * directory.
     *
     * @param string $preset Preset id, one of self::PRESETS without `default`.
     * @return array{path: string, url: string}
     */
    public static function presetFile(string $preset): array
    {
        $file = 'dashboard-designer-' . $preset . '.css';
        return [
            'path' => \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/assets/css/modules/' . $file,
            'url' => \TrayDigita\WP\Plugin\Core\Core::pluginUrl() . '/assets/css/modules/' . $file,
        ];
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{preset: string, accent: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $preset = isset($settings['preset']) && is_string($settings['preset']) ? $settings['preset'] : '';
        if (!in_array($preset, self::PRESETS, true)) {
            $preset = 'default';
        }
        $accent = isset($settings['accent']) && is_string($settings['accent'])
            ? sanitize_hex_color($settings['accent'])
            : '';
        if (!is_string($accent)) {
            $accent = '';
        }
        return [
            'preset' => $preset,
            'accent' => $accent,
        ];
    }

    /**
     * Default settings: no styling until an admin picks a preset.
     *
     * @return array{preset: string, accent: string}
     */
    public static function getDefaults(): array
    {
        return [
            'preset' => 'default',
            'accent' => '',
        ];
    }
}
