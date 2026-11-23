<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function implode;
use function is_array;

/**
 * Elastic Email API v2 provider.
 *
 * Endpoint `POST https://api.elasticemail.com/v2/email/send`
 * with the API key as the `apikey` form field. A 200 response
 * with `success === true` means the mail was accepted.
 *
 * Free tier verified from the official pricing page (Nov 2026):
 * 3,000 emails/month, 100/day. Best for: budget sending at
 * the lowest managed per-email cost.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class ElasticEmailProvider extends AbstractApiProvider
{
    /**
     * Elastic Email v2 send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.elasticemail.com/v2/email/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'elasticemail',
            'label' => 'Elastic Email',
            'description' => __(
                'Elastic Email — free 3,000 emails/month (100/day). Best for budget sending at the lowest managed per-email cost.',
                'traydigita-core'
            ),
            'free_tier' => '3,000/month',
            'best_for' => 'Budget sending at the lowest managed cost',
            'link' => '',
            'fields' => [
                [
                    'key' => 'elasticemail_api_key',
                    'label' => __('Elastic Email API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key under Settings → API in Elastic Email. The From address must use a verified domain.',
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
        $apiKey = self::setting($settings, 'elasticemail_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Elastic Email API key in the Mailer settings first.', 'traydigita-core')];
        }
        $fields = [
            'apikey' => $apiKey,
            'from' => $mail['from_email'],
            'fromName' => $mail['from_name'],
            'to' => implode(',', self::formatAddressList($mail['to'])),
            'subject' => $mail['subject'],
            'isTransactional' => 'true',
        ];
        if ($mail['is_html']) {
            $fields['bodyHtml'] = $mail['body'];
            $fields['bodyText'] = self::plainText($mail);
        } else {
            $fields['bodyText'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $fields['replyTo'] = $mail['reply_to'];
        }
        // Cc / Bcc are not separate v2 parameters; the API
        // accepts them inside the `to` list as `cc:` / `bcc:`
        // prefixed addresses.
        foreach ($mail['cc'] as $address) {
            $fields['to'] .= ',cc:' . $address['email'];
        }
        foreach ($mail['bcc'] as $address) {
            $fields['to'] .= ',bcc:' . $address['email'];
        }
        $result = self::postForm(self::API_URL, [], $fields);
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Elastic Email')];
        }
        if (is_array($result['data']) && ($result['data']['success'] ?? false) !== true) {
            $error = (string) ($result['data']['error'] ?? '');
            return ['success' => false, 'message' => $error !== '' ? $error : __('Elastic Email did not accept the message.', 'traydigita-core')];
        }
        return ['success' => true, 'message' => __('Sent through Elastic Email.', 'traydigita-core')];
    }
}
