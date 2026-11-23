<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function base64_encode;

/**
 * SMTP.com provider.
 *
 * Endpoint `POST https://api.smtp.com/v4/messages` with the
 * API key as a Bearer token. The body parts are base64-encoded;
 * a plain-text part is always sent, plus an HTML part when the
 * mail is HTML. A 200 response means the mail was accepted.
 *
 * Pricing (Nov 2026): paid, from $25 per 50,000 emails. Best
 * for: high-volume senders who want a simple pay-as-you-go API.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SmtpComProvider extends AbstractApiProvider
{
    /**
     * SMTP.com send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.smtp.com/v4/messages';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'smtpcom',
            'label' => 'SMTP.com',
            'description' => __(
                'SMTP.com — paid, from $25 per 50,000 emails. Best for high-volume senders who want a simple pay-as-you-go API.',
                'traydigita-core'
            ),
            'free_tier' => '',
            'best_for' => 'High-volume pay-as-you-go sending',
            'link' => '',
            'fields' => [
                [
                    'key' => 'smtpcom_api_key',
                    'label' => __('SMTP.com API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in the SMTP.com dashboard (API → Keys). Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'smtpcom_channel',
                    'label' => __('SMTP.com channel', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('The channel name from your SMTP.com account (Channels in the dashboard).', 'traydigita-core'),
                    'secret' => false,
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
        $apiKey = self::setting($settings, 'smtpcom_api_key');
        $channel = self::setting($settings, 'smtpcom_channel');
        if ($apiKey === '' || $channel === '') {
            return ['success' => false, 'message' => __('Enter the SMTP.com API key and channel in the Mailer settings first.', 'traydigita-core')];
        }
        $to = [];
        foreach ($mail['to'] as $address) {
            $to[] = ['address' => $address['email'], 'name' => $address['name']];
        }
        $recipients = ['to' => $to];
        if ($mail['cc'] !== []) {
            $cc = [];
            foreach ($mail['cc'] as $address) {
                $cc[] = ['address' => $address['email'], 'name' => $address['name']];
            }
            $recipients['cc'] = $cc;
        }
        if ($mail['bcc'] !== []) {
            $bcc = [];
            foreach ($mail['bcc'] as $address) {
                $bcc[] = ['address' => $address['email'], 'name' => $address['name']];
            }
            $recipients['bcc'] = $bcc;
        }
        $originator = [
            'from' => ['address' => $mail['from_email'], 'name' => $mail['from_name']],
        ];
        if ($mail['reply_to'] !== '') {
            $originator['reply_to'] = ['address' => $mail['reply_to']];
        }
        $parts = [
            ['type' => 'text/plain', 'content' => base64_encode(self::plainText($mail))],
        ];
        if ($mail['is_html']) {
            $parts[] = ['type' => 'text/html', 'content' => base64_encode($mail['body'])];
        }
        $payload = [
            'channel' => $channel,
            'recipients' => $recipients,
            'originator' => $originator,
            'subject' => $mail['subject'],
            'body' => ['parts' => $parts],
        ];
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $apiKey],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SMTP.com')];
        }
        return ['success' => true, 'message' => __('Sent through SMTP.com.', 'traydigita-core')];
    }
}
