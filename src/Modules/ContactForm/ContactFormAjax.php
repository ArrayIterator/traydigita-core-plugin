<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ContactForm;

use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateBuilder;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilder;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilderModule;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;
use TrayDigita\WP\Plugin\Core\Modules\Recaptcha\RecaptchaModule;
use TrayDigita\WP\Plugin\Core\Modules\Recaptcha\Verifier as RecaptchaVerifier;
use TrayDigita\WP\Plugin\Core\Modules\Turnstile\TurnstileModule;
use TrayDigita\WP\Plugin\Core\Modules\Turnstile\Verifier as TurnstileVerifier;
use function __;
use function absint;
use function array_map;
use function current_user_can;
use function esc_html;
use function get_option;
use function gmdate;
use function header;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function nl2br;
use function preg_match;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function strlen;
use function trim;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Contact Form submission handling (frontend + admin list).
 *
 * The frontend flow is: nonce → spam verification → Form
 * Builder validation → STORE the submission → send the
 * notification mail via the Core Mailer (only when the form
 * has send-to-email on). The store step happens before the
 * mail step on purpose: a failed mail must never lose the
 * submission. The mail result is recorded in the
 * `email_sent` column and reported in the response.
 *
 * The admin list actions (read/unread, delete, CSV export)
 * run as plain POST/GET requests from the submissions page,
 * each guarded by the submissions nonce + manage_options.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\ContactForm
 */
final class ContactFormAjax
{
    /**
     * Handle a frontend contact form submission (AJAX).
     *
     * @return void
     */
    public static function handleSubmit(): void
    {
        $nonce = isset($_POST['traydigita_form_nonce']) ? sanitize_text_field((string) wp_unslash($_POST['traydigita_form_nonce'])) : '';
        if (!wp_verify_nonce($nonce, FormBuilder::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page and try again.', 'traydigita-core')], 403);
        }
        $formId = isset($_POST['form_id']) ? sanitize_key((string) wp_unslash($_POST['form_id'])) : '';
        $form = $formId !== '' ? ContactFormModule::getForm($formId) : null;
        if ($form === null) {
            wp_send_json_error(['message' => __('Unknown contact form.', 'traydigita-core')], 400);
        }
        $builderForm = FormBuilderModule::getForm($form['form_builder_id']);
        if ($builderForm === null) {
            wp_send_json_error(['message' => __('This form is not configured yet.', 'traydigita-core')], 400);
        }
        // Spam verification happens BEFORE validation so a bot
        // never reaches the store. A failed challenge is a
        // rejection, not a stored submission.
        $spamError = self::verifySpam($form);
        if ($spamError !== '') {
            wp_send_json_error(['message' => $spamError], 403);
        }
        $posted = isset($_POST['td_field']) && is_array($_POST['td_field']) ? wp_unslash($_POST['td_field']) : [];
        $posted[FormBuilder::HONEYPOT_FIELD] = isset($_POST[FormBuilder::HONEYPOT_FIELD]) ? sanitize_text_field((string) wp_unslash($_POST[FormBuilder::HONEYPOT_FIELD])) : '';
        $result = FormBuilder::validateSubmission($builderForm['definition'], $posted);
        if ($result['errors'] !== []) {
            wp_send_json_error(
                [
                    'message' => __('Please check the form and try again.', 'traydigita-core'),
                    'errors' => $result['errors'],
                ],
                422
            );
        }
        $values = $result['values'];
        // Store FIRST: the submission must survive a mail failure.
        $submissionId = SubmissionStore::insert([
            'form_id' => $form['id'],
            'form_title' => $form['title'],
            'name' => self::pickValue($values, 'name'),
            'email' => self::pickValue($values, 'email'),
            'subject' => self::pickValue($values, 'subject'),
            'fields' => $values,
            'ip' => self::clientIp(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'spam' => false,
            'email_sent' => false,
        ]);
        if ($submissionId === 0) {
            wp_send_json_error(['message' => __('Your message could not be saved. Please try again later.', 'traydigita-core')], 500);
        }
        // Retention prune runs on the insert path (cheap, once per
        // submission) in addition to the daily cron event.
        $settings = ContactFormModule::getSettings();
        if ($settings['retention_days'] > 0) {
            SubmissionStore::prune($settings['retention_days']);
        }
        $mailSent = false;
        $mailAttempted = false;
        if (!empty($form['send_email'])) {
            $mailAttempted = true;
            $mailSent = self::sendNotification($form, $values);
            SubmissionStore::markEmailSent($submissionId, $mailSent);
        }
        if ($mailAttempted && !$mailSent) {
            wp_send_json_success([
                'message' => __('Thank you. Your message was saved, but the notification email could not be sent — the site administrator has been notified through the submissions list.', 'traydigita-core'),
                'submission_id' => $submissionId,
            ]);
        }
        wp_send_json_success([
            'message' => __('Thank you. Your message has been sent.', 'traydigita-core'),
            'submission_id' => $submissionId,
        ]);
    }

    /**
     * Verify the spam protection for one form.
     *
     * Returns an error message on failure, or '' when the
     * protection passes (or when the form has none).
     *
     * @param array<string, mixed> $form Form config.
     * @return string Error message, or ''.
     */
    private static function verifySpam(array $form): string
    {
        $mode = ContactFormModule::resolveSpamMode($form);
        if ($mode === 'turnstile') {
            $token = isset($_POST[TurnstileModule::TOKEN_FIELD]) ? sanitize_text_field((string) wp_unslash($_POST[TurnstileModule::TOKEN_FIELD])) : '';
            $result = TurnstileVerifier::verify($token, TurnstileModule::clientIp());
            if (empty($result['success'])) {
                return __('Spam protection failed. Please try again.', 'traydigita-core');
            }
            return '';
        }
        if ($mode === 'recaptcha-v2' || $mode === 'recaptcha-v3') {
            $settings = RecaptchaModule::getSettings();
            $token = isset($_POST[RecaptchaModule::TOKEN_FIELD]) ? sanitize_text_field((string) wp_unslash($_POST[RecaptchaModule::TOKEN_FIELD])) : '';
            $version = $mode === 'recaptcha-v3' ? 'v3' : 'v2';
            $result = RecaptchaVerifier::verify(
                $token,
                $settings['secret_key'],
                $version,
                (float) $settings['v3_threshold'],
                $version === 'v3' ? ContactFormModule::RECAPTCHA_V3_ACTION : ''
            );
            if (!$result['success']) {
                return $result['error'] !== '' ? $result['error'] : __('Spam protection failed. Please try again.', 'traydigita-core');
            }
            return '';
        }
        return '';
    }

    /**
     * Send the notification email for one submission.
     *
     * Uses the form's shared email template when one is set
     * (with {{field}} placeholders replaced), otherwise a
     * plain-text list of the submitted values. Sending goes
     * through the Core Mailer, so the mail appears in the
     * Mailer send log.
     *
     * @param array<string, mixed> $form Form config.
     * @param array<string, mixed> $values Cleaned values keyed by field id.
     * @return bool True when the mail was accepted.
     */
    private static function sendNotification(array $form, array $values): bool
    {
        $recipient = (string) ($form['recipient'] ?? '');
        if ($recipient === '') {
            $settings = ContactFormModule::getSettings();
            $recipient = (string) ($settings['default_recipient'] ?? '');
        }
        if ($recipient === '') {
            $recipient = (string) get_option('admin_email', '');
        }
        if ($recipient === '') {
            return false;
        }
        $prefix = (string) ($form['subject_prefix'] ?? '');
        $subject = trim($prefix . ' ' . sprintf(
            /* translators: %s: contact form title. */
            __('New contact form submission: %s', 'traydigita-core'),
            (string) ($form['title'] ?? '')
        ));
        $template = null;
        if (isset($form['email_template_id']) && is_string($form['email_template_id']) && $form['email_template_id'] !== '') {
            $template = FormBuilderModule::getEmailTemplate($form['email_template_id']);
        }
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        if ($template !== null) {
            $body = TemplateBuilder::renderEmail($template, $values);
            if ($body === '') {
                $body = self::plainBody($values);
            }
        } else {
            $body = self::plainBody($values);
        }
        $from = self::pickValue($values, 'email');
        if ($from !== '') {
            $fromName = self::pickValue($values, 'name');
            $headers[] = 'Reply-To: ' . ($fromName !== '' ? $fromName . ' ' : '') . '<' . $from . '>';
        }
        return MailerModule::sendMail($recipient, $subject, $body, $headers);
    }

    /**
     * Plain-text body listing the submitted values.
     *
     * @param array<string, mixed> $values Cleaned values keyed by field id.
     * @return string
     */
    private static function plainBody(array $values): string
    {
        $lines = [];
        foreach ($values as $fieldId => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $lines[] = (string) $fieldId . ': ' . (string) $value;
        }
        return '<p>' . nl2br(esc_html(implode("\n", $lines))) . '</p>';
    }

    /**
     * Pick a value by field id (name/email/subject).
     *
     * @param array<string, mixed> $values Cleaned values.
     * @param string $fieldId Field id to look for.
     * @return string
     */
    private static function pickValue(array $values, string $fieldId): string
    {
        $value = $values[$fieldId] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        return is_string($value) ? $value : '';
    }

    /**
     * Best-effort client IP for the submission record.
     *
     * @return string
     */
    private static function clientIp(): string
    {
        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return '';
    }

    /**
     * Read the submissions list filters from $_GET.
     *
     * @return array<string, mixed>
     */
    public static function submissionsFilters(): array
    {
        $filters = [];
        if (isset($_GET['form_id']) && is_string($_GET['form_id'])) {
            $formId = sanitize_key(wp_unslash($_GET['form_id']));
            if ($formId !== '') {
                $filters['form_id'] = $formId;
            }
        }
        if (isset($_GET['status']) && is_string($_GET['status'])) {
            $status = sanitize_key(wp_unslash($_GET['status']));
            if (in_array($status, ['read', 'unread'], true)) {
                $filters['status'] = $status;
            }
        }
        if (isset($_GET['from']) && is_string($_GET['from'])) {
            $from = sanitize_text_field(wp_unslash($_GET['from']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
                $filters['from'] = $from;
            }
        }
        if (isset($_GET['to']) && is_string($_GET['to'])) {
            $to = sanitize_text_field(wp_unslash($_GET['to']));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
                $filters['to'] = $to;
            }
        }
        if (isset($_GET['s']) && is_string($_GET['s'])) {
            $search = sanitize_text_field(wp_unslash($_GET['s']));
            if ($search !== '') {
                $filters['search'] = $search;
            }
        }
        return $filters;
    }

    /**
     * Handle submissions list actions (POST) and CSV export (GET).
     *
     * Actions: mark_read, mark_unread, delete (POST, nonce +
     * manage_options). Export: ?export=csv (GET, nonce +
     * manage_options) streams the CSV and ends the request.
     *
     * @return string|null 'csv' when the CSV was streamed, else null.
     */
    public static function handleSubmissionsActions(): ?string
    {
        if (!current_user_can('manage_options')) {
            return null;
        }
        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, ContactFormModule::SUBMISSIONS_NONCE_ACTION)) {
            // No valid nonce: no action runs. The list still renders.
            return null;
        }
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $csv = SubmissionStore::exportCsv(self::submissionsFilters());
            $filename = 'contact-submissions-' . gmdate('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string) strlen($csv));
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV is generated from stored submissions, not user input.
            echo $csv;
            exit;
        }
        if (isset($_POST['submission_action']) && is_string($_POST['submission_action'])) {
            $action = sanitize_key(wp_unslash($_POST['submission_action']));
            $id = isset($_POST['submission_id']) ? absint((string) wp_unslash($_POST['submission_id'])) : 0;
            if ($id > 0) {
                if ($action === 'mark_read') {
                    SubmissionStore::markRead($id, true);
                } elseif ($action === 'mark_unread') {
                    SubmissionStore::markRead($id, false);
                } elseif ($action === 'delete') {
                    SubmissionStore::delete($id);
                }
            }
        }
        return null;
    }
}
