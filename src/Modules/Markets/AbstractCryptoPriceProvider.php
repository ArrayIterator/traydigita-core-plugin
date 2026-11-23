<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use function array_merge;
use function is_array;
use function is_numeric;
use function is_wp_error;
use function json_decode;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_retrieve_body;

/**
 * Shared fetch helper for crypto price providers.
 *
 * Every request goes through wp_remote_get with an 8 second timeout
 * and an allowlisted host, and is only ever made from admin save,
 * WP-Cron refresh, or the admin preview — never from a frontend
 * render path. Any failure returns null so the caller can fall back
 * to the manual snapshot or an honest empty state.
 */
abstract class AbstractCryptoPriceProvider implements CryptoPriceProvider
{
    /**
     * Request timeout in seconds.
     */
    protected const int TIMEOUT = 8;

    /**
     * Fetch and decode a JSON document from an allowlisted host.
     *
     * @param string $url Full request URL.
     * @param string $allowedHost Only this host may be fetched.
     * @param array<string, string> $headers Extra request headers.
     * @return array<string, mixed>|null Decoded JSON, or null on any failure.
     */
    protected function fetchJson(string $url, string $allowedHost, array $headers = []): ?array
    {
        if (wp_parse_url($url, PHP_URL_HOST) !== $allowedHost) {
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => array_merge(['Accept' => 'application/json'], $headers),
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Coerce a decoded JSON value to float, or null when not numeric.
     *
     * @param mixed $value Raw value.
     * @return float|null
     */
    protected function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
