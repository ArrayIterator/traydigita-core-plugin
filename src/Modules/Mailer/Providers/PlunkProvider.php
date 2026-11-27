<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function array_merge;

/**
 * Plunk provider.
 *
 * Endpoint `POST https://api.useplunk.com/v1/send` with the
 * secret key (`sk_*`) as a Bearer token. The endpoint takes
 * one recipient at a time, so a mail with several recipients
 * is sent as one Plunk call per address (CC and BCC addresses
 * are included in the same per-recipient loop, so no address
 * is ever exposed to another recipient). A 200 or 201 response
 * means the mail was accepted.
 *
 * Free tier (Oct-Nov 2026 sources): 1,000 emails/month, then
 * $0.001 per email. Older sources quoted 3,000/month; the
 * 1,000 figure is used here because it is the newer one.
 * Best for: simple transactional mail with a generous
 * pay-as-you-go price after the free tier.
 *
 * Note: Plunk's current docs also mention the
 * `next-api.useplunk.com` base URL; `api.useplunk.com` is the
 * long-standing documented base and is used here.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class PlunkProvider extends AbstractApiProvider
{
    /**
     * Plunk send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.useplunk.com/v1/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'plunk',
            'label' => 'Plunk',
            'description' => __(
                'Plunk — free 1,000 emails/month, then $0.001 per email. Best for simple transactional mail with a generous pay-as-you-go price after the free tier.',
                'traydigita-core'
            ),
            'free_tier' => '1,000/month',
            'best_for' => 'Simple transactional mail, pay-as-you-go',
            'link' => '',
            'fields' => [
                [
                    'key' => 'plunk_api_key',
                    'label' => __('Plunk secret key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a secret key (sk_…) in the Plunk dashboard (Settings → API Keys). Leave empty to keep the saved key.',
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
        $apiKey = self::setting($settings, 'plunk_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Plunk secret key in the Mailer settings first.', 'traydigita-core')];
        }
        $recipients = array_merge($mail['to'], $mail['cc'], $mail['bcc']);
        foreach ($recipients as $recipient) {
            $result = self::postJson(
                self::API_URL,
                ['Authorization' => 'Bearer ' . $apiKey],
                [
                    'to' => $recipient['email'],
                    'subject' => $mail['subject'],
                    'body' => $mail['body'],
                    'from' => $mail['from_email'],
                ]
            );
            if ($result['error'] !== '' || ($result['code'] !== 200 && $result['code'] !== 201)) {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'Plunk')];
            }
        }
        return ['success' => true, 'message' => __('Sent through Plunk.', 'traydigita-core')];
    }
}
