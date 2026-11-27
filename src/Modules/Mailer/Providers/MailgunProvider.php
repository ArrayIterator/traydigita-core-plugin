<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function base64_encode;
use function implode;

/**
 * Mailgun (Sinch) Messages API provider.
 *
 * Endpoint `POST https://api.mailgun.net/v3/{domain}/messages`
 * (EU domains use `https://api.eu.mailgun.net`) with HTTP Basic
 * auth (username `api`, password the API key). The payload is
 * form-encoded; attachments are not sent through this provider
 * (the Messages API needs multipart form data, which the
 * URL-encoded call cannot carry - the mail still goes out).
 *
 * Free tier (Nov 2026): 100 emails/day. Best for: developers
 * who need detailed logs, routes, and suppression handling.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MailgunProvider extends AbstractApiProvider
{
    /**
     * Mailgun US API base URL.
     */
    public const string API_URL_US = 'https://api.mailgun.net/v3';

    /**
     * Mailgun EU API base URL.
     */
    public const string API_URL_EU = 'https://api.eu.mailgun.net/v3';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'mailgun',
            'label' => 'Mailgun',
            'description' => __(
                'Mailgun — free 100 emails/day. Best for developers who need detailed logs, inbound routes, and suppression handling.',
                'traydigita-core'
            ),
            'free_tier' => '100/day',
            'best_for' => 'Detailed logs, routes, and suppressions',
            'link' => '',
            'fields' => [
                [
                    'key' => 'mailgun_api_key',
                    'label' => __('Mailgun API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'The private API key (or a domain sending key) from Account Settings → API Keys in Mailgun. Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'mailgun_domain',
                    'label' => __('Mailgun sending domain', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __('The verified sending domain, for example mg.example.com. The From address must use this domain.', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'mailgun_region',
                    'label' => __('Mailgun region', 'traydigita-core'),
                    'type' => 'select',
                    'options' => [
                        'us' => __('United States (api.mailgun.net)', 'traydigita-core'),
                        'eu' => __('European Union (api.eu.mailgun.net)', 'traydigita-core'),
                    ],
                    'hint' => __('Pick the region where the sending domain lives; a domain exists in one region only.', 'traydigita-core'),
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
        $apiKey = self::setting($settings, 'mailgun_api_key');
        $domain = self::setting($settings, 'mailgun_domain');
        if ($apiKey === '' || $domain === '') {
            return ['success' => false, 'message' => __('Enter the Mailgun API key and sending domain in the Mailer settings first.', 'traydigita-core')];
        }
        $base = self::setting($settings, 'mailgun_region') === 'eu' ? self::API_URL_EU : self::API_URL_US;
        $fields = [
            'from' => self::formatAddress(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'to' => implode(', ', self::formatAddressList($mail['to'])),
            'subject' => $mail['subject'],
        ];
        if ($mail['cc'] !== []) {
            $fields['cc'] = implode(', ', self::formatAddressList($mail['cc']));
        }
        if ($mail['bcc'] !== []) {
            $fields['bcc'] = implode(', ', self::formatAddressList($mail['bcc']));
        }
        if ($mail['is_html']) {
            $fields['html'] = $mail['body'];
            $fields['text'] = self::plainText($mail);
        } else {
            $fields['text'] = $mail['body'];
        }
        if ($mail['reply_to'] !== '') {
            $fields['h:Reply-To'] = $mail['reply_to'];
        }
        foreach ($mail['headers'] as $name => $value) {
            $fields['h:' . $name] = $value;
        }
        $result = self::postForm(
            $base . '/' . $domain . '/messages',
            ['Authorization' => 'Basic ' . base64_encode('api:' . $apiKey)],
            $fields
        );
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Mailgun')];
        }
        return ['success' => true, 'message' => __('Sent through Mailgun.', 'traydigita-core')];
    }
}
