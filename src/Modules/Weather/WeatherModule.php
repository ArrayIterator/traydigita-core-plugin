<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Weather;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use function __;
use function array_key_exists;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function explode;
use function get_option;
use function get_theme_mod;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function min;
use function sanitize_text_field;
use function sprintf;
use function trim;
use function update_option;
use function wp_nonce_field;

/**
 * Weather module (Open-Meteo current weather).
 *
 * Weather used to be always on with no module and no toggle:
 * the widget, the top bar and the header rendered it
 * unconditionally from the Customizer's Weather section
 * (`weather_default_city`, `weather_default_latitude`,
 * `weather_default_longitude`, `weather_locations`). This
 * module is the single on/off switch and the single source
 * of truth for those settings. Default ON, preserving the
 * live site's behaviour - the user asked for the choice,
 * not for weather to be forced off.
 *
 * The module owns the settings (option `traydigita_weather`).
 * Values an existing site set in the Customizer keep working
 * through the theme-mod fallback in getSettings(): when the
 * module option has never stored a key, the theme mod of
 * the same name is used. Once the settings page is saved,
 * the keys exist in the module option and the theme mods
 * are never consulted again.
 *
 * The module registers no hooks of its own: the forecast
 * fetch lives in Core Widgets\WeatherHelper (Open-Meteo,
 * free, no API key) and the `traydigita_weather_current`
 * AJAX endpoint plus the widget / top-bar render paths
 * live in the theme, gated there on isEnabled('weather').
 * boot() therefore only guards the enabled flag. The
 * Integrations hub entry for Open-Meteo hides itself
 * while the module is disabled (see
 * IntegrationsModule::registerWeatherEntry()).
 */
final class WeatherModule implements ModuleInterface
{
    /**
     * Option name for all weather settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_weather';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_weather_save';

    /**
     * Default city name.
     *
     * Same default the Customizer shipped.
     */
    public const string DEFAULT_CITY = 'Padang';

    /**
     * Default city latitude.
     */
    public const float DEFAULT_LATITUDE = -0.9471;

    /**
     * Default city longitude.
     */
    public const float DEFAULT_LONGITUDE = 100.4172;

    /**
     * Default carousel locations, one per line as Name|latitude|longitude.
     *
     * Same five cities the Customizer shipped, Padang first.
     */
    public const string DEFAULT_LOCATIONS = "Padang|-0.9471|100.4172\nJakarta|-6.2088|106.8456\nSurabaya|-7.2575|112.7521\nSingapura|1.3521|103.8198\nPalembang|-2.9909|104.7566";

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
        return 'weather';
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
        return __('Weather', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Current weather from Open-Meteo (free, no API key) for the top bar and the weather widget. Disable to remove every trace of it.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'cloud';
    }

    /**
     * Module version.
     *
     * 1.0.0: created as its own module - weather used to be
     * always on with no toggle and its settings lived in the
     * Customizer.
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
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Current weather in the top bar and the weather widget, from Open-Meteo.', 'traydigita-core'),
            __('Location picker over a carousel of cities.', 'traydigita-core'),
            __('Disabling removes every trace.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Weather is a module of its own, with an enable/disable toggle.', 'traydigita-core'),
                    __('Default city, coordinates and carousel locations moved here from the Customizer.', 'traydigita-core'),
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
        return 'traydigita-weather';
    }

    /**
     * Whether the module can run (it has no hard dependencies).
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Weather needs no keys: Open-Meteo is free and keyless,
     * and a default location always exists, so the module is
     * always considered configured.
     *
     * @return bool
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
     * Whether the module is currently enabled.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * Each key falls back to the theme mod of the same name
     * when the module option has never stored it (see
     * settingWithThemeModFallback()), so values set in the
     * Customizer's Weather section keep working after that
     * section is removed. Once the settings page is saved,
     * the keys exist in the module option and the theme mods
     * are never consulted again.
     *
     * @return array{default_city: string, default_latitude: float, default_longitude: float, locations: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $city = self::settingWithThemeModFallback($stored, 'weather_default_city', self::DEFAULT_CITY);
        $latitude = self::coordinateWithThemeModFallback($stored, 'weather_default_latitude', self::DEFAULT_LATITUDE, -90.0, 90.0);
        $longitude = self::coordinateWithThemeModFallback($stored, 'weather_default_longitude', self::DEFAULT_LONGITUDE, -180.0, 180.0);
        $locations = self::settingWithThemeModFallback($stored, 'weather_locations', self::DEFAULT_LOCATIONS);
        return [
            'default_city' => $city,
            'default_latitude' => $latitude,
            'default_longitude' => $longitude,
            'locations' => $locations,
        ];
    }

    /**
     * Default settings.
     *
     * Same values the Customizer shipped, so a fresh install
     * shows the same weather as before.
     *
     * @return array{default_city: string, default_latitude: float, default_longitude: float, locations: string}
     */
    public static function getDefaults(): array
    {
        return [
            'default_city' => self::DEFAULT_CITY,
            'default_latitude' => self::DEFAULT_LATITUDE,
            'default_longitude' => self::DEFAULT_LONGITUDE,
            'locations' => self::DEFAULT_LOCATIONS,
        ];
    }

    /**
     * Default location (city + coordinates) for the widget and top bar.
     *
     * Widget/theme handover: render paths read this, not the
     * `weather_default_*` theme mods.
     *
     * @return array{name: string, latitude: float, longitude: float}
     */
    public static function getDefaultLocation(): array
    {
        $settings = self::getSettings();
        return [
            'name' => $settings['default_city'],
            'latitude' => $settings['default_latitude'],
            'longitude' => $settings['default_longitude'],
        ];
    }

    /**
     * Carousel locations for the weather widget.
     *
     * Widget/theme handover: the widget reads this, not the
     * `weather_locations` theme mod. Lines that do not parse
     * are skipped; when nothing parses, the built-in five
     * cities are returned.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float}>
     */
    public static function getDefaultLocations(): array
    {
        $locations = self::parseLocations(self::getSettings()['locations']);
        if ($locations !== []) {
            return $locations;
        }
        return self::parseLocations(self::DEFAULT_LOCATIONS);
    }

    /**
     * Resolve one text setting with its Customizer fallback.
     *
     * A stored non-empty value wins. When the module option
     * has never stored the key, the theme mod of the same
     * name wins; when there is no theme mod either, the
     * module default wins. When the key exists in the option
     * but is empty, the empty value wins - clearing a field
     * on the settings page really clears it instead of
     * resurrecting the old theme mod.
     *
     * @param array<string, mixed> $stored Raw stored option value.
     * @param string $key Theme-mod key (`weather_*`).
     * @param string $default Module default when neither source has a value.
     * @return string
     */
    private static function settingWithThemeModFallback(array $stored, string $key, string $default): string
    {
        if (isset($stored[$key]) && is_string($stored[$key]) && trim($stored[$key]) !== '') {
            return trim($stored[$key]);
        }
        if (!array_key_exists($key, $stored)) {
            $mod = get_theme_mod($key, '');
            if (is_string($mod) && trim($mod) !== '') {
                return trim($mod);
            }
            return $default;
        }
        return '';
    }

    /**
     * Resolve one coordinate setting with its Customizer fallback.
     *
     * Same fallback order as settingWithThemeModFallback(),
     * but the value is a float clamped to the given range
     * (non-numeric input becomes 0 before clamping, the
     * way the Customizer sanitizer did it).
     *
     * @param array<string, mixed> $stored Raw stored option value.
     * @param string $key Theme-mod key (`weather_*`).
     * @param float $default Module default when neither source has a value.
     * @param float $min Minimum allowed value.
     * @param float $max Maximum allowed value.
     * @return float
     */
    private static function coordinateWithThemeModFallback(array $stored, string $key, float $default, float $min, float $max): float
    {
        if (array_key_exists($key, $stored)) {
            return self::sanitizeCoordinate($stored[$key], $min, $max);
        }
        $mod = get_theme_mod($key, '');
        if (is_numeric($mod)) {
            return self::sanitizeCoordinate($mod, $min, $max);
        }
        return $default;
    }

    /**
     * Clamp a coordinate to its range.
     *
     * Non-numeric input becomes 0 before clamping, the way
     * the Customizer sanitizer did it.
     *
     * @param mixed $value Raw value.
     * @param float $min Minimum allowed value.
     * @param float $max Maximum allowed value.
     * @return float
     */
    private static function sanitizeCoordinate(mixed $value, float $min, float $max): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        return max($min, min($max, $number));
    }

    /**
     * Parse location lines (`Name|latitude|longitude`).
     *
     * Same rules the Customizer used: one location per line,
     * exactly three pipe-separated parts, a non-empty name,
     * numeric coordinates (clamped), first-seen name wins.
     *
     * @param string $value Raw textarea value.
     * @return array<int, array{name: string, latitude: float, longitude: float}>
     */
    private static function parseLocations(string $value): array
    {
        $locations = [];
        $names = [];
        foreach (explode("\n", $value) as $line) {
            $parts = explode('|', trim(sanitize_text_field($line)));
            if (count($parts) !== 3) {
                continue;
            }
            $name = trim($parts[0]);
            $latitude = $parts[1];
            $longitude = $parts[2];
            if ($name === '' || !is_numeric($latitude) || !is_numeric($longitude)) {
                continue;
            }
            if (in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
            $locations[] = [
                'name' => $name,
                'latitude' => self::sanitizeCoordinate($latitude, -90.0, 90.0),
                'longitude' => self::sanitizeCoordinate($longitude, -180.0, 180.0),
            ];
        }
        return $locations;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings weather-settings">
            <?php
            SettingsControls::intro(
                __('Current weather, free from Open-Meteo.', 'traydigita-core'),
                __('No API key, no account: the widget and the top bar show the current temperature and condition for the default city, with a location picker over the carousel cities below. Forecasts are cached for 30 minutes.', 'traydigita-core')
            );
            ?>

            <form class="weather-settings-form" data-traydigita-module-form data-traydigita-module="weather" data-module="weather" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php SettingsControls::sectionOpen('location', __('Default location', 'traydigita-core')); ?>
                <?php
                SettingsControls::input([
                    'id' => 'weather-default-city',
                    'name' => 'default_city',
                    'label' => __('Default city', 'traydigita-core'),
                    'value' => $settings['default_city'],
                    'help' => __('City shown by default in the weather widget and the top bar.', 'traydigita-core'),
                ]);
                SettingsControls::number([
                    'id' => 'weather-default-latitude',
                    'name' => 'default_latitude',
                    'label' => __('Default latitude', 'traydigita-core'),
                    'value' => (string) $settings['default_latitude'],
                    'help' => __('Latitude of the default weather location, between -90 and 90.', 'traydigita-core'),
                    'attrs' => ['min' => '-90', 'max' => '90', 'step' => '0.0001'],
                ]);
                SettingsControls::number([
                    'id' => 'weather-default-longitude',
                    'name' => 'default_longitude',
                    'label' => __('Default longitude', 'traydigita-core'),
                    'value' => (string) $settings['default_longitude'],
                    'help' => __('Longitude of the default weather location, between -180 and 180.', 'traydigita-core'),
                    'attrs' => ['min' => '-180', 'max' => '180', 'step' => '0.0001'],
                ]);
                SettingsControls::sectionClose();
                ?>

                <?php SettingsControls::sectionOpen('locations', __('Carousel locations', 'traydigita-core')); ?>
                <?php
                SettingsControls::textarea([
                    'id' => 'weather-locations',
                    'name' => 'locations',
                    'label' => __('Weather locations', 'traydigita-core'),
                    'value' => $settings['locations'],
                    'variant' => 'code',
                    'help' => __('Carousel locations for the weather widget, one per line as Name|latitude|longitude.', 'traydigita-core'),
                    'attrs' => ['rows' => '6'],
                ]);
                SettingsControls::sectionClose();
                ?>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Coordinates are clamped to their ranges; location
     * lines that do not parse are dropped, the rest are
     * rejoined in canonical `Name|latitude|longitude` form.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change weather settings.', 'traydigita-core'),
            ];
        }
        $previous = self::getSettings();
        $city = isset($data['default_city']) && is_string($data['default_city'])
            ? sanitize_text_field($data['default_city'])
            : $previous['default_city'];
        if ($city === '') {
            $city = self::DEFAULT_CITY;
        }
        $latitude = array_key_exists('default_latitude', $data)
            ? self::sanitizeCoordinate($data['default_latitude'], -90.0, 90.0)
            : $previous['default_latitude'];
        $longitude = array_key_exists('default_longitude', $data)
            ? self::sanitizeCoordinate($data['default_longitude'], -180.0, 180.0)
            : $previous['default_longitude'];
        $locations = isset($data['locations']) && is_string($data['locations'])
            ? $data['locations']
            : $previous['locations'];
        $lines = [];
        foreach (self::parseLocations($locations) as $location) {
            $lines[] = sprintf(
                '%s|%s|%s',
                $location['name'],
                (string) $location['latitude'],
                (string) $location['longitude']
            );
        }
        $settings = [
            'default_city' => $city,
            'default_latitude' => $latitude,
            'default_longitude' => $longitude,
            'locations' => implode("\n", $lines),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Weather settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module's hooks. Called only when the module is enabled.
     *
     * Weather registers no hooks of its own: the forecast fetch
     * lives in Widgets\WeatherHelper and the
     * `traydigita_weather_current` AJAX endpoint plus the
     * widget / top-bar render paths live in the theme, gated
     * there on isEnabled('weather'). When the module is
     * disabled this method never runs, so nothing weather
     * registers at all.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (!self::isEnabled()) {
            return;
        }
    }
}
