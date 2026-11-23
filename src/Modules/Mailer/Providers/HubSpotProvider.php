<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function array_merge;
use function is_array;

/**
 * HubSpot transactional email provider.
 *
 * Endpoint `POST https://api.hubapi.com/marketing/v3/transactional/single-email/send`
 * with a private-app access token as a Bearer token. This is a
 * genuine single-email send, but the content comes from a
 * HubSpot email template (`emailId`): one call is made per
 * recipient (CC and BCC addresses are included in the same
 * per-recipient loop, so no address is ever exposed to another
 * recipient). A 200 response with a `statusId` means the mail
 * was accepted.
 *
 * Free tier (Nov 2026): 2,000 emails/month — but only with
 * the Transactional Email add-on or Marketing Hub Enterprise.
 * Best for: sites already running HubSpot CRM and marketing.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class HubSpotProvider extends AbstractApiProvider
{
    /**
     * HubSpot transactional single-send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.hubapi.com/marketing/v3/transactional/single-email/send';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'hubspot',
            'label' => 'HubSpot',
            'description' => __(
                'HubSpot — free 2,000 emails/month (needs the Transactional Email add-on or Marketing Hub Enterprise). Template-based single send: the content comes from a HubSpot email template. Best for sites already running HubSpot CRM and marketing.',
                'traydigita-core'
            ),
            'free_tier' => '2,000/month (with add-on)',
            'best_for' => 'Sites already on HubSpot CRM',
            'link' => '',
            'fields' => [
                [
                    'key' => 'hubspot_access_token',
                    'label' => __('HubSpot private app access token', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create a private app in HubSpot (Settings → Integrations → Private Apps) with the transactional email scope. Leave empty to keep the saved token.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'hubspot_email_id',
                    'label' => __('HubSpot email template ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The ID of a HubSpot email template (Marketing → Email → your template; the ID is in the URL). The template supplies the mail content.',
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
        $token = self::setting($settings, 'hubspot_access_token');
        $emailId = self::setting($settings, 'hubspot_email_id');
        if ($token === '' || $emailId === '') {
            return ['success' => false, 'message' => __('Enter the HubSpot access token and email template ID in the Mailer settings first.', 'traydigita-core')];
        }
        $recipients = array_merge($mail['to'], $mail['cc'], $mail['bcc']);
        foreach ($recipients as $recipient) {
            $message = [
                'to' => $recipient['email'],
                'from' => $mail['from_email'],
            ];
            if ($mail['reply_to'] !== '') {
                $message['replyTo'] = $mail['reply_to'];
            }
            $result = self::postJson(
                self::API_URL,
                ['Authorization' => 'Bearer ' . $token],
                [
                    'emailId' => $emailId,
                    'message' => $message,
                    'contactProperties' => [],
                    'customProperties' => [
                        'subject' => $mail['subject'],
                    ],
                ]
            );
            if ($result['error'] !== '' || $result['code'] !== 200) {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'HubSpot')];
            }
            $data = $result['data'];
            if (!is_array($data) || empty($data['statusId'])) {
                return ['success' => false, 'message' => self::apiErrorMessage($result, 'HubSpot')];
            }
        }
        return ['success' => true, 'message' => __('Sent through HubSpot.', 'traydigita-core')];
    }
}
