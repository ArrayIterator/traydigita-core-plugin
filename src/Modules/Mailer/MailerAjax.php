<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer;

use Throwable;
use WP_Error;
use function __;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function get_bloginfo;
use function get_current_user_id;
use function get_transient;
use function is_email;
use function is_string;
use function remove_action;
use function sanitize_email;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function set_transient;
use function sprintf;
use function wp_mail;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * "Send test email" AJAX endpoint for the Mailer module.
 *
 * Registered from `MailerModule::boot()` (only when the module is
 * enabled), so the endpoint does not exist while the module is
 * disabled.
 *
 * Request (POST to `admin-ajax.php`):
 * - `action` = `traydigita_mailer_test`
 * - `_ajax_nonce` = nonce for action `traydigita-admin` (the
 *   `check_ajax_referer()` default field; never a field named
 *   `nonce`)
 * - `to` (required, valid email address), `subject` and `body`
 *   (optional; defaults are provided when empty)
 *
 * The test goes through `wp_mail()`, so it exercises the
 * selected provider end to end — SMTP via PHPMailer, SendGrid
 * and Gmail via the `pre_wp_mail` short-circuit. Responses are
 * JSON via `wp_send_json_success()` / `wp_send_json_error()`
 * with a `message` key. On failure the message is the mailer
 * error with secrets redacted; a secret never appears in any
 * response. The endpoint is rate-limited to one test email per
 * 30 seconds per user (HTTP 429 while limited). Only
 * administrators (`manage_options`) may call it, and only while
 * the module is configured; otherwise `wp_mail()` would
 * silently fall back to the default mailer and the "test" would
 * prove nothing about the provider settings.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer
 */
final class MailerAjax
{
    /**
     * AJAX action name for the test-email endpoint.
     */
    public const string AJAX_ACTION = 'traydigita_mailer_test';

    /**
     * Minimum seconds between test emails from the same user.
     */
    public const int RATE_LIMIT_SECONDS = 30;

    /**
     * Register the test-email AJAX action.
     *
     * Called from `MailerModule::boot()`; do not call it directly.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'sendTest']);
    }

    /**
     * Send a test email through `wp_mail()` (which routes through
     * the selected provider when the module is configured).
     *
     * POST: `to` (required), `subject` / `body` (optional).
     * Success records the test in `traydigita_mailer_last_test`
     * (timestamp + success only; the recipient and any error text
     * are never stored). The send itself is also written to the
     * send log by the MailerModule hooks.
     *
     * @return void
     */
    public static function sendTest(): void
    {
        check_ajax_referer(MailerModule::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        if (!MailerModule::isConfigured()) {
            wp_send_json_error(
                ['message' => __('Mailer is not configured yet. Save the provider settings first.', 'traydigita-core')],
                400
            );
        }

        $to = sanitize_email(self::postString('to'));
        if ($to === '' || is_email($to) === false) {
            wp_send_json_error(
                ['message' => __('Please enter a valid recipient email address.', 'traydigita-core')],
                400
            );
        }

        $userId = get_current_user_id();
        $rateLimitKey = 'traydigita_mailer_test_' . $userId;
        if (false !== get_transient($rateLimitKey)) {
            wp_send_json_error(
                [
                    /* translators: %d is the number of seconds to wait before the next test email. */
                    'message' => sprintf(
                        __('Please wait %d seconds before sending another test email.', 'traydigita-core'),
                        self::RATE_LIMIT_SECONDS
                    ),
                ],
                429
            );
        }
        set_transient($rateLimitKey, 1, self::RATE_LIMIT_SECONDS);

        $subject = self::postString('subject');
        if ($subject === '') {
            /* translators: %s is the site name. */
            $subject = sprintf(__('Test email from %s', 'traydigita-core'), (string) get_bloginfo('name'));
        }
        $body = sanitize_textarea_field(wp_unslash(self::postRaw('body')));
        if ($body === '') {
            /* translators: %1$s is the site name, %2$s is the site URL. */
            $body = sprintf(
                __(
                    "This is a test email from %1\$s (%2\$s).\n\nIf you received this message, the Mailer settings are working.",
                    'traydigita-core'
                ),
                (string) get_bloginfo('name'),
                (string) get_bloginfo('url')
            );
        }

        // Capture the WP_Error from the `wp_mail_failed` action so
        // the real mailer error can be returned to the admin.
        // (API providers short-circuit before that action fires,
        // so their failure text comes from the send log instead —
        // see the fallback below.)
        /** @var WP_Error|null $mailError */
        $mailError = null;
        $onMailFailed = static function (WP_Error $error) use (&$mailError): void {
            $mailError = $error;
        };
        add_action('wp_mail_failed', $onMailFailed);
        $failureMessage = null;
        try {
            $sent = wp_mail($to, $subject, $body);
        } catch (Throwable $exception) {
            $sent = false;
            $failureMessage = $exception->getMessage();
        }
        remove_action('wp_mail_failed', $onMailFailed);

        if ($sent) {
            MailerModule::recordLastTest(true);
            wp_send_json_success(
                ['message' => __('Test email sent. Please check the inbox of the recipient address.', 'traydigita-core')]
            );
        }

        $message = $failureMessage;
        if ($message === null && $mailError instanceof WP_Error) {
            $message = $mailError->get_error_message();
        }
        if ($message === null || $message === '') {
            // API providers return false without a WP_Error; the
            // send log holds the provider's own error text.
            $log = MailerModule::getSendLog();
            if (isset($log[0]['error']) && $log[0]['error'] !== '') {
                $message = $log[0]['error'];
            }
        }
        if ($message === null || $message === '') {
            $message = __('WordPress could not send the test email.', 'traydigita-core');
        }
        MailerModule::recordLastTest(false);
        wp_send_json_error(['message' => MailerModule::redact($message)], 500);
    }

    /**
     * Read a POST field as a sanitized string.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Read a POST field as a raw string, without sanitizing.
     *
     * Used for the test-email body, where `sanitize_text_field()`
     * would strip the line breaks; the caller sanitizes with
     * `sanitize_textarea_field()` instead.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postRaw(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
