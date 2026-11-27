<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function array_merge;

/**
 * Loops provider.
 *
 * Endpoint `POST https://app.loops.so/api/v1/transactional`
 * with the API key as a Bearer token. Loops is
 * template-based: there is no raw HTML or subject in the
 * request - the mail is sent through a Loops transactional
 * template, and the subject and body are passed as data
 * variables (`subject` and `body`) so templates that use
 * `{{subject}}` and `{{body}}` render the mail content. One
 * call is made per recipient (CC and BCC addresses are
 * included in the same per-recipient loop, so no address is
 * ever exposed to another recipient). A 200 response means
 * the mail was accepted.
 *
 * Free tier (Nov 2026): 4,000 sends per rolling 30 days to
 * the first 1,000 contacts. Best for: transactional mail for
 * SaaS products that already manage their audience in Loops.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class LoopsProvider extends AbstractApiProvider
{
    /**
     * Loops transactional send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://app.loops.so/api/v1/transactional';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'loops',
            'label' => 'Loops',
            'description' => __(
                'Loops — free 4,000 sends per rolling 30 days (first 1,000 contacts). Template-based: the mail goes through a Loops transactional template; use {{subject}} and {{body}} in the template to render the mail content. Best for transactional mail for SaaS products that already manage their audience in Loops.',
                'traydigita-core'
            ),
            'free_tier' => '4,000/30 days',
            'best_for' => 'Transactional mail for SaaS products',
            'link' => '',
            'fields' => [
                [
                    'key' => 'loops_api_key',
                    'label' => __('Loops API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('Create an API key in the Loops dashboard (Settings → API). Leave empty to keep the saved key.', 'traydigita-core'),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'loops_transactional_id',
                    'label' => __('Loops transactional template ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The ID of a transactional template in Loops (Transactional → your template). The template should use {{subject}} and {{body}} to render the mail content.',
                        'traydigita-core'
                    ),
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
        $apiKey = self::setting($settings, 'loops_api_key');
        $templateId = self::setting($settings, 'loops_transactional_id');
        if ($apiKey === '' || $templateId === '') {
            return ['success' => false, 'message' => __('Enter the Loops API key and transactional template ID in the Mailer settings first.', 'traydigita-core')];
        }
        $recipients = array_merge($mail['to'], $mail['cc'], $mail['bcc']);
        foreach ($recipients as $recipient) {
            $result = self::postJson(
                self::API_URL,
                ['Authorization' => 'Bearer ' . $apiKey],
                [
                    'email' => $recipient['email'],
                    'transactionalId' => $templateId,
                    'dataVariables' => [
                        'subject' => $mail['subject'],
                        'body' => $mail['body'],
                    ],
                    'addToAudience' => false,
                ]
            );
            if ($result['error'] !== '' || $result['code'] !== 200) {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'Loops')];
            }
        }
        return ['success' => true, 'message' => __('Sent through Loops.', 'traydigita-core')];
    }
}
