<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;

/**
 * Gmail API provider (OAuth2).
 *
 * Sends through the Gmail API as the connected Google account,
 * via GmailOAuth (authorization-code flow + token refresh +
 * base64url RFC 822 send). The OAuth connect / callback /
 * disconnect flow itself stays in MailerModule — it is
 * settings-page flow (nonce, capability, redirects to the
 * module's settings slug), not send logic.
 *
 * `isConfigured()` is token-based, not field-based: the
 * client id / secret fields are `required => false`, so the
 * inherited required-field check would be wrong.
 *
 * Free tier: none (uses the connected account). Best for:
 * small sites that already live in Google Workspace or Gmail.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class GmailProvider extends AbstractApiProvider
{
    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>, type: string}
     */
    public static function definition(): array
    {
        return [
            'id' => 'gmail',
            'label' => 'Gmail',
            'description' => __(
                'Gmail — send through the Gmail API as the connected Google account. Best for small sites that already live in Google Workspace or Gmail.',
                'traydigita-core'
            ),
            'free_tier' => '',
            'best_for' => 'Small sites on Gmail / Google Workspace',
            'link' => '',
            'fields' => [
                [
                    'key' => 'gmail_client_id',
                    'label' => __('Google OAuth client ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'Create OAuth credentials (Web application) in the Google Cloud console and add this site’s callback URL as an authorized redirect URI.',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => false,
                ],
                [
                    'key' => 'gmail_client_secret',
                    'label' => __('Google OAuth client secret', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('Leave empty to keep the saved secret. The secret is never shown.', 'traydigita-core'),
                    'secret' => true,
                    'required' => false,
                ],
            ],
            'type' => 'oauth',
        ];
    }

    /**
     * Whether a Gmail account is connected (OAuth tokens stored).
     *
     * The client id / secret fields are not required, so the
     * inherited required-field check cannot answer this; only
     * the stored tokens can.
     *
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return bool
     */
    public static function isConfigured(array $settings): bool
    {
        return GmailOAuth::isConnected();
    }

    /**
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return array{success: bool, message: string}
     */
    public static function send(array $mail, array $settings): array
    {
        return GmailOAuth::send($mail);
    }
}
