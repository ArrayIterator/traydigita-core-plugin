<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Libs;

use function __;
use function base64_encode;
use function is_array;
use function is_string;
use function is_wp_error;
use function json_decode;
use function openssl_sign;
use function rtrim;
use function sanitize_email;
use function str_contains;
use function strtr;
use function time;
use function trim;
use function wp_json_encode;
use function wp_parse_url;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use WP_Error;
use const OPENSSL_ALGO_SHA256;
use const PHP_URL_HOST;

/**
 * Google service-account client (OAuth2, JWT RS256, pure PHP).
 *
 * ONE client for every Google service-account flow in the
 * plugin: Push uses it for the Firebase Cloud Messaging scope
 * and SeoIndexing uses it for the Indexing API scope, instead
 * of each writing its own JWT signing + token exchange. The
 * scope is a parameter; the token endpoint host is allowlisted
 * (only oauth2.googleapis.com is ever contacted), so a stored
 * account can never redirect the token request elsewhere.
 *
 * The client never caches the access token and never logs it:
 * callers that need caching (Push) keep their own transient,
 * callers that fetch per call (SeoIndexing) just call this
 * again. It also never prints or returns the private key.
 */
final class GoogleServiceAccount
{
    /**
     * Google OAuth token endpoint: the only host contacted for
     * an access token, whatever the scope.
     */
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * The only host a token request may go to.
     */
    public const string TOKEN_HOST = 'oauth2.googleapis.com';

    /**
     * Grant type for the JWT-bearer OAuth flow.
     */
    public const string GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /**
     * How long a signed JWT stays valid, in seconds.
     */
    public const int JWT_TTL = 3600;

    /**
     * Parse a pasted Service Account JSON into its normalized fields.
     *
     * The JSON must decode and carry a valid client_email and a
     * private_key that looks like a PEM key; anything else
     * returns null. project_id, token_uri, and type are carried
     * through when present (empty string when absent) so callers
     * can apply their own extra requirements.
     *
     * @param string $raw Raw pasted JSON.
     * @return array{client_email: string, private_key: string, project_id: string, token_uri: string, type: string}|null
     */
    public static function parse(string $raw): ?array
    {
        $parsed = self::parseWithError($raw, false);
        return $parsed['account'];
    }

    /**
     * Validate a pasted Service Account JSON string.
     *
     * @param string $raw Raw pasted JSON.
     * @param bool $requireType Also require "type": "service_account" (Push).
     * @return array{account: array{client_email: string, private_key: string, project_id: string, token_uri: string, type: string}|null, error: string} Account array on success, else an error message.
     */
    public static function validate(string $raw, bool $requireType = false): array
    {
        return self::parseWithError($raw, $requireType);
    }

    /**
     * Parse and validate, reporting the first failure.
     *
     * @param string $raw Raw pasted JSON.
     * @param bool $requireType Also require "type": "service_account".
     * @return array{account: array{client_email: string, private_key: string, project_id: string, token_uri: string, type: string}|null, error: string}
     */
    private static function parseWithError(string $raw, bool $requireType): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['account' => null, 'error' => __('Service Account JSON is required.', 'traydigita-core')];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['account' => null, 'error' => __('Service Account JSON is not valid JSON.', 'traydigita-core')];
        }
        if ($requireType && ($decoded['type'] ?? '') !== 'service_account') {
            return ['account' => null, 'error' => __('Service Account JSON must have "type": "service_account".', 'traydigita-core')];
        }
        $email = isset($decoded['client_email']) && is_string($decoded['client_email'])
            ? sanitize_email($decoded['client_email'])
            : '';
        if ($email === '') {
            return ['account' => null, 'error' => __('Service Account JSON is missing a valid client_email.', 'traydigita-core')];
        }
        $privateKey = isset($decoded['private_key']) && is_string($decoded['private_key'])
            ? $decoded['private_key']
            : '';
        if (!str_contains($privateKey, 'BEGIN PRIVATE KEY')) {
            return ['account' => null, 'error' => __('Service Account JSON is missing a valid private_key.', 'traydigita-core')];
        }
        return [
            'account' => [
                'client_email' => $email,
                'private_key' => $privateKey,
                'project_id' => isset($decoded['project_id']) && is_string($decoded['project_id'])
                    ? $decoded['project_id']
                    : '',
                'token_uri' => isset($decoded['token_uri']) && is_string($decoded['token_uri'])
                    ? $decoded['token_uri']
                    : '',
                'type' => isset($decoded['type']) && is_string($decoded['type'])
                    ? $decoded['type']
                    : '',
            ],
            'error' => '',
        ];
    }

    /**
     * Fetch an OAuth2 access token for one scope.
     *
     * Builds a signed RS256 JWT for the account and exchanges
     * it at the token endpoint. The endpoint is the account's
     * own token_uri when one is stored, otherwise the Google
     * token endpoint; either way the host must be
     * oauth2.googleapis.com. The token value itself is never
     * logged; on failure a WP_Error is returned with one of
     * these codes (callers map them to their own messages):
     * 'google_sa_no_account', 'google_sa_sign_failed',
     * 'google_sa_token_request_failed', 'google_sa_token_denied'
     * (whose error data carries the Google 'detail' string when
     * Google gave one).
     *
     * @param array{client_email: string, private_key: string, token_uri?: string} $account Parsed service account.
     * @param string $scope OAuth scope the token is for.
     * @return string|WP_Error Access token, or an error describing why none could be obtained.
     */
    public static function getAccessToken(array $account, string $scope): string|WP_Error
    {
        $email = isset($account['client_email']) && is_string($account['client_email'])
            ? $account['client_email']
            : '';
        $privateKey = isset($account['private_key']) && is_string($account['private_key'])
            ? $account['private_key']
            : '';
        if ($email === '' || $privateKey === '') {
            return new WP_Error('google_sa_no_account', 'Service Account has not been saved yet.');
        }
        $tokenUri = isset($account['token_uri']) && is_string($account['token_uri']) && $account['token_uri'] !== ''
            ? $account['token_uri']
            : self::TOKEN_URL;
        $tokenHost = (string) wp_parse_url($tokenUri, PHP_URL_HOST);
        if ($tokenHost !== self::TOKEN_HOST) {
            return new WP_Error('google_sa_token_denied', 'Refusing to request a token from a non-Google host.');
        }
        $jwt = self::buildJwt($email, $privateKey, $scope, $tokenUri);
        if ($jwt === null) {
            return new WP_Error('google_sa_sign_failed', 'Could not sign the Service Account JWT. Check the private_key.');
        }
        $response = wp_remote_post($tokenUri, [
            'timeout' => 20,
            'body' => [
                'grant_type' => self::GRANT_TYPE,
                'assertion' => $jwt,
            ],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('google_sa_token_request_failed', $response->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['access_token']) || !is_string($body['access_token'])) {
            $detail = isset($body['error_description']) && is_string($body['error_description'])
                ? $body['error_description']
                : (isset($body['error']) && is_string($body['error']) ? $body['error'] : '');
            return new WP_Error(
                'google_sa_token_denied',
                $detail !== '' ? $detail : 'Google did not return an access token.',
                ['detail' => $detail]
            );
        }
        return $body['access_token'];
    }

    /**
     * Build a signed JWT for the service account (Google OAuth2 service-account flow).
     *
     * Header {alg: RS256, typ: JWT}; claims iss and sub are the
     * client email, aud is the token URI, scope is the caller's
     * scope, exp is now + 3600 seconds. Signed with the
     * account's private key via openssl_sign (SHA256). Returns
     * null when the signature fails.
     *
     * @param string $email Service account client email.
     * @param string $privateKey Service account private key (PEM).
     * @param string $scope OAuth scope the token is for.
     * @param string $tokenUri Token endpoint the JWT is addressed to.
     * @return string|null Signed JWT, or null on failure.
     */
    private static function buildJwt(string $email, string $privateKey, string $scope, string $tokenUri): ?string
    {
        $now = time();
        $header = self::base64UrlEncode((string) wp_json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64UrlEncode((string) wp_json_encode([
            'iss' => $email,
            'sub' => $email,
            'aud' => $tokenUri,
            'scope' => $scope,
            'iat' => $now,
            'exp' => $now + self::JWT_TTL,
        ]));
        $input = $header . '.' . $claims;
        $signature = '';
        if (!openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return $input . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Base64url-encode without padding (JWT encoding).
     *
     * @param string $data Raw bytes.
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
