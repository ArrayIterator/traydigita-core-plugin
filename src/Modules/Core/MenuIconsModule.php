<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Core;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function admin_url;
use function array_merge;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function update_option;
use function wp_nonce_field;

/**
 * Menu Icons core module.
 *
 * The nav-menu icon picker (icons from the theme icon sets, with an
 * optional colour) is part of the theme itself, so this module is
 * core: always enabled, never deactivated. Icons are picked per
 * menu item on the Menus screen; this page holds the default
 * behaviour for every icon on the frontend - whether icons render
 * at all, and whether they sit before or after the label
 * (MenuIcons::prependIconToTitle() reads these settings on every
 * render). boot() creates the single shared MenuIcons instance
 * and wires its hooks, so the icon list AJAX endpoint, the
 * menu-item fields and the frontend rendering all run.
 */
final class MenuIconsModule implements ModuleInterface
{
    /**
     * The shared MenuIcons instance, created by boot().
     */
    private static ?MenuIcons $menuIcons = null;
    /**
     * Option name for the default icon behaviour (autoload `no`).
     */
    public const string OPTION = 'traydigita_menu_icons';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_menu_icons_save';

    /**
     * Allowed icon positions relative to the label.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['before', 'after'];
    /**
     * @inheritDoc
     */
    public static function id(): string
    {
        return 'menu-icons';
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
     * @inheritDoc
     */
    public static function name(): string
    {
        return __('Menu Icons', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public static function description(): string
    {
        return __(
            'Icons for navigation menu items. Icons are picked per menu item on the Menus screen.',
            'traydigita-core'
        );
    }

    /**
     * @inheritDoc
     */
    public static function icon(): string
    {
        return 'menu';
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
            __('Choose an icon for any navigation menu item on the Menus screen.', 'traydigita-core'),
            __('Icons render beside the menu labels on the frontend.', 'traydigita-core'),
            __('No extra settings page — everything happens on the Menus screen.', 'traydigita-core'),
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
        return 'traydigita-menu-icons';
    }

    /**
     * @inheritDoc
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function isCore(): bool
    {
        return true;
    }

    /**
     * Default icon behaviour: icons render, before the label.
     *
     * @return array{enabled: bool, position: string}
     */
    public static function getDefaults(): array
    {
        return [
            'enabled' => true,
            'position' => 'before',
        ];
    }

    /**
     * Read the default icon behaviour settings, merged over the
     * defaults. Safe to call on the frontend render path: a
     * missing or corrupt option falls back to the defaults, and
     * an unknown position falls back to 'before'.
     *
     * @return array{enabled: bool, position: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $position = isset($settings['position']) && is_string($settings['position']) ? $settings['position'] : '';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'before';
        }
        return [
            'enabled' => (bool) ($settings['enabled'] ?? true),
            'position' => $position,
        ];
    }

    /**
     * Render the module page: the default icon behaviour settings
     * plus a button linking to the Menus screen, where icons are
     * picked per menu item.
     *
     * Submitted through the generic module-settings AJAX
     * dispatcher (see `data-traydigita-module-form`).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings menu-icons-settings">
            <?php
            SettingsControls::intro(
                __('Menu icons are picked per menu item on the Menus screen. The settings below control the '
                    . 'default behaviour for every icon on the frontend.', 'traydigita-core'),
                __('Icons are stored per menu item and render inside the link label on every menu rendered by wp_nav_menu().', 'traydigita-core')
            );
            ?>

            <form class="menu-icons-settings-form" data-traydigita-module-form data-traydigita-module="menu-icons" data-module="menu-icons" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php
                SettingsControls::sectionOpen('default-behaviour', __('Default icon behaviour', 'traydigita-core'));
                SettingsControls::toggle([
                    'id' => 'menu-icons-enabled',
                    'name' => 'enabled',
                    'label' => __('Show icons in menus', 'traydigita-core'),
                    'checked' => $settings['enabled'],
                    'help' => __('When off, icons are still pickable on the Menus screen but are not rendered on the frontend.', 'traydigita-core'),
                ]);
                SettingsControls::select([
                    'id' => 'menu-icons-position',
                    'name' => 'position',
                    'label' => __('Icon position', 'traydigita-core'),
                    'value' => $settings['position'],
                    'options' => [
                        'before' => __('Before the label (default)', 'traydigita-core'),
                        'after' => __('After the label', 'traydigita-core'),
                    ],
                    'help' => __('Where the icon sits relative to the menu item label.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose();
                SettingsControls::saveBar(
                    null,
                    false,
                    '<a class="traydigita-admin-button" href="' . esc_url(admin_url('nav-menus.php')) . '">'
                        . esc_html__('Open Menus', 'traydigita-core') . '</a>'
                );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save the default icon behaviour.
     *
     * Expected flat keys in `$data`: `enabled` (`1` when checked;
     * the toggle prints a hidden `0` first, so an absent key is
     * treated as off) and `position` (allowlisted to `before` /
     * `after`). Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change menu icon settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position']) ? $data['position'] : '';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'before';
        }
        $settings = [
            'enabled' => !empty($data['enabled']),
            'position' => $position,
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Menu icon settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * The shared MenuIcons instance created by boot(), or null
     * before boot() has run. Core code that needs the icon list
     * or validation outside the hooked paths reads it here.
     *
     * @return MenuIcons|null
     */
    public static function menuIcons(): ?MenuIcons
    {
        return self::$menuIcons;
    }

    /**
     * Create the shared MenuIcons instance and wire its hooks
     * (menu-item fields, saving, the icon-list AJAX endpoint and
     * frontend rendering). Runs once; later calls are no-ops.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$menuIcons !== null) {
            return;
        }
        self::$menuIcons = new MenuIcons(Core::getInstance());
        self::$menuIcons->init();
    }
}
