<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function base64_encode;
use function count;
use function is_array;

/**
 * Campaign Monitor provider (classic transactional email).
 *
 * Endpoint `POST https://api.createsend.com/api/v3.3/transactional/classicEmail/send`
 * with the API key as the HTTP Basic username (password `x`).
 * Unlike the smart-email endpoint, classic email takes the
 * whole message content in the request (`Subject`, `From`,
 * `ReplyTo`, `To`, `CC`, `BCC`, `Html`, `Text`), so it is a
 * genuine single-email send. A 202 response is an array with
 * one entry per recipient; the send counts as a success only
 * when every entry is `Accepted`. At most 25 recipients may
 * be sent to in one call.
 *
 * Pricing (Nov 2026): trial only, no free plan. Best for:
 * transactional mail for teams already running campaigns on
 * Campaign Monitor.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class CampaignMonitorProvider extends AbstractApiProvider
{
    /**
     * Campaign Monitor classic-email send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.createsend.com/api/v3.3/transactional/classicEmail/send';

    /**
     * Most recipients allowed in one classic-email call.
     */
    public const int MAX_RECIPIENTS = 25;

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'campaignmonitor',
            'label' => 'Campaign Monitor',
            'description' => __(
                'Campaign Monitor — trial only, no free plan. Genuine single-email send (classic transactional email) with your own HTML content. Best for transactional mail for teams already running campaigns on Campaign Monitor.',
                'traydigita-core'
            ),
            'free_tier' => 'Trial only',
            'best_for' => 'Transactional mail for Campaign Monitor teams',
            'link' => '',
            'fields' => [
                [
                    'key' => 'campaignmonitor_api_key',
                    'label' => __('Campaign Monitor API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in Campaign Monitor (Account Settings → API Keys). Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'campaignmonitor_client_id',
                    'label' => __('Campaign Monitor client ID (optional)', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'Only needed when using an account API key: the client the mail is sent for. Leave empty when using a client API key.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => false,
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
        $apiKey = self::setting($settings, 'campaignmonitor_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Campaign Monitor API key in the Mailer settings first.', 'traydigita-core')];
        }
        $recipientCount = count($mail['to']) + count($mail['cc']) + count($mail['bcc']);
        if ($recipientCount > self::MAX_RECIPIENTS) {
            return ['success' => false, 'message' => __('Campaign Monitor classic email accepts at most 25 recipients per mail.', 'traydigita-core')];
        }
        $payload = [
            'Subject' => $mail['subject'],
            'From' => self::formatAddress(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'To' => self::formatAddressList($mail['to']),
            'Html' => $mail['is_html'] ? $mail['body'] : '',
            'Text' => self::plainText($mail),
            'ConsentToTrack' => 'Yes',
        ];
        if ($mail['reply_to'] !== '') {
            $payload['ReplyTo'] = $mail['reply_to'];
        }
        if ($mail['cc'] !== []) {
            $payload['CC'] = self::formatAddressList($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $payload['BCC'] = self::formatAddressList($mail['bcc']);
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['Attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['Attachments'][] = [
                    'Type' => $attachment['type'],
                    'Name' => $attachment['filename'],
                    'Content' => $attachment['content'],
                ];
            }
        }
        $url = self::API_URL;
        $clientId = self::setting($settings, 'campaignmonitor_client_id');
        if ($clientId !== '') {
            $url .= '?clientID=' . $clientId;
        }
        $result = self::postJson(
            $url,
            ['Authorization' => 'Basic ' . base64_encode($apiKey . ':x')],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 202) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Campaign Monitor')];
        }
        $data = $result['data'];
        if (!is_array($data) || $data === []) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Campaign Monitor')];
        }
        foreach ($data as $entry) {
            if (!is_array($entry) || ($entry['Status'] ?? '') !== 'Accepted') {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'Campaign Monitor')];
            }
        }
        return ['success' => true, 'message' => __('Sent through Campaign Monitor.', 'traydigita-core')];
    }
}
