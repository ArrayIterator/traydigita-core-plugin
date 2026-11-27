<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Customizer;

use function array_key_exists;
use function get_option;
use function get_theme_mod;
use function is_array;
use function is_string;

/**
 * Read-only settings shim for values the theme used to own.
 *
 * A few modules read theme Customizer settings (announcement
 * text, Photon toggle, thumbnail sizes). Core has no Customizer
 * of its own in v1.0.0, so this shim reads the same values the
 * theme stored: a Core option override first
 * (`traydigita_core_settings`), then the active theme's theme
 * mod - so existing values keep working while the theme is
 * active, and Core-owned values win once the theme stops
 * providing them. Only the keys modules actually read are
 * known here; anything else returns the given default.
 */
final class Settings
{
    /**
     * Option holding Core-owned overrides of theme settings.
     */
    public const string OPTION = 'traydigita_core_settings';

    /**
     * Known setting ids with their defaults.
     *
     * @var array<string, mixed>
     */
    private const array DEFAULTS = [
        'photon_enabled' => false,
        'announcement_text' => '',
    ];

    /**
     * Read one setting.
     *
     * @param string $id Setting id.
     * @param mixed $default Fallback when the setting is unknown.
     * @return mixed Setting value, or the default.
     */
    public static function get(string $id, mixed $default = null): mixed
    {
        if (!array_key_exists($id, self::DEFAULTS) && $default === null) {
            return null;
        }
        $fallback = array_key_exists($id, self::DEFAULTS) ? self::DEFAULTS[$id] : $default;
        $stored = get_option(self::OPTION, []);
        if (is_array($stored) && array_key_exists($id, $stored)) {
            return $stored[$id];
        }
        return get_theme_mod($id, $fallback);
    }

    /**
     * Read one setting as a boolean checkbox value.
     *
     * @param mixed $value Raw value.
     * @return bool True for truthy checkbox values.
     */
    public static function sanitizeCheckbox(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && $value !== 'false';
        }
        return (bool) $value;
    }
}
