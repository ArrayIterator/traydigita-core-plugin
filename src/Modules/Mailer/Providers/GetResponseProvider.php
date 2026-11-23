<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function sprintf;

/**
 * GetResponse provider.
 *
 * GetResponse has no single-email send endpoint: a mail is
 * sent as a newsletter to a whole campaign (list) in one call:
 * `POST https://api.getresponse.com/v3/newsletters`
 * `{subject, name, campaign: {campaignId}, content: {html,
 * plain}, fromField: {fromFieldId}, sendSettings:
 * {selectedCampaigns: [campaignId]}}` creates the newsletter
 * and puts it in the queue to send. The API key travels in
 * the `X-Auth-Token: api-key <key>` header.
 *
 * The mail's To addresses are ignored: the newsletter goes to
 * every contact of the configured campaign, so a test email
 * sends to the whole campaign — never use it for a test unless
 * that is intended.
 *
 * Free tier (Nov 2026): 500 contacts. Best for: newsletters
 * and marketing automation on GetResponse.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class GetResponseProvider extends AbstractApiProvider
{
    /**
     * GetResponse newsletters endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.getresponse.com/v3/newsletters';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'getresponse',
            'label' => 'GetResponse',
            'description' => __(
                'GetResponse — free 500 contacts. Campaign provider: a mail is sent as a newsletter to a whole campaign (list), not to the mail’s To addresses — a test email goes to the whole campaign. Best for newsletters and marketing automation on GetResponse.',
                'traydigita-core'
            ),
            'free_tier' => '500 contacts',
            'best_for' => 'Newsletters and marketing automation',
            'link' => '',
            'fields' => [
                [
                    'key' => 'getresponse_api_key',
                    'label' => __('GetResponse API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in GetResponse (Profile → Integrations & API → API). Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'getresponse_campaign_id',
                    'label' => __('GetResponse campaign ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The campaign (list) the newsletter is sent to (Contacts → Lists → your list; the ID is in the URL). Every mail goes to the whole campaign.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'getresponse_from_field_id',
                    'label' => __('GetResponse from-field ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The sender identity (from field) used for the newsletter (Profile → My account → Email addresses → From fields).',
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
        $apiKey = self::setting($settings, 'getresponse_api_key');
        $campaignId = self::setting($settings, 'getresponse_campaign_id');
        $fromFieldId = self::setting($settings, 'getresponse_from_field_id');
        if ($apiKey === '' || $campaignId === '' || $fromFieldId === '') {
            return ['success' => false, 'message' => __('Enter the GetResponse API key, campaign ID, and from-field ID in the Mailer settings first.', 'traydigita-core')];
        }
        $result = self::postJson(
            self::API_URL,
            ['X-Auth-Token' => 'api-key ' . $apiKey],
            [
                'subject' => $mail['subject'],
                'name' => sprintf('TrayDigita: %s', $mail['subject']),
                'campaign' => ['campaignId' => $campaignId],
                'content' => [
                    'html' => $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>',
                    'plain' => self::plainText($mail),
                ],
                'fromField' => ['fromFieldId' => $fromFieldId],
                'sendSettings' => ['selectedCampaigns' => [$campaignId]],
            ]
        );
        if ($result['error'] !== '' || ($result['code'] !== 200 && $result['code'] !== 201 && $result['code'] !== 202)) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'GetResponse')];
        }
        return ['success' => true, 'message' => __('Newsletter queued through GetResponse.', 'traydigita-core')];
    }
}
