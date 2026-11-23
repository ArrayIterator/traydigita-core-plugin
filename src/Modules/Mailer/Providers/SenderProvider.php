<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Sender.net transactional email API provider.
 *
 * Endpoint `POST https://api.sender.net/v2/message/send` with a
 * Bearer API token. The API takes a single `to` object, so a
 * mail with several recipients is sent as one call per
 * recipient; the send only succeeds when every call succeeds.
 * Attachments are URL-only on this API, so local attachment
 * files are not sent (the mail still goes out).
 *
 * Free tier (Nov 2026): 15,000 emails/month, up to 2,500
 * contacts. Best for: newsletters and transactional mail on a
 * generous free allowance.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SenderProvider extends AbstractApiProvider
{
    /**
     * Sender.net transactional send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.sender.net/v2/message/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'sender',
            'label' => 'Sender.net',
            'description' => __(
                'Sender.net — free 15,000 emails/month (up to 2,500 contacts). Best for newsletters and transactional mail on a generous free allowance.',
                'traydigita-core'
            ),
            'free_tier' => '15,000/month',
            'best_for' => 'Newsletters and transactional mail',
            'link' => '',
            'fields' => [
                [
                    'key' => 'sender_api_key',
                    'label' => __('Sender.net API token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a token under Account settings → API access tokens in Sender.net. The From address must use a domain verified in Sender.net.',
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
        $apiKey = self::setting($settings, 'sender_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Sender.net API token in the Mailer settings first.', 'traydigita-core')];
        }
        $payloadBase = [
            'from' => [
                'email' => $mail['from_email'],
                'name' => $mail['from_name'],
            ],
            'subject' => $mail['subject'],
            'text' => self::plainText($mail),
        ];
        if ($mail['is_html']) {
            $payloadBase['html'] = $mail['body'];
        }
        if ($mail['headers'] !== []) {
            $payloadBase['headers'] = $mail['headers'];
        }
        // One call per recipient: the Sender API takes a single
        // `to` object, and Cc / Bcc are not part of this endpoint.
        foreach ($mail['to'] as $recipient) {
            $payload = $payloadBase;
            $payload['to'] = ['email' => $recipient['email'], 'name' => $recipient['name']];
            $result = self::postJson(
                self::API_URL,
                ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                $payload
            );
            if ($result['error'] !== '' || ($result['code'] !== 200 && $result['code'] !== 201 && $result['code'] !== 202)) {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'Sender.net')];
            }
        }
        return ['success' => true, 'message' => __('Sent through Sender.net.', 'traydigita-core')];
    }
}
