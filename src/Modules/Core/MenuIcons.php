<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Core;

use TrayDigita\WP\Plugin\Core\Stack\Libs\Icons\IcoMoon\Collections;
use TrayDigita\WP\Plugin\Core\Core;
use WP_Post;
use function __;
use function add_action;
use function add_filter;
use function admin_url;
use function apply_filters;
use function array_key_exists;
use function check_ajax_referer;
use function current_user_can;
use function delete_post_meta;
use function delete_transient;
use function did_action;
use function doing_action;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_url;
use function explode;
use function get_post_meta;
use function get_theme_file_uri;
use function get_transient;
use function is_array;
use function is_object;
use function is_string;
use function remove_filter;
use function sanitize_hex_color;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function str_contains;
use function strcmp;
use function update_post_meta;
use function usort;
use function wp_create_nonce;
use function wp_kses;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use const DAY_IN_SECONDS;

/**
 * Nav menu item icons.
 *
 * Every menu item (Appearance > Menus) gets a Pick / Remove icon
 * control in its settings panel. The picker lists all glyphs from
 * the three IcoMoon sets shipped with traydigita-stack (Entypo,
 * Octicons, Orbiters) with live search and a custom colour picker;
 * the default colour is empty, which means `currentColor` (the icon
 * inherits the link colour).
 *
 * Design decisions (see the phase 3 plan, section 4):
 *
 * - Icon list data source: an AJAX endpoint backed by the stack
 *   `Collections` API (`svgSpriteDataList()`), cached in a transient
 *   for one day. The bundled `assets/icons/*.json` files are the
 *   same data frozen at build time; the live endpoint is preferred
 *   because it is the only source the
 *   `traydigita:core:menu-icons` filter can change, so a child
 *   theme that filters the list sees its icons in the picker.
 *   The picker fetches the list lazily on first open and caches it
 *   in memory for the rest of the page.
 * - Picker glyph rendering: the three `assets/icons/*.svg` sprite
 *   files are fetched by the picker script on first open and
 *   injected into the page once, so every grid icon is a cheap
 *   `<svg><use href="#set-name"></use></svg>`. The sprites are not
 *   printed on page load, so nav-menus.php stays light until the
 *   picker is actually used.
 * - Frontend rendering: the `nav_menu_item_title` filter prepends
 *   the icon to the link label. It needs no custom walker (the
 *   reason the plan prefers `walker_nav_menu_start_el`), it filters
 *   exactly the label text instead of requiring string surgery on
 *   the built `<a>` tag, and it applies to every menu location
 *   (primary, footer, mobile) because all of them render through
 *   `wp_nav_menu()`. The icon is an inline `Glyph::toSvg()` (not a
 *   sprite `<use>`), so a menu rendered anywhere — including after
 *   `wp_footer` or inside the Customizer preview — always finds
 *   its icon, with no page-level sprite state to track.
 * - Storage: one menu-item meta entry (`traydigita_menu_icon`)
 *   holding `['set' => ..., 'name' => ..., 'color' => ...]`, where
 *   the colour is an empty string for `currentColor` or a `#rrggbb`
 *   hex colour. The icon name stored is the bare glyph name
 *   (`home`); the sprite id is derived as `{set}-{name}`.
 * - Colour: the glyphs use `fill="currentColor"`, so a picked
 *   colour is applied as an inline `style="color:#..."` on the
 *   `<svg>` (the only style property used, so it survives the
 *   `wp_kses` sanitization of the SVG). Size and spacing come from
 *   `width="1em" height="1em"` attributes plus a word space after
 *   the icon, so the icon renders correctly even where no theme
 *   stylesheet styles it; `.traydigita-menu-icon` is the hook for
 *   future frontend styling.
 * - Filter: `traydigita:core:menu-icons` receives the full list
 *   grouped by set (`['entypo' => ['label' => ..., 'icons' =>
 *   [['id' => 'entypo-home', 'name' => 'home'], ...]], ...]`).
 *   A child theme may remove, relabel or reorder icons, or add
 *   glyphs that exist in the stack `Collections` (saving validates
 *   against both the filtered list and the stack, so an icon the
 *   stack cannot render can never be stored). The same list is
 *   then passed through `traydigita:core:menu-icons/sets`, so a
 *   plugin can target the specific name; that filter runs after
 *   the legacy one and has the final word.
 * - Filter: `traydigita:core:menu-icons/render` receives the
 *   rendered item HTML (`$html, $icon, $item, $depth`) and may
 *   replace it — for example to move the icon after the label or
 *   wrap it in extra markup. It runs only when the icon actually
 *   renders (an item with no icon, or rendering disabled in the
 *   module settings, never reaches it).
 * - Settings: the Menu Icons module page holds the default
 *   behaviour (render icons on or off, icon before or after the
 *   label); see `MenuIconsModule::getSettings()`.
 *
 * Security: the AJAX endpoint requires the `traydigita-menu-icons`
 * nonce plus the `edit_theme_options` capability; saving runs
 * through core's menu update (already nonce + capability checked
 * by `nav-menus.php`) and re-checks the capability here. Set and
 * glyph names are allowlisted against the filtered list and the
 * stack `Collections`; the colour must be empty or a valid hex
 * colour; SVG output comes only from the stack `Glyph` and is
 * passed through `wp_kses` with a tight allowlist built from the
 * elements and attributes the shipped sprites actually use. No
 * raw SQL, no network call in any render path.
 *
 * Known limitation: the fields render in the Customizer's menu
 * item panel too (same walker), but saving there goes through the
 * Customizer's `customized` JSON rather than the nav-menus form
 * POST, so icon changes made in the Customizer are not persisted
 * — the save handler only touches meta when its fields are present
 * in `$_POST`, and never deletes meta it did not see. Icons are
 * edited on Appearance > Menus.
 */
final class MenuIcons
{
    /**
     * Menu item meta key holding the icon array.
     */
    public const string META_KEY = 'traydigita_menu_icon';

    /**
     * Nonce action for the icon list AJAX endpoint, created with
     * wp_create_nonce() and printed on every icon field.
     */
    public const string NONCE_ACTION = 'traydigita-menu-icons';

    /**
     * AJAX action returning the filtered icon list.
     */
    public const string AJAX_ACTION = 'traydigita_menu_icons';

    /**
     * Transient key caching the filtered icon list.
     */
    public const string TRANSIENT_KEY = 'traydigita_menu_icons';

    /**
     * Hook suffix for the icon list filter; the full name is
     * `traydigita:core:menu-icons` (hook prefix from Core).
     */
    public const string FILTER_HOOK = 'menu-icons';

    /**
     * Built-in icon sets: set id => picker label.
     *
     * @var array<string, string>
     */
    public const array SETS = [
        'entypo' => 'Entypo',
        'octicons' => 'Octicons',
        'orbiters' => 'Orbiters',
    ];

    /**
     * @param Core $core
     */
    public function __construct(public readonly Core $core)
    {
    }

    /**
     * Wire the menu item fields, saving, AJAX endpoint and frontend
     * rendering.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_nav_menu_item_custom_fields', [$this, 'renderFields'], 10, 4);
        add_action('wp_update_nav_menu_item', [$this, 'saveFields'], 10, 3);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxIconList']);
        add_filter('nav_menu_item_title', [$this, 'prependIconToTitle'], 10, 4);
        add_action('switch_theme', [self::class, 'flushIconListCache']);
        add_action('after_switch_theme', [self::class, 'flushIconListCache']);
        // Coexistence with the theme's identical MenuIcons
        // implementation: when both Core and the theme are active
        // they each register `prependIconToTitle` on
        // `nav_menu_item_title` and the icon is prepended twice.
        // Core takes over the frontend render, so once the theme
        // has booted (it wires its hooks on `after_setup_theme`
        // priority 10) any foreign callback with the same method
        // is removed, leaving exactly one icon per menu item.
        // Core boots on `after_setup_theme` priority 5, so the
        // removal cannot run here: the theme has not registered
        // its callback yet.
        if (did_action('after_setup_theme') && !doing_action('after_setup_theme')) {
            $this->removeDuplicateTitleFilters();
        } else {
            add_action('after_setup_theme', [$this, 'removeDuplicateTitleFilters'], 20);
        }
    }

    /**
     * Remove foreign `nav_menu_item_title` callbacks that prepend
     * a menu icon the same way Core does.
     *
     * Runs on `after_setup_theme` priority 20, after the theme
     * has booted. Only callbacks whose method is
     * `prependIconToTitle` and whose class is not this class are
     * removed; Core's own callback is never touched, so with any
     * other theme (or no icon implementation at all) nothing
     * changes. Removing the callback by its exact registered
     * callable (taken from `$wp_filter`) is what makes the
     * removal reliable: no class-name string matching is needed.
     *
     * @return void
     */
    public function removeDuplicateTitleFilters(): void
    {
        self::removeForeignHookCallbacks(
            'nav_menu_item_title',
            'prependIconToTitle',
            self::class
        );
    }

    /**
     * Remove every callback on a hook whose method matches the
     * given name but whose class is not the given class.
     *
     * The callbacks are collected from `$wp_filter` first and
     * removed afterwards, using each callback's exact registered
     * callable and priority, so `remove_filter()` always matches
     * the registration.
     *
     * @param string $hook Hook name.
     * @param string $method Method name identifying the duplicate.
     * @param string $ownClass Class whose callbacks must be kept.
     * @return void
     */
    private static function removeForeignHookCallbacks(
        string $hook,
        string $method,
        string $ownClass
    ): void {
        global $wp_filter;

        if (!isset($wp_filter[$hook])) {
            return;
        }

        $toRemove = [];

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;
                $class = null;

                if (is_array($function)
                    && isset($function[0], $function[1])
                    && $function[1] === $method
                ) {
                    $class = is_object($function[0])
                        ? $function[0]::class
                        : (is_string($function[0]) ? $function[0] : null);
                } elseif (is_string($function)
                    && str_contains($function, '::')
                ) {
                    [$className, $callbackMethod] = explode('::', $function, 2);

                    if ($callbackMethod === $method) {
                        $class = $className;
                    }
                }

                if ($class !== null && $class !== $ownClass) {
                    $toRemove[] = [$function, (int) $priority];
                }
            }
        }

        foreach ($toRemove as [$function, $priority]) {
            remove_filter($hook, $function, $priority);
        }
    }

    /**
     * Drop the cached icon list (theme switch invalidates any
     * child-theme filtering of the list).
     *
     * @return void
     */
    public static function flushIconListCache(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }

    /**
     * The icon list, grouped by set, filtered for child themes and
     * cached for one day (the sets do not change at runtime).
     *
     * Shape: `['entypo' => ['label' => 'Entypo', 'icons' =>
     * [['id' => 'entypo-home', 'name' => 'home'], ...]], ...]`.
     *
     * @return array<string, array{label: string, icons: array<int, array{id: string, name: string}>}>
     */
    public function getIconSets(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached)) {
            return self::normalizeIconSets($cached);
        }
        $collections = new Collections();
        $sets = [];
        foreach (self::SETS as $id => $label) {
            $component = $collections->get($id);
            if ($component === null) {
                continue;
            }
            $icons = [];
            foreach ($component->svgSpriteDataList() as $data) {
                if (!is_array($data)) {
                    continue;
                }
                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
                $glyphId = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
                if ($name === '' || $glyphId === '') {
                    continue;
                }
                $icons[] = ['id' => $glyphId, 'name' => $name];
            }
            usort($icons, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
            $sets[$id] = ['label' => $label, 'icons' => $icons];
        }
        $filtered = $this->core->hooks->apply(self::FILTER_HOOK, $sets);
        if (is_array($filtered)) {
            $sets = self::normalizeIconSets($filtered);
        }
        // The specific-name alias filter: the same grouped list,
        // applied after the legacy `traydigita:core:menu-icons`
        // filter, so a callback on this name has the final word.
        $filtered = apply_filters('traydigita:core:menu-icons/sets', $sets);
        if (is_array($filtered)) {
            $sets = self::normalizeIconSets($filtered);
        }
        set_transient(self::TRANSIENT_KEY, $sets, DAY_IN_SECONDS);
        return $sets;
    }

    /**
     * Normalize a (possibly filtered) icon list into the canonical
     * shape: set ids sanitized, labels kept only when they are
     * non-empty strings, icons kept only when both id and name are
     * non-empty strings, duplicates dropped, icons sorted by name,
     * empty sets dropped.
     *
     * @param mixed $sets Raw list, typically from the filter.
     * @return array<string, array{label: string, icons: array<int, array{id: string, name: string}>}>
     */
    private static function normalizeIconSets(mixed $sets): array
    {
        if (!is_array($sets)) {
            return [];
        }
        $clean = [];
        foreach ($sets as $setId => $set) {
            $setId = sanitize_key((string) $setId);
            if ($setId === '' || !is_array($set)) {
                continue;
            }
            $label = isset($set['label']) && is_string($set['label']) ? $set['label'] : '';
            if ($label === '') {
                continue;
            }
            $icons = [];
            $seen = [];
            if (isset($set['icons']) && is_array($set['icons'])) {
                foreach ($set['icons'] as $icon) {
                    if (!is_array($icon)) {
                        continue;
                    }
                    $id = isset($icon['id']) && is_string($icon['id']) ? $icon['id'] : '';
                    $name = isset($icon['name']) && is_string($icon['name']) ? $icon['name'] : '';
                    if ($id === '' || $name === '' || isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                    $icons[] = ['id' => $id, 'name' => $name];
                }
            }
            if ($icons === []) {
                continue;
            }
            usort($icons, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
            $clean[$setId] = ['label' => $label, 'icons' => $icons];
        }
        return $clean;
    }

    /**
     * Validate a set name, glyph name and colour triple.
     *
     * The set must exist in the (filtered) icon list, the glyph id
     * must be listed for that set, the glyph must exist in the
     * stack `Collections` (so it can actually render), and the
     * colour must be empty (currentColor) or a valid hex colour.
     *
     * @param string $set Icon set id.
     * @param string $name Bare glyph name, without the set prefix.
     * @param string $color Hex colour with leading `#`, or empty.
     * @return array{set: string, name: string, color: string}|null The cleaned triple, or null when invalid.
     */
    public function validateIcon(string $set, string $name, string $color): ?array
    {
        $set = sanitize_key($set);
        $name = sanitize_key($name);
        if ($set === '' || $name === '') {
            return null;
        }
        $sets = $this->getIconSets();
        if (!isset($sets[$set])) {
            return null;
        }
        $listed = false;
        foreach ($sets[$set]['icons'] as $icon) {
            if ($icon['id'] === $set . '-' . $name) {
                $listed = true;
                break;
            }
        }
        if (!$listed) {
            return null;
        }
        $collections = new Collections();
        $component = $collections->get($set);
        if ($component === null || !isset($component->{$name})) {
            return null;
        }
        $cleanColor = $color === '' ? '' : sanitize_hex_color($color);
        if (!is_string($cleanColor)) {
            return null;
        }
        return ['set' => $set, 'name' => $name, 'color' => $cleanColor];
    }

    /**
     * Read and re-validate a menu item's stored icon.
     *
     * @param int $itemId Menu item (post) id.
     * @return array{set: string, name: string, color: string}|null The stored icon, or null when none/invalid.
     */
    public function getMenuItemIcon(int $itemId): ?array
    {
        $meta = get_post_meta($itemId, self::META_KEY, true);
        if (!is_array($meta)) {
            return null;
        }
        $set = isset($meta['set']) && is_string($meta['set']) ? $meta['set'] : '';
        $name = isset($meta['name']) && is_string($meta['name']) ? $meta['name'] : '';
        $color = isset($meta['color']) && is_string($meta['color']) ? $meta['color'] : '';
        if ($set === '' || $name === '') {
            return null;
        }
        return $this->validateIcon($set, $name, $color);
    }

    /**
     * Render one icon as inline SVG from the stack `Glyph`.
     *
     * Unknown glyphs render nothing (never fatal, never raw output).
     * The SVG is passed through `wp_kses` with the allowlist below,
     * built from the elements and attributes the shipped sprite
     * files actually use. The only inline style is `color:` for a
     * picked colour; an empty colour renders no style attribute, so
     * the glyph inherits the link colour via `currentColor`.
     *
     * @param array{set: string, name: string, color: string} $icon Validated icon triple.
     *     Unvalidated input is tolerated too: a missing or
     *     non-string set or name renders nothing, and a missing or
     *     non-string colour falls back to the default colour.
     * @return string The sanitized inline SVG, or an empty string.
     */
    public function renderIconSvg(array $icon): string
    {
        // Guard the keys up front: callers pass validated triples,
        // but the docblock promises this never fatals, so a bare
        // [] or a non-string value must render nothing instead of
        // tripping an undefined-array-key warning.
        $set = isset($icon['set']) && is_string($icon['set']) ? $icon['set'] : '';
        $name = isset($icon['name']) && is_string($icon['name']) ? $icon['name'] : '';
        $color = isset($icon['color']) && is_string($icon['color']) ? $icon['color'] : '';
        if ($set === '' || $name === '') {
            return '';
        }
        $collections = new Collections();
        $component = $collections->get($set);
        if ($component === null) {
            return '';
        }
        if (!isset($component->{$name})) {
            return '';
        }
        $glyph = $component->{$name};
        $attributes = [
            'class' => 'traydigita-menu-icon',
            'aria-hidden' => 'true',
            'focusable' => 'false',
            'width' => '1em',
            'height' => '1em',
        ];
        if ($color !== '') {
            $attributes['style'] = 'color:' . $color;
        }
        return wp_kses($glyph->toSvg($attributes), self::svgAllowedHtml());
    }

    /**
     * `wp_kses` allowlist for stack-generated icon SVG.
     *
     * Covers every element and attribute found in the shipped
     * `assets/icons/*.svg` sprite files (symbols, paths, shapes,
     * gradients, masks, animation elements), plus the `svg` tag
     * attributes the theme itself sets (class, style, aria-hidden,
     * focusable, width, height). Attribute names are lowercase:
     * `wp_kses` matches them case-insensitively and the HTML parser
     * restores SVG casing (`viewbox` -> `viewBox`) on parse.
     *
     * @return array<string, array<string, bool>>
     */
    private static function svgAllowedHtml(): array
    {
        $shape = [
            'fill' => true,
            'fill-rule' => true,
            'fill-opacity' => true,
            'stroke' => true,
            'stroke-width' => true,
            'stroke-linecap' => true,
            'stroke-linejoin' => true,
            'stroke-dasharray' => true,
            'stroke-dashoffset' => true,
            'opacity' => true,
            'transform' => true,
            'transform-origin' => true,
            'mask' => true,
            'class' => true,
            'id' => true,
        ];
        return [
            'svg' => [
                'xmlns' => true,
                'viewbox' => true,
                'class' => true,
                'style' => true,
                'aria-hidden' => true,
                'focusable' => true,
                'width' => true,
                'height' => true,
                'role' => true,
            ],
            'defs' => [
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
            ],
            'symbol' => [
                'viewbox' => true,
                'id' => true,
                'fill' => true,
                'stroke' => true,
                'stroke-width' => true,
                'stroke-linecap' => true,
                'stroke-linejoin' => true,
                'stroke-dasharray' => true,
                'stroke-dashoffset' => true,
            ],
            'path' => [...$shape, 'd' => true, 'clip-rule' => true],
            'g' => $shape,
            'circle' => [...$shape, 'cx' => true, 'cy' => true, 'r' => true],
            'rect' => [...$shape, 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true],
            'ellipse' => [...$shape, 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true],
            'polygon' => [...$shape, 'points' => true],
            'polyline' => [...$shape, 'points' => true],
            'line' => [...$shape, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true],
            'lineargradient' => [
                'id' => true,
                'x1' => true,
                'x2' => true,
                'y1' => true,
                'y2' => true,
                'gradienttransform' => true,
                'xlink:href' => true,
            ],
            'radialgradient' => [
                'id' => true,
                'gradienttransform' => true,
            ],
            'stop' => [
                'offset' => true,
                'stop-color' => true,
                'stop-opacity' => true,
            ],
            'mask' => [
                'id' => true,
                'maskunits' => true,
                'color' => true,
                'fill' => true,
            ],
            'use' => [
                'href' => true,
                'xlink:href' => true,
                'transform' => true,
            ],
            'animate' => [
                'attributename' => true,
                'dur' => true,
                'id' => true,
                'repeatcount' => true,
                'values' => true,
                'begin' => true,
                'keytimes' => true,
                'type' => true,
                'calcmode' => true,
            ],
            'animatetransform' => [
                'attributename' => true,
                'dur' => true,
                'keytimes' => true,
                'repeatcount' => true,
                'type' => true,
                'values' => true,
                'calcmode' => true,
                'id' => true,
                'begin' => true,
            ],
            'animatemotion' => [
                'dur' => true,
                'path' => true,
                'repeatcount' => true,
            ],
        ];
    }

    /**
     * Render the Pick / Remove icon controls for one menu item.
     *
     * Fired by `wp_nav_menu_item_custom_fields` inside the item's
     * settings panel on Appearance > Menus. The AJAX URL, nonce,
     * sprite URLs and all user-facing strings travel as data
     * attributes on the field itself (the established theme pattern
     * for admin JS), so no localized script object is needed.
     * The preview is rendered server-side with the same
     * `renderIconSvg()` the frontend uses; the picker script
     * updates it live with a sprite `<use>` after a pick.
     *
     * @param int $itemId Menu item (post) id.
     * @param WP_Post $item Menu item object.
     * @param int $depth Menu depth.
     * @param mixed $args Walker arguments (unused).
     * @return void
     */
    public function renderFields(int $itemId, WP_Post $item, int $depth, mixed $args): void
    {
        $icon = $this->getMenuItemIcon($itemId);
        $set = $icon['set'] ?? '';
        $name = $icon['name'] ?? '';
        $color = $icon['color'] ?? '';
        $preview = $icon !== null ? $this->renderIconSvg($icon) : '';
        $displayName = $name !== '' ? $set . '-' . $name : __('No icon selected', 'traydigita-core');
        $pickLabel = $icon !== null
            ? esc_html__('Change icon', 'traydigita-core')
            : esc_html__('Pick icon', 'traydigita-core');
        echo '<div class="field-traydigita-menu-icon description description-wide traydigita-menu-icon-field"'
            . ' data-menu-item-id="' . esc_attr((string) $itemId) . '"'
            . ' data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
            . ' data-nonce="' . esc_attr(wp_create_nonce(self::NONCE_ACTION)) . '"'
            . ' data-sprite-entypo="' . esc_url(get_theme_file_uri('assets/icons/entypo.svg')) . '"'
            . ' data-sprite-octicons="' . esc_url(get_theme_file_uri('assets/icons/octicons.svg')) . '"'
            . ' data-sprite-orbiters="' . esc_url(get_theme_file_uri('assets/icons/orbiters.svg')) . '"'
            . ' data-msg-choose="' . esc_attr__('Choose an icon', 'traydigita-core') . '"'
            . ' data-msg-search="' . esc_attr__('Search icons…', 'traydigita-core') . '"'
            . ' data-msg-all="' . esc_attr__('All sets', 'traydigita-core') . '"'
            . ' data-msg-colour="' . esc_attr__('Icon colour', 'traydigita-core') . '"'
            . ' data-msg-default-colour="' . esc_attr__('Use default colour', 'traydigita-core') . '"'
            . ' data-msg-no-icon="' . esc_attr__('No icon selected', 'traydigita-core') . '"'
            . ' data-msg-close="' . esc_attr__('Close', 'traydigita-core') . '"'
            . ' data-msg-done="' . esc_attr__('Done', 'traydigita-core') . '"'
            . ' data-msg-loading="' . esc_attr__('Loading icons…', 'traydigita-core') . '"'
            . ' data-msg-load-error="' . esc_attr__('Could not load the icons. Please try again.', 'traydigita-core') . '"'
            . ' data-msg-no-results="' . esc_attr__('No icons match your search.', 'traydigita-core') . '"'
            . ' data-msg-remove="' . esc_attr__('Remove icon', 'traydigita-core') . '"'
            . ' data-msg-pick="' . esc_attr__('Pick icon', 'traydigita-core') . '"'
            . ' data-msg-change="' . esc_attr__('Change icon', 'traydigita-core') . '"'
            . ' data-msg-selected="' . esc_attr__('Selected icon', 'traydigita-core') . '">';
        echo '<p class="traydigita-menu-icon-field-title">' . esc_html__('Menu icon', 'traydigita-core') . '</p>';
        echo '<p class="traydigita-menu-icon-preview-row">';
        // The preview SVG is already sanitized by renderIconSvg()
        // (stack Glyph output through wp_kses), so it is echoed raw.
        echo '<span class="traydigita-menu-icon-preview">' . $preview . '</span>';
        echo '<span class="traydigita-menu-icon-selected-name">' . esc_html($displayName) . '</span>';
        echo '</p>';
        echo '<p class="traydigita-menu-icon-actions">';
        echo '<button type="button" class="button traydigita-menu-icon-pick">' . $pickLabel . '</button>';
        echo '<button type="button" class="button traydigita-menu-icon-remove">' . esc_html__('Remove icon', 'traydigita-core') . '</button>';
        echo '</p>';
        echo '<input type="hidden" name="menu-item-traydigita-icon-set[' . esc_attr((string) $itemId) . ']"'
            . ' class="traydigita-menu-icon-input-set" value="' . esc_attr($set) . '">';
        echo '<input type="hidden" name="menu-item-traydigita-icon-name[' . esc_attr((string) $itemId) . ']"'
            . ' class="traydigita-menu-icon-input-name" value="' . esc_attr($name) . '">';
        echo '<input type="hidden" name="menu-item-traydigita-icon-color[' . esc_attr((string) $itemId) . ']"'
            . ' class="traydigita-menu-icon-input-color" value="' . esc_attr($color) . '">';
        echo '</div>';
    }

    /**
     * Save one menu item's icon from the nav-menus form POST.
     *
     * Core already verified the `update-nav_menu` nonce and the
     * `edit_theme_options` capability for the form POST; the
     * capability is re-checked here as defense in depth. The
     * fields are only acted on when they are present in `$_POST`
     * for this item: a save that does not carry them (Customizer,
     * programmatic update) leaves the stored icon untouched, and
     * is never mistaken for a removal. Empty values delete the
     * icon; invalid values are refused and the stored icon is
     * removed rather than storing unvalidated data.
     *
     * @param int $menuId Menu (term) id.
     * @param int $menuItemDbId Menu item (post) id.
     * @param array<string, mixed> $args Menu item arguments.
     * @return void
     */
    public function saveFields(int $menuId, int $menuItemDbId, array $args): void
    {
        if (!current_user_can('edit_theme_options')) {
            return;
        }
        $sets = $_POST['menu-item-traydigita-icon-set'] ?? null;
        $names = $_POST['menu-item-traydigita-icon-name'] ?? null;
        $colors = $_POST['menu-item-traydigita-icon-color'] ?? null;
        if (!is_array($sets) || !is_array($names) || !is_array($colors)) {
            return;
        }
        if (!array_key_exists($menuItemDbId, $sets)
            && !array_key_exists($menuItemDbId, $names)
            && !array_key_exists($menuItemDbId, $colors)
        ) {
            return;
        }
        $set = isset($sets[$menuItemDbId]) && is_string($sets[$menuItemDbId])
            ? sanitize_text_field(wp_unslash($sets[$menuItemDbId]))
            : '';
        $name = isset($names[$menuItemDbId]) && is_string($names[$menuItemDbId])
            ? sanitize_text_field(wp_unslash($names[$menuItemDbId]))
            : '';
        $color = isset($colors[$menuItemDbId]) && is_string($colors[$menuItemDbId])
            ? sanitize_text_field(wp_unslash($colors[$menuItemDbId]))
            : '';
        if ($set === '' && $name === '' && $color === '') {
            delete_post_meta($menuItemDbId, self::META_KEY);
            return;
        }
        $clean = $this->validateIcon($set, $name, $color);
        if ($clean === null) {
            delete_post_meta($menuItemDbId, self::META_KEY);
            return;
        }
        update_post_meta($menuItemDbId, self::META_KEY, $clean);
    }

    /**
     * AJAX endpoint returning the filtered icon list.
     *
     * POST: `_ajax_nonce` (the `traydigita-menu-icons` nonce).
     * Requires the `edit_theme_options` capability. The list is
     * cached in a transient for one day by getIconSets().
     *
     * @return void
     */
    public function ajaxIconList(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('edit_theme_options')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        wp_send_json_success(['sets' => $this->getIconSets()]);
    }

    /**
     * Prepend the menu item's icon to its link label.
     *
     * Runs on `nav_menu_item_title`, so the icon lands inside the
     * `<a>` before the label on every menu location rendered by
     * `wp_nav_menu()` (primary, footer, mobile) with no custom
     * walker. A word space separates the icon from the label; the
     * icon itself is `aria-hidden` and sized `1em` by its own
     * attributes. Items without an icon (or with an icon that no
     * longer resolves) are returned untouched, as are all items
     * when rendering is disabled in the Menu Icons module
     * settings. The position setting (`before`/`after`) decides
     * which side of the label the icon sits on, and the finished
     * HTML passes through the
     * `traydigita:core:menu-icons/render` filter
     * (`$html, $icon, $item, $depth`) for per-item overrides.
     *
     * @param string $title Menu item title.
     * @param WP_Post $item Menu item object.
     * @param mixed $args Walker arguments (unused).
     * @param int $depth Menu depth.
     * @return string The title, with the icon prepended when set.
     */
    public function prependIconToTitle(string $title, WP_Post $item, mixed $args, int $depth): string
    {
        // Idempotency guard: another icon renderer (for example
        // the theme's identical implementation, if its callback
        // was registered after Core's duplicate removal ran) may
        // already have prepended an icon. The icon SVG always
        // carries the `traydigita-menu-icon` class, so a title
        // that already contains it is returned untouched and the
        // item never shows the icon twice.
        if (str_contains($title, 'traydigita-menu-icon')) {
            return $title;
        }
        $icon = $this->getMenuItemIcon((int) $item->ID);
        if ($icon === null) {
            return $title;
        }
        $settings = MenuIconsModule::getSettings();
        if (!$settings['enabled']) {
            return $title;
        }
        $svg = $this->renderIconSvg($icon);
        if ($svg === '') {
            return $title;
        }
        $html = $settings['position'] === 'after'
            ? $title . ' ' . $svg
            : $svg . ' ' . $title;
        /** @var string $html */
        $html = (string) apply_filters('traydigita:core:menu-icons/render', $html, $icon, $item, $depth);
        return $html;
    }
}
