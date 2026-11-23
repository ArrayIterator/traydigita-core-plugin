<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * SendGrid v3 API provider.
 *
 * Endpoint `POST https://api.sendgrid.com/v3/mail/send` with
 * the API key as the Bearer token. SendGrid answers 202
 * Accepted for a queued mail. Sends one mail per call; the
 * caller (MailerModule::maybeSendViaApi()) already normalized
 * the recipients, subject, body and attachments.
 *
 * Attachments are read from disk and base64-encoded into the
 * JSON payload by the shared AbstractApiProvider helper.
 * Files over 10 MB each (or 25 MB in total) are refused with
 * a clear error instead of building a payload SendGrid would
 * reject.
 *
 * Free tier: 60-day free trial (100/day), then paid. Best for:
 * teams already on Twilio with high-volume transactional and
 * marketing mail.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SendGridProvider extends AbstractApiProvider
{
    /**
     * SendGrid v3 mail-send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.sendgrid.com/v3/mail/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'sendgrid',
            'label' => 'SendGrid',
            'description' => __(
                'SendGrid — 60-day free trial (100/day), then paid. Best for teams already on Twilio with high-volume transactional and marketing mail.',
                'traydigita-core'
            ),
            'free_tier' => '60-day trial',
            'best_for' => 'Teams already on Twilio, high volume',
            'link' => '',
            'fields' => [
                [
                    'key' => 'sendgrid_api_key',
                    'label' => __('SendGrid API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a key with Mail Send permission in the SendGrid dashboard. Leave empty to keep the saved key. The key is never shown.',
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
        $apiKey = self::setting($settings, 'sendgrid_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the SendGrid API key in the Mailer settings first.', 'traydigita-core')];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        $personalization = ['to' => self::addressObjects($mail['to'])];
        if ($mail['cc'] !== []) {
            $personalization['cc'] = self::addressObjects($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $personalization['bcc'] = self::addressObjects($mail['bcc']);
        }
        if (($mail['headers'] ?? []) !== []) {
            $personalization['headers'] = $mail['headers'];
        }
        $from = ['email' => $mail['from_email']];
        if ($mail['from_name'] !== '') {
            $from['name'] = $mail['from_name'];
        }
        $payload = [
            'personalizations' => [$personalization],
            'from' => $from,
            'subject' => $mail['subject'],
            'content' => [
                [
                    'type' => $mail['is_html'] ? 'text/html' : 'text/plain',
                    'value' => $mail['body'],
                ],
            ],
        ];
        if (($mail['reply_to'] ?? '') !== '') {
            $payload['reply_to'] = ['email' => $mail['reply_to']];
        }
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        $result = self::postJson(
            self::API_URL,
            ['Authorization' => 'Bearer ' . $apiKey],
            $payload
        );
        // SendGrid answers 202 Accepted for a queued mail.
        if ($result['error'] !== '' || $result['code'] !== 202) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'SendGrid')];
        }
        return ['success' => true, 'message' => __('Sent through SendGrid.', 'traydigita-core')];
    }
}
