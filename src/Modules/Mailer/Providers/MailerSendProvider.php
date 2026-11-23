<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * MailerSend API provider.
 *
 * Endpoint `POST https://api.mailersend.com/v1/email` with a
 * Bearer API token (plus the `X-Requested-With:
 * XMLHttpRequest` header the API expects). A 202 Accepted
 * response means the mail was queued.
 *
 * Free tier (Nov 2026): 500 emails/month (100 API requests a
 * day). Best for: transactional mail with inbound routing,
 * templates, and SMS/WhatsApp on the same account.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MailerSendProvider extends AbstractApiProvider
{
    /**
     * MailerSend send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.mailersend.com/v1/email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mailersend',
            'label' => 'MailerSend',
            'description' => __(
                'MailerSend — free 500 emails/month. Best for transactional mail with inbound routing, templates, and SMS/WhatsApp on the same account.',
                'traydigita-core'
            ),
            'free_tier' => '500/month',
            'best_for' => 'Transactional mail with inbound routing',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mailersend_api_key',
                    'label' => __('MailerSend API token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a token with Email → Full access under Developer tools → API tokens in MailerSend. The From address must use a verified domain.',
                        'traydigita-core'
                    ),
                    'secret' => true,
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
        $apiKey = self::setting($settings, 'mailersend_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the MailerSend API token in the Mailer settings first.', 'traydigita-core')];
        }
        $payload = [
            'from' => ['email' => $mail['from_email'], 'name' => $mail['from_name']],
            'to' => self::addressObjects($mail['to']),
            'subject' => $mail['subject'],
        ];
        if ($mail['cc'] !== []) {
            $payload['cc'] = self::addressObjects($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $payload['bcc'] = self::addressObjects($mail['bcc']);
        }
        if ($mail['is_html']) {
            $payload['html'] = $mail['body'];
            $payload['text'] = self::plainText($mail);
        } else {
            $payload['text'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $payload['reply_to'] = ['email' => $mail['reply_to']];
        }
        if ($mail['headers'] !== []) {
            $payload['headers'] = [];
            foreach ($mail['headers'] as $name => $value) {
                $payload['headers'][] = ['name' => $name, 'value' => $value];
            }
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['attachments'][] = [
                    'content' => $attachment['content'],
                    'filename' => $attachment['filename'],
                    'type' => $attachment['type'],
                    'disposition' => 'attachment',
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 202) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'MailerSend')];
        }
        return ['success' => true, 'message' => __('Sent through MailerSend.', 'traydigita-core')];
    }
}
