<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Widgets;

use TrayDigita\WP\Plugin\Core\Modules\Weather\WeatherModule;
use function __;
use function date_i18n;
use function get_transient;
use function is_array;
use function is_numeric;
use function is_wp_error;
use function json_decode;
use function md5;
use function rawurlencode;
use function round;
use function set_transient;
use function time;
use function trim;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_retrieve_body;

/**
 * Open-Meteo helpers for the weather widget and its AJAX endpoints.
 *
 * Provider: Open-Meteo (free, no API key). Two hosts are allowlisted
 * and every request has an 8 second timeout. All fetches go through
 * this class; widget render paths only read the transient cache.
 *
 * Default city: Padang (-0.9471, 100.4172), read from the Weather
 * module (WeatherModule::getDefaultLocation()), which itself falls
 * back to the `weather_default_*` theme mods for values set in
 * the Customizer before the module existed.
 */
final class WeatherHelper
{
    /**
     * Geocoding API host (allowlisted).
     */
    public const string GEOCODING_HOST = 'geocoding-api.open-meteo.com';

    /**
     * Forecast API host (allowlisted).
     */
    public const string FORECAST_HOST = 'api.open-meteo.com';

    /**
     * Request timeout in seconds.
     */
    public const int TIMEOUT = 8;

    /**
     * Transient TTL in seconds (30 minutes).
     */
    public const int CACHE_TTL = 1800;

    /**
     * Default city name.
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
     * WMO weather code => English condition label.
     *
     * @var array<int, string>
     */
    public const array CODE_LABELS = [
        0 => 'Clear',
        1 => 'Mostly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45 => 'Fog',
        48 => 'Fog',
        51 => 'Drizzle',
        53 => 'Drizzle',
        55 => 'Drizzle',
        56 => 'Freezing drizzle',
        57 => 'Freezing drizzle',
        61 => 'Light rain',
        63 => 'Rain',
        65 => 'Heavy rain',
        66 => 'Freezing rain',
        67 => 'Freezing rain',
        71 => 'Light snow',
        73 => 'Snow',
        75 => 'Heavy snow',
        77 => 'Snow',
        80 => 'Heavy rain',
        81 => 'Heavy rain',
        82 => 'Very heavy rain',
        85 => 'Snow',
        86 => 'Heavy snow',
        95 => 'Thunderstorm',
        96 => 'Thunderstorm with hail',
        99 => 'Thunderstorm with hail',
    ];

    /**
     * English label for a WMO weather code.
     *
     * @param int $code WMO weather code.
     * @return string
     */
    public static function labelForCode(int $code): string
    {
        return __(self::CODE_LABELS[$code] ?? 'Unknown', 'traydigita-core');
    }

    /**
     * Icon key for a WMO weather code (used as a CSS class suffix).
     *
     * @param int $code WMO weather code.
     * @return string One of "sun", "partly-cloudy", "cloudy", "fog",
     *                "drizzle", "rain", "snow", "storm", "unknown".
     */
    public static function iconKeyForCode(int $code): string
    {
        return match (true) {
            $code === 0 => 'sun',
            $code === 1 => 'partly-cloudy',
            $code === 2 || $code === 3 => 'cloudy',
            $code === 45 || $code === 48 => 'fog',
            $code >= 51 && $code <= 57 => 'drizzle',
            $code >= 61 && $code <= 67 => 'rain',
            $code >= 71 && $code <= 77 => 'snow',
            $code >= 80 && $code <= 82 => 'rain',
            $code === 85 || $code === 86 => 'snow',
            $code >= 95 => 'storm',
            default => 'unknown',
        };
    }

    /**
     * Default city from the Weather module, with the Padang fallback.
     *
     * Delegates to WeatherModule, the single source of truth
     * (module option with a theme-mod fallback for values set
     * in the Customizer before the module existed).
     *
     * @return array{name: string, latitude: float, longitude: float}
     */
    public static function getDefaultLocation(): array
    {
        return WeatherModule::getDefaultLocation();
    }

    /**
     * Default carousel locations from the Weather module, with a fallback list.
     *
     * Delegates to WeatherModule, the single source of truth.
     * When the stored list is empty or has no valid row, the
     * built-in five-city list is returned.
     *
     * @return array<int, array{name: string, latitude: float, longitude: float}>
     */
    public static function getDefaultLocations(): array
    {
        return WeatherModule::getDefaultLocations();
    }

    /**
     * Transient key for one location (coordinates rounded to 4 decimals).
     *
     * @param float $latitude Latitude.
     * @param float $longitude Longitude.
     * @return string
     */
    public static function transientKey(float $latitude, float $longitude): string
    {
        return 'weather_' . md5(round($latitude, 4) . ',' . round($longitude, 4));
    }

    /**
     * Read the cached current weather for a location (no network call).
     *
     * @param float $latitude Latitude.
     * @param float $longitude Longitude.
     * @return array{temperature: float, weather_code: int, condition: string,
     *     updated_at: int}|null Null on a cache miss.
     */
    public static function getCachedCurrent(float $latitude, float $longitude): ?array
    {
        $cached = get_transient('traydigita_' . self::transientKey($latitude, $longitude));
        if (!is_array($cached)) {
            return null;
        }
        if (!isset($cached['temperature'], $cached['weather_code'], $cached['updated_at'])) {
            return null;
        }
        $code = (int) $cached['weather_code'];
        return [
            'temperature' => (float) $cached['temperature'],
            'weather_code' => $code,
            'condition' => self::labelForCode($code),
            'updated_at' => (int) $cached['updated_at'],
        ];
    }

    /**
     * Fetch current weather from Open-Meteo (network call).
     *
     * Only call this from AJAX endpoints, admin save, or WP-Cron —
     * never from a widget render path.
     *
     * @param float $latitude Latitude.
     * @param float $longitude Longitude.
     * @return array{temperature: float, weather_code: int, updated_at: int}|null
     *     Null when the request fails or the response is unusable.
     */
    public static function fetchCurrent(float $latitude, float $longitude): ?array
    {
        $url = 'https://' . self::FORECAST_HOST . '/v1/forecast?latitude=' . rawurlencode((string) $latitude)
            . '&longitude=' . rawurlencode((string) $longitude)
            . '&current=temperature_2m,weather_code';
        if (wp_parse_url($url, PHP_URL_HOST) !== self::FORECAST_HOST) {
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !is_array($data['current'] ?? null)) {
            return null;
        }
        $current = $data['current'];
        if (!isset($current['temperature_2m'], $current['weather_code'])) {
            return null;
        }
        if (!is_numeric($current['temperature_2m']) || !is_numeric($current['weather_code'])) {
            return null;
        }
        return [
            'temperature' => (float) $current['temperature_2m'],
            'weather_code' => (int) $current['weather_code'],
            'updated_at' => time(),
        ];
    }

    /**
     * Get current weather, using the transient cache and fetching on a miss.
     *
     * @param float $latitude Latitude.
     * @param float $longitude Longitude.
     * @return array{temperature: float, weather_code: int, condition: string,
     *     updated_at: int, cached: bool}|null Null when unavailable.
     */
    public static function getCurrent(float $latitude, float $longitude): ?array
    {
        $cached = self::getCachedCurrent($latitude, $longitude);
        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }
        $fetched = self::fetchCurrent($latitude, $longitude);
        if ($fetched === null) {
            return null;
        }
        set_transient(
            'traydigita_' . self::transientKey($latitude, $longitude),
            $fetched,
            self::CACHE_TTL
        );
        $fetched['condition'] = self::labelForCode($fetched['weather_code']);
        $fetched['cached'] = false;
        return $fetched;
    }

    /**
     * Search cities through the Open-Meteo Geocoding API.
     *
     * @param string $query Search text (already sanitized, max 100 chars).
     * @return array<int, array{name: string, country: string, admin1: string,
     *     latitude: float, longitude: float}>
     */
    public static function searchLocations(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $url = 'https://' . self::GEOCODING_HOST . '/v1/search?name=' . rawurlencode($query)
            . '&count=10&language=id&format=json';
        if (wp_parse_url($url, PHP_URL_HOST) !== self::GEOCODING_HOST) {
            return [];
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return [];
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || !is_array($data['results'] ?? null)) {
            return [];
        }
        $results = [];
        foreach ($data['results'] as $row) {
            if (!is_array($row) || !isset($row['name'], $row['latitude'], $row['longitude'])) {
                continue;
            }
            if (!is_numeric($row['latitude']) || !is_numeric($row['longitude'])) {
                continue;
            }
            $results[] = [
                'name' => (string) $row['name'],
                'country' => (string) ($row['country'] ?? ''),
                'admin1' => (string) ($row['admin1'] ?? ''),
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
            ];
        }
        return $results;
    }

    /**
     * Format a Unix timestamp for display in the site locale.
     *
     * @param int $timestamp Unix timestamp.
     * @return string
     */
    public static function formatUpdatedAt(int $timestamp): string
    {
        return date_i18n('j M Y, H:i', $timestamp);
    }
}
