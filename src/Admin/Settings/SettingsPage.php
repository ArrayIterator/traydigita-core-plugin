<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin\Settings;

use TrayDigita\WP\Plugin\Core\Admin\AdminMenu;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function apply_filters;
use function is_array;
use function is_string;
use function method_exists;
use function sanitize_key;

/**
 * Settings-page framework (Round 4, R4-5).
 *
 * The shell (templates/admin/shell.php) renders every module
 * settings page inside the same layout: a page card with the
 * breadcrumb, title and tab bar, the content in cards beside a
 * context rail, and a floating save bar. This class is the PHP
 * side of that contract: it tells the shell which navigation
 * items a module page has.
 *
 * Navigation sources, in priority order:
 *
 * 1. Shell tabs: a module that declares at least two tabs through
 *    the optional static `tabs()` method gets those tabs as
 *    underline tab buttons in the page card's tab bar. The buttons
 *    keep their `[data-traydigita-tab]` attributes, so the shell
 *    JavaScript (resources/js/admin.ts) switches the panels
 *    exactly as it did with the old top tab bar — only the
 *    placement changed.
 * 2. Declared sections: a module may declare an optional static
 *    `settingsSections()` method returning a list of
 *    `['id' => string, 'label' => string, 'icon' => string?]`
 *    entries. Each entry becomes an anchor link to the matching
 *    `td-section-<id>` card rendered by the module (see
 *    SettingsControls::sectionOpen()). Extensions may add or
 *    change sections through the
 *    `traydigita:core:admin:settings_sections` filter.
 * 3. Fallback: a module with neither tabs nor sections gets a
 *    single navigation item — its own name, linking to the top of
 *    the page — so the tab bar is never empty and the shell
 *    looks the same on every module page.
 *
 * Nothing here changes how settings are saved: forms, field
 * names, nonces and `saveSettings()` are untouched. This is a
 * presentation refactor only.
 */
final class SettingsPage
{
    /**
     * Navigation items for a module settings page.
     *
     * Each item is `['kind' => 'tab'|'anchor', 'id' => string,
     * 'label' => string, 'icon' => string]`; `icon` is a Dashicons
     * slug without the `dashicons-` prefix (empty when the module
     * declares none). Tab items use the tab id, anchor items the
     * section id (without the `td-section-` prefix).
     *
     * @param class-string<\TrayDigita\WP\Plugin\Core\Admin\ModuleInterface> $moduleClass Module class.
     * @return array<int, array{kind: string, id: string, label: string, icon: string}>
     */
    public static function navItems(string $moduleClass): array
    {
        $tabs = AdminMenu::moduleTabs($moduleClass);
        if (count($tabs) >= 2) {
            $items = [];
            foreach ($tabs as $tab) {
                $items[] = [
                    'kind' => 'tab',
                    'id' => $tab['id'],
                    'label' => $tab['label'],
                    'icon' => '',
                    'disabled' => !empty($tab['disabled']),
                ];
            }
            return $items;
        }
        $sections = self::settingsSections($moduleClass);
        if ($sections !== []) {
            $items = [];
            foreach ($sections as $section) {
                $items[] = [
                    'kind' => 'anchor',
                    'id' => $section['id'],
                    'label' => $section['label'],
                    'icon' => $section['icon'] ?? '',
                ];
            }
            return $items;
        }
        return [
            [
                'kind' => 'anchor',
                'id' => 'top',
                'label' => $moduleClass::name(),
                'icon' => $moduleClass::icon(),
            ],
        ];
    }

    /**
     * Declared settings sections of a module.
     *
     * A module declares sections through an optional static
     * `settingsSections()` method returning a list of
     * `['id' => string, 'label' => string]` pairs, with an
     * optional `'icon'` (Dashicons slug). The
     * `traydigita:core:admin:settings_sections` filter may add,
     * remove or reorder entries. Malformed entries are dropped.
     *
     * @param class-string<\TrayDigita\WP\Plugin\Core\Admin\ModuleInterface> $moduleClass Module class.
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(string $moduleClass): array
    {
        $declared = [];
        if (method_exists($moduleClass, 'settingsSections')) {
            $candidate = $moduleClass::settingsSections();
            if (is_array($candidate)) {
                $declared = $candidate;
            }
        }
        /** @var mixed $filtered */
        $filtered = apply_filters(
            Core::HOOK_PREFIX . ':admin:settings_sections',
            $declared,
            $moduleClass
        );
        $clean = [];
        if (!is_array($filtered)) {
            return [];
        }
        foreach ($filtered as $section) {
            if (!is_array($section)) {
                continue;
            }
            $id = isset($section['id']) && is_string($section['id'])
                ? sanitize_key($section['id'])
                : '';
            $label = isset($section['label']) && is_string($section['label'])
                ? $section['label']
                : '';
            if ($id === '' || $label === '') {
                continue;
            }
            $item = ['id' => $id, 'label' => $label];
            if (isset($section['icon']) && is_string($section['icon']) && $section['icon'] !== '') {
                $item['icon'] = sanitize_key($section['icon']);
            }
            $clean[] = $item;
        }
        return $clean;
    }

    /**
     * Label of the navigation landmark, for screen readers.
     *
     * @return string
     */
    public static function navLabel(): string
    {
        return __('Module settings', 'traydigita-core');
    }
}
