<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Pepipost (Netcore Cloud) provider.
 *
 * Endpoint `POST https://api.pepipost.com/v5/mail/send` with
 * the API key in the `api_key` header. A 200 or 202 response
 * means the mail was accepted.
 *
 * Pricing (Nov 2026): a free trial is available; the free-tier
 * limits were not verified against Netcore's pricing page, so
 * no free-tier number is claimed here. Best for: transactional
 * mail on Netcore's infrastructure with AI-based deliverability.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class PepipostProvider extends AbstractApiProvider
{
    /**
     * Pepipost v5 send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.pepipost.com/v5/mail/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'pepipost',
            'label' => 'Pepipost (Netcore)',
            'description' => __(
                'Pepipost (Netcore) — free trial available (free-tier limits not verified — check Netcore pricing). Best for transactional mail on Netcore infrastructure with AI-based deliverability.',
                'traydigita-core'
            ),
            'free_tier' => 'Trial',
            'best_for' => 'Transactional mail on Netcore infrastructure',
            'link' => '',
            'fields' => [
                [
                    'key' => 'pepipost_api_key',
                    'label' => __('Pepipost API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in the Netcore / Pepipost dashboard (Settings → API Keys). Leave empty to keep the saved key.',
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
        $apiKey = self::setting($settings, 'pepipost_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Pepipost API key in the Mailer settings first.', 'traydigita-core')];
        }
        $content = [];
        if ($mail['is_html']) {
            $content[] = ['type' => 'html', 'value' => $mail['body']];
            $content[] = ['type' => 'text', 'value' => self::plainText($mail)];
        } else {
            $content[] = ['type' => 'text', 'value' => $mail['body']];
        }
        $personalization = ['to' => self::addressObjects($mail['to'])];
        if ($mail['cc'] !== []) {
            $personalization['cc'] = self::addressObjects($mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $personalization['bcc'] = self::addressObjects($mail['bcc']);
        }
        $payload = [
            'from' => ['email' => $mail['from_email'], 'name' => $mail['from_name']],
            'subject' => $mail['subject'],
            'content' => $content,
            'personalizations' => [$personalization],
        ];
        if ($mail['reply_to'] !== '') {
            $payload['reply_to'] = $mail['reply_to'];
        }
        $result = self::postJson(
            self::API_URL,
            ['api_key' => $apiKey],
            $payload
        );
        if ($result['error'] !== '' || ($result['code'] !== 200 && $result['code'] !== 202)) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Pepipost')];
        }
        return ['success' => true, 'message' => __('Sent through Pepipost.', 'traydigita-core')];
    }
}
