<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;
use function __;
use function gmdate;
use function is_array;
use function is_string;
use function time;

/**
 * AWeber provider.
 *
 * AWeber has no single-email send endpoint: a mail is sent
 * as a broadcast to a whole list, in two steps against
 * `https://api.aweber.com/1.0` with an OAuth 2.0 access token
 * as a Bearer token:
 *
 * 1. `POST /accounts/{account_id}/lists/{list_id}/broadcasts`
 *    (form-encoded) `{subject, body_html, body_text,
 *    click_tracking_enabled, is_archived, notify_on_send}`
 *    creates a draft broadcast.
 * 2. `POST {self_link}/schedule` (form-encoded)
 *    `{scheduled_for}` (two minutes ahead) sends it.
 *
 * Access tokens expire after two hours: on a 401 the refresh
 * token is exchanged at
 * `POST https://auth.aweber.com/oauth2/token` and the new
 * tokens are saved back to the Mailer settings, then the send
 * is retried once.
 *
 * The mail's To addresses are ignored: the broadcast goes to
 * every subscriber of the configured list, so a test email
 * sends to the whole list - never use it for a test unless
 * that is intended.
 *
 * Free tier (Nov 2026): 500 subscribers. Best for: email
 * marketing with automation on AWeber.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class AWeberProvider extends AbstractApiProvider
{
    /**
     * AWeber API base: the only hosts contacted (plus the OAuth host below).
     */
    public const string API_BASE = 'https://api.aweber.com/1.0';

    /**
     * AWeber OAuth token endpoint, used for refresh only.
     */
    public const string TOKEN_URL = 'https://auth.aweber.com/oauth2/token';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'aweber',
            'label' => 'AWeber',
            'description' => __(
                'AWeber — free 500 subscribers. Campaign provider: a mail is sent as a broadcast to a whole list, not to the mail’s To addresses — a test email goes to the whole list. Best for email marketing with automation on AWeber.',
                'traydigita-core'
            ),
            'free_tier' => '500 subscribers',
            'best_for' => 'Email marketing with automation',
            'link' => '',
            'fields' => [
                [
                    'key' => 'aweber_client_id',
                    'label' => __('AWeber client ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('The client ID of your AWeber app (Developer → your app). Needed to refresh the access token.', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'aweber_client_secret',
                    'label' => __('AWeber client secret', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('The client secret of your AWeber app. Leave empty to keep the saved secret.', 'traydigita-core'),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'aweber_access_token',
                    'label' => __('AWeber access token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Paste the access token from your AWeber OAuth flow. It expires after two hours and is refreshed automatically from the refresh token below.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'aweber_refresh_token',
                    'label' => __('AWeber refresh token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('Paste the refresh token from your AWeber OAuth flow. Leave empty to keep the saved token.', 'traydigita-core'),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'aweber_account_id',
                    'label' => __('AWeber account ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('Your AWeber account ID (the number in the API URLs, for example 1234567).', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'aweber_list_id',
                    'label' => __('AWeber list ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The list the broadcast is sent to (Lists → your list; the ID is in the URL). Every mail goes to the whole list.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => true,
                ],
            ],
        ];
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
        $accessToken = self::setting($settings, 'aweber_access_token');
        if ($accessToken === '') {
            return ['success' => false, 'message' => __('Enter the AWeber access token in the Mailer settings first.', 'traydigita-core')];
        }
        $result = self::sendBroadcast($mail, $settings, $accessToken);
        if ($result['code'] === 401) {
            // The access token expired (two-hour lifetime):
            // refresh it once and retry the send.
            $freshToken = self::refreshToken($settings);
            if ($freshToken === '') {
                return ['success' => false, 'message' => __('AWeber refused the access token and the refresh token did not produce a new one. Paste fresh tokens in the Mailer settings.', 'traydigita-core')];
            }
            $result = self::sendBroadcast($mail, $settings, $freshToken);
        }
        return $result;
    }

    /**
     * Create and schedule one broadcast. Returns the send
     * result plus the HTTP code of the failing step, so the
     * caller can refresh the token on a 401 and retry once.
     *
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @param string $accessToken AWeber OAuth access token.
     * @return array{success: bool, message: string, code: int}
     */
    private static function sendBroadcast(array $mail, array $settings, string $accessToken): array
    {
        $accountId = self::setting($settings, 'aweber_account_id');
        $listId = self::setting($settings, 'aweber_list_id');
        if ($accountId === '' || $listId === '') {
            return ['success' => false, 'message' => __('Enter the AWeber account ID and list ID in the Mailer settings first.', 'traydigita-core'), 'code' => 0];
        }
        $headers = ['Authorization' => 'Bearer ' . $accessToken];
        $created = self::postForm(
            self::API_BASE . '/accounts/' . $accountId . '/lists/' . $listId . '/broadcasts',
            $headers,
            [
                'subject' => $mail['subject'],
                'body_html' => $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>',
                'body_text' => self::plainText($mail),
                'click_tracking_enabled' => 'false',
                'is_archived' => 'false',
                'notify_on_send' => 'false',
            ]
        );
        if ($created['error'] !== '' || ($created['code'] !== 200 && $created['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'AWeber'), 'code' => $created['code']];
        }
        $selfLink = is_array($created['data']) && isset($created['data']['self_link']) ? (string) $created['data']['self_link'] : '';
        if ($selfLink === '') {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'AWeber'), 'code' => $created['code']];
        }
        $scheduled = self::postForm(
            $selfLink . '/schedule',
            $headers,
            ['scheduled_for' => gmdate('Y-m-d\TH:i:s\Z', time() + 120)]
        );
        if ($scheduled['error'] !== '' || ($scheduled['code'] !== 200 && $scheduled['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($scheduled, 'AWeber'), 'code' => $scheduled['code']];
        }
        return ['success' => true, 'message' => __('Broadcast sent through AWeber.', 'traydigita-core'), 'code' => $scheduled['code']];
    }

    /**
     * Exchange the stored refresh token for a fresh access
     * token and save both new tokens back to the Mailer
     * settings. Returns the new access token, or '' when the
     * refresh failed.
     *
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return string
     */
    private static function refreshToken(array $settings): string
    {
        $result = self::postForm(
            self::TOKEN_URL,
            [],
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => self::setting($settings, 'aweber_refresh_token'),
                'client_id' => self::setting($settings, 'aweber_client_id'),
                'client_secret' => self::setting($settings, 'aweber_client_secret'),
            ]
        );
        if ($result['error'] !== '' || $result['code'] !== 200 || !is_array($result['data'])) {
            return '';
        }
        $accessToken = isset($result['data']['access_token']) && is_string($result['data']['access_token']) ? $result['data']['access_token'] : '';
        if ($accessToken === '') {
            return '';
        }
        MailerModule::updateSetting('aweber_access_token', $accessToken);
        $refreshToken = isset($result['data']['refresh_token']) && is_string($result['data']['refresh_token']) ? $result['data']['refresh_token'] : '';
        if ($refreshToken !== '') {
            MailerModule::updateSetting('aweber_refresh_token', $refreshToken);
        }
        return $accessToken;
    }
}
