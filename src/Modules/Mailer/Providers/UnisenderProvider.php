<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Unisender One (UniOne) transactional API provider.
 *
 * Endpoint `POST https://api.unione.io/en/transactional/api/v1/email/send.json`
 * with the API key in the `X-API-KEY` header. A 200 response
 * means the mail was accepted.
 *
 * Pricing (Nov 2026): a free plan exists, but the monthly
 * limits were not verified against Unisender's pricing page,
 * so no number is claimed here. Best for: transactional mail
 * with delivery tracking on Unisender's infrastructure.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class UnisenderProvider extends AbstractApiProvider
{
    /**
     * UniOne transactional send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.unione.io/en/transactional/api/v1/email/send.json';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'unisender',
            'label' => 'Unisender',
            'description' => __(
                'Unisender — free plan available (limits not verified — check Unisender pricing). Best for transactional mail with delivery tracking on Unisender infrastructure.',
                'traydigita-core'
            ),
            'free_tier' => 'Free plan',
            'best_for' => 'Transactional mail with delivery tracking',
            'link' => '',
            'fields' => [
                [
                    'key' => 'unisender_api_key',
                    'label' => __('Unisender API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in the Unisender dashboard (Settings → API Keys). Leave empty to keep the saved key.',
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
        $apiKey = self::setting($settings, 'unisender_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Unisender API key in the Mailer settings first.', 'traydigita-core')];
        }
        $recipients = [];
        foreach ($mail['to'] as $address) {
            $recipients[] = ['email' => $address['email']];
        }
        $message = [
            'recipients' => $recipients,
            'from_email' => $mail['from_email'],
            'from_name' => $mail['from_name'],
            'subject' => $mail['subject'],
            'body' => [
                'plaintext' => self::plainText($mail),
            ],
        ];
        if ($mail['is_html']) {
            $message['body']['html'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $message['reply_to'] = $mail['reply_to'];
        }
        if ($mail['cc'] !== []) {
            $cc = [];
            foreach ($mail['cc'] as $address) {
                $cc[] = ['email' => $address['email']];
            }
            $message['cc'] = $cc;
        }
        if ($mail['bcc'] !== []) {
            $bcc = [];
            foreach ($mail['bcc'] as $address) {
                $bcc[] = ['email' => $address['email']];
            }
            $message['bcc'] = $bcc;
        }
        if ($mail['headers'] !== []) {
            $message['headers'] = $mail['headers'];
        }
        $result = self::postJson(
            self::API_URL,
            ['X-API-KEY' => $apiKey],
            ['message' => $message]
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Unisender')];
        }
        return ['success' => true, 'message' => __('Sent through Unisender.', 'traydigita-core')];
    }
}
