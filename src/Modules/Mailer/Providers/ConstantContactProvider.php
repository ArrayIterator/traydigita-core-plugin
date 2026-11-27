<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;
use function __;
use function base64_encode;
use function gmdate;
use function is_array;
use function is_string;
use function sprintf;
use function time;

/**
 * Constant Contact provider.
 *
 * Constant Contact has no single-email send endpoint: a mail
 * is sent as an email campaign to a whole list, in three
 * steps against `https://api.cc.email/v3` with an OAuth 2.0
 * access token as a Bearer token:
 *
 * 1. `POST /v3/emails` `{name, email_campaign_activities:
 *    [{format_type: 5, from_name, from_email, reply_to_email,
 *    subject, html_content}]}` creates the campaign.
 * 2. `PUT /v3/emails/activities/{campaign_activity_id}`
 *    `{contact_list_ids: [list_id]}` assigns the list.
 * 3. `POST /v3/emails/activities/{id}/schedules`
 *    `{scheduled_date}` (two minutes ahead) sends it.
 *
 * Access tokens expire: on a 401 the refresh token is
 * exchanged at
 * `POST https://authz.constantcontact.com/oauth2/default/v1/token`
 * (HTTP Basic with client id and secret) and the new tokens
 * are saved back to the Mailer settings, then the send is
 * retried once.
 *
 * The mail's To addresses are ignored: the campaign goes to
 * every contact of the configured list, so a test email sends
 * to the whole list - never use it for a test unless that is
 * intended.
 *
 * Pricing (Nov 2026): trial only, no free plan. Best for:
 * small businesses already running email marketing on
 * Constant Contact.
 *
 * Note: the middle PUT payload shape follows Constant
 * Contact's API reference but has not been verified against a
 * live account - confirm the campaign arrives before relying
 * on it in production.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class ConstantContactProvider extends AbstractApiProvider
{
    /**
     * Constant Contact API base: the only hosts contacted (plus the OAuth host below).
     */
    public const string API_BASE = 'https://api.cc.email/v3';

    /**
     * Constant Contact OAuth token endpoint, used for refresh only.
     */
    public const string TOKEN_URL = 'https://authz.constantcontact.com/oauth2/default/v1/token';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'constantcontact',
            'label' => 'Constant Contact',
            'description' => __(
                'Constant Contact — trial only, no free plan. Campaign provider: a mail is sent as an email campaign to a whole list, not to the mail’s To addresses — a test email goes to the whole list. Best for small businesses already running email marketing on Constant Contact.',
                'traydigita-core'
            ),
            'free_tier' => 'Trial only',
            'best_for' => 'Small businesses on Constant Contact',
            'link' => '',
            'fields' => [
                [
                    'key' => 'constantcontact_client_id',
                    'label' => __('Constant Contact client ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('The API key (client ID) of your Constant Contact app. Needed to refresh the access token.', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'constantcontact_client_secret',
                    'label' => __('Constant Contact client secret', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('The client secret of your Constant Contact app. Leave empty to keep the saved secret.', 'traydigita-core'),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'constantcontact_access_token',
                    'label' => __('Constant Contact access token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Paste the access token from your Constant Contact OAuth flow. It expires and is refreshed automatically from the refresh token below.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'constantcontact_refresh_token',
                    'label' => __('Constant Contact refresh token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('Paste the refresh token from your Constant Contact OAuth flow. Leave empty to keep the saved token.', 'traydigita-core'),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'constantcontact_list_id',
                    'label' => __('Constant Contact list ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The list the campaign is sent to (Contacts → Lists → your list; the ID is in the URL). Every mail goes to the whole list.',
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
        $accessToken = self::setting($settings, 'constantcontact_access_token');
        if ($accessToken === '') {
            return ['success' => false, 'message' => __('Enter the Constant Contact access token in the Mailer settings first.', 'traydigita-core')];
        }
        $result = self::sendCampaign($mail, $settings, $accessToken);
        if ($result['code'] === 401) {
            // The access token expired: refresh it once and
            // retry the send.
            $freshToken = self::refreshToken($settings);
            if ($freshToken === '') {
                return ['success' => false, 'message' => __('Constant Contact refused the access token and the refresh token did not produce a new one. Paste fresh tokens in the Mailer settings.', 'traydigita-core')];
            }
            $result = self::sendCampaign($mail, $settings, $freshToken);
        }
        return $result;
    }

    /**
     * Create, assign, and schedule one campaign. Returns the
     * send result plus the HTTP code of the failing step, so
     * the caller can refresh the token on a 401 and retry once.
     *
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @param string $accessToken Constant Contact OAuth access token.
     * @return array{success: bool, message: string, code: int}
     */
    private static function sendCampaign(array $mail, array $settings, string $accessToken): array
    {
        $listId = self::setting($settings, 'constantcontact_list_id');
        if ($listId === '') {
            return ['success' => false, 'message' => __('Enter the Constant Contact list ID in the Mailer settings first.', 'traydigita-core'), 'code' => 0];
        }
        $headers = ['Authorization' => 'Bearer ' . $accessToken];
        $created = self::postJson(
            self::API_BASE . '/emails',
            $headers,
            [
                'name' => sprintf('TrayDigita: %s', $mail['subject']),
                'email_campaign_activities' => [
                    [
                        'format_type' => 5,
                        'from_name' => $mail['from_name'],
                        'from_email' => $mail['from_email'],
                        'reply_to_email' => $mail['reply_to'] !== '' ? $mail['reply_to'] : $mail['from_email'],
                        'subject' => $mail['subject'],
                        'html_content' => $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>',
                    ],
                ],
            ]
        );
        if ($created['error'] !== '' || ($created['code'] !== 200 && $created['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'Constant Contact'), 'code' => $created['code']];
        }
        $activityId = '';
        if (is_array($created['data']) && isset($created['data']['email_campaign_activities'][0]['campaign_activity_id'])) {
            $activityId = (string) $created['data']['email_campaign_activities'][0]['campaign_activity_id'];
        }
        if ($activityId === '') {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'Constant Contact'), 'code' => $created['code']];
        }
        $assigned = self::putJson(
            self::API_BASE . '/emails/activities/' . $activityId,
            $headers,
            ['contact_list_ids' => [$listId]]
        );
        if ($assigned['error'] !== '' || ($assigned['code'] !== 200 && $assigned['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($assigned, 'Constant Contact'), 'code' => $assigned['code']];
        }
        $scheduled = self::postJson(
            self::API_BASE . '/emails/activities/' . $activityId . '/schedules',
            $headers,
            ['scheduled_date' => gmdate('Y-m-d\TH:i:s\Z', time() + 120)]
        );
        if ($scheduled['error'] !== '' || ($scheduled['code'] !== 200 && $scheduled['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($scheduled, 'Constant Contact'), 'code' => $scheduled['code']];
        }
        return ['success' => true, 'message' => __('Campaign sent through Constant Contact.', 'traydigita-core'), 'code' => $scheduled['code']];
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
        $clientId = self::setting($settings, 'constantcontact_client_id');
        $clientSecret = self::setting($settings, 'constantcontact_client_secret');
        $result = self::postForm(
            self::TOKEN_URL,
            ['Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret)],
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => self::setting($settings, 'constantcontact_refresh_token'),
            ]
        );
        if ($result['error'] !== '' || $result['code'] !== 200 || !is_array($result['data'])) {
            return '';
        }
        $accessToken = isset($result['data']['access_token']) && is_string($result['data']['access_token']) ? $result['data']['access_token'] : '';
        if ($accessToken === '') {
            return '';
        }
        MailerModule::updateSetting('constantcontact_access_token', $accessToken);
        $refreshToken = isset($result['data']['refresh_token']) && is_string($result['data']['refresh_token']) ? $result['data']['refresh_token'] : '';
        if ($refreshToken !== '') {
            MailerModule::updateSetting('constantcontact_refresh_token', $refreshToken);
        }
        return $accessToken;
    }
}
