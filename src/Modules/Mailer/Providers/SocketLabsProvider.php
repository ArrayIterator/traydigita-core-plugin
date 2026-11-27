<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;

/**
 * SocketLabs provider.
 *
 * Endpoint `POST https://inject.socketlabs.com/api/v1/email`
 * with the server ID and API key inside the JSON body. The
 * response carries an `ErrorCode` of `Success` when the mail
 * was accepted.
 *
 * Pricing (Nov 2026): no free plan - the first month is free
 * (40,000 emails), then from $39.95/month. Best for: high
 * deliverability with detailed analytics and dedicated IPs.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SocketLabsProvider extends AbstractApiProvider
{
    /**
     * SocketLabs injection endpoint: the only host contacted.
     */
    public const string API_URL = 'https://inject.socketlabs.com/api/v1/email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'socketlabs',
            'label' => 'SocketLabs',
            'description' => __(
                'SocketLabs — no free plan (first month free, 40,000 emails, then from $39.95/month). Best for high deliverability with detailed analytics and dedicated IPs.',
                'traydigita-core'
            ),
            'free_tier' => '',
            'best_for' => 'High deliverability with detailed analytics',
            'link' => '',
            'fields' => [
                [
                    'key' => 'socketlabs_server_id',
                    'label' => __('SocketLabs server ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('The server ID of the SocketLabs server the mail is sent through (Servers in the SocketLabs dashboard).', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'socketlabs_api_key',
                    'label' => __('SocketLabs API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('Create an API key for the server in the SocketLabs dashboard. Leave empty to keep the saved key.', 'traydigita-core'),
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
        $serverId = self::setting($settings, 'socketlabs_server_id');
        $apiKey = self::setting($settings, 'socketlabs_api_key');
        if ($serverId === '' || $apiKey === '') {
            return ['success' => false, 'message' => __('Enter the SocketLabs server ID and API key in the Mailer settings first.', 'traydigita-core')];
        }
        $to = [];
        foreach ($mail['to'] as $address) {
            $to[] = ['emailAddress' => $address['email'], 'friendlyName' => $address['name']];
        }
        $message = [
            'To' => $to,
            'From' => ['emailAddress' => $mail['from_email'], 'friendlyName' => $mail['from_name']],
            'Subject' => $mail['subject'],
        ];
        if ($mail['is_html']) {
            $message['HtmlBody'] = $mail['body'];
            $message['TextBody'] = self::plainText($mail);
        } else {
            $message['TextBody'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $message['ReplyTo'] = ['emailAddress' => $mail['reply_to'], 'friendlyName' => ''];
        }
        if ($mail['cc'] !== []) {
            $cc = [];
            foreach ($mail['cc'] as $address) {
                $cc[] = ['emailAddress' => $address['email'], 'friendlyName' => $address['name']];
            }
            $message['Cc'] = $cc;
        }
        if ($mail['bcc'] !== []) {
            $bcc = [];
            foreach ($mail['bcc'] as $address) {
                $bcc[] = ['emailAddress' => $address['email'], 'friendlyName' => $address['name']];
            }
            $message['Bcc'] = $bcc;
        }
        if ($mail['headers'] !== []) {
            $message['CustomHeaders'] = [];
            foreach ($mail['headers'] as $name => $value) {
                $message['CustomHeaders'][] = ['Name' => $name, 'Value' => $value];
            }
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $message['Attachments'] = [];
            foreach ($attachments as $attachment) {
                $message['Attachments'][] = [
                    'Name' => $attachment['filename'],
                    'ContentType' => $attachment['type'],
                    'Content' => $attachment['content'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            [],
            [
                'serverId' => $serverId,
                'APIKey' => $apiKey,
                'Messages' => [$message],
            ]
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SocketLabs')];
        }
        $data = $result['data'];
        if (is_array($data) && isset($data['ErrorCode']) && $data['ErrorCode'] !== 'Success') {
            $reason = isset($data['Message']) && is_string($data['Message']) && $data['Message'] !== ''
                ? $data['Message']
                : self::apiErrorMessage($result, 'SocketLabs');
            return ['success' => false, 'message' => $reason];
        }
        return ['success' => true, 'message' => __('Sent through SocketLabs.', 'traydigita-core')];
    }
}
