<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Mailtrap Email API provider.
 *
 * Endpoint `POST https://send.api.mailtrap.io/api/send` with
 * the API token as a Bearer token. A 200 response means the
 * mail was accepted.
 *
 * Free tier (Nov 2026): 4,000 emails/month. Best for:
 * transactional mail with a built-in sandbox for testing
 * before going live.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MailtrapProvider extends AbstractApiProvider
{
    /**
     * Mailtrap send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://send.api.mailtrap.io/api/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mailtrap',
            'label' => 'Mailtrap',
            'description' => __(
                'Mailtrap — free 4,000 emails/month. Best for transactional mail with a built-in sandbox for testing before going live.',
                'traydigita-core'
            ),
            'free_tier' => '4,000/month',
            'best_for' => 'Transactional mail with a testing sandbox',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mailtrap_api_token',
                    'label' => __('Mailtrap API token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a token under Sending Domains → your domain → API Tokens in Mailtrap. Leave empty to keep the saved token.',
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
        $token = self::setting($settings, 'mailtrap_api_token');
        if ($token === '') {
            return ['success' => false, 'message' => __('Enter the Mailtrap API token in the Mailer settings first.', 'traydigita-core')];
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
            $payload['reply_to'] = $mail['reply_to'];
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
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Mailtrap')];
        }
        return ['success' => true, 'message' => __('Sent through Mailtrap.', 'traydigita-core')];
    }
}
