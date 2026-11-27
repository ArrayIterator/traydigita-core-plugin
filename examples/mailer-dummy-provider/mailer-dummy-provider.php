<?php
declare(strict_types=1);

/**
 * Plugin Name: TrayDigita Mailer - Dummy Provider Example
 * Plugin URI: https://github.com/ArrayIterator/traydigita-core-plugin
 * Description: Example third-party provider for the TrayDigita Core Mailer. Registers a "Dummy" provider through the traydigita:core:mailer/providers filter: it appears in the Mailer settings dropdown, works in test-send, and appears in the send log - without sending any real mail. Copy this file as a starting point for a real provider.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Author: TrayDigita
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: traydigita-mailer-dummy-provider
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the dummy provider.
 *
 * A provider entry carries:
 * - `id` (string, required): the provider id, used in the
 *   settings dropdown and the send log. Must match the array key.
 * - `label` (string, required): display name in the dropdown.
 * - `description` (string): free tier + best for, shown in the
 *   provider list on the Mailer settings page.
 * - `free_tier` / `best_for` (strings): short lines used in the
 *   dropdown label and the provider list.
 * - `link` (string): reserved slot for a future affiliate link;
 *   keep it empty (v1.0.0 ships no active affiliate links).
 * - `fields` (array): settings fields for this provider. Each
 *   field: `key` (stored in the `traydigita_mailer` option),
 *   `label`, `type` (`text`, `password`, or `select`),
 *   `options` (select only), `hint`, `secret` (never shown,
 *   only overwritten when a non-empty value is submitted),
 *   `required` (used by the configured check when no
 *   `is_configured` callback is given).
 * - `send` (callable, required unless `class` is given):
 *   `fn (array $mail, array $settings): array` returning
 *   `['success' => bool, 'message' => string]`. `$mail` is the
 *   normalized mail (to/cc/bcc address lists, subject, body,
 *   is_html, from_email/from_name, reply_to, headers,
 *   is_bulk, attachments).
 * - `is_configured` (callable, optional): whether the
 *   provider is ready to send; defaults to "every `required`
 *   field is filled in".
 *
 * @param array<string, array<string, mixed>> $providers Registered providers.
 * @return array<string, array<string, mixed>>
 */
function traydigita_mailer_dummy_provider_register(array $providers): array
{
    $providers['dummy'] = [
        'id' => 'dummy',
        'label' => 'Dummy (Example)',
        'description' => 'Example provider — accepts every mail and records it in the send log without sending anything. Use it to test the Mailer provider hook.',
        'free_tier' => 'Unlimited (sends nothing)',
        'best_for' => 'Testing the provider hook',
        'link' => '',
        'fields' => [
            [
                'key' => 'dummy_note',
                'label' => 'Dummy note',
                'type' => 'text',
                'hint' => 'Free text, stored with the Mailer settings. It is shown in the send result message below.',
                'secret' => false,
                'required' => false,
            ],
        ],
        'send' => static function (array $mail, array $settings): array {
            $note = isset($settings['dummy_note']) && is_string($settings['dummy_note']) ? $settings['dummy_note'] : '';
            $message = 'Dummy provider accepted the mail (no mail was actually sent).';
            if ($note !== '') {
                $message .= ' Note: ' . $note;
            }
            return ['success' => true, 'message' => $message];
        },
    ];
    return $providers;
}
add_filter('traydigita:core:mailer/providers', 'traydigita_mailer_dummy_provider_register');

/**
 * React to dummy sends: log the recipient to the PHP error log
 * (development only - remove this in a real provider).
 *
 * The Mailer fires `traydigita:core:mailer/send` before the
 * attempt and `traydigita:core:mailer/sent` /
 * `traydigita:core:mailer/failed` after it.
 *
 * @param string $provider Provider id.
 * @param array<string, mixed> $mail Normalized mail.
 * @param string $message Provider result message.
 * @return void
 */
function traydigita_mailer_dummy_provider_log_sent(string $provider, array $mail, string $message): void
{
    if ($provider !== 'dummy') {
        return;
    }
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Example only: shows how to react to the sent action.
    error_log(sprintf('[mailer-dummy] Accepted mail to %s. %s', $mail['to_text'] ?? '', $message));
}
add_action('traydigita:core:mailer/sent', 'traydigita_mailer_dummy_provider_log_sent', 10, 3);
