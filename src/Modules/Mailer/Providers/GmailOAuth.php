<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;
use WP_Error;
use function __;
use function add_query_arg;
use function admin_url;
use function base64_encode;
use function get_option;
use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function sprintf;
use function strtr;
use function time;
use function trim;
use function update_option;
use function wp_json_encode;
use function wp_remote_get;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_remote_retrieve_response_message;
use function is_wp_error;

/**
 * Gmail OAuth2 + Gmail API sender for the Mailer module.
 *
 * No OAuth client library is needed: the authorization-code flow
 * (exchange + refresh) is plain `wp_remote_post()` calls to
 * Google's token endpoint, and sending is one POST to the Gmail
 * API with a base64url-encoded RFC 822 message built by
 * MailerModule::buildRfc822(). The only Google hosts ever
 * contacted are `accounts.google.com` (authorization redirect,
 * browser-side), `oauth2.googleapis.com` (token exchange and
 * refresh) and `gmail.googleapis.com` (profile + send).
 *
 * Scope is the minimum that can send mail:
 * `https://www.googleapis.com/auth/gmail.send`. No read scope
 * is requested, so the token can never list or read the mailbox.
 *
 * Tokens live in `traydigita_mailer_gmail` (autoload `no`):
 * access token, refresh token, expiry timestamp, and the
 * connected account address (fetched once from the Gmail
 * profile endpoint, because the API only accepts mail From
 * that address). The tokens are never logged; MailerModule::
 * redact() strips them from error text.
 */
final class GmailOAuth
{
    /**
     * Google authorization endpoint (browser redirect).
     */
    public const string AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * Google token endpoint (code exchange + refresh).
     */
    public const string TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * Gmail API send endpoint.
     */
    public const string SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

    /**
     * Gmail API profile endpoint (connected account address).
     */
    public const string PROFILE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    /**
     * OAuth scope: send mail only, never read the mailbox.
     */
    public const string SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    /**
     * HTTP timeout in seconds for Google API calls.
     */
    public const int TIMEOUT = 15;

    /**
     * Refresh the access token when it expires within this many seconds.
     */
    public const int REFRESH_SKEW = 60;

    /**
     * Redirect URI registered in the Google Cloud console.
     *
     * The same URL is used for the authorization request and the
     * code exchange; Google rejects the exchange when they differ.
     *
     * @return string
     */
    public static function redirectUri(): string
    {
        return admin_url('admin-post.php?action=traydigita_mailer_gmail_callback');
    }

    /**
     * Google authorization URL for the connect flow.
     *
     * `access_type=offline` + `prompt=consent` make Google return
     * a refresh token (without them the first connect may return
     * an access token only, which cannot be refreshed).
     *
     * @param string $clientId Google OAuth client id.
     * @param string $state Nonce the callback verifies.
     * @return string
     */
    public static function authUrl(string $clientId, string $state): string
    {
        return add_query_arg(
            [
                'client_id' => $clientId,
                'redirect_uri' => self::redirectUri(),
                'response_type' => 'code',
                'scope' => self::SCOPE,
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $state,
            ],
            self::AUTH_URL
        );
    }

    /**
     * Exchange an authorization code for tokens and store them.
     *
     * Also fetches the account address from the Gmail profile
     * endpoint: without it the From header cannot be built, so
     * a profile failure fails the whole connect with a clear
     * error instead of storing half-usable tokens.
     *
     * @param string $code Authorization code from Google.
     * @param string $clientId Google OAuth client id.
     * @param string $clientSecret Google OAuth client secret.
     * @return array{email: string}|WP_Error Connected account, or an error.
     */
    public static function exchangeCode(string $code, string $clientId, string $clientSecret): array|WP_Error
    {
        $response = wp_remote_post(
            self::TOKEN_URL,
            [
                'timeout' => self::TIMEOUT,
                'body' => [
                    'code' => $code,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => self::redirectUri(),
                    'grant_type' => 'authorization_code',
                ],
            ]
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $data = self::decodeBody($response);
        if (!is_array($data) || empty($data['access_token']) || empty($data['refresh_token'])) {
            return new WP_Error(
                'mailer_gmail_exchange',
                __('Google did not return usable tokens. Check the client ID and secret, then try connecting again.', 'traydigita-core')
            );
        }
        $accessToken = (string) $data['access_token'];
        $email = self::profileEmail($accessToken);
        if ($email instanceof WP_Error) {
            return $email;
        }
        $tokens = [
            'access_token' => $accessToken,
            'refresh_token' => (string) $data['refresh_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
            'email' => $email,
        ];
        update_option(MailerModule::GMAIL_TOKENS_OPTION, $tokens, false);
        return ['email' => $email];
    }

    /**
     * Whether a Gmail account is connected (tokens stored).
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        $tokens = self::getStoredTokens();
        return is_array($tokens)
            && !empty($tokens['access_token'])
            && !empty($tokens['refresh_token'])
            && !empty($tokens['email']);
    }

    /**
     * Connected Gmail account address, or '' when not connected.
     *
     * @return string
     */
    public static function connectedEmail(): string
    {
        $tokens = self::getStoredTokens();
        if (!is_array($tokens) || empty($tokens['email'])) {
            return '';
        }
        return (string) $tokens['email'];
    }

    /**
     * Read the stored tokens, or null when nothing is stored.
     *
     * @return array{access_token: string, refresh_token: string, expires_at: int, email: string}|null
     */
    public static function getStoredTokens(): ?array
    {
        $stored = get_option(MailerModule::GMAIL_TOKENS_OPTION, null);
        if (!is_array($stored)) {
            return null;
        }
        return [
            'access_token' => (string) ($stored['access_token'] ?? ''),
            'refresh_token' => (string) ($stored['refresh_token'] ?? ''),
            'expires_at' => (int) ($stored['expires_at'] ?? 0),
            'email' => (string) ($stored['email'] ?? ''),
        ];
    }

    /**
     * Delete the stored Gmail tokens (disconnect).
     *
     * @return void
     */
    public static function clearTokens(): void
    {
        update_option(MailerModule::GMAIL_TOKENS_OPTION, [], false);
    }

    /**
     * Send one mail through the Gmail API.
     *
     * Refreshes the access token first when it is expired or
     * about to expire. The From header is always the connected
     * account - the API rejects any other sender.
     *
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, subject: string, body: string, is_html: bool, attachments: array<int, string>} $mail Normalized mail.
     * @return array{success: bool, message: string}
     */
    public static function send(array $mail): array
    {
        $accessToken = self::accessToken();
        if ($accessToken instanceof WP_Error) {
            return ['success' => false, 'message' => $accessToken->get_error_message()];
        }
        $fromEmail = self::connectedEmail();
        $raw = MailerModule::buildRfc822($mail, $fromEmail);
        if ($raw === '') {
            return ['success' => false, 'message' => __('The email has no recipients.', 'traydigita-core')];
        }
        $response = wp_remote_post(
            self::SEND_URL,
            [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => (string) wp_json_encode(['raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]),
            ]
        );
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return ['success' => true, 'message' => __('Sent through Gmail.', 'traydigita-core')];
        }
        return ['success' => false, 'message' => self::apiErrorMessage($response, $code)];
    }

    /**
     * A usable access token, refreshing it first when needed.
     *
     * @return string|WP_Error Access token, or an error when no token can be produced.
     */
    public static function accessToken(): string|WP_Error
    {
        $tokens = self::getStoredTokens();
        if (!is_array($tokens) || $tokens['access_token'] === '' || $tokens['refresh_token'] === '') {
            return new WP_Error('mailer_gmail_not_connected', __('Gmail is not connected.', 'traydigita-core'));
        }
        if ($tokens['expires_at'] > time() + self::REFRESH_SKEW) {
            return $tokens['access_token'];
        }
        $settings = MailerModule::getSettings();
        if ($settings['gmail_client_id'] === '' || $settings['gmail_client_secret'] === '') {
            return new WP_Error('mailer_gmail_no_client', __('The Google OAuth client ID and secret are missing. Reconnect Gmail from the Mailer settings.', 'traydigita-core'));
        }
        $response = wp_remote_post(
            self::TOKEN_URL,
            [
                'timeout' => self::TIMEOUT,
                'body' => [
                    'refresh_token' => $tokens['refresh_token'],
                    'client_id' => $settings['gmail_client_id'],
                    'client_secret' => $settings['gmail_client_secret'],
                    'grant_type' => 'refresh_token',
                ],
            ]
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $data = self::decodeBody($response);
        if (!is_array($data) || empty($data['access_token'])) {
            return new WP_Error(
                'mailer_gmail_refresh',
                __('Google refused to refresh the Gmail access token. Reconnect Gmail from the Mailer settings.', 'traydigita-core')
            );
        }
        $tokens['access_token'] = (string) $data['access_token'];
        $tokens['expires_at'] = time() + (int) ($data['expires_in'] ?? 3600);
        update_option(MailerModule::GMAIL_TOKENS_OPTION, $tokens, false);
        return $tokens['access_token'];
    }

    /**
     * Account address for an access token (Gmail profile endpoint).
     *
     * @param string $accessToken Access token.
     * @return string|WP_Error Email address, or an error.
     */
    private static function profileEmail(string $accessToken): string|WP_Error
    {
        $response = wp_remote_get(
            self::PROFILE_URL,
            [
                'timeout' => self::TIMEOUT,
                'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            ]
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $data = self::decodeBody($response);
        if (!is_array($data) || empty($data['emailAddress'])) {
            return new WP_Error(
                'mailer_gmail_profile',
                __('Google did not return the Gmail account address.', 'traydigita-core')
            );
        }
        return (string) $data['emailAddress'];
    }

    /**
     * Decode a Google JSON response body into an array.
     *
     * @param array<string, mixed> $response wp_remote_* response.
     * @return array<string, mixed>|null
     */
    private static function decodeBody(array $response): ?array
    {
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Human error text for a failed Gmail API call.
     *
     * Prefers Google's own `error.message`; falls back to the
     * HTTP status line. The message is passed through
     * MailerModule::redact() by the caller before it is stored.
     *
     * @param array<string, mixed> $response wp_remote_* response.
     * @param int $code HTTP status code.
     * @return string
     */
    private static function apiErrorMessage(array $response, int $code): string
    {
        $data = self::decodeBody($response);
        if (is_array($data) && isset($data['error']['message']) && is_string($data['error']['message'])) {
            return trim($data['error']['message']);
        }
        $status = wp_remote_retrieve_response_message($response);
        if ($status !== '') {
            return sprintf(
                /* translators: %1$d is the HTTP status code, %2$s is the HTTP status text. */
                __('Gmail API error %1$d: %2$s', 'traydigita-core'),
                $code,
                $status
            );
        }
        return sprintf(
            /* translators: %d is the HTTP status code returned by the Gmail API. */
            __('Gmail API error %d.', 'traydigita-core'),
            $code
        );
    }
}
