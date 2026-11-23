<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Brevo (formerly Sendinblue) transactional email API provider.
 *
 * Endpoint `POST https://api.brevo.com/v3/smtp/email` with the
 * API key in the `api-key` header. A 201 response means the
 * mail was accepted.
 *
 * Free tier (Nov 2026): 300 emails/day (~9,000/month). Best
 * for: transactional mail plus marketing campaigns and CRM on
 * the same account.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class BrevoProvider extends AbstractApiProvider
{
    /**
     * Brevo transactional send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.brevo.com/v3/smtp/email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'brevo',
            'label' => 'Brevo',
            'description' => __(
                'Brevo — free 300 emails/day (about 9,000/month). Best for transactional mail plus marketing campaigns and CRM on the same account.',
                'traydigita-core'
            ),
            'free_tier' => '300/day',
            'best_for' => 'Transactional mail + marketing campaigns and CRM',
            'link' => '',
            'fields' => [
                [
                    'key' => 'brevo_api_key',
                    'label' => __('Brevo API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a key under Settings → SMTP & API → API keys in Brevo (keys start with xkeysib-). The From address must be a verified sender.',
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
        $apiKey = self::setting($settings, 'brevo_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Brevo API key in the Mailer settings first.', 'traydigita-core')];
        }
        $payload = [
            'sender' => ['name' => $mail['from_name'], 'email' => $mail['from_email']],
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
            $payload['htmlContent'] = $mail['body'];
            $payload['textContent'] = self::plainText($mail);
        } else {
            $payload['textContent'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $payload['replyTo'] = ['email' => $mail['reply_to']];
        }
        if ($mail['headers'] !== []) {
            $payload['headers'] = $mail['headers'];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['attachment'] = [];
            foreach ($attachments as $attachment) {
                $payload['attachment'][] = ['content' => $attachment['content'], 'name' => $attachment['filename']];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['api-key' => $apiKey, 'Accept' => 'application/json'],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 201) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Brevo')];
        }
        return ['success' => true, 'message' => __('Sent through Brevo.', 'traydigita-core')];
    }
}
