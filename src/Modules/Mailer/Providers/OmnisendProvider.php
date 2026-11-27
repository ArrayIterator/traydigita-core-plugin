<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;
use function sprintf;
use function str_contains;

/**
 * Omnisend provider.
 *
 * Omnisend has no single-email send endpoint: a mail is sent
 * as a campaign to a segment (or to all subscribers), in three
 * steps, all against `https://api.omnisend.com` with the API
 * key in the `Authorization: Omnisend-API-Key` header and the
 * `Omnisend-Version: 2026-03-15` header:
 *
 * 1. `POST /api/email-templates/import` `{name, html}` imports
 *    the mail body as a template (every template must contain
 *    an `[[unsubscribe_link]]`, so one is appended when the
 *    body does not already carry it).
 * 2. `POST /api/campaigns` `{name, type: "regular", channel:
 *    "email", language: "en_US", content: {email: {subject,
 *    senderName, templateID}}, audience: {includedSegmentIDs:
 *    [...]}}` creates a draft campaign (the audience is
 *    omitted when no segment is set, which targets all
 *    subscribers; sending settings are omitted, so the
 *    strategy is immediate).
 * 3. `POST /api/campaigns/{id}/send` starts the send; a 204
 *    response confirms it.
 *
 * The mail's To addresses are ignored: the campaign goes to
 * the configured segment (or all subscribers), so a test
 * email sends to the whole audience - never use it for a test
 * unless that is intended. The first campaign (or the first in
 * 90+ days) is verified by Omnisend before full delivery.
 *
 * Free tier (Nov 2026): 500 emails/month. Best for:
 * e-commerce stores already using Omnisend for email and SMS.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class OmnisendProvider extends AbstractApiProvider
{
    /**
     * Omnisend API base: the only host contacted.
     */
    public const string API_BASE = 'https://api.omnisend.com/api';

    /**
     * Omnisend API version header value.
     */
    public const string API_VERSION = '2026-03-15';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'omnisend',
            'label' => 'Omnisend',
            'description' => __(
                'Omnisend — free 500 emails/month. Campaign provider: a mail is sent as a campaign to a segment (or all subscribers), not to the mail’s To addresses — a test email goes to the whole audience. Best for e-commerce stores already using Omnisend for email and SMS.',
                'traydigita-core'
            ),
            'free_tier' => '500/month',
            'best_for' => 'E-commerce stores on Omnisend',
            'link' => '',
            'fields' => [
                [
                    'key' => 'omnisend_api_key',
                    'label' => __('Omnisend API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in Omnisend (Store Settings → Integrations & API → API Keys) with the campaigns.write and email-templates.write scopes. Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'omnisend_segment_id',
                    'label' => __('Omnisend segment ID (optional)', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The segment ID the campaign is sent to. Leave empty to send to all subscribers. Every mail goes to the whole segment.',
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
        $apiKey = self::setting($settings, 'omnisend_api_key');
        if ($apiKey === '') {
            return ['success' => false, 'message' => __('Enter the Omnisend API key in the Mailer settings first.', 'traydigita-core')];
        }
        $headers = [
            'Authorization' => 'Omnisend-API-Key ' . $apiKey,
            'Omnisend-Version' => self::API_VERSION,
        ];
        $html = $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>';
        if (!str_contains($html, '[[unsubscribe_link]]')) {
            // Omnisend requires an unsubscribe link in every template.
            $html .= '<p><a href="[[unsubscribe_link]]">' . __('Unsubscribe', 'traydigita-core') . '</a></p>';
        }
        $template = self::postJson(
            self::API_BASE . '/email-templates/import',
            $headers,
            [
                'name' => sprintf('TrayDigita: %s', $mail['subject']),
                'html' => $html,
            ]
        );
        if ($template['error'] !== '' || ($template['code'] !== 200 && $template['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($template, 'Omnisend')];
        }
        $templateId = is_array($template['data']) && isset($template['data']['id']) ? (string) $template['data']['id'] : '';
        if ($templateId === '') {
            return ['success' => false, 'message' => self::apiErrorMessage($template, 'Omnisend')];
        }
        $campaign = [
            'name' => sprintf('TrayDigita: %s', $mail['subject']),
            'type' => 'regular',
            'channel' => 'email',
            'language' => 'en_US',
            'content' => [
                'email' => [
                    'subject' => $mail['subject'],
                    'senderName' => $mail['from_name'],
                    'senderEmail' => $mail['from_email'],
                    'templateID' => $templateId,
                ],
            ],
        ];
        $segmentId = self::setting($settings, 'omnisend_segment_id');
        if ($segmentId !== '') {
            $campaign['audience'] = ['includedSegmentIDs' => [$segmentId]];
        }
        $created = self::postJson(
            self::API_BASE . '/campaigns',
            $headers,
            $campaign
        );
        if ($created['error'] !== '' || ($created['code'] !== 200 && $created['code'] !== 201)) {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'Omnisend')];
        }
        $campaignId = is_array($created['data']) && isset($created['data']['id']) ? (string) $created['data']['id'] : '';
        if ($campaignId === '') {
            return ['success' => false, 'message' => self::apiErrorMessage($created, 'Omnisend')];
        }
        $sent = self::postNoBody(
            self::API_BASE . '/campaigns/' . $campaignId . '/send',
            $headers
        );
        if ($sent['error'] !== '' || ($sent['code'] !== 200 && $sent['code'] !== 204)) {
            return ['success' => false, 'message' => self::apiErrorMessage($sent, 'Omnisend')];
        }
        return ['success' => true, 'message' => __('Campaign sent through Omnisend.', 'traydigita-core')];
    }
}
