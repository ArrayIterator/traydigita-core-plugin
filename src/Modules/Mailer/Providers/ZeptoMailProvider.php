<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * ZeptoMail (Zoho CPaaS) Email API v1.1 provider.
 *
 * Endpoint `POST https://api.zeptomail.com/v1.1/email` with the
 * API key in the `Authorization: Zoho-enczapikey <key>` header.
 * A 201 response means the mail was accepted. ZeptoMail is
 * transactional-only: no bulk or marketing mail.
 *
 * Free tier verified from Zoho's pricing (Nov 2026): the first
 * 10,000-email credit is free (one-time, credits valid 6
 * months), then $2.50 per 10,000 emails, pay as you go. Best
 * for: low-volume transactional mail and sites already in the
 * Zoho ecosystem.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class ZeptoMailProvider extends AbstractApiProvider
{
    /**
     * ZeptoMail send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.zeptomail.com/v1.1/email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'zeptomail',
            'label' => 'ZeptoMail',
            'description' => __(
                'ZeptoMail — first 10,000-email credit free (one-time), then $2.50 per 10,000 emails. Best for low-volume transactional mail and sites already in the Zoho ecosystem.',
                'traydigita-core'
            ),
            'free_tier' => 'First 10,000 free',
            'best_for' => 'Low-volume transactional mail (Zoho ecosystem)',
            'link' => '',
            'fields' => [
                [
                    'key' => 'zeptomail_api_key',
                    'label' => __('ZeptoMail API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'From the ZeptoMail dashboard (Mail Agents → API). The From address must be a verified sender. ZeptoMail is transactional-only.',
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
        $apiKey = self::setting($settings, 'zeptomail_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the ZeptoMail API key in the Mailer settings first.', 'traydigita-core')];
        }
        $address = static function (array $item): array {
            $entry = ['address' => $item['email']];
            if ($item['name'] !== '') {
                $entry['name'] = $item['name'];
            }
            return $entry;
        };
        $to = [];
        foreach ($mail['to'] as $recipient) {
            $to[] = ['email_address' => $address($recipient)];
        }
        $payload = [
            'from' => $address(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'to' => $to,
            'subject' => $mail['subject'],
        ];
        if ($mail['cc'] !== []) {
            $payload['cc'] = [];
            foreach ($mail['cc'] as $recipient) {
                $payload['cc'][] = ['email_address' => $address($recipient)];
            }
        }
        if ($mail['bcc'] !== []) {
            $payload['bcc'] = [];
            foreach ($mail['bcc'] as $recipient) {
                $payload['bcc'][] = ['email_address' => $address($recipient)];
            }
        }
        if ($mail['is_html']) {
            $payload['htmlbody'] = $mail['body'];
            $payload['textbody'] = self::plainText($mail);
        } else {
            $payload['textbody'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $payload['reply_to'] = [['address' => $mail['reply_to']]];
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
                    'name' => $attachment['filename'],
                    'mime_type' => $attachment['type'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Zoho-enczapikey ' . $apiKey],
            $payload
        );
        if ($result['error'] !== '' || ($result['code'] !== 200 && $result['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'ZeptoMail')];
        }
        return ['success' => true, 'message' => __('Sent through ZeptoMail.', 'traydigita-core')];
    }
}
