<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use WP_Error;
use function __;
use function array_map;
use function base64_encode;
use function basename;
use function file_exists;
use function file_get_contents;
use function filesize;
use function http_build_query;
use function is_array;
use function is_readable;
use function is_string;
use function json_decode;
use function sprintf;
use function strip_tags;
use function trim;
use function wp_check_filetype;
use function wp_json_encode;
use function wp_remote_post;
use function wp_remote_request;
use function wp_remote_retrieve_body;
use function wp_remote_retrieve_response_code;
use function is_wp_error;

/**
 * Shared HTTP and payload helpers for the Mailer API providers.
 *
 * Every provider class in this directory implements the same
 * three static methods:
 *
 * - `definition()`: id, label, description (free tier + best
 *   for), free-tier line, best-for line, an (empty for now)
 *   `link` slot reserved for a future affiliate link, and the
 *   settings `fields` the provider needs. The link slot exists
 *   so a later release can add affiliate links without changing
 *   the data shape; v1.0.0 ships it empty on purpose.
 * - `isConfigured(array $settings)`: whether the required
 *   fields for this provider are filled in.
 * - `send(array $mail, array $settings)`: one normalized mail
 *   (the shape MailerModule::normalizeMail() returns) through
 *   the provider API, returning
 *   `['success' => bool, 'message' => string]`.
 *
 * Provider hosts are class constants, never caller input, so a
 * provider can only ever contact its own API. All calls go
 * through `wp_remote_post()` with a 15 second timeout.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
abstract class AbstractApiProvider
{
    /**
     * HTTP timeout in seconds for provider API calls.
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
     * Provider definition: id, label, description, free tier,
     * best for, link slot, and settings fields.
     *
     * Field shape: `['key' => string, 'label' => string,
     * 'type' => 'text'|'password'|'select', 'options' =>
     * array<string, string> (select only), 'hint' => string,
     * 'secret' => bool, 'required' => bool]`.
     *
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    abstract public static function definition(): array;

    /**
     * Send one normalized mail through this provider.
     *
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return array{success: bool, message: string}
     */
    abstract public static function send(array $mail, array $settings): array;

    /**
     * Whether the provider's required fields are filled in.
     *
     * Reads the field list from `definition()` so a provider
     * never has to repeat its own requirements here.
     *
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return bool
     */
    public static function isConfigured(array $settings): bool
    {
        foreach (static::definition()['fields'] as $field) {
            if (empty($field['required'])) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || empty($settings[$key]) || !is_string($settings[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Send a JSON payload with any HTTP method and return the decoded result.
     *
     * `postJson()` and `putJson()` are thin wrappers around this:
     * some marketing APIs need PUT (set campaign content) while
     * most providers only need POST.
     *
     * @param string $method HTTP method (POST, PUT, ...).
     * @param string $url Allowlisted provider endpoint.
     * @param array<string, string> $headers Request headers.
     * @param array<string, mixed> $payload JSON payload.
     * @param int $timeout HTTP timeout in seconds.
     * @return array{code: int, body: string, data: array<string, mixed>|null, error: string}
     */
    protected static function requestJson(string $method, string $url, array $headers, array $payload, int $timeout = self::TIMEOUT): array
    {
        $headers['Content-Type'] = 'application/json';
        $response = wp_remote_request(
            $url,
            [
                'method' => $method,
                'timeout' => $timeout,
                'headers' => $headers,
                'body' => (string) wp_json_encode($payload),
            ]
        );
        return self::unpackResponse($response);
    }

    /**
     * POST a JSON payload and return the decoded result.
     *
     * @param string $url Allowlisted provider endpoint.
     * @param array<string, string> $headers Request headers.
     * @param array<string, mixed> $payload JSON payload.
     * @param int $timeout HTTP timeout in seconds.
     * @return array{code: int, body: string, data: array<string, mixed>|null, error: string}
     */
    protected static function postJson(string $url, array $headers, array $payload, int $timeout = self::TIMEOUT): array
    {
        return self::requestJson('POST', $url, $headers, $payload, $timeout);
    }

    /**
     * PUT a JSON payload and return the decoded result.
     *
     * @param string $url Allowlisted provider endpoint.
     * @param array<string, string> $headers Request headers.
     * @param array<string, mixed> $payload JSON payload.
     * @param int $timeout HTTP timeout in seconds.
     * @return array{code: int, body: string, data: array<string, mixed>|null, error: string}
     */
    protected static function putJson(string $url, array $headers, array $payload, int $timeout = self::TIMEOUT): array
    {
        return self::requestJson('PUT', $url, $headers, $payload, $timeout);
    }

    /**
     * POST with no body and return the decoded result.
     *
     * Some send actions (Mailchimp campaign send, Moosend
     * campaign send) take no payload at all; sending an empty
     * JSON body there would be wrong.
     *
     * @param string $url Allowlisted provider endpoint.
     * @param array<string, string> $headers Request headers.
     * @param int $timeout HTTP timeout in seconds.
     * @return array{code: int, body: string, data: array<string, mixed>|null, error: string}
     */
    protected static function postNoBody(string $url, array $headers, int $timeout = self::TIMEOUT): array
    {
        $response = wp_remote_post(
            $url,
            [
                'timeout' => $timeout,
                'headers' => $headers,
            ]
        );
        return self::unpackResponse($response);
    }

    /**
     * POST a URL-encoded form payload and return the decoded result.
     *
     * @param string $url Allowlisted provider endpoint.
     * @param array<string, string> $headers Request headers.
     * @param array<string, string> $fields Form fields.
     * @param int $timeout HTTP timeout in seconds.
     * @return array{code: int, body: string, data: array<string, mixed>|null, error: string}
     */
    protected static function postForm(string $url, array $headers, array $fields, int $timeout = self::TIMEOUT): array
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $response = wp_remote_post(
            $url,
            [
                'timeout' => $timeout,
                'headers' => $headers,
                'body' => http_build_query($fields),
            ]
        );
        return self::unpackResponse($response);
    }

    /**
     * Unpack a wp_remote_* response into a plain array.
     *
     * @param array<string, mixed>|WP_Error $response wp_remote_post() result.
     * @return array{code: int, body: string, data: array<string, mixed>|null, error: string}
     */
    protected static function unpackResponse(array|WP_Error $response): array
    {
        if (is_wp_error($response)) {
            return ['code' => 0, 'body' => '', 'data' => null, 'error' => $response->get_error_message()];
        }
        $body = wp_remote_retrieve_body($response);
        $data = null;
        if ($body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => $body,
            'data' => $data,
            'error' => '',
        ];
    }

    /**
     * Human error text for a failed provider call.
     *
     * Prefers the provider's own message when the decoded body
     * carries one (`message`, `error.message`, or
     * `errors[0].message`); falls back to the HTTP status line.
     *
     * @param array{code: int, body: string, data: array<string, mixed>|null, error: string} $result unpackResponse() result.
     * @param string $provider Provider label for the fallback text.
     * @return string
     */
    protected static function apiErrorMessage(array $result, string $provider): string
    {
        if ($result['error'] !== '') {
            return $result['error'];
        }
        $data = $result['data'];
        if (is_array($data)) {
            if (isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
                return trim($data['message']);
            }
            if (isset($data['error']['message']) && is_string($data['error']['message']) && $data['error']['message'] !== '') {
                return trim($data['error']['message']);
            }
            if (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
                return trim($data['error']);
            }
            if (isset($data['errors'][0]['message']) && is_string($data['errors'][0]['message'])) {
                return trim($data['errors'][0]['message']);
            }
        }
        $code = $result['code'];
        if ($code > 0) {
            return sprintf(
                /* translators: %1$s is the provider name, %2$d is the HTTP status code. */
                __('%1$s API error %2$d.', 'traydigita-core'),
                $provider,
                $code
            );
        }
        return sprintf(
            /* translators: %s is the provider name. */
            __('%s API request failed.', 'traydigita-core'),
            $provider
        );
    }

    /**
     * Format one address as `Name <email>` or a bare email.
     *
     * @param array{email: string, name: string} $address Address.
     * @return string
     */
    protected static function formatAddress(array $address): string
    {
        return $address['name'] !== ''
            ? sprintf('%s <%s>', $address['name'], $address['email'])
            : $address['email'];
    }

    /**
     * Format an address list as `Name <email>` strings.
     *
     * @param array<int, array{email: string, name: string}> $addresses Addresses.
     * @return array<int, string>
     */
    protected static function formatAddressList(array $addresses): array
    {
        return array_map([static::class, 'formatAddress'], $addresses);
    }

    /**
     * Format an address list as `['email' => ..., 'name' => ...]` objects.
     *
     * @param array<int, array{email: string, name: string}> $addresses Addresses.
     * @return array<int, array<string, string>>
     */
    protected static function addressObjects(array $addresses): array
    {
        return array_map(
            static function (array $address): array {
                $entry = ['email' => $address['email']];
                if ($address['name'] !== '') {
                    $entry['name'] = $address['name'];
                }
                return $entry;
            },
            $addresses
        );
    }

    /**
     * Plain-text body for a mail: the text body when the mail is
     * plain text, otherwise the HTML body with tags stripped.
     *
     * @param array{body: string, is_html: bool} $mail Normalized mail.
     * @return string
     */
    protected static function plainText(array $mail): string
    {
        if (!$mail['is_html']) {
            return $mail['body'];
        }
        return trim(strip_tags($mail['body']));
    }

    /**
     * Encode attachment files as base64 for a JSON payload.
     *
     * Unreadable or missing files are skipped (the mail still
     * sends - a missing file must not lose the whole message);
     * oversize files fail the send with a clear error instead.
     *
     * @param array<int, string> $files Attachment paths.
     * @return array<int, array{content: string, filename: string, type: string}>|WP_Error
     */
    protected static function encodeAttachments(array $files): array|WP_Error
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
                return new WP_Error(
                    'mailer_attachment',
                    sprintf(
                        /* translators: %s is the attachment file name. */
                        __('Attachment %s is too large (10 MB limit).', 'traydigita-core'),
                        basename($file)
                    )
                );
            }
            $total += $size;
            if ($total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
                return new WP_Error(
                    'mailer_attachment',
                    __('The attachments are too large (25 MB total limit).', 'traydigita-core')
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
     * Read one string field from the settings array.
     *
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @param string $key Field key.
     * @return string
     */
    protected static function setting(array $settings, string $key): string
    {
        $value = $settings[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Guard shared by every send(): no recipients or no From
     * address fails fast with a clear message.
     *
     * @param array{to: array<int, array{email: string, name: string}>, from_email: string} $mail Normalized mail.
     * @return array{success: bool, message: string}|null Null when the mail may be sent.
     */
    protected static function guard(array $mail): ?array
    {
        if ($mail['to'] === []) {
            return ['success' => false, 'message' => __('The email has no recipients.', 'traydigita-core')];
        }
        if ($mail['from_email'] === '') {
            return ['success' => false, 'message' => __('Set a From email address in the Mailer settings first.', 'traydigita-core')];
        }
        return null;
    }
}
