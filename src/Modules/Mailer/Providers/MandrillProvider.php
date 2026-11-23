<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function in_array;
use function is_array;

/**
 * Mailchimp Transactional (Mandrill) provider.
 *
 * Endpoint `POST https://mandrillapp.com/api/1.0/messages/send.json`
 * with the API key inside the JSON body. The response is an
 * array with one entry per recipient; the send counts as a
 * success only when every entry is `sent` or `queued`.
 *
 * Pricing (Nov 2026): paid only, no free tier. Best for:
 * teams already on Mailchimp who want transactional mail on
 * the same account.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MandrillProvider extends AbstractApiProvider
{
    /**
     * Mandrill send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://mandrillapp.com/api/1.0/messages/send.json';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mandrill',
            'label' => 'Mailchimp Transactional (Mandrill)',
            'description' => __(
                'Mailchimp Transactional (Mandrill) — paid only, no free tier. Best for teams already on Mailchimp who want transactional mail on the same account.',
                'traydigita-core'
            ),
            'free_tier' => '',
            'best_for' => 'Teams already on Mailchimp',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mandrill_api_key',
                    'label' => __('Mandrill API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a key in the Mailchimp Transactional dashboard (Settings → API Keys). Leave empty to keep the saved key.',
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
        $apiKey = self::setting($settings, 'mandrill_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Mandrill API key in the Mailer settings first.', 'traydigita-core')];
        }
        $to = [];
        foreach ($mail['to'] as $address) {
            $to[] = ['email' => $address['email'], 'name' => $address['name'], 'type' => 'to'];
        }
        foreach ($mail['cc'] as $address) {
            $to[] = ['email' => $address['email'], 'name' => $address['name'], 'type' => 'cc'];
        }
        foreach ($mail['bcc'] as $address) {
            $to[] = ['email' => $address['email'], 'name' => $address['name'], 'type' => 'bcc'];
        }
        $message = [
            'from_email' => $mail['from_email'],
            'from_name' => $mail['from_name'],
            'to' => $to,
            'subject' => $mail['subject'],
            'headers' => $mail['headers'],
        ];
        if ($mail['is_html']) {
            $message['html'] = $mail['body'];
            $message['text'] = self::plainText($mail);
        } else {
            $message['text'] = $mail['body'];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $message['attachments'] = [];
            foreach ($attachments as $attachment) {
                $message['attachments'][] = [
                    'type' => $attachment['type'],
                    'name' => $attachment['filename'],
                    'content' => $attachment['content'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            [],
            ['key' => $apiKey, 'message' => $message]
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Mandrill')];
        }
        $data = $result['data'];
        if (!is_array($data) || $data === []) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Mandrill')];
        }
        foreach ($data as $entry) {
            if (!is_array($entry) || !isset($entry['status']) || !in_array($entry['status'], ['sent', 'queued'], true)) {
                $reason = is_array($entry) && isset($entry['reject_reason']) && is_string($entry['reject_reason']) ? $entry['reject_reason'] : '';
                return [
                    'success' => false,
                    'message' => $reason !== ''
                        ? $reason
                        : self::apiErrorMessage($result, 'Mandrill'),
                ];
            }
        }
        return ['success' => true, 'message' => __('Sent through Mailchimp Transactional (Mandrill).', 'traydigita-core')];
    }
}
