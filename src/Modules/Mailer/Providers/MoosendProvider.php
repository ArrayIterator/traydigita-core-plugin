<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Moosend provider.
 *
 * Moosend has no single-email send endpoint: a mail is sent
 * as a campaign to a whole mailing list, in two steps against
 * `https://api.moosend.com/v3` with the API key as a query
 * parameter:
 *
 * 1. `POST /campaigns/create.json?apikey=<key>` `{Name,
 *    Subject, SenderEmail, ReplyToEmail, MailingLists:
 *    [{MailingListID}], Content}` creates a draft campaign
 *    (the response `Context` is the campaign ID).
 * 2. `POST /campaigns/{id}/send.json?apikey=<key>` (empty
 *    body) sends the campaign now. Both steps report success
 *    with `Code: 0`.
 *
 * The mail's To addresses are ignored: the campaign goes to
 * every subscriber of the configured mailing list, so a test
 * email sends to the whole list — never use it for a test
 * unless that is intended.
 *
 * Pricing (Nov 2026): 30-day trial, then paid. Best for:
 * email marketing and automation on Moosend.
 *
 * Note: the `Content` field of the create call follows
 * Moosend's API reference but the docs page could not be
 * re-read while this provider was written — confirm the
 * campaign carries the mail body before relying on it in
 * production.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class MoosendProvider extends AbstractApiProvider
{
    /**
     * Moosend API base: the only host contacted.
     */
    public const string API_BASE = 'https://api.moosend.com/v3';

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'moosend',
            'label' => 'Moosend',
            'description' => __(
                'Moosend — 30-day trial, then paid. Campaign provider: a mail is sent as a campaign to a whole mailing list, not to the mail’s To addresses — a test email goes to the whole list. Best for email marketing and automation on Moosend.',
                'traydigita-core'
            ),
            'free_tier' => '30-day trial',
            'best_for' => 'Email marketing and automation',
            'link' => '',
            'fields' => [
                [
                    'key' => 'moosend_api_key',
                    'label' => __('Moosend API key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __(
                        'Create an API key in Moosend (Settings → API Keys). Leave empty to keep the saved key.',
                        'traydigita-core'
                    ),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'moosend_mailing_list_id',
                    'label' => __('Moosend mailing list ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'The mailing list the campaign is sent to (Audience → Email Lists → your list; the ID is in the URL). Every mail goes to the whole list.',
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
        $apiKey = self::setting($settings, 'moosend_api_key');
        $listId = self::setting($settings, 'moosend_mailing_list_id');
        if ($apiKey === '' || $listId === '') {
            return ['success' => false, 'message' => __('Enter the Moosend API key and mailing list ID in the Mailer settings first.', 'traydigita-core')];
        }
        $query = '?apikey=' . $apiKey;
        $payload = [
            'Name' => sprintf('TrayDigita: %s', $mail['subject']),
            'Subject' => $mail['subject'],
            'SenderEmail' => $mail['from_email'],
            'MailingLists' => [['MailingListID' => $listId]],
            'Content' => $mail['is_html'] ? $mail['body'] : '<p>' . $mail['body'] . '</p>',
        ];
        if ($mail['reply_to'] !== '') {
            $payload['ReplyToEmail'] = $mail['reply_to'];
        }
        $created = self::postJson(
            self::API_BASE . '/campaigns/create.json' . $query,
            [],
            $payload
        );
        if ($created['error'] !== '' || $created['code'] !== 200 || !self::moosendOk($created)) {
            return ['success' => false, 'message' => self::moosendError($created)];
        }
        $campaignId = is_array($created['data']) && isset($created['data']['Context']) ? (string) $created['data']['Context'] : '';
        if ($campaignId === '') {
            return ['success' => false, 'message' => self::moosendError($created)];
        }
        $sent = self::postNoBody(
            self::API_BASE . '/campaigns/' . $campaignId . '/send.json' . $query,
            []
        );
        if ($sent['error'] !== '' || $sent['code'] !== 200 || !self::moosendOk($sent)) {
            return ['success' => false, 'message' => self::moosendError($sent)];
        }
        return ['success' => true, 'message' => __('Campaign sent through Moosend.', 'traydigita-core')];
    }

    /**
     * Moosend reports success with `Code: 0` in the body.
     *
     * @param array{code: int, body: string, data: array<string, mixed>|null, error: string} $result unpackResponse() result.
     * @return bool
     */
    private static function moosendOk(array $result): bool
    {
        $data = $result['data'];
        return is_array($data) && isset($data['Code']) && (int) $data['Code'] === 0;
    }

    /**
     * Moosend error text: its API reports the problem in an
     * `Error` field, which the shared error helper does not
     * read, so prefer it here.
     *
     * @param array{code: int, body: string, data: array<string, mixed>|null, error: string} $result unpackResponse() result.
     * @return string
     */
    private static function moosendError(array $result): string
    {
        $data = $result['data'];
        if (is_array($data) && isset($data['Error']) && is_string($data['Error']) && $data['Error'] !== '') {
            return $data['Error'];
        }
        return self::apiErrorMessage($result, 'Moosend');
    }
}
