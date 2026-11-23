<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Accessibility;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function add_action;
use function checked;
use function current_user_can;
use function esc_attr__;
use function esc_html__;
use function esc_html_e;
use function get_option;
use function is_admin;
use function is_feed;
use function update_option;
use function wp_nonce_field;

/**
 * Accessibility Toolbar module.
 *
 * A floating accessibility button on every frontend page, opening
 * a small panel with three reader controls: text size, high
 * contrast, and line spacing. Choices are stored per visitor in
 * localStorage (`traydigita_a11y`) and applied by the frontend
 * JavaScript (`resources/js/modules/accessibility-toolbar.ts`,
 * `initAccessibilityToolbar()`) as `data-td-a11y-*` attributes
 * on the `<html>` element, which the toolbar stylesheet
 * (`resources/css/components/accessibility-toolbar.css`) reads —
 * so a returning visitor gets their own settings on every page
 * without a single server round-trip, cookie, or account.
 *
 * The panel is keyboard accessible: the toggle button carries
 * `aria-expanded` and `aria-controls`, Escape closes the panel
 * and returns focus to the toggle, and every control is a real
 * button or radio with a visible focus ring. The toolbar is a
 * pure progressive enhancement: with JavaScript disabled the
 * button never renders (it is printed with the `hidden`
 * attribute and revealed by the script), so nothing dead is
 * left on the page.
 *
 * Settings live in the single option `traydigita_accessibility`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type. The settings
 * choose which controls the panel offers; the visitor's own
 * choices are never stored on the server.
 */
final class AccessibilityToolbarModule implements ModuleInterface
{
    /**
     * Option name for all accessibility-toolbar settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_accessibility';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_accessibility_save';

    /**
     * Whether boot() already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'accessibility-toolbar';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Accessibility Toolbar', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A floating accessibility button with text size, high contrast and line spacing controls, remembered per visitor in their own browser — no account, no cookies, no server storage.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'universal-access';
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
            __('Floating button on every page.', 'traydigita-core'),
            __('Text size, high contrast, and line spacing controls.', 'traydigita-core'),
            __('Each visitor’s choices are remembered in their own browser.', 'traydigita-core'),
            __('No account, no cookies, no server storage.', 'traydigita-core'),
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
    public static function settingsPageSlug(): string
    {
        return 'traydigita-accessibility-toolbar';
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
     * No setup is needed: the defaults offer all three controls.
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
     * One form: a toggle per control offered in the panel (text
     * size, high contrast, line spacing). The form is submitted by
     * the generic admin JavaScript via the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings accessibility-settings">
            <?php
            SettingsControls::intro(
                __('A floating accessibility button on every page opens a panel with text size, '
                    . 'high contrast and line spacing controls.', 'traydigita-core'),
                __('Each visitor\'s choices are remembered in their own browser only — nothing is stored on the server. Choose which controls the panel offers below.', 'traydigita-core')
            );
            ?>

            <form class="accessibility-settings-form" data-traydigita-module-form data-traydigita-module="accessibility-toolbar" data-module="accessibility-toolbar" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="accessibility-field">
                    <label class="traydigita-ui-toggle" for="accessibility-show-font-size"><input class="traydigita-ui-toggle-input" type="checkbox" id="accessibility-show-font-size" name="show_font_size" value="1" <?php checked($settings['show_font_size']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Offer text size', 'traydigita-core'); ?></span></label>
                    <span class="accessibility-field-help">
                        <?php esc_html_e('Smaller, default, larger and largest text. Applied as a font-size step on the whole page.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="accessibility-field">
                    <label class="traydigita-ui-toggle" for="accessibility-show-contrast"><input class="traydigita-ui-toggle-input" type="checkbox" id="accessibility-show-contrast" name="show_contrast" value="1" <?php checked($settings['show_contrast']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Offer high contrast', 'traydigita-core'); ?></span></label>
                    <span class="accessibility-field-help">
                        <?php esc_html_e('A high-contrast palette: near-black text on white in light mode, near-white text on black in dark mode.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="accessibility-field">
                    <label class="traydigita-ui-toggle" for="accessibility-show-line-height"><input class="traydigita-ui-toggle-input" type="checkbox" id="accessibility-show-line-height" name="show_line_height" value="1" <?php checked($settings['show_line_height']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Offer line spacing', 'traydigita-core'); ?></span></label>
                    <span class="accessibility-field-help">
                        <?php esc_html_e('Comfortable or relaxed line spacing for article text, for readers who find tight lines hard to follow.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="accessibility-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys: `show_font_size`, `show_contrast` and
     * `show_line_height` (checkboxes: `1` when checked, absent
     * when unchecked). Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Accessibility Toolbar settings.', 'traydigita-core'),
            ];
        }
        update_option(self::OPTION, [
            'show_font_size' => !empty($data['show_font_size']),
            'show_contrast' => !empty($data['show_contrast']),
            'show_line_height' => !empty($data['show_line_height']),
        ], false);
        return [
            'success' => true,
            'message' => __('Accessibility Toolbar settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Hooks the toolbar render into `wp_footer` (priority 20).
     * A disabled module registers nothing.
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
        add_action('wp_footer', [self::class, 'renderToolbar'], 20);
    }

    /**
     * Print the toolbar from `wp_footer`.
     *
     * Only on the frontend: never in admin screens or feeds. The
     * markup comes from render(), so a coordinator that prefers
     * manual placement can call render() instead — but never
     * both, or two toolbars render.
     *
     * @return void
     */
    public static function renderToolbar(): void
    {
        if (is_admin() || is_feed()) {
            return;
        }
        $html = self::render();
        if ($html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns fully escaped markup.
        }
    }

    /**
     * Render the toolbar markup.
     *
     * Returns an empty string when every control is switched off
     * in the settings (there would be nothing to offer). The
     * wrapper is hidden by default and revealed by the JavaScript
     * only, so without JavaScript the page shows no dead button.
     * All user-facing strings are translated server-side and read
     * by the script from data attributes where it needs them.
     *
     * @return string Toolbar markup, or '' when nothing is offered.
     */
    public static function render(): string
    {
        $settings = self::getSettings();
        if (!$settings['show_font_size'] && !$settings['show_contrast'] && !$settings['show_line_height']) {
            return '';
        }
        $html = '<div class="td-a11y" data-td-a11y hidden>'
            . '<button type="button" class="td-a11y-toggle" data-td-a11y-toggle'
            . ' aria-expanded="false" aria-controls="td-a11y-panel"'
            . ' aria-label="' . esc_attr__('Accessibility options', 'traydigita-core') . '">'
            . '<span aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="2"/><path d="M4 9h16"/><path d="M12 9v5"/><path d="M12 14l-3 7"/><path d="M12 14l3 7"/></svg>'
            . '</span>'
            . '</button>'
            . '<div class="td-a11y-panel" id="td-a11y-panel" data-td-a11y-panel role="dialog"'
            . ' aria-label="' . esc_attr__('Accessibility options', 'traydigita-core') . '" hidden>'
            . '<p class="td-a11y-title">' . esc_html__('Accessibility', 'traydigita-core') . '</p>';
        if ($settings['show_font_size']) {
            $html .= '<fieldset class="td-a11y-group" data-td-a11y-group="font-size">'
                . '<legend>' . esc_html__('Text size', 'traydigita-core') . '</legend>'
                . '<div class="td-a11y-options" role="group" aria-label="' . esc_attr__('Text size', 'traydigita-core') . '">'
                . '<button type="button" data-td-a11y-font="small" aria-pressed="false">A−</button>'
                . '<button type="button" data-td-a11y-font="default" aria-pressed="true">A</button>'
                . '<button type="button" data-td-a11y-font="large" aria-pressed="false">A+</button>'
                . '<button type="button" data-td-a11y-font="largest" aria-pressed="false">A++</button>'
                . '</div></fieldset>';
        }
        if ($settings['show_contrast']) {
            $html .= '<fieldset class="td-a11y-group" data-td-a11y-group="contrast">'
                . '<legend>' . esc_html__('Contrast', 'traydigita-core') . '</legend>'
                . '<div class="td-a11y-options" role="group" aria-label="' . esc_attr__('Contrast', 'traydigita-core') . '">'
                . '<button type="button" data-td-a11y-contrast="default" aria-pressed="true">' . esc_html__('Default', 'traydigita-core') . '</button>'
                . '<button type="button" data-td-a11y-contrast="high" aria-pressed="false">' . esc_html__('High contrast', 'traydigita-core') . '</button>'
                . '</div></fieldset>';
        }
        if ($settings['show_line_height']) {
            $html .= '<fieldset class="td-a11y-group" data-td-a11y-group="line-height">'
                . '<legend>' . esc_html__('Line spacing', 'traydigita-core') . '</legend>'
                . '<div class="td-a11y-options" role="group" aria-label="' . esc_attr__('Line spacing', 'traydigita-core') . '">'
                . '<button type="button" data-td-a11y-leading="default" aria-pressed="true">' . esc_html__('Default', 'traydigita-core') . '</button>'
                . '<button type="button" data-td-a11y-leading="comfortable" aria-pressed="false">' . esc_html__('Comfortable', 'traydigita-core') . '</button>'
                . '<button type="button" data-td-a11y-leading="relaxed" aria-pressed="false">' . esc_html__('Relaxed', 'traydigita-core') . '</button>'
                . '</div></fieldset>';
        }
        $html .= '<button type="button" class="td-a11y-reset" data-td-a11y-reset>'
            . esc_html__('Reset accessibility settings', 'traydigita-core') . '</button>'
            . '</div></div>';
        return $html;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_font_size: bool, show_contrast: bool, show_line_height: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'show_font_size' => (bool) ($settings['show_font_size'] ?? false),
            'show_contrast' => (bool) ($settings['show_contrast'] ?? false),
            'show_line_height' => (bool) ($settings['show_line_height'] ?? false),
        ];
    }

    /**
     * Default settings.
     *
     * All three controls offered.
     *
     * @return array{show_font_size: bool, show_contrast: bool, show_line_height: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'show_font_size' => true,
            'show_contrast' => true,
            'show_line_height' => true,
        ];
    }
}
