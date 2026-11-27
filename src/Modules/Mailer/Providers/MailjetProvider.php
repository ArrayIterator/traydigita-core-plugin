<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function base64_encode;
use function is_array;

/**
 * Mailjet Send API v3.1 provider.
 *
 * Endpoint `POST https://api.mailjet.com/v3.1/send` with HTTP
 * Basic auth (API key as username, secret key as password).
 * A 200 response with `Messages[0].Status === 'success'`
 * means the mail was accepted. The From address must be a
 * validated, active sender in Mailjet.
 *
 * Free tier (Nov 2026): 6,000 emails/month (200/day) - the
 * largest free monthly allowance of the built-in providers.
 * Best for: high-volume free sending with templates and
 * detailed delivery tracking.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MailjetProvider extends AbstractApiProvider
{
    /**
     * Mailjet Send API v3.1 endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.mailjet.com/v3.1/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mailjet',
            'label' => 'Mailjet',
            'description' => __(
                'Mailjet — free 6,000 emails/month (200/day), the largest free monthly allowance here. Best for high-volume free sending with templates and detailed delivery tracking.',
                'traydigita-core'
            ),
            'free_tier' => '6,000/month',
            'best_for' => 'High-volume free sending with templates',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mailjet_api_key',
                    'label' => __('Mailjet API key', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'From Account Settings → REST API → API Key Management in Mailjet. The From address must be a validated, active sender.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'mailjet_secret_key',
                    'label' => __('Mailjet secret key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('From the same Mailjet API Key Management page. Leave empty to keep the saved secret.', 'traydigita-core'),
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
        $apiKey = self::setting($settings, 'mailjet_api_key');
        $secretKey = self::setting($settings, 'mailjet_secret_key');
        if ($apiKey === '' || $secretKey === '') {
            return ['success' => false, 'message' => __('Enter the Mailjet API key and secret key in the Mailer settings first.', 'traydigita-core')];
        }
        $format = static function (array $address): array {
            $entry = ['Email' => $address['email']];
            if ($address['name'] !== '') {
                $entry['Name'] = $address['name'];
            }
            return $entry;
        };
        $message = [
            'From' => $format(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'To' => [],
            'Subject' => $mail['subject'],
        ];
        foreach ($mail['to'] as $address) {
            $message['To'][] = $format($address);
        }
        if ($mail['cc'] !== []) {
            $message['Cc'] = [];
            foreach ($mail['cc'] as $address) {
                $message['Cc'][] = $format($address);
            }
        }
        if ($mail['bcc'] !== []) {
            $message['Bcc'] = [];
            foreach ($mail['bcc'] as $address) {
                $message['Bcc'][] = $format($address);
            }
        }
        if ($mail['is_html']) {
            $message['HTMLPart'] = $mail['body'];
            $message['TextPart'] = self::plainText($mail);
        } else {
            $message['TextPart'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $message['ReplyTo'] = ['Email' => $mail['reply_to']];
        }
        if ($mail['headers'] !== []) {
            $message['Headers'] = $mail['headers'];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $message['Attachments'] = [];
            foreach ($attachments as $attachment) {
                $message['Attachments'][] = [
                    'ContentType' => $attachment['type'],
                    'Filename' => $attachment['filename'],
                    'Base64Content' => $attachment['content'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $secretKey)],
            ['Messages' => [$message]]
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Mailjet')];
        }
        $status = $result['data']['Messages'][0]['Status'] ?? '';
        if ($status !== 'success') {
            $error = '';
            if (is_array($result['data']['Messages'][0]['Errors'] ?? null)) {
                $first = $result['data']['Messages'][0]['Errors'][0] ?? [];
                if (is_array($first) && isset($first['ErrorMessage']) && is_string($first['ErrorMessage'])) {
                    $error = $first['ErrorMessage'];
                }
            }
            return ['success' => false, 'message' => $error !== '' ? $error : __('Mailjet did not accept the message.', 'traydigita-core')];
        }
        return ['success' => true, 'message' => __('Sent through Mailjet.', 'traydigita-core')];
    }
}
