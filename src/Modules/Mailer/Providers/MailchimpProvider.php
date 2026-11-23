<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function base64_encode;
use function end;
use function explode;
use function is_array;
use function is_string;

/**
 * Mailchimp (Marketing API) provider.
 *
 * Mailchimp has no single-email send endpoint: a mail is sent
 * as a campaign to a whole list, in three steps:
 *
 * 1. `POST https://{dc}.api.mailchimp.com/3.0/campaigns`
 *    `{type: "regular", recipients: {list_id}, settings:
 *    {subject_line, from_name, reply_to}}` creates a draft.
 * 2. `PUT https://{dc}.api.mailchimp.com/3.0/campaigns/{id}/content`
 *    `{html, plain_text}` sets the campaign content.
 * 3. `POST https://{dc}.api.mailchimp.com/3.0/campaigns/{id}/actions/send`
 *    sends the campaign now.
 *
 * Authentication is HTTP Basic (any username, the API key as
 * the password); the data center (`dc`) is the suffix after
 * the `-` in the API key. The mail's To addresses are ignored:
 * the campaign goes to every subscriber of the configured
 * list, so a test email sends to the whole list — never use it
 * for a test unless that is intended.
 *
 * Free tier (Nov 2026): 500 contacts / 1,000 emails per
 * month. Best for: marketing campaigns and audiences already
 * managed in Mailchimp.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MailchimpProvider extends AbstractApiProvider
{
    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mailchimp',
            'label' => 'Mailchimp',
            'description' => __(
                'Mailchimp — free 500 contacts / 1,000 emails per month. Campaign provider: a mail is sent as a campaign to a whole list, not to the mail’s To addresses — a test email goes to the whole list. Best for marketing campaigns and audiences already managed in Mailchimp.',
                'traydigita-core'
            ),
            'free_tier' => '500 contacts / 1,000/month',
            'best_for' => 'Marketing campaigns and Mailchimp audiences',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mailchimp_api_key',
                    'label' => __('Mailchimp API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in Mailchimp (Account → Extras → API keys). The key ends with a data-center suffix such as -us21. Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'mailchimp_list_id',
                    'label' => __('Mailchimp audience (list) ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The audience ID the campaign is sent to (Audience → Settings → Audience name and defaults). Every mail goes to the whole audience.',
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
        $apiKey = self::setting($settings, 'mailchimp_api_key');
        $listId = self::setting($settings, 'mailchimp_list_id');
        if ($apiKey === '' || $listId === '') {
            return ['success' => false, 'message' => __('Enter the Mailchimp API key and audience ID in the Mailer settings first.', 'traydigita-core')];
        }
        $parts = explode('-', $apiKey);
        $dataCenter = end($parts);
        if ($dataCenter === '' || $dataCenter === $apiKey) {
            return ['success' => false, 'message' => __('The Mailchimp API key does not contain a data-center suffix (for example -us21).', 'traydigita-core')];
        }
        $base = 'https://' . $dataCenter . '.api.mailchimp.com/3.0';
        $headers = ['Authorization' => 'Basic ' . base64_encode('traydigita:' . $apiKey)];
        $create = self::postJson(
            $base . '/campaigns',
            $headers,
            [
                'type' => 'regular',
                'recipients' => ['list_id' => $listId],
                'settings' => [
                    'subject_line' => $mail['subject'],
                    'from_name' => $mail['from_name'],
                    'reply_to' => $mail['reply_to'] !== '' ? $mail['reply_to'] : $mail['from_email'],
                ],
            ]
        );
        if ($create['error'] !== '' || ($create['code'] !== 200 && $create['code'] !== 201)) {
            return ['success' => false, 'message' => self::mailchimpError($create)];
        }
        $campaignId = is_array($create['data']) && isset($create['data']['id']) ? (string) $create['data']['id'] : '';
        if ($campaignId === '') {
            return ['success' => false, 'message' => self::mailchimpError($create)];
        }
        $content = self::putJson(
            $base . '/campaigns/' . $campaignId . '/content',
            $headers,
            [
                'html' => $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>',
                'plain_text' => self::plainText($mail),
            ]
        );
        if ($content['error'] !== '' || ($content['code'] !== 200 && $content['code'] !== 201)) {
            return ['success' => false, 'message' => self::mailchimpError($content)];
        }
        $sent = self::postNoBody(
            $base . '/campaigns/' . $campaignId . '/actions/send',
            $headers
        );
        if ($sent['error'] !== '' || ($sent['code'] !== 200 && $sent['code'] !== 204)) {
            return ['success' => false, 'message' => self::mailchimpError($sent)];
        }
        return ['success' => true, 'message' => __('Campaign sent through Mailchimp.', 'traydigita-core')];
    }

    /**
     * Mailchimp error text: its API reports the detail in a
     * `detail` field, which the shared error helper does not
     * read, so prefer it here.
     *
     * @param array{code: int, body: string, data: array<string, mixed>|null, error: string} $result unpackResponse() result.
     * @return string
     */
    private static function mailchimpError(array $result): string
    {
        $data = $result['data'];
        if (is_array($data) && isset($data['detail']) && is_string($data['detail']) && $data['detail'] !== '') {
            return $data['detail'];
        }
        return self::apiErrorMessage($result, 'Mailchimp');
    }
}
