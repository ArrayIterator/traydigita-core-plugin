<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Resend transactional email API provider.
 *
 * Endpoint `POST https://api.resend.com/emails` with a Bearer
 * API key. A 200 response means the mail was accepted. The
 * From address must use a verified domain (or the built-in
 * test address for development).
 *
 * Free tier (Nov 2026): 3,000 emails/month (100/day). Best
 * for: developers who want the simplest possible API with
 * excellent deliverability.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class ResendProvider extends AbstractApiProvider
{
    /**
     * Resend send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.resend.com/emails';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'resend',
            'label' => 'Resend',
            'description' => __(
                'Resend — free 3,000 emails/month (100/day). Best for developers who want the simplest possible API with excellent deliverability.',
                'traydigita-core'
            ),
            'free_tier' => '3,000/month',
            'best_for' => 'Simple developer-first API',
            'link' => '',
            'fields' => [
                [
                    'key' => 'resend_api_key',
                    'label' => __('Resend API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a key with sending-only access in the Resend dashboard (keys start with re_). The From address must use a verified domain.',
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
        $apiKey = self::setting($settings, 'resend_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Resend API key in the Mailer settings first.', 'traydigita-core')];
        }
        $payload = [
            'from' => self::formatAddress(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'to' => self::formatAddressList($mail['to']),
            'subject' => $mail['subject'],
        ];
        if ($mail['cc'] !== []) {
            $payload['cc'] = self::formatAddressList($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $payload['bcc'] = self::formatAddressList($mail['bcc']);
        }
        if ($mail['is_html']) {
            $payload['html'] = $mail['body'];
            $payload['text'] = self::plainText($mail);
        } else {
            $payload['text'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $payload['reply_to'] = $mail['reply_to'];
        }
        if ($mail['headers'] !== []) {
            $payload['headers'] = $mail['headers'];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['attachments'][] = [
                    'filename' => $attachment['filename'],
                    'content' => $attachment['content'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $apiKey],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Resend')];
        }
        return ['success' => true, 'message' => __('Sent through Resend.', 'traydigita-core')];
    }
}
