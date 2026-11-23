<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer;

use function __;
use function array_map;
use function base64_encode;
use function basename;
use function file_exists;
use function file_get_contents;
use function filesize;
use function is_array;
use function is_readable;
use function is_string;
use function json_decode;
use function sprintf;
use function trim;
use function wp_check_filetype;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function wp_remote_retrieve_response_message;
use function is_wp_error;

/**
 * SendGrid v3 API sender for the Mailer module.
 *
 * One endpoint, allowlisted by construction: the URL is a class
 * constant, never caller input. Sends one mail per call; the
 * caller (MailerModule::maybeSendViaApi()) already normalized
 * the recipients, subject, body and attachments.
 *
 * Attachments are read from disk and base64-encoded into the
 * JSON payload. Files over 10 MB each (or 25 MB in total) are
 * refused with a clear error instead of building a payload
 * SendGrid would reject.
 */
final class SendGridSender
{
    /**
     * SendGrid v3 mail-send endpoint: the only host contacted.
     */
    public const string API_URL = 'https://api.sendgrid.com/v3/mail/send';

    /**
     * HTTP timeout in seconds.
     */
    public const int TIMEOUT = 15;

    /**
     * Largest single attachment accepted, in bytes (10 MB).
     */
    public const int MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /**
     * Largest total attachment size accepted, in bytes (25 MB).
     */
    public const int MAX_TOTAL_ATTACHMENT_BYTES = 25 * 1024 * 1024;

    /**
     * Send one normalized mail through SendGrid.
     *
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param string $apiKey SendGrid API key (Bearer token).
     * @return array{success: bool, message: string}
     */
    public static function send(array $mail, string $apiKey): array
    {
        if ($mail['to'] === []) {
            return ['success' => false, 'message' => __('The email has no recipients.', 'traydigita-core')];
        }
        if ($mail['from_email'] === '') {
            return ['success' => false, 'message' => __('Set a From email address in the Mailer settings first.', 'traydigita-core')];
        }
        $attachments = self::encodeAttachments($mail['attachments']);
        if ($attachments instanceof \WP_Error) {
            return ['success' => false, 'message' => $attachments->get_error_message()];
        }
        $format = static function (array $address): array {
            $entry = ['email' => $address['email']];
            if ($address['name'] !== '') {
                $entry['name'] = $address['name'];
            }
            return $entry;
        };
        $personalization = ['to' => array_map($format, $mail['to'])];
        if ($mail['cc'] !== []) {
            $personalization['cc'] = array_map($format, $mail['cc']);
        }
        if ($mail['bcc'] !== []) {
            $personalization['bcc'] = array_map($format, $mail['bcc']);
        }
        if (($mail['headers'] ?? []) !== []) {
            $personalization['headers'] = $mail['headers'];
        }
        $payload = [
            'personalizations' => [$personalization],
            'from' => $format(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'subject' => $mail['subject'],
            'content' => [
                [
                    'type' => $mail['is_html'] ? 'text/html' : 'text/plain',
                    'value' => $mail['body'],
                ],
            ],
        ];
        if (($mail['reply_to'] ?? '') !== '') {
            $payload['reply_to'] = ['email' => $mail['reply_to']];
        }
        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }
        $response = wp_remote_post(
            self::API_URL,
            [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'body' => (string) wp_json_encode($payload),
            ]
        );
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        // SendGrid answers 202 Accepted for a queued mail.
        if ($code === 202) {
            return ['success' => true, 'message' => __('Sent through SendGrid.', 'traydigita-core')];
        }
        return ['success' => false, 'message' => self::apiErrorMessage($response, $code)];
    }

    /**
     * Encode attachment files for the SendGrid payload.
     *
     * Unreadable or missing files are skipped (the mail still
     * sends — a missing file must not lose the whole message);
     * oversize files fail the send with a clear error instead.
     *
     * @param array<int, string> $files Attachment paths.
     * @return array<int, array{content: string, filename: string, type: string}>|\WP_Error
     */
    private static function encodeAttachments(array $files): array|\WP_Error
    {
        $encoded = [];
        $total = 0;
        foreach ($files as $file) {
            if (!is_string($file) || !file_exists($file) || !is_readable($file)) {
                continue;
            }
            $size = filesize($file);
            if ($size === false) {
                continue;
            }
            if ($size > self::MAX_ATTACHMENT_BYTES) {
                return new \WP_Error(
                    'mailer_sendgrid_attachment',
                    sprintf(
                        /* translators: %s is the attachment file name. */
                        __('Attachment %s is too large for SendGrid (10 MB limit).', 'traydigita-core'),
                        basename($file)
                    )
                );
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
                return new \WP_Error(
                    'mailer_sendgrid_attachment',
                    __('The attachments are too large for SendGrid (25 MB total limit).', 'traydigita-core')
                );
            }
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }
            $type = 'application/octet-stream';
            $check = wp_check_filetype($file);
            if (is_array($check) && !empty($check['type'])) {
                $type = (string) $check['type'];
            }
            $encoded[] = [
                'content' => base64_encode($contents),
                'filename' => basename($file),
                'type' => $type,
            ];
        }
        return $encoded;
    }

    /**
     * Human error text for a failed SendGrid call.
     *
     * Prefers SendGrid's own `errors[0].message`; falls back to
     * the HTTP status line. The message is passed through
     * MailerModule::redact() by the caller before it is stored.
     *
     * @param array<string, mixed> $response wp_remote_* response.
     * @param int $code HTTP status code.
     * @return string
     */
    private static function apiErrorMessage(array $response, int $code): string
    {
        $body = wp_remote_retrieve_body($response);
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)
                && isset($decoded['errors'][0]['message'])
                && is_string($decoded['errors'][0]['message'])
            ) {
                return trim($decoded['errors'][0]['message']);
            }
        }
        $status = wp_remote_retrieve_response_message($response);
        if ($status !== '') {
            return sprintf(
                /* translators: %1$d is the HTTP status code, %2$s is the HTTP status text. */
                __('SendGrid API error %1$d: %2$s', 'traydigita-core'),
                $code,
                $status
            );
        }
        return sprintf(
            /* translators: %d is the HTTP status code returned by the SendGrid API. */
            __('SendGrid API error %d.', 'traydigita-core'),
            $code
        );
    }
}
