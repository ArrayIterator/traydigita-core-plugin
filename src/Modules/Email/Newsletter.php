<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Email;

use function __;
use function absint;
use function add_query_arg;
use function admin_url;
use function array_filter;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function delete_transient;
use function esc_html__;
use function esc_url;
use function filter_var;
use function get_bloginfo;
use function get_option;
use function get_transient;
use function hash_equals;
use function is_array;
use function is_email;
use function random_bytes;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function time;
use function update_option;
use function usort;
use function wp_generate_uuid4;
use function wp_hash;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;

/**
 * Newsletter subscribers, double opt-in tokens, chunked sending and log.
 *
 * Subscribers live in the single option 'traydigita_email_subscribers'
 * (autoload 'no'), capped at 5000 entries. Each subscriber is:
 * ['email' => string, 'name' => string, 'token' => string (32 hex chars),
 *  'confirmed' => bool, 'created_at' => int].
 *
 * Double opt-in: a subscription stores the address unconfirmed and mails
 * a confirmation link. The token in that link IS the secret, so the
 * confirmation and unsubscribe GET endpoints take no nonce; the token
 * is compared with hash_equals() only, and both endpoints are
 * rate-limited per IP (10 attempts per hour). Newsletters are never
 * sent to unconfirmed addresses.
 *
 * Sending is chunked: one AJAX call sends at most 25 mails. Progress
 * is tracked in a transient keyed by the send job id. The send log
 * lives in 'traydigita_email_log' (autoload 'no', last 50 entries).
 */
final class Newsletter
{
    /**
     * Option name holding the subscriber list.
     */
    public const string OPTION_SUBSCRIBERS = 'traydigita_email_subscribers';

    /**
     * Option name holding the send log.
     */
    public const string OPTION_LOG = 'traydigita_email_log';

    /**
     * Maximum number of subscribers. New subscriptions past this cap
     * are refused with a clear message.
     */
    public const int MAX_SUBSCRIBERS = 5000;

    /**
     * Maximum number of log entries kept.
     */
    public const int MAX_LOG_ENTRIES = 50;

    /**
     * Mails sent per chunked-send request. A single request never
     * sends more than this many mails.
     */
    public const int CHUNK_SIZE = 25;

    /**
     * Maximum confirmation / unsubscribe attempts per IP per hour.
     */
    public const int RATE_LIMIT_PER_HOUR = 10;

    /**
     * How long a send job transient lives (seconds).
     */
    public const int JOB_TTL = 3600;

    /**
     * Option name holding the newsletter settings.
     *
     * Shape: `['double_opt_in' => bool, 'from_name' => string,
     * 'from_email' => string]`. An empty sender name/email means
     * "use the Mailer From identity".
     */
    public const string OPTION_SETTINGS = 'traydigita_email_settings';

    /**
     * Read the newsletter settings, merged over the defaults.
     *
     * @return array{double_opt_in: bool, from_name: string, from_email: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION_SETTINGS, null);
        $stored = is_array($stored) ? $stored : [];
        $fromEmail = sanitize_email((string) ($stored['from_email'] ?? ''));
        if ($fromEmail !== '' && is_email($fromEmail) === false) {
            $fromEmail = '';
        }
        return [
            'double_opt_in' => !isset($stored['double_opt_in']) || (bool) $stored['double_opt_in'],
            'from_name' => sanitize_text_field((string) ($stored['from_name'] ?? '')),
            'from_email' => $fromEmail,
        ];
    }

    /**
     * Validate and save the newsletter settings.
     *
     * Only keys present in `$data` are changed; missing keys keep
     * their stored values. The double opt-in toggle accepts any
     * boolean-ish value; the sender email must be a valid address
     * (or empty, which means "use the Mailer From identity").
     *
     * @param array<string, mixed> $data Submitted settings.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        $settings = self::getSettings();
        if (array_key_exists('double_opt_in', $data)) {
            $settings['double_opt_in'] = (bool) filter_var($data['double_opt_in'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('from_name', $data)) {
            $settings['from_name'] = sanitize_text_field((string) ($data['from_name'] ?? ''));
        }
        if (array_key_exists('from_email', $data)) {
            $email = sanitize_email((string) ($data['from_email'] ?? ''));
            if ($email !== '' && is_email($email) === false) {
                return [
                    'success' => false,
                    'message' => __('Please enter a valid sender email address.', 'traydigita-core'),
                ];
            }
            $settings['from_email'] = $email;
        }
        update_option(self::OPTION_SETTINGS, $settings, false);
        return [
            'success' => true,
            'message' => __('Newsletter settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * From header for newsletter mail, from the newsletter sender
     * identity (empty when the Mailer From identity applies).
     *
     * @return array<int, string>
     */
    public static function senderHeaders(): array
    {
        $settings = self::getSettings();
        if ($settings['from_email'] === '') {
            return [];
        }
        $from = $settings['from_name'] !== ''
            ? sprintf('%s <%s>', $settings['from_name'], $settings['from_email'])
            : $settings['from_email'];
        return ['From: ' . $from];
    }

    /**
     * Get all subscribers, ordered by created_at ascending.
     *
     * @return list<array{email: string, name: string, token: string, confirmed: bool, created_at: int}>
     */
    public static function getSubscribers(): array
    {
        $stored = get_option(self::OPTION_SUBSCRIBERS, []);
        if (!is_array($stored)) {
            return [];
        }
        $subscribers = [];
        foreach ($stored as $subscriber) {
            if (!is_array($subscriber) || !isset($subscriber['email'], $subscriber['token'])) {
                continue;
            }
            $subscribers[] = [
                'email' => sanitize_email((string) $subscriber['email']),
                'name' => sanitize_text_field((string) ($subscriber['name'] ?? '')),
                'token' => (string) $subscriber['token'],
                'confirmed' => (bool) ($subscriber['confirmed'] ?? false),
                'created_at' => absint($subscriber['created_at'] ?? 0),
            ];
        }
        usort($subscribers, static fn(array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
        return $subscribers;
    }

    /**
     * Get confirmed subscribers only, in send order.
     *
     * @return list<array{email: string, name: string, token: string, confirmed: bool, created_at: int}>
     */
    public static function getConfirmedSubscribers(): array
    {
        return array_values(array_filter(
            self::getSubscribers(),
            static fn(array $subscriber): bool => $subscriber['confirmed']
        ));
    }

    /**
     * Find a subscriber by email address.
     *
     * @param string $email Email address.
     * @return array{email: string, name: string, token: string, confirmed: bool, created_at: int}|null
     */
    public static function findSubscriber(string $email): ?array
    {
        $email = sanitize_email($email);
        foreach (self::getSubscribers() as $subscriber) {
            if ($subscriber['email'] === $email) {
                return $subscriber;
            }
        }
        return null;
    }

    /**
     * Persist the subscriber list.
     *
     * @param list<array<string, mixed>> $subscribers Subscriber list.
     * @return void
     */
    private static function saveSubscribers(array $subscribers): void
    {
        update_option(self::OPTION_SUBSCRIBERS, array_values($subscribers), false);
    }

    /**
     * Subscribe an address (public double opt-in flow).
     *
     * Stores the address unconfirmed and sends a confirmation mail.
     * Re-subscribing an unconfirmed address resends the confirmation
     * mail with a fresh token; an already confirmed address is left
     * untouched. Returns a generic message so the endpoint never
     * reveals whether an address is already subscribed.
     *
     * @param string $email Email address.
     * @param string $name Optional subscriber name.
     * @return array{success: bool, message: string}
     */
    public static function subscribe(string $email, string $name = ''): array
    {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return ['success' => false, 'message' => __('Please enter a valid email address.', 'traydigita-core')];
        }
        $subscribers = self::getSubscribers();
        $existing = self::findSubscriber($email);
        if ($existing !== null && $existing['confirmed']) {
            return ['success' => true, 'message' => __('Please check your inbox to confirm your subscription.', 'traydigita-core')];
        }
        if ($existing === null && count($subscribers) >= self::MAX_SUBSCRIBERS) {
            return ['success' => false, 'message' => __('Subscriptions are closed for now. Please try again later.', 'traydigita-core')];
        }
        $token = bin2hex(random_bytes(16));
        $settings = self::getSettings();
        $record = [
            'email' => $email,
            'name' => sanitize_text_field($name),
            'token' => $token,
            'confirmed' => !$settings['double_opt_in'],
            'created_at' => $existing !== null ? $existing['created_at'] : time(),
        ];
        if ($existing !== null) {
            foreach ($subscribers as $index => $subscriber) {
                if ($subscriber['email'] === $email) {
                    $subscribers[$index] = $record;
                }
            }
        } else {
            $subscribers[] = $record;
        }
        self::saveSubscribers($subscribers);
        if (!$settings['double_opt_in']) {
            // Single opt-in: the address is confirmed at once and
            // no confirmation mail goes out.
            return ['success' => true, 'message' => __('You are subscribed.', 'traydigita-core')];
        }
        self::sendConfirmationMail($record);
        return ['success' => true, 'message' => __('Please check your inbox to confirm your subscription.', 'traydigita-core')];
    }

    /**
     * Send the double opt-in confirmation mail.
     *
     * The mail body is plain text with the confirmation link; it is
     * wrapped by the default email template through the wp_mail filter
     * when one is configured. Sent through the Core Mailer
     * (MailerModule::sendMail()), so the selected provider delivers
     * it and it appears in the Mailer send log.
     *
     * @param array{email: string, name: string, token: string, confirmed: bool, created_at: int} $subscriber Subscriber record.
     * @return void
     */
    private static function sendConfirmationMail(array $subscriber): void
    {
        $link = add_query_arg(
            ['email' => $subscriber['email'], 'token' => $subscriber['token']],
            admin_url('admin-ajax.php?action=traydigita_email_confirm')
        );
        $subject = sprintf(
            // translators: %s: site name.
            __('Confirm your subscription to %s', 'traydigita-core'),
            get_bloginfo('name')
        );
        $body = sprintf(
            // translators: 1: site name, 2: confirmation link.
            __('Thank you for subscribing to %1$s. Please confirm your subscription by opening this link: %2$s', 'traydigita-core'),
            get_bloginfo('name'),
            $link
        );
        MailerModule::sendMail($subscriber['email'], $subject, $body, self::senderHeaders());
    }

    /**
     * Confirm a subscription from the emailed link.
     *
     * The token is compared with hash_equals() only. No nonce is
     * required on this GET link because the token itself is the
     * secret; callers are rate-limited per IP (see EmailAjax).
     *
     * @param string $email Email address from the link.
     * @param string $token Token from the link.
     * @return bool True when the subscription was confirmed.
     */
    public static function confirm(string $email, string $token): bool
    {
        $subscriber = self::findSubscriber($email);
        if ($subscriber === null || $token === '') {
            return false;
        }
        if (!hash_equals($subscriber['token'], $token)) {
            return false;
        }
        if ($subscriber['confirmed']) {
            return true;
        }
        $subscribers = self::getSubscribers();
        foreach ($subscribers as $index => $record) {
            if ($record['email'] === $subscriber['email']) {
                $subscribers[$index]['confirmed'] = true;
            }
        }
        self::saveSubscribers($subscribers);
        return true;
    }

    /**
     * Unsubscribe an address from the emailed link.
     *
     * Token comparison uses hash_equals() only; no nonce is required
     * on this GET link because the token itself is the secret.
     *
     * @param string $email Email address from the link.
     * @param string $token Token from the link.
     * @return bool True when the address was removed.
     */
    public static function unsubscribe(string $email, string $token): bool
    {
        $subscriber = self::findSubscriber($email);
        if ($subscriber === null || $token === '') {
            return false;
        }
        if (!hash_equals($subscriber['token'], $token)) {
            return false;
        }
        $subscribers = self::getSubscribers();
        $subscribers = array_values(array_filter(
            $subscribers,
            static fn(array $record): bool => $record['email'] !== $subscriber['email']
        ));
        self::saveSubscribers($subscribers);
        return true;
    }

    /**
     * Add a subscriber from the admin (immediately confirmed).
     *
     * An admin adding an address is an explicit act, so the record
     * is created confirmed without a confirmation mail.
     *
     * @param string $email Email address.
     * @param string $name Optional subscriber name.
     * @return array{success: bool, message: string}
     */
    public static function addSubscriber(string $email, string $name = ''): array
    {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return ['success' => false, 'message' => __('Please enter a valid email address.', 'traydigita-core')];
        }
        $subscribers = self::getSubscribers();
        if (self::findSubscriber($email) !== null) {
            return ['success' => false, 'message' => __('That email address is already subscribed.', 'traydigita-core')];
        }
        if (count($subscribers) >= self::MAX_SUBSCRIBERS) {
            return ['success' => false, 'message' => __('The subscriber limit has been reached.', 'traydigita-core')];
        }
        $subscribers[] = [
            'email' => $email,
            'name' => sanitize_text_field($name),
            'token' => bin2hex(random_bytes(16)),
            'confirmed' => true,
            'created_at' => time(),
        ];
        self::saveSubscribers($subscribers);
        return ['success' => true, 'message' => __('Subscriber added.', 'traydigita-core')];
    }

    /**
     * Remove a subscriber from the admin.
     *
     * @param string $email Email address.
     * @return bool True when a subscriber was removed.
     */
    public static function removeSubscriber(string $email): bool
    {
        $email = sanitize_email($email);
        if (self::findSubscriber($email) === null) {
            return false;
        }
        $subscribers = array_values(array_filter(
            self::getSubscribers(),
            static fn(array $record): bool => $record['email'] !== $email
        ));
        self::saveSubscribers($subscribers);
        return true;
    }

    /**
     * Build the unsubscribe link for a subscriber.
     *
     * @param array{email: string, name: string, token: string, confirmed: bool, created_at: int} $subscriber Subscriber record.
     * @return string
     */
    public static function unsubscribeLink(array $subscriber): string
    {
        return add_query_arg(
            ['email' => $subscriber['email'], 'token' => $subscriber['token']],
            admin_url('admin-ajax.php?action=traydigita_email_unsubscribe')
        );
    }

    /**
     * Unsubscribe link for an address, or '' when the address is
     * not a subscriber. Used by the Mailer List-Unsubscribe
     * header (see EmailModule::unsubscribeUrl()).
     *
     * @param string $email Subscriber email address.
     * @return string
     */
    public static function unsubscribeUrlFor(string $email): string
    {
        $subscriber = self::findSubscriber($email);
        if ($subscriber === null) {
            return '';
        }
        return self::unsubscribeLink($subscriber);
    }

    /**
     * Rate-limit helper for public token endpoints.
     *
     * Counts attempts per hashed client IP in a transient. Returns
     * false once the hourly limit is reached. The raw IP is never
     * stored; only its salted hash is used as the transient key.
     *
     * @param string $ip Client IP address.
     * @return bool True when the attempt is allowed.
     */
    public static function checkRateLimit(string $ip): bool
    {
        $key = 'traydigita_email_rl_' . sanitize_key(wp_hash($ip));
        $attempts = absint(get_transient($key));
        if ($attempts >= self::RATE_LIMIT_PER_HOUR) {
            return false;
        }
        set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Start a newsletter send job.
     *
     * The job state (offset, totals, subject, template, intro) is
     * kept in a transient keyed by the returned job id. The job id
     * is a UUID, so it cannot be guessed to hijack another send.
     *
     * @param string $subject Newsletter subject.
     * @param string $templateId Template id to render the mail with.
     * @param string $introHtml Optional intro HTML (already sanitized).
     * @return array{job_id: string, total: int}
     */
    public static function startSend(string $subject, string $templateId, string $introHtml = ''): array
    {
        $jobId = wp_generate_uuid4();
        $total = count(self::getConfirmedSubscribers());
        set_transient(
            self::jobKey($jobId),
            [
                'job_id' => $jobId,
                'subject' => sanitize_text_field($subject),
                'template_id' => sanitize_key($templateId),
                'intro' => $introHtml,
                'total' => $total,
                'offset' => 0,
                'sent' => 0,
                'failed' => 0,
                'started_at' => time(),
            ],
            self::JOB_TTL
        );
        return ['job_id' => $jobId, 'total' => $total];
    }

    /**
     * Transient key for a send job.
     *
     * @param string $jobId Job id.
     * @return string
     */
    public static function jobKey(string $jobId): string
    {
        return 'traydigita_email_job_' . sanitize_key($jobId);
    }

    /**
     * Send one chunk (at most CHUNK_SIZE mails) of a send job.
     *
     * Recipients are confirmed subscribers ordered by created_at.
     * When the chunk finishes the list, the job is logged and the
     * transient removed.
     *
     * @param string $jobId Job id from startSend().
     * @return array{job_id: string, sent: int, failed: int, total: int, done: bool}|null
     *         Null when the job id is unknown or expired.
     */
    public static function sendChunk(string $jobId): ?array
    {
        $job = get_transient(self::jobKey($jobId));
        if (!is_array($job)) {
            return null;
        }
        $template = TemplateBuilder::getTemplate((string) ($job['template_id'] ?? ''));
        $recipients = self::getConfirmedSubscribers();
        $total = (int) ($job['total'] ?? count($recipients));
        $offset = absint($job['offset'] ?? 0);
        $sent = absint($job['sent'] ?? 0);
        $failed = absint($job['failed'] ?? 0);
        $chunk = array_slice($recipients, $offset, self::CHUNK_SIZE);
        foreach ($chunk as $subscriber) {
            $intro = (string) ($job['intro'] ?? '');
            $footer = '<p style="font-size:12px;color:#888888;">'
                . '<a href="' . esc_url(self::unsubscribeLink($subscriber)) . '">'
                . esc_html__('Unsubscribe', 'traydigita-core') . '</a></p>';
            $content = $intro === '' ? $footer : $intro . $footer;
            $body = $template !== null
                ? TemplateBuilder::renderTemplate($template, $content)
                : TemplateBuilder::renderFallbackDocument($content);
            // Sent through the Core Mailer (MailerModule::sendMail()),
            // so the selected provider delivers it and each send
            // appears in the Mailer send log. The bulk header marks
            // the mail as a newsletter for the deliverability
            // headers (Precedence: bulk, Feedback-ID).
            $mailed = MailerModule::sendMail(
                $subscriber['email'],
                (string) ($job['subject'] ?? ''),
                $body,
                array_merge(
                    ['Content-Type: text/html; charset=UTF-8', 'X-TrayDigita-Bulk: 1'],
                    self::senderHeaders()
                )
            );
            if ($mailed) {
                $sent++;
            } else {
                $failed++;
            }
        }
        $offset += count($chunk);
        $done = $offset >= $total || $chunk === [];
        if ($done) {
            self::appendLog([
                'job_id' => (string) ($job['job_id'] ?? $jobId),
                'subject' => (string) ($job['subject'] ?? ''),
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed,
                'started_at' => absint($job['started_at'] ?? time()),
                'finished_at' => time(),
            ]);
            delete_transient(self::jobKey($jobId));
        } else {
            $job['offset'] = $offset;
            $job['sent'] = $sent;
            $job['failed'] = $failed;
            set_transient(self::jobKey($jobId), $job, self::JOB_TTL);
        }
        return [
            'job_id' => $jobId,
            'sent' => $sent,
            'failed' => $failed,
            'total' => $total,
            'done' => $done,
        ];
    }

    /**
     * Append an entry to the send log, keeping only the last 50.
     *
     * @param array<string, mixed> $entry Log entry.
     * @return void
     */
    public static function appendLog(array $entry): void
    {
        $log = self::getLog();
        $log[] = [
            'job_id' => (string) ($entry['job_id'] ?? ''),
            'subject' => sanitize_text_field((string) ($entry['subject'] ?? '')),
            'total' => absint($entry['total'] ?? 0),
            'sent' => absint($entry['sent'] ?? 0),
            'failed' => absint($entry['failed'] ?? 0),
            'started_at' => absint($entry['started_at'] ?? time()),
            'finished_at' => absint($entry['finished_at'] ?? time()),
        ];
        if (count($log) > self::MAX_LOG_ENTRIES) {
            $log = array_slice($log, -self::MAX_LOG_ENTRIES);
        }
        update_option(self::OPTION_LOG, $log, false);
    }

    /**
     * Get the send log, newest first.
     *
     * @return list<array{job_id: string, subject: string, total: int, sent: int, failed: int, started_at: int, finished_at: int}>
     */
    public static function getLog(): array
    {
        $log = get_option(self::OPTION_LOG, []);
        if (!is_array($log)) {
            return [];
        }
        $entries = [];
        foreach (array_reverse($log) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entries[] = [
                'job_id' => (string) ($entry['job_id'] ?? ''),
                'subject' => sanitize_text_field((string) ($entry['subject'] ?? '')),
                'total' => absint($entry['total'] ?? 0),
                'sent' => absint($entry['sent'] ?? 0),
                'failed' => absint($entry['failed'] ?? 0),
                'started_at' => absint($entry['started_at'] ?? 0),
                'finished_at' => absint($entry['finished_at'] ?? 0),
            ];
        }
        return $entries;
    }
}
