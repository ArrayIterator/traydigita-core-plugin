<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function esc_html;
use function is_string;
use function nl2br;
use function str_contains;

/**
 * OneSignal Email provider.
 *
 * Endpoint `POST https://api.onesignal.com/notifications?c=email`
 * with the REST API key as a Bearer token. One call per mail;
 * `email_body` is the HTML body and must contain an
 * `[unsubscribe_url]` link, so one is appended when the body
 * does not already carry it.
 *
 * Free tier (Nov 2026): 10,000 emails/month. Best for: apps
 * already using OneSignal for push, adding email on the same
 * account.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class OneSignalProvider extends AbstractApiProvider
{
    /**
     * OneSignal email notification endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.onesignal.com/notifications?c=email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'onesignal',
            'label' => 'OneSignal Email',
            'description' => __(
                'OneSignal Email — free 10,000 emails/month. Best for apps already using OneSignal for push, adding email on the same account.',
                'traydigita-core'
            ),
            'free_tier' => '10,000/month',
            'best_for' => 'Apps already on OneSignal push',
            'link' => '',
            'fields' => [
                [
                    'key' => 'onesignal_app_id',
                    'label' => __('OneSignal app ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('From Settings → Keys & IDs in the OneSignal dashboard (the "App ID", not the API key).', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'onesignal_api_key',
                    'label' => __('OneSignal REST API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('From Settings → Keys & IDs in the OneSignal dashboard. Leave empty to keep the saved key.', 'traydigita-core'),
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
        $appId = self::setting($settings, 'onesignal_app_id');
        $apiKey = self::setting($settings, 'onesignal_api_key');
        if ($appId === '' || $apiKey === '') {
            return ['success' => false, 'message' => __('Enter the OneSignal app ID and REST API key in the Mailer settings first.', 'traydigita-core')];
        }
        $html = $mail['is_html']
            ? $mail['body']
            : '<p>' . nl2br(esc_html($mail['body'])) . '</p>';
        if (!str_contains($html, '[unsubscribe_url]')) {
            // OneSignal requires an unsubscribe link in the body.
            $html .= '<p><a href="[unsubscribe_url]">' . esc_html(__('Unsubscribe', 'traydigita-core')) . '</a></p>';
        }
        $payload = [
            'app_id' => $appId,
            'email_subject' => $mail['subject'],
            'email_body' => $html,
            'email_to' => self::formatAddressList($mail['to']),
            'email_from_name' => $mail['from_name'],
            'email_from_address' => $mail['from_email'],
            'target_channel' => 'email',
        ];
        if ($mail['reply_to'] !== '') {
            $payload['email_reply_to_address'] = $mail['reply_to'];
        }
        if ($mail['bcc'] !== []) {
            $payload['email_bcc'] = self::formatAddressList($mail['bcc']);
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $apiKey],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'OneSignal')];
        }
        $data = $result['data'];
        if (!is_array($data) || empty($data['id']) || !is_string($data['id'])) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'OneSignal')];
        }
        return ['success' => true, 'message' => __('Sent through OneSignal Email.', 'traydigita-core')];
    }
}
