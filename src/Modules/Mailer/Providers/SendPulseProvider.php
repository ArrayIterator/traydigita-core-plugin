<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function base64_encode;
use function get_transient;
use function is_array;
use function set_transient;
use function delete_transient;

/**
 * SendPulse SMTP API provider.
 *
 * Two authentication modes, in order of preference:
 *
 * 1. OAuth 2.0 client credentials (`client_id` +
 *    `client_secret` from Account Settings -> API): the token
 *    endpoint `POST https://api.sendpulse.com/oauth/access_token`
 *    returns a Bearer token valid for one hour; it is cached in
 *    a transient and refreshed on HTTP 401 (one retry).
 * 2. A static single API key (Settings -> API -> API keys),
 *    used directly as the Bearer token when the OAuth fields
 *    are empty.
 *
 * Send endpoint `POST https://api.sendpulse.com/smtp/emails`
 * with `{email: {html (base64), text, subject, from, to}}`.
 *
 * Free tier (Nov 2026): 12,000 emails/month. Best for:
 * transactional mail with strong tracking and automation.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SendPulseProvider extends AbstractApiProvider
{
    /**
     * SendPulse OAuth token endpoint.
     */
    public const string TOKEN_URL = 'https://api.sendpulse.com/oauth/access_token';

    /**
     * SendPulse SMTP send endpoint.
     */
    public const string API_URL = 'https://api.sendpulse.com/smtp/emails';

    /**
     * Transient holding the cached OAuth access token.
     */
    public const string TOKEN_TRANSIENT = 'traydigita_mailer_sendpulse_token';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'sendpulse',
            'label' => 'SendPulse',
            'description' => __(
                'SendPulse — free 12,000 emails/month. Best for transactional mail with strong tracking, automation, and multichannel (SMS/push) on the same account.',
                'traydigita-core'
            ),
            'free_tier' => '12,000/month',
            'best_for' => 'Transactional mail with tracking and automation',
            'link' => '',
            'fields' => [
                [
                    'key' => 'sendpulse_client_id',
                    'label' => __('SendPulse client ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'From Account Settings → API in SendPulse. Used for the OAuth client-credentials flow; leave empty when using a static API key instead.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => false,
                ],
                [
                    'key' => 'sendpulse_client_secret',
                    'label' => __('SendPulse client secret', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('From Account Settings → API in SendPulse. Leave empty to keep the saved secret.', 'traydigita-core'),
                    'secret' => true,
                    'required' => false,
                ],
                [
                    'key' => 'sendpulse_api_key',
                    'label' => __('SendPulse API key (optional)', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'A static single API key (Settings → API → API keys). Only used when the client ID and secret above are empty.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * SendPulse needs either the OAuth pair or a static key.
     *
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return bool
     */
    public static function isConfigured(array $settings): bool
    {
        $oauth = self::setting($settings, 'sendpulse_client_id') !== ''
            && self::setting($settings, 'sendpulse_client_secret') !== '';
        return $oauth || self::setting($settings, 'sendpulse_api_key') !== '';
    }

    /**
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return array{success: bool, message: string}
     */
    public static function send(array $mail, array $settings): array
    {
        $guard = self::guard($mail);
        if ($guard !== null) {
            return $guard;
        }
        $token = self::accessToken($settings);
        if ($token === '') {
            return ['success' => false, 'message' => __('Enter the SendPulse client ID and secret (or a static API key) in the Mailer settings first.', 'traydigita-core')];
        }
        $email = [
            'html' => base64_encode($mail['is_html'] ? $mail['body'] : ''),
            'text' => self::plainText($mail),
            'subject' => $mail['subject'],
            'from' => ['name' => $mail['from_name'], 'email' => $mail['from_email']],
            'to' => self::addressObjects($mail['to']),
        ];
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $binary = [];
            foreach ($attachments as $attachment) {
                $binary[$attachment['filename']] = $attachment['content'];
            }
            $email['attachments_binary'] = $binary;
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $token],
            ['email' => $email]
        );
        if ($result['code'] === 401 && self::setting($settings, 'sendpulse_client_id') !== '') {
            // The cached token expired: drop it and retry once.
            delete_transient(self::TOKEN_TRANSIENT);
            $token = self::accessToken($settings, true);
            if ($token === '') {
                return ['success' => false, 'message' => __('SendPulse refused the client credentials.', 'traydigita-core')];
            }
            $result = self::postJson(
                self::API_URL,
                ['Authorization' => 'Bearer ' . $token],
                ['email' => $email]
            );
        }
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SendPulse')];
        }
        return ['success' => true, 'message' => __('Sent through SendPulse.', 'traydigita-core')];
    }

    /**
     * A usable Bearer token: the static API key when set, or a
     * cached OAuth token (fetched on demand, cached 50 minutes).
     *
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @param bool $forceRefresh Skip the cached token.
     * @return string Empty when no token can be produced.
     */
    private static function accessToken(array $settings, bool $forceRefresh = false): string
    {
        $staticKey = self::setting($settings, 'sendpulse_api_key');
        $clientId = self::setting($settings, 'sendpulse_client_id');
        $clientSecret = self::setting($settings, 'sendpulse_client_secret');
        if ($clientId === '' || $clientSecret === '') {
            return $staticKey;
        }
        if (!$forceRefresh) {
            $cached = get_transient(self::TOKEN_TRANSIENT);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }
        $result = self::postJson(
            self::TOKEN_URL,
            [],
            [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]
        );
        if ($result['error'] !== '' || $result['code'] !== 200 || !is_array($result['data'])) {
            return '';
        }
        $token = (string) ($result['data']['access_token'] ?? '');
        if ($token === '') {
            return '';
        }
        // The token lives one hour; cache 50 minutes to stay inside it.
        set_transient(self::TOKEN_TRANSIENT, $token, 50 * 60);
        return $token;
    }
}
