<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Klaviyo provider.
 *
 * Klaviyo has no single-email send endpoint: a mail is sent
 * as a campaign to a whole list, in two steps against
 * `https://a.klaviyo.com/api` (revision `2024-06-15`, API key
 * in the `Authorization: Klaviyo-API-Key` header):
 *
 * 1. `POST /api/campaigns` creates a campaign for the list
 *    with an immediate send strategy and a campaign message
 *    that references the email template.
 * 2. `POST /api/campaign-send-jobs` with the campaign id
 *    starts the send.
 *
 * The mail's To addresses are ignored: the campaign goes to
 * every profile of the configured list, so a test email sends
 * to the whole list — never use it for a test unless that is
 * intended.
 *
 * Free tier (Nov 2026): 500 profiles. Best for: e-commerce
 * marketing (flows, segments, campaigns) on Klaviyo.
 *
 * Note: the campaign-create payload shape follows Klaviyo's
 * API reference (revision 2024-06-15) but has not been
 * verified against a live Klaviyo account — confirm the
 * campaign arrives before relying on it in production.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class KlaviyoProvider extends AbstractApiProvider
{
    /**
     * Klaviyo API base: the only host contacted.
     */
    public const string API_BASE = 'https://a.klaviyo.com/api';

    /**
     * Klaviyo API revision used for every call.
     */
    public const string API_REVISION = '2024-06-15';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'klaviyo',
            'label' => 'Klaviyo',
            'description' => __(
                'Klaviyo — free 500 profiles. Campaign provider: a mail is sent as a campaign to a whole list using an email template, not to the mail’s To addresses — a test email goes to the whole list. Best for e-commerce marketing (flows, segments, campaigns) on Klaviyo.',
                'traydigita-core'
            ),
            'free_tier' => '500 profiles',
            'best_for' => 'E-commerce marketing on Klaviyo',
            'link' => '',
            'fields' => [
                [
                    'key' => 'klaviyo_api_key',
                    'label' => __('Klaviyo API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a private API key in Klaviyo (Settings → API Keys). Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'klaviyo_list_id',
                    'label' => __('Klaviyo list ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The list the campaign is sent to (Audience → Lists → your list; the ID is in the URL). Every mail goes to the whole list.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'klaviyo_template_id',
                    'label' => __('Klaviyo email template ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The email template the campaign message uses (Content → Templates → your template; the ID is in the URL).',
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
        $apiKey = self::setting($settings, 'klaviyo_api_key');
        $listId = self::setting($settings, 'klaviyo_list_id');
        $templateId = self::setting($settings, 'klaviyo_template_id');
        if ($apiKey === '' || $listId === '' || $templateId === '') {
            return ['success' => false, 'message' => __('Enter the Klaviyo API key, list ID, and template ID in the Mailer settings first.', 'traydigita-core')];
        }
        $headers = [
            'Authorization' => 'Klaviyo-API-Key ' . $apiKey,
            'revision' => self::API_REVISION,
            'Accept' => 'application/json',
        ];
        $created = self::postJson(
            self::API_BASE . '/campaigns',
            $headers,
            [
                'data' => [
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => sprintf('TrayDigita: %s', $mail['subject']),
                        'audiences' => ['included' => [$listId]],
                        'send_strategy' => ['method' => 'immediate'],
                    ],
                    'relationships' => [
                        'campaign-messages' => [
                            'data' => [
                                [
                                    'type' => 'campaign-message',
                                    'attributes' => [
                                        'label' => sprintf('TrayDigita: %s', $mail['subject']),
                                        'channel' => 'email',
                                        'content' => [
                                            'subject' => $mail['subject'],
                                            'preview_text' => '',
                                            'from_email' => $mail['from_email'],
                                            'from_label' => $mail['from_name'],
                                            'reply_to_email' => $mail['reply_to'] !== '' ? $mail['reply_to'] : $mail['from_email'],
                                            'template_id' => $templateId,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
        if ($created['error'] !== '' || ($created['code'] !== 200 && $created['code'] !== 201 && $created['code'] !== 202)) {
            return ['success' => false, 'message' => self::klaviyoError($created)];
        }
        $campaignId = is_array($created['data']) && isset($created['data']['data']['id']) ? (string) $created['data']['data']['id'] : '';
        if ($campaignId === '') {
            return ['success' => false, 'message' => self::klaviyoError($created)];
        }
        $job = self::postJson(
            self::API_BASE . '/campaign-send-jobs',
            $headers,
            [
                'data' => [
                    'type' => 'campaign-send-job',
                    'relationships' => [
                        'campaign' => [
                            'data' => ['type' => 'campaign', 'id' => $campaignId],
                        ],
                    ],
                ],
            ]
        );
        if ($job['error'] !== '' || ($job['code'] !== 200 && $job['code'] !== 201 && $job['code'] !== 202)) {
            return ['success' => false, 'message' => self::klaviyoError($job)];
        }
        return ['success' => true, 'message' => __('Campaign sent through Klaviyo.', 'traydigita-core')];
    }

    /**
     * Klaviyo error text: its API reports the detail in an
     * `errors[0].detail` field, which the shared error helper
     * does not read, so prefer it here.
     *
     * @param array{code: int, body: string, data: array<string, mixed>|null, error: string} $result unpackResponse() result.
     * @return string
     */
    private static function klaviyoError(array $result): string
    {
        $data = $result['data'];
        if (is_array($data) && isset($data['errors'][0]['detail']) && is_string($data['errors'][0]['detail']) && $data['errors'][0]['detail'] !== '') {
            return $data['errors'][0]['detail'];
        }
        return self::apiErrorMessage($result, 'Klaviyo');
    }
}
