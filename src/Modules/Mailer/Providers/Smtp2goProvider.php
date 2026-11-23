<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;

/**
 * SMTP2GO API v3 provider.
 *
 * Endpoint `POST https://api.smtp2go.com/v3/email/send` with
 * the API key in the `X-Smtp2go-Api-Key` header. Note the API
 * answers 200 even when individual recipients fail, so the
 * response is only a success when `data.failed` is 0.
 *
 * Free tier (Nov 2026): about 1,000 emails/month. Best for:
 * small senders who want simple pricing and per-key
 * permissions.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class Smtp2goProvider extends AbstractApiProvider
{
    /**
     * SMTP2GO send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.smtp2go.com/v3/email/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'smtp2go',
            'label' => 'SMTP2GO',
            'description' => __(
                'SMTP2GO — free about 1,000 emails/month. Best for small senders who want simple pricing and per-key permissions.',
                'traydigita-core'
            ),
            'free_tier' => '1,000/month',
            'best_for' => 'Simple pricing and per-key permissions',
            'link' => '',
            'fields' => [
                [
                    'key' => 'smtp2go_api_key',
                    'label' => __('SMTP2GO API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key (scoped to /email/send) in the SMTP2GO dashboard. The From address must be a verified sender.',
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
        $apiKey = self::setting($settings, 'smtp2go_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the SMTP2GO API key in the Mailer settings first.', 'traydigita-core')];
        }
        $emails = [];
        foreach ($mail['to'] as $address) {
            $emails[] = $address['email'];
        }
        $payload = [
            'sender' => self::formatAddress(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'to' => $emails,
            'subject' => $mail['subject'],
        ];
        if ($mail['cc'] !== []) {
            $cc = [];
            foreach ($mail['cc'] as $address) {
                $cc[] = $address['email'];
            }
            $payload['cc'] = $cc;
        }
        if ($mail['bcc'] !== []) {
            $bcc = [];
            foreach ($mail['bcc'] as $address) {
                $bcc[] = $address['email'];
            }
            $payload['bcc'] = $bcc;
        }
        if ($mail['is_html']) {
            $payload['html_body'] = $mail['body'];
            $payload['text_body'] = self::plainText($mail);
        } else {
            $payload['text_body'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $payload['custom_headers'] = [['header' => 'Reply-To', 'value' => $mail['reply_to']]];
        }
        foreach ($mail['headers'] as $name => $value) {
            $payload['custom_headers'][] = ['header' => $name, 'value' => $value];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['attachments'][] = [
                    'file_name' => $attachment['filename'],
                    'file_blob' => $attachment['content'],
                    'mime_type' => $attachment['type'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['X-Smtp2go-Api-Key' => $apiKey, 'Accept' => 'application/json'],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SMTP2GO')];
        }
        // SMTP2GO answers 200 even when recipients fail: only a
        // zero `failed` count is a success.
        $data = is_array($result['data']['data'] ?? null) ? $result['data']['data'] : [];
        $failed = (int) ($data['failed'] ?? 0);
        if ($failed > 0) {
            $failures = is_array($data['failures'] ?? null) ? $data['failures'] : [];
            $first = is_array($failures[0] ?? null) ? $failures[0] : [];
            $message = '';
            if (isset($first['error']) && is_string($first['error']) && $first['error'] !== '') {
                $message = $first['error'];
            } elseif (isset($first['reason']) && is_string($first['reason']) && $first['reason'] !== '') {
                $message = $first['reason'];
            }
            return ['success' => false, 'message' => $message !== '' ? $message : __('SMTP2GO rejected one or more recipients.', 'traydigita-core')];
        }
        return ['success' => true, 'message' => __('Sent through SMTP2GO.', 'traydigita-core')];
    }
}
