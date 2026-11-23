<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;
use function sprintf;

/**
 * MailerLite provider (classic v2 API).
 *
 * MailerLite has no single-email send endpoint: a mail is sent
 * as a campaign to a whole group, in three steps:
 *
 * 1. `POST https://api.mailerlite.com/api/v2/campaigns`
 *    `{name, subject, type: "regular", groups: [group_id]}`
 *    creates a draft campaign.
 * 2. `PUT https://api.mailerlite.com/api/v2/campaigns/{id}`
 *    `{html, plain}` sets the campaign content.
 * 3. `POST https://api.mailerlite.com/api/v2/campaigns/{id}/actions/send`
 *    `{type: 1}` sends the campaign now.
 *
 * The API key travels in the `X-Api-Key` header. The mail's To
 * addresses are ignored: the campaign goes to every subscriber
 * of the configured group, so a test email sends to the whole
 * group — never use it for a test unless that is intended.
 *
 * Free tier (Nov 2026): 1,000 subscribers / 12,000
 * emails/month. Best for: newsletters and marketing campaigns
 * with an easy editor.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MailerLiteProvider extends AbstractApiProvider
{
    /**
     * MailerLite v2 API base: the only host contacted.
     */
    public const string API_BASE = 'https://api.mailerlite.com/api/v2';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mailerlite',
            'label' => 'MailerLite',
            'description' => __(
                'MailerLite — free 1,000 subscribers / 12,000 emails per month. Campaign provider: a mail is sent as a campaign to a whole group, not to the mail’s To addresses — a test email goes to the whole group. Best for newsletters and marketing campaigns with an easy editor.',
                'traydigita-core'
            ),
            'free_tier' => '1,000 subscribers / 12,000/month',
            'best_for' => 'Newsletters and marketing campaigns',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mailerlite_api_key',
                    'label' => __('MailerLite API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in MailerLite (Integrations → Developer API). Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'mailerlite_group_id',
                    'label' => __('MailerLite group ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The numeric ID of the group the campaign is sent to (Subscribers → Groups → your group; the ID is in the URL). Every mail goes to the whole group.',
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
        $apiKey = self::setting($settings, 'mailerlite_api_key');
        $groupId = self::setting($settings, 'mailerlite_group_id');
        if ($apiKey === '' || $groupId === '') {
            return ['success' => false, 'message' => __('Enter the MailerLite API key and group ID in the Mailer settings first.', 'traydigita-core')];
        }
        $headers = ['X-Api-Key' => $apiKey];
        $created = self::postJson(
            self::API_BASE . '/campaigns',
            $headers,
            [
                'name' => sprintf('TrayDigita: %s', $mail['subject']),
                'subject' => $mail['subject'],
                'type' => 'regular',
                'groups' => [$groupId],
            ]
        );
        if ($created['error'] !== '' || ($created['code'] !== 200 && $created['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'MailerLite')];
        }
        $campaignId = is_array($created['data']) && isset($created['data']['id']) ? (string) $created['data']['id'] : '';
        if ($campaignId === '') {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'MailerLite')];
        }
        $content = self::putJson(
            self::API_BASE . '/campaigns/' . $campaignId,
            $headers,
            [
                'html' => $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>',
                'plain' => self::plainText($mail),
            ]
        );
        if ($content['error'] !== '' || ($content['code'] !== 200 && $content['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($content, 'MailerLite')];
        }
        $sent = self::postJson(
            self::API_BASE . '/campaigns/' . $campaignId . '/actions/send',
            $headers,
            ['type' => 1]
        );
        if ($sent['error'] !== '' || ($sent['code'] !== 200 && $sent['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($sent, 'MailerLite')];
        }
        return ['success' => true, 'message' => __('Campaign sent through MailerLite.', 'traydigita-core')];
    }
}
