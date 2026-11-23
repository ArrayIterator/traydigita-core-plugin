<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function array_merge;

/**
 * Courier provider.
 *
 * Endpoint `POST https://api.courier.com/send` with the API
 * key as a Bearer token. The message targets one recipient at
 * a time, so a mail with several recipients is sent as one
 * Courier call per address (CC and BCC addresses are included
 * in the same per-recipient loop, so no address is ever exposed
 * to another recipient). A 202 response with a `requestId`
 * means the mail was accepted.
 *
 * Pricing (Nov 2026): a free tier exists, but the amount was
 * not verified against Courier's pricing page, so no number is
 * claimed here. Best for: multi-channel notifications (email,
 * SMS, push) from one API.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class CourierProvider extends AbstractApiProvider
{
    /**
     * Courier send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.courier.com/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'courier',
            'label' => 'Courier',
            'description' => __(
                'Courier — free tier available (amount not verified — check Courier pricing). Best for multi-channel notifications (email, SMS, push) from one API.',
                'traydigita-core'
            ),
            'free_tier' => 'Free tier',
            'best_for' => 'Multi-channel notifications from one API',
            'link' => '',
            'fields' => [
                [
                    'key' => 'courier_api_key',
                    'label' => __('Courier API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in the Courier dashboard (Settings → API Keys). Leave empty to keep the saved key.',
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
        $apiKey = self::setting($settings, 'courier_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Courier API key in the Mailer settings first.', 'traydigita-core')];
        }
        $recipients = array_merge($mail['to'], $mail['cc'], $mail['bcc']);
        $content = [
            'title' => $mail['subject'],
            'body' => self::plainText($mail),
        ];
        if ($mail['is_html']) {
            $content['html'] = $mail['body'];
        }
        foreach ($recipients as $recipient) {
            $payload = [
                'message' => [
                    'to' => [
                        'email' => $recipient['email'],
                        'data' => ['name' => $recipient['name']],
                    ],
                    'content' => $content,
                    'routing' => ['method' => 'single', 'channels' => ['email']],
                ],
            ];
            $result = self::postJson(
                self::API_URL,
                ['Authorization' => 'Bearer ' . $apiKey],
                $payload
            );
            if ($result['error'] !== '' || $result['code'] !== 202) {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'Courier')];
            }
        }
        return ['success' => true, 'message' => __('Sent through Courier.', 'traydigita-core')];
    }
}
