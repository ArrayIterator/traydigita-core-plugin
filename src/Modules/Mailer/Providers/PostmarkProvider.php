<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function implode;

/**
 * Postmark Email API provider.
 *
 * Endpoint `POST https://api.postmarkapp.com/email` with the
 * server token in the `X-Postmark-Server-Token` header. A 200
 * response with `ErrorCode === 0` means the mail was accepted.
 * The From address must be a registered and confirmed sender
 * signature (or a verified domain) in Postmark.
 *
 * Free tier (Nov 2026): 100 emails/month. Best for:
 * transactional mail where deliverability is the top priority
 * (separate outbound and broadcast message streams).
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class PostmarkProvider extends AbstractApiProvider
{
    /**
     * Postmark send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.postmarkapp.com/email';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'postmark',
            'label' => 'Postmark',
            'description' => __(
                'Postmark — free 100 emails/month. Best for transactional mail where deliverability is the top priority, with separate outbound and broadcast streams.',
                'traydigita-core'
            ),
            'free_tier' => '100/month',
            'best_for' => 'Maximum deliverability for transactional mail',
            'link' => '',
            'fields' => [
                [
                    'key' => 'postmark_server_token',
                    'label' => __('Postmark server token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'From the API Tokens tab of the Postmark server. The From address must be a registered and confirmed sender signature in Postmark.',
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
        $token = self::setting($settings, 'postmark_server_token');
        if ($token === '') {
            return ['success' => false, 'message' => __('Enter the Postmark server token in the Mailer settings first.', 'traydigita-core')];
        }
        $payload = [
            'From' => self::formatAddress(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'To' => implode(', ', self::formatAddressList($mail['to'])),
            'Subject' => $mail['subject'],
            'MessageStream' => 'outbound',
        ];
        if ($mail['cc'] !== []) {
            $payload['Cc'] = implode(', ', self::formatAddressList($mail['cc']));
        }
        if ($mail['bcc'] !== []) {
            $payload['Bcc'] = implode(', ', self::formatAddressList($mail['bcc']));
        }
        if ($mail['is_html']) {
            $payload['HtmlBody'] = $mail['body'];
            $payload['TextBody'] = self::plainText($mail);
        } else {
            $payload['TextBody'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $payload['ReplyTo'] = $mail['reply_to'];
        }
        if ($mail['headers'] !== []) {
            $payload['Headers'] = [];
            foreach ($mail['headers'] as $name => $value) {
                $payload['Headers'][] = ['Name' => $name, 'Value' => $value];
            }
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        if ($attachments !== []) {
            $payload['Attachments'] = [];
            foreach ($attachments as $attachment) {
                $payload['Attachments'][] = [
                    'Name' => $attachment['filename'],
                    'Content' => $attachment['content'],
                    'ContentType' => $attachment['type'],
                ];
            }
        }
        $result = self::postJson(
            self::API_URL,
            ['X-Postmark-Server-Token' => $token, 'Accept' => 'application/json'],
            $payload
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Postmark')];
        }
        $errorCode = (int) ($result['data']['ErrorCode'] ?? 0);
        if ($errorCode !== 0) {
            $message = (string) ($result['data']['Message'] ?? '');
            return ['success' => false, 'message' => $message !== '' ? $message : __('Postmark did not accept the message.', 'traydigita-core')];
        }
        return ['success' => true, 'message' => __('Sent through Postmark.', 'traydigita-core')];
    }
}
