<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;

/**
 * SendLayer provider.
 *
 * Endpoint `POST https://console.sendlayer.com/api/v1/email`
 * with the API key as a Bearer token. A 200 response with a
 * `MessageID` means the mail was accepted.
 *
 * Pricing (Nov 2026): trial only (about 200 emails), no free
 * plan. Best for: simple transactional sending with a clean
 * dashboard and good deliverability tooling.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SendLayerProvider extends AbstractApiProvider
{
    /**
     * SendLayer send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://console.sendlayer.com/api/v1/email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'sendlayer',
            'label' => 'SendLayer',
            'description' => __(
                'SendLayer — trial only (about 200 emails), no free plan. Best for simple transactional sending with a clean dashboard and good deliverability tooling.',
                'traydigita-core'
            ),
            'free_tier' => 'Trial only',
            'best_for' => 'Simple transactional sending',
            'link' => '',
            'fields' => [
                [
                    'key' => 'sendlayer_api_key',
                    'label' => __('SendLayer API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in the SendLayer dashboard (Settings → API Keys). Leave empty to keep the saved key.',
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
        $apiKey = self::setting($settings, 'sendlayer_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the SendLayer API key in the Mailer settings first.', 'traydigita-core')];
        }
        $payload = [
            'From' => ['name' => $mail['from_name'], 'email' => $mail['from_email']],
            'To' => self::addressObjects($mail['to']),
            'Subject' => $mail['subject'],
            'ContentType' => $mail['is_html'] ? 'HTML' : 'Text',
        ];
        if ($mail['cc'] !== []) {
            $payload['CC'] = self::addressObjects($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $payload['BCC'] = self::addressObjects($mail['bcc']);
        }
        if ($mail['reply_to'] !== '') {
            $payload['ReplyTo'] = [['name' => '', 'email' => $mail['reply_to']]];
        }
        if ($mail['is_html']) {
            $payload['HTMLContent'] = $mail['body'];
            $payload['PlainContent'] = self::plainText($mail);
        } else {
            $payload['PlainContent'] = $mail['body'];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['Attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['Attachments'][] = [
                    'Content' => $attachment['content'],
                    'Name' => $attachment['filename'],
                    'Type' => $attachment['type'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $apiKey],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SendLayer')];
        }
        $data = $result['data'];
        if (!is_array($data) || empty($data['MessageID']) || !is_string($data['MessageID'])) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SendLayer')];
        }
        return ['success' => true, 'message' => __('Sent through SendLayer.', 'traydigita-core')];
    }
}
