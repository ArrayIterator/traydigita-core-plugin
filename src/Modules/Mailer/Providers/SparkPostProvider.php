<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;

/**
 * SparkPost (Bird Email) Transmissions API provider.
 *
 * Endpoint `POST https://api.sparkpost.com/api/v1/transmissions`
 * with the API key as the `Authorization` header value. A 200
 * response with a `results.id` means the transmission was
 * accepted. Cc / Bcc are sent as extra recipients with
 * `header_to` set to the primary recipient, which is how
 * SparkPost renders them.
 *
 * Free tier verified from the official pricing page (Nov 2026):
 * 1,000 emails/month. Best for: high-volume sending with
 * predictive deliverability analytics (wallet-based pricing).
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SparkPostProvider extends AbstractApiProvider
{
    /**
     * SparkPost transmissions endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.sparkpost.com/api/v1/transmissions';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'sparkpost',
            'label' => 'SparkPost',
            'description' => __(
                'SparkPost — free 1,000 emails/month. Best for high-volume sending with predictive deliverability analytics (wallet-based pricing).',
                'traydigita-core'
            ),
            'free_tier' => '1,000/month',
            'best_for' => 'High-volume sending with deliverability analytics',
            'link' => '',
            'fields' => [
                [
                    'key' => 'sparkpost_api_key',
                    'label' => __('SparkPost API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key with Transmissions: Read/Write permission in the SparkPost (Bird) dashboard. The From address must use a verified domain.',
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
        $apiKey = self::setting($settings, 'sparkpost_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the SparkPost API key in the Mailer settings first.', 'traydigita-core')];
        }
        $primary = self::formatAddress($mail['to'][0]);
        $recipients = [];
        foreach ($mail['to'] as $address) {
            $entry = ['address' => ['email' => $address['email']]];
            if ($address['name'] !== '') {
                $entry['address']['name'] = $address['name'];
            }
            $recipients[] = $entry;
        }
        // Cc / Bcc ride along as extra recipients whose visible
        // To header stays the primary recipient.
        foreach ($mail['cc'] as $address) {
            $recipients[] = ['address' => ['email' => $address['email'], 'header_to' => $primary]];
        }
        foreach ($mail['bcc'] as $address) {
            $recipients[] = ['address' => ['email' => $address['email'], 'header_to' => $primary]];
        }
        $content = [
            'from' => ['email' => $mail['from_email'], 'name' => $mail['from_name']],
            'subject' => $mail['subject'],
        ];
        if ($mail['is_html']) {
            $content['html'] = $mail['body'];
            $content['text'] = self::plainText($mail);
        } else {
            $content['text'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $content['reply_to'] = $mail['reply_to'];
        }
        if ($mail['headers'] !== []) {
            $content['headers'] = $mail['headers'];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $content['attachments'] = [];
            foreach ($attachments as $attachment) {
                $content['attachments'][] = [
                    'name' => $attachment['filename'],
                    'type' => $attachment['type'],
                    'data' => $attachment['content'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => $apiKey],
            [
                'options' => ['transactional' => true],
                'content' => $content,
                'recipients' => $recipients,
            ]
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SparkPost')];
        }
        if (!is_array($result['data']['results'] ?? null) || empty($result['data']['results']['id'])) {
            return ['success' => false, 'message' => __('SparkPost did not accept the message.', 'traydigita-core')];
        }
        return ['success' => true, 'message' => __('Sent through SparkPost.', 'traydigita-core')];
    }
}
