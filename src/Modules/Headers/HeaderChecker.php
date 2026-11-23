<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Headers;

use function __;
use function is_array;
use function is_string;
use function method_exists;
use function sprintf;
use function strtolower;
use function trim;
use function wp_remote_get;
use function wp_remote_retrieve_headers;
use function wp_remote_retrieve_response_code;

/**
 * Header checker for the site's own URL.
 *
 * Sends a GET request to the home URL with a real browser
 * User-Agent and Accept headers (some hosts and security plugins
 * answer differently — or not at all — to requests that look like
 * bots), then reports which of the recommended headers are present
 * or missing, with a plain-language explanation for each.
 *
 * The checker never writes anything and never follows a login: it
 * only reads the response headers of a public page. When the
 * server-side request is denied or returns nothing, the admin page
 * offers a browser-fetch fallback instead (see HeadersAjax and
 * resources/js/modules/admin-headers.ts).
 */
final class HeaderChecker
{
    /**
     * Browser User-Agent sent with the check request.
     *
     * A current Chrome on Windows string: servers that block
     * unknown agents still answer a request that looks like a
     * real visitor's browser.
     */
    public const string BROWSER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Recommended headers, in display order.
     *
     * Each entry carries the value the module recommends, a
     * plain-language explanation shown when the header is present,
     * and one shown when it is missing. Strict-Transport-Security
     * only applies to HTTPS sites (see evaluate()).
     *
     * @var array<string, array{expected: string, present: string, missing: string}>
     */
    public const array RECOMMENDED = [
        'X-Content-Type-Options' => [
            'expected' => 'nosniff',
            'present' => 'Browsers will not guess a file type. A plain-text file cannot be treated as JavaScript.',
            'missing' => 'Without it, a browser may guess a file type and run a plain-text file as JavaScript.',
        ],
        'X-Frame-Options' => [
            'expected' => 'SAMEORIGIN',
            'present' => 'Only pages from this site may embed the site in a frame, which protects against clickjacking.',
            'missing' => 'Without it, another site could embed this site in a frame and trick visitors into clicking something they cannot see.',
        ],
        'Referrer-Policy' => [
            'expected' => 'strict-origin-when-cross-origin',
            'present' => 'The browser limits how much of the page address is sent to other sites when a visitor follows a link.',
            'missing' => 'Without it, browsers fall back to their own default, which may send the full page address to other sites.',
        ],
        'Permissions-Policy' => [
            'expected' => 'camera=(), microphone=(), geolocation=()',
            'present' => 'The page and any embedded frame cannot use the camera, microphone, or geolocation.',
            'missing' => 'Without it, an embedded frame could ask to use the camera, microphone, or geolocation.',
        ],
        'Strict-Transport-Security' => [
            'expected' => 'max-age=31536000; includeSubDomains',
            'present' => 'Browsers will only ever reach this site over HTTPS for the next year, even if a visitor types http://.',
            'missing' => 'Without it, a visitor can still be sent to the plain-http version of the site on the first visit.',
        ],
        'Content-Security-Policy-Report-Only' => [
            'expected' => 'default-src \'self\'; …',
            'present' => 'The browser reports what the policy would block, so problems can be found without breaking the site.',
            'missing' => 'Without it, there is no early warning when a script, style, or embed comes from an unexpected source.',
        ],
    ];

    /**
     * Request the URL and collect its response headers.
     *
     * @param string $url     Absolute URL to check (the site home URL).
     * @param int    $timeout Request timeout in seconds.
     * @return array{ok: bool, status: int, url: string, headers: array<string, string>, error: string}
     */
    public static function check(string $url, int $timeout): array
    {
        $response = wp_remote_get($url, [
            'timeout' => $timeout,
            'redirection' => 3,
            'headers' => [
                'User-Agent' => self::BROWSER_USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);
        if ($response instanceof \WP_Error) {
            return [
                'ok' => false,
                'status' => 0,
                'url' => $url,
                'headers' => [],
                'error' => $response->get_error_message(),
            ];
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $headers = self::normalizeHeaders($response);
        if ($status === 0 || $headers === []) {
            return [
                'ok' => false,
                'status' => $status,
                'url' => $url,
                'headers' => $headers,
                'error' => $status === 0
                    ? __('The request returned an empty response.', 'traydigita-core')
                    : sprintf(
                        /* translators: %d is the HTTP status code. */
                        __('The request returned status %d with no readable headers.', 'traydigita-core'),
                        $status
                    ),
            ];
        }
        return [
            'ok' => true,
            'status' => $status,
            'url' => $url,
            'headers' => $headers,
            'error' => '',
        ];
    }

    /**
     * Evaluate a set of response headers against the recommended list.
     *
     * Header names are matched case-insensitively. Strict-Transport-
     * Security is only evaluated for HTTPS URLs: on a plain-http
     * site it is reported as not applicable, because HSTS is
     * meaningless (and must never be sent) without HTTPS.
     *
     * @param array<string, string> $headers Response headers (name => value).
     * @param bool                  $isHttps Whether the checked URL uses HTTPS.
     * @return array<int, array{name: string, expected: string, value: string|null, present: bool, applicable: bool, explanation: string}>
     */
    public static function evaluate(array $headers, bool $isHttps): array
    {
        $byLower = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $byLower[strtolower($name)] = trim($value);
            }
        }
        $rows = [];
        foreach (self::RECOMMENDED as $name => $info) {
            $applicable = $name !== 'Strict-Transport-Security' || $isHttps;
            $value = $byLower[strtolower($name)] ?? null;
            $present = $applicable && $value !== null && $value !== '';
            if (!$applicable) {
                $explanation = __('HSTS only applies to HTTPS sites. Turn on HTTPS first, then this header can be enabled.', 'traydigita-core');
            } else {
                $explanation = $present ? $info['present'] : $info['missing'];
            }
            $rows[] = [
                'name' => $name,
                'expected' => $info['expected'],
                'value' => $value,
                'present' => $present,
                'applicable' => $applicable,
                'explanation' => $explanation,
            ];
        }
        return $rows;
    }

    /**
     * Normalize a wp_remote_get() response into a name => value map.
     *
     * wp_remote_retrieve_headers() returns either a plain array or a
     * Requests_Utility_CaseInsensitiveDictionary (WP_HTTP_Headers),
     * which exposes getAll(). Both shapes are handled, and only
     * string names and values are kept.
     *
     * @param mixed $response Response array from wp_remote_get().
     * @return array<string, string>
     */
    private static function normalizeHeaders(mixed $response): array
    {
        $raw = wp_remote_retrieve_headers($response);
        if (is_array($raw)) {
            $headers = $raw;
        } elseif (is_object($raw) && method_exists($raw, 'getAll')) {
            $all = $raw->getAll();
            $headers = is_array($all) ? $all : [];
        } else {
            $headers = [];
        }
        $clean = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            if (!is_string($value)) {
                continue;
            }
            $clean[$name] = trim($value);
        }
        return $clean;
    }

    /**
     * Read one header from a set of response headers, case-insensitively.
     *
     * Kept for callers that only need a single header value.
     *
     * @param array<string, string> $headers Response headers (name => value).
     * @param string                $name    Header name to look up.
     * @return string|null The header value, or null when absent.
     */
    public static function getHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (is_string($headerName) && strtolower($headerName) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }
}
