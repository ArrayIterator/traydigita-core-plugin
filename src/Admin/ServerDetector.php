<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use function __;
use function apply_filters;
use function function_exists;
use function in_array;
use function is_array;
use function is_string;
use function stripos;
use function strtolower;

/**
 * Web server detection from $_SERVER['SERVER_SOFTWARE'].
 *
 * The cache recommendation on the Plugin Suggestions page depends
 * on the server (LiteSpeed Cache only makes sense on LiteSpeed),
 * so this class answers one question — which server runs this
 * site — and nothing else. It parses the server-software string
 * the SAPI reports; when that string says nothing recognisable
 * it returns 'unknown' instead of guessing, because a wrong
 * guess would recommend the wrong cache plugin. The result is
 * cached in a static property for the request only — never in an
 * option, since the server can change under the theme.
 *
 * A child theme can override the detected value with the
 * `traydigita:core:server/detect` filter, which receives the
 * detected array and the raw server-software string and must
 * return the same array shape (or a plain slug string). An
 * override that is not a known slug is ignored, so a bad filter
 * can never produce a server the theme does not know.
 */
final class ServerDetector
{
    /**
     * LiteSpeed Web Server (Enterprise).
     */
    public const string SERVER_LITESPEED = 'litespeed';

    /**
     * OpenLiteSpeed.
     */
    public const string SERVER_OPENLITESPEED = 'openlitespeed';

    /**
     * Apache HTTP Server.
     */
    public const string SERVER_APACHE = 'apache';

    /**
     * nginx.
     */
    public const string SERVER_NGINX = 'nginx';

    /**
     * PHP built-in development server.
     */
    public const string SERVER_DEVELOPMENT = 'development';

    /**
     * The server could not be identified from SERVER_SOFTWARE.
     */
    public const string SERVER_UNKNOWN = 'unknown';

    /**
     * Known server slugs, in detection order.
     */
    public const array SLUGS = [
        self::SERVER_LITESPEED,
        self::SERVER_OPENLITESPEED,
        self::SERVER_APACHE,
        self::SERVER_NGINX,
        self::SERVER_DEVELOPMENT,
        self::SERVER_UNKNOWN,
    ];

    /**
     * Filter name for overriding the detected server.
     */
    public const string FILTER = 'traydigita:core:server/detect';

    /**
     * Detected server for this request, once computed.
     *
     * @var array{slug: string, label: string}|null
     */
    private static ?array $detected = null;

    /**
     * Detect the web server for this request.
     *
     * @return array{slug: string, label: string} Slug plus human-readable label.
     */
    public static function detect(): array
    {
        if (self::$detected !== null) {
            return self::$detected;
        }
        $raw = isset($_SERVER['SERVER_SOFTWARE']) && is_string($_SERVER['SERVER_SOFTWARE'])
            ? $_SERVER['SERVER_SOFTWARE']
            : '';
        $detected = self::detectFromString($raw);
        if (function_exists('apply_filters')) {
            $detected = self::normalize(apply_filters(self::FILTER, $detected, $raw), $detected);
        }
        self::$detected = $detected;
        return $detected;
    }

    /**
     * Detected server slug, e.g. 'litespeed' or 'unknown'.
     *
     * @return string
     */
    public static function slug(): string
    {
        return self::detect()['slug'];
    }

    /**
     * Human-readable label for the detected server.
     *
     * @return string
     */
    public static function label(): string
    {
        return self::detect()['label'];
    }

    /**
     * Whether the site runs on LiteSpeed or OpenLiteSpeed.
     *
     * @return bool
     */
    public static function isLiteSpeed(): bool
    {
        $slug = self::slug();
        return $slug === self::SERVER_LITESPEED || $slug === self::SERVER_OPENLITESPEED;
    }

    /**
     * Parse a server-software string into slug + label.
     *
     * OpenLiteSpeed is checked before LiteSpeed because its name
     * contains "litespeed" — the other way round every
     * OpenLiteSpeed server would report as LiteSpeed. The PHP
     * built-in server is recognised by its "Development Server"
     * string (and by the cli-server SAPI, for a string that
     * omits it). Anything else is 'unknown': the string is
     * free-form and hosts behind a proxy or CDN may report
     * something unrecognisable, so guessing would be wrong
     * more often than right.
     *
     * @param string $software Raw SERVER_SOFTWARE value, or '' when absent.
     * @return array{slug: string, label: string}
     */
    private static function detectFromString(string $software): array
    {
        if (stripos($software, 'openlitespeed') !== false) {
            return ['slug' => self::SERVER_OPENLITESPEED, 'label' => 'OpenLiteSpeed'];
        }
        if (stripos($software, 'litespeed') !== false) {
            return ['slug' => self::SERVER_LITESPEED, 'label' => 'LiteSpeed'];
        }
        if (stripos($software, 'apache') !== false) {
            return ['slug' => self::SERVER_APACHE, 'label' => 'Apache'];
        }
        if (stripos($software, 'nginx') !== false) {
            return ['slug' => self::SERVER_NGINX, 'label' => 'nginx'];
        }
        if (stripos($software, 'development server') !== false || PHP_SAPI === 'cli-server') {
            return ['slug' => self::SERVER_DEVELOPMENT, 'label' => __('Development server', 'traydigita-core')];
        }
        return ['slug' => self::SERVER_UNKNOWN, 'label' => __('Unknown', 'traydigita-core')];
    }

    /**
     * Validate a filter override and fall back to the detected value.
     *
     * Accepts the documented array shape or a plain slug string;
     * the label falls back to the built-in label for the slug when
     * the override omits it or passes an empty one. Anything that
     * is not a known slug is ignored outright.
     *
     * @param mixed $filtered Value returned by the filter.
     * @param array{slug: string, label: string} $fallback Detected value to keep on a bad override.
     * @return array{slug: string, label: string}
     */
    private static function normalize(mixed $filtered, array $fallback): array
    {
        if (is_string($filtered)) {
            $filtered = ['slug' => $filtered];
        }
        if (!is_array($filtered) || !isset($filtered['slug']) || !is_string($filtered['slug'])) {
            return $fallback;
        }
        $slug = strtolower($filtered['slug']);
        if (!in_array($slug, self::SLUGS, true)) {
            return $fallback;
        }
        $label = isset($filtered['label']) && is_string($filtered['label']) && $filtered['label'] !== ''
            ? $filtered['label']
            : self::labelFor($slug);
        return ['slug' => $slug, 'label' => $label];
    }

    /**
     * Built-in label for a known slug.
     *
     * @param string $slug Known server slug.
     * @return string
     */
    private static function labelFor(string $slug): string
    {
        return match ($slug) {
            self::SERVER_LITESPEED => 'LiteSpeed',
            self::SERVER_OPENLITESPEED => 'OpenLiteSpeed',
            self::SERVER_APACHE => 'Apache',
            self::SERVER_NGINX => 'nginx',
            self::SERVER_DEVELOPMENT => __('Development server', 'traydigita-core'),
            default => __('Unknown', 'traydigita-core'),
        };
    }
}
