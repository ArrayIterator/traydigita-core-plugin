<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Turnstile;

use WP_Error;
use function home_url;
use function is_array;
use function is_string;
use function json_decode;
use function parse_url;
use function strtolower;
use function str_starts_with;
use function substr;
use function trim;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;

/**
 * Server-side Cloudflare Turnstile token verification.
 *
 * Sends the submitted token to Cloudflare's siteverify endpoint
 * with `wp_remote_post()` and interprets the answer. The secret key
 * is read from the module settings and is only ever sent to
 * Cloudflare over HTTPS; it is never included in any returned
 * array, never logged, and never appears in an error message —
 * Cloudflare's `error-codes` are mapped onto a small allowlist of
 * internal codes first, so nothing Cloudflare returns verbatim
 * reaches the caller.
 *
 * Result shape:
 * `['success' => bool, 'unreachable' => bool, 'error_code' => string]`
 * where `error_code` is one of `''` (no error), `missing-token`,
 * `invalid-token`, `invalid-secret`, `invalid-host`, `unreachable`,
 * or `failed`. `unreachable` is true only when Cloudflare could not
 * be reached at all (network error, non-200 status, or an
 * unparseable answer); callers apply their fail-open / fail-closed
 * setting to that case. A token Cloudflare definitively rejects is
 * NOT unreachable — it is a failed verification and always blocks,
 * whichever way the fail-open setting points.
 *
 * The `hostname` returned by Cloudflare is the domain the widget
 * was rendered on; it must match this site's host (ignoring a
 * leading `www.` on either side), which proves the token was issued
 * for this site's key rather than replayed from another site.
 */
final class Verifier
{
    /**
     * Cloudflare Turnstile siteverify endpoint.
     */
    public const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Request timeout in seconds.
     */
    public const int TIMEOUT = 10;

    /**
     * Error codes Cloudflare may return, mapped to internal codes.
     *
     * Anything outside this list is collapsed to `failed`, so an
     * unexpected Cloudflare answer can never leak verbatim text
     * into a response or a log line.
     *
     * @var array<string, string>
     */
    private const array ERROR_CODE_MAP = [
        'missing-input-secret' => 'invalid-secret',
        'invalid-input-secret' => 'invalid-secret',
        'invalid-parsed-secret' => 'invalid-secret',
        'missing-input-response' => 'missing-token',
        'invalid-input-response' => 'invalid-token',
        'timeout-or-duplicate' => 'invalid-token',
        'invalid-widget-id' => 'invalid-token',
        'invalid-action' => 'invalid-token',
    ];

    /**
     * Verify a Turnstile token with Cloudflare.
     *
     * No network request is made for an empty token, or when the
     * module has no keys stored: both are local failures.
     *
     * @param string $token Token from the `cf-turnstile-response` form field.
     * @param string|null $remoteIp Client IP address, or null to omit it.
     * @return array{success: bool, unreachable: bool, error_code: string}
     */
    public static function verify(string $token, ?string $remoteIp = null): array
    {
        if (trim($token) === '') {
            return ['success' => false, 'unreachable' => false, 'error_code' => 'missing-token'];
        }
        $settings = TurnstileModule::getSettings();
        if ($settings['site_key'] === '' || $settings['secret_key'] === '') {
            return ['success' => false, 'unreachable' => false, 'error_code' => 'failed'];
        }
        $body = [
            'secret' => $settings['secret_key'],
            'response' => $token,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $body['remoteip'] = $remoteIp;
        }
        $response = wp_remote_post(self::SITEVERIFY_URL, [
            'timeout' => self::TIMEOUT,
            'body' => $body,
        ]);
        if ($response instanceof WP_Error) {
            return ['success' => false, 'unreachable' => true, 'error_code' => 'unreachable'];
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return ['success' => false, 'unreachable' => true, 'error_code' => 'unreachable'];
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !isset($decoded['success'])) {
            return ['success' => false, 'unreachable' => true, 'error_code' => 'unreachable'];
        }
        if ($decoded['success'] !== true) {
            return [
                'success' => false,
                'unreachable' => false,
                'error_code' => self::mapErrorCodes($decoded['error-codes'] ?? []),
            ];
        }
        if (!self::hostnameMatches($decoded['hostname'] ?? null)) {
            return ['success' => false, 'unreachable' => false, 'error_code' => 'invalid-host'];
        }
        return ['success' => true, 'unreachable' => false, 'error_code' => ''];
    }

    /**
     * Map Cloudflare's `error-codes` list onto one internal code.
     *
     * A bad secret outranks a bad token: when Cloudflare reports
     * both, the secret is the problem to fix. Unknown codes are
     * collapsed to `failed`.
     *
     * @param mixed $codes Raw `error-codes` value from Cloudflare.
     * @return string Internal error code.
     */
    private static function mapErrorCodes(mixed $codes): string
    {
        if (!is_array($codes)) {
            return 'failed';
        }
        $mapped = 'failed';
        foreach ($codes as $code) {
            if (!is_string($code)) {
                continue;
            }
            $internal = self::ERROR_CODE_MAP[$code] ?? null;
            if ($internal === null) {
                continue;
            }
            if ($internal === 'invalid-secret') {
                return 'invalid-secret';
            }
            if ($mapped === 'failed') {
                $mapped = $internal;
            }
        }
        return $mapped;
    }

    /**
     * Check Cloudflare's `hostname` against this site's host.
     *
     * A missing hostname is accepted (older or proxied answers may
     * omit it); a present hostname must match the site host after
     * lowercasing and stripping a leading `www.` from both sides,
     * so `www.example.com` and `example.com` count as the same site.
     *
     * @param mixed $hostname Raw `hostname` value from Cloudflare.
     * @return bool
     */
    private static function hostnameMatches(mixed $hostname): bool
    {
        if (!is_string($hostname) || $hostname === '') {
            return true;
        }
        $siteHost = parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($siteHost) || $siteHost === '') {
            return true;
        }
        return self::normalizeHost($hostname) === self::normalizeHost($siteHost);
    }

    /**
     * Lowercase a host and strip a leading `www.`.
     *
     * @param string $host Host name.
     * @return string
     */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }
}
