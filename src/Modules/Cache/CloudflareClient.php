<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Cache;

use function __;
use function is_array;
use function is_wp_error;
use function json_decode;
use function sprintf;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Minimal Cloudflare cache-purge client (purge-everything only).
 *
 * Talks to exactly one allowlisted host -
 * https://api.cloudflare.com/client/v4 - with a bearer API
 * token. The token is never printed, never logged, and never
 * included in any returned message or stored result: error
 * messages are built from the HTTP status code and the generic
 * outcome only, never from the response body.
 *
 * The client is deliberately small: it does one thing (purge
 * everything for one zone) and nothing else. It does not manage
 * DNS, page rules, or any other Cloudflare surface, and it never
 * retries a failed purge on its own - a failed purge is reported
 * and the administrator decides whether to try again.
 */
final class CloudflareClient
{
    /**
     * Cloudflare API base URL (the only host this client contacts).
     */
    public const string API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Request timeout in seconds.
     */
    public const int TIMEOUT_SECONDS = 30;

    /**
     * Purge everything cached for one zone.
     *
     * The token and zone id are validated before any request is
     * made; an empty token or a malformed zone id is refused
     * locally without touching the network. A non-200 answer or a
     * `success: false` body is reported with its HTTP status code
     * only - the body is never echoed back, so no API detail (and
     * never the token) can leak into an admin notice or a log.
     *
     * @param string $token Cloudflare API token (bearer).
     * @param string $zoneId Cloudflare zone id (32 hex characters).
     * @return array{success: bool, message: string, code: int}
     */
    public static function purgeEverything(string $token, string $zoneId): array
    {
        if ($token === '' || !CacheModule::isValidZoneId($zoneId)) {
            return [
                'success' => false,
                'message' => __('Cloudflare is not configured. Save an API token and a zone ID first.', 'traydigita-core'),
                'code' => 0,
            ];
        }
        $body = wp_json_encode(['purge_everything' => true]);
        if (!is_string($body)) {
            return [
                'success' => false,
                'message' => __('The Cloudflare purge request could not be built. Please try again.', 'traydigita-core'),
                'code' => 0,
            ];
        }
        $response = wp_remote_post(
            self::API_BASE . '/zones/' . $zoneId . '/purge_cache',
            [
                'timeout' => self::TIMEOUT_SECONDS,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]
        );
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Cloudflare could not be reached. Please check the server connection and try again.', 'traydigita-core'),
                'code' => 0,
            ];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 200 && is_array($decoded) && ($decoded['success'] ?? false) === true) {
            return [
                'success' => true,
                'message' => __('Cloudflare cache purged for the whole zone.', 'traydigita-core'),
                'code' => $code,
            ];
        }
        if ($code === 400) {
            return [
                'success' => false,
                'message' => __('Cloudflare rejected the purge request. Please check the zone ID and try again.', 'traydigita-core'),
                'code' => $code,
            ];
        }
        if ($code === 403) {
            return [
                'success' => false,
                'message' => __('Cloudflare rejected the API token. Please check the token’s permissions and try again.', 'traydigita-core'),
                'code' => $code,
            ];
        }
        return [
            'success' => false,
            'message' => sprintf(
                /* translators: %d is the HTTP status code Cloudflare returned. */
                __('Cloudflare did not purge the cache (HTTP %d). Please try again later.', 'traydigita-core'),
                $code
            ),
            'code' => $code,
        ];
    }
}
