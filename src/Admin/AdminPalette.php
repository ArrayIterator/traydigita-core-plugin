<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use function __;
use function get_theme_mod;
use function in_array;
use function is_string;
use function sprintf;

/**
 * Admin colour palettes.
 *
 * The admin UI is neutral by design: its base colours are slate /
 * zinc, and green is only one opt-in palette choice, never the admin
 * identity. The site owner picks a palette in the Customizer
 * ("Admin Palette" section: setting `traydigita_admin_palette`),
 * and may override the primary / accent colours inside the same
 * section (`traydigita_admin_primary_color`,
 * `traydigita_admin_accent_color`). The frontend brand green
 * (`brand_primary_color`) is untouched by all of this.
 *
 * Mechanism: AdminMenu prints the resolved palette as CSS variables
 * on every TrayDigita admin page (admin_head, scoped to the shell
 * root `.traydigita-admin-shell`), the same approach the Dashboard
 * Designer module uses for its `--traydigita-admin-accent`
 * variable. The admin CSS only ever reads the variables, so no
 * palette colour is hardcoded in the stylesheets.
 *
 * Variables:
 * - --traydigita-admin-accent: palette primary, used for text
 *   accents, tab underlines and focus outlines.
 * - --traydigita-admin-accent-strong: a darker step of the same
 *   hue, used as the fill of primary buttons. Every palette's
 *   strong step is dark enough for white text at WCAG AA (4.5:1)
 *   by construction — buttons never pair a light text with a
 *   mid-tone fill, which is what made the old "Open" button text
 *   unreadable (blue link text on a green button).
 * - --traydigita-admin-accent-soft: a pale tint of the primary,
 *   used for icon tiles and success backgrounds.
 * - --traydigita-admin-focus: focus-ring colour (the accent).
 *
 * Resolution order for each colour: an explicitly stored Customizer
 * colour wins; otherwise the chosen palette's colour is used; an
 * unknown palette id falls back to Elegant Blue. The default palette
 * is Elegant Blue (refined blue on a neutral base), never green.
 */
final class AdminPalette
{
    /**
     * Default palette id: elegant blue.
     */
    public const string DEFAULT_PALETTE = 'elegant-blue';

    /**
     * Palette definitions: id => [label, primary, strong, soft].
     *
     * The label is English source text for the Customizer choices;
     * the colours are the palette's primary (600-ish), strong
     * (700/800-ish, white-text safe) and soft (50-ish tint) steps.
     * Elegant Blue is the default: refined blue (#2563eb family)
     * on a neutral base, white cards, soft borders. Koperasi Green
     * stays available as an opt-in choice only, never the default.
     *
     * @var array<string, array{label: string, primary: string, strong: string, soft: string}>
     */
    public const array PALETTES = [
        'elegant-blue' => [
            'label' => 'Elegant Blue',
            'primary' => '#2563eb',
            'strong' => '#1e40af',
            'soft' => '#eff6ff',
        ],
        'slate' => [
            'label' => 'Slate (neutral)',
            'primary' => '#334155',
            'strong' => '#1e293b',
            'soft' => '#f1f5f9',
        ],
        'koperasi-green' => [
            'label' => 'Koperasi Green',
            'primary' => '#166534',
            'strong' => '#14532d',
            'soft' => '#edf7ed',
        ],
        'ocean' => [
            'label' => 'Ocean',
            'primary' => '#155e75',
            'strong' => '#164e63',
            'soft' => '#ecfeff',
        ],
        'plum' => [
            'label' => 'Plum',
            'primary' => '#6b21a8',
            'strong' => '#581c87',
            'soft' => '#faf5ff',
        ],
        'amber' => [
            'label' => 'Amber',
            'primary' => '#92400e',
            'strong' => '#78350f',
            'soft' => '#fffbeb',
        ],
        'rose' => [
            'label' => 'Rose',
            'primary' => '#9f1239',
            'strong' => '#881337',
            'soft' => '#fff1f2',
        ],
    ];

    /**
     * Palette ids offered in the Customizer, in display order.
     *
     * Labels are translated: the Customizer select shows them in the
     * site language (the palette ids themselves stay English).
     *
     * @return array<string, string> Palette id => label.
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::PALETTES as $id => $palette) {
            $choices[$id] = __($palette['label'], 'traydigita-core');
        }
        return $choices;
    }

    /**
     * Resolve the active palette id, falling back to Elegant Blue.
     *
     * @return string
     */
    public static function activePaletteId(): string
    {
        $stored = get_option('traydigita_core_admin_palette', null);
        if (!is_array($stored)) {
            $stored = get_theme_mod('traydigita_admin_palette', self::DEFAULT_PALETTE);
        }
        if (!is_string($stored) || !in_array($stored, array_keys(self::PALETTES), true)) {
            return self::DEFAULT_PALETTE;
        }
        return $stored;
    }

    /**
     * Resolve the palette colours for the current Customizer values.
     *
     * An explicitly stored primary/accent colour overrides the
     * palette colour; when nothing is stored the palette's own
     * colours are used. The soft tint always comes from the palette
     * (there is no Customizer control for it).
     *
     * @return array{palette: string, primary: string, strong: string, accent: string, soft: string}
     */
    public static function resolve(): array
    {
        $paletteId = self::activePaletteId();
        $palette = self::PALETTES[$paletteId];
        $primaryOverride = (string) get_option('traydigita_core_admin_primary_color', get_theme_mod('traydigita_admin_primary_color', ''));
        $accentOverride = (string) get_option('traydigita_core_admin_accent_color', get_theme_mod('traydigita_admin_accent_color', ''));
        $primary = is_string($primaryOverride) && $primaryOverride !== ''
            ? $primaryOverride
            : $palette['primary'];
        $accent = is_string($accentOverride) && $accentOverride !== ''
            ? $accentOverride
            : $palette['primary'];
        return [
            'palette' => $paletteId,
            'primary' => $primary,
            'strong' => $palette['strong'],
            'accent' => $accent,
            'soft' => $palette['soft'],
        ];
    }

    /**
     * Inline <style> block printing the palette as CSS variables.
     *
     * Scoped to `.traydigita-admin-shell` so the variables never
     * leak into other admin screens. Printed by AdminMenu on
     * TrayDigita admin pages only.
     *
     * @return string
     */
    public static function inlineStyle(): string
    {
        $resolved = self::resolve();
        return sprintf(
            '<style id="traydigita-admin-palette">' . "\n"
            . '.traydigita-admin-shell{--traydigita-color-primary:%1$s;'
            . '--traydigita-color-primary-dark:%2$s;'
            . '--traydigita-admin-accent:%3$s;'
            . '--traydigita-admin-accent-strong:%2$s;'
            . '--traydigita-admin-accent-soft:%4$s;'
            . '--traydigita-admin-success:%1$s;'
            . '--traydigita-admin-success-bg:%4$s;'
            . '--traydigita-admin-focus:%3$s;}'
            . "\n" . '</style>',
            $resolved['primary'],
            $resolved['strong'],
            $resolved['accent'],
            $resolved['soft']
        );
    }
}
