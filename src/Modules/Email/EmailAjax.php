<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Email;

use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function fclose;
use function fopen;
use function fputcsv;
use function gmdate;
use function header;
use function is_array;
use function is_string;
use function json_decode;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function wp_die;
use function wp_kses;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * AJAX endpoints for the Email module.
 *
 * Admin endpoints (template CRUD, preview, newsletter send, subscriber
 * management, CSV export) require the 'traydigita-admin' nonce and the
 * manage_options capability. The nonce is read from the _ajax_nonce
 * field by check_ajax_referer() with no second argument, matching the
 * widget AJAX pattern.
 *
 * Public endpoints:
 * - traydigita_email_subscribe (priv + nopriv): requires the separate
 *   public nonce action 'traydigita-email-public' (see
 *   EmailModule::PUBLIC_NONCE_ACTION), created with wp_create_nonce()
 *   and printed by EmailModule::subscribeFormHtml().
 * - traydigita_email_confirm / traydigita_email_unsubscribe (GET):
 *   no nonce - the emailed token IS the secret, compared with
 *   hash_equals() only, and rate-limited per IP (10/hour).
 *
 * Endpoint reference (POST fields unless noted):
 * - traydigita_email_save_template: template (JSON string of one template), status ('published' | 'draft')
 * - traydigita_email_autosave: template (JSON string of one template, blocks may be empty)
 * - traydigita_email_get_autosave: template_id
 * - traydigita_email_delete_autosave: template_id
 * - traydigita_email_set_default: template_id
 * - traydigita_email_delete_template: template_id
 * - traydigita_email_preview: blocks (JSON string of block list)
 * - traydigita_email_send_start: subject, template_id, intro (HTML, tight allowlist)
 * - traydigita_email_send_chunk: job_id
 * - traydigita_email_export_csv: GET, nonce=_ajax_nonce value for 'traydigita-admin'
 * - traydigita_email_add_subscriber: email, name
 * - traydigita_email_remove_subscriber: email
 * - traydigita_email_subscribe: email, name (public, public nonce)
 * - traydigita_email_confirm: GET email, token
 * - traydigita_email_unsubscribe: GET email, token
 */
final class EmailAjax
{
    /**
     * Nonce action for all admin Email endpoints.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Register every Email AJAX action.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_email_save_template', [self::class, 'saveTemplate']);
        add_action('wp_ajax_traydigita_email_autosave', [self::class, 'autosaveTemplate']);
        add_action('wp_ajax_traydigita_email_get_autosave', [self::class, 'getAutosave']);
        add_action('wp_ajax_traydigita_email_delete_autosave', [self::class, 'deleteAutosave']);
        add_action('wp_ajax_traydigita_email_set_default', [self::class, 'setDefault']);
        add_action('wp_ajax_traydigita_email_delete_template', [self::class, 'deleteTemplate']);
        add_action('wp_ajax_traydigita_email_preview', [self::class, 'previewTemplate']);
        add_action('wp_ajax_traydigita_email_send_start', [self::class, 'sendStart']);
        add_action('wp_ajax_traydigita_email_send_chunk', [self::class, 'sendChunk']);
        add_action('wp_ajax_traydigita_email_export_csv', [self::class, 'exportCsv']);
        add_action('wp_ajax_traydigita_email_add_subscriber', [self::class, 'addSubscriber']);
        add_action('wp_ajax_traydigita_email_remove_subscriber', [self::class, 'removeSubscriber']);
        add_action('wp_ajax_traydigita_email_subscribe', [self::class, 'subscribe']);
        add_action('wp_ajax_nopriv_traydigita_email_subscribe', [self::class, 'subscribe']);
        add_action('wp_ajax_traydigita_email_confirm', [self::class, 'confirm']);
        add_action('wp_ajax_nopriv_traydigita_email_confirm', [self::class, 'confirm']);
        add_action('wp_ajax_traydigita_email_unsubscribe', [self::class, 'unsubscribe']);
        add_action('wp_ajax_nopriv_traydigita_email_unsubscribe', [self::class, 'unsubscribe']);
    }

    /**
     * Verify the admin nonce and the manage_options capability.
     *
     * Sends a JSON error and stops the request when unauthorized.
     *
     * @return void
     */
    private static function authorizeAdmin(): void
    {
        check_ajax_referer(self::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')], 403);
        }
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
     * Read a POST field as raw (unslashed) text for JSON decoding.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postRaw(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return wp_unslash($value);
    }

    /**
     * Create or update a template.
     *
     * POST: template (JSON string of one template: id, name, blocks),
     * status ('published' or 'draft', default 'published'). The
     * template is sanitized by TemplateBuilder; invalid input is
     * rejected with a 400 error. A draft is editable but never
     * sent; a published save clears the template's autosave row.
     *
     * @return void
     */
    public static function saveTemplate(): void
    {
        self::authorizeAdmin();
        $decoded = json_decode(self::postRaw('template'), true);
        $template = TemplateBuilder::sanitizeTemplate($decoded);
        if ($template === null) {
            wp_send_json_error(['message' => esc_html__('The template needs a name and at least one block.', 'traydigita-core')], 400);
        }
        $status = self::postString('status');
        if ($status !== TemplateStore::STATUS_DRAFT && $status !== TemplateStore::STATUS_PUBLISHED) {
            $status = TemplateStore::STATUS_PUBLISHED;
        }
        $saved = TemplateBuilder::saveTemplate($template, $status);
        wp_send_json_success([
            'template' => $saved,
            'status' => $status,
            'message' => esc_html__('Template saved.', 'traydigita-core'),
        ]);
    }

    /**
     * Autosave the in-progress editor state of a template.
     *
     * POST: template (JSON string of one template: id, name,
     * blocks). Unlike a publish save, empty blocks are allowed:
     * the editor autosaves while the user is still typing. When
     * the template has no id yet, a draft parent row is created
     * first and its id is returned. Drafts are never sent.
     *
     * @return void
     */
    public static function autosaveTemplate(): void
    {
        self::authorizeAdmin();
        $decoded = json_decode(self::postRaw('template'), true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => esc_html__('No template to autosave.', 'traydigita-core')], 400);
        }
        $saved = TemplateBuilder::saveAutosave($decoded);
        if ($saved === null) {
            wp_send_json_error(['message' => esc_html__('Could not autosave the template.', 'traydigita-core')], 400);
        }
        wp_send_json_success($saved);
    }

    /**
     * Read the autosave of a template, with its freshness.
     *
     * POST: template_id. Returns {autosave: {template,
     * updated_at, parent_updated_at, is_newer} | null}. The
     * editor offers a restore only when is_newer is true.
     *
     * @return void
     */
    public static function getAutosave(): void
    {
        self::authorizeAdmin();
        $id = sanitize_key(self::postString('template_id'));
        if ($id === '') {
            wp_send_json_error(['message' => esc_html__('Missing template.', 'traydigita-core')], 400);
        }
        $autosave = TemplateBuilder::getAutosave($id);
        wp_send_json_success([
            'autosave' => $autosave,
            'is_newer' => $autosave !== null && $autosave['is_newer'],
        ]);
    }

    /**
     * Delete the autosave of a template.
     *
     * POST: template_id. Used when the editor discards a
     * restore offer.
     *
     * @return void
     */
    public static function deleteAutosave(): void
    {
        self::authorizeAdmin();
        $id = sanitize_key(self::postString('template_id'));
        if ($id === '') {
            wp_send_json_error(['message' => esc_html__('Missing template.', 'traydigita-core')], 400);
        }
        TemplateBuilder::deleteAutosave($id);
        wp_send_json_success(['message' => esc_html__('Autosave discarded.', 'traydigita-core')]);
    }

    /**
     * Mark a template as the default template.
     *
     * POST: template_id.
     *
     * @return void
     */
    public static function setDefault(): void
    {
        self::authorizeAdmin();
        $id = sanitize_key(self::postString('template_id'));
        if ($id === '' || !TemplateBuilder::setDefault($id)) {
            wp_send_json_error(['message' => esc_html__('Template not found.', 'traydigita-core')], 404);
        }
        wp_send_json_success(['message' => esc_html__('Default template updated.', 'traydigita-core')]);
    }

    /**
     * Delete a template.
     *
     * POST: template_id.
     *
     * @return void
     */
    public static function deleteTemplate(): void
    {
        self::authorizeAdmin();
        $id = sanitize_key(self::postString('template_id'));
        if ($id === '' || !TemplateBuilder::deleteTemplate($id)) {
            wp_send_json_error(['message' => esc_html__('Template not found.', 'traydigita-core')], 404);
        }
        wp_send_json_success(['message' => esc_html__('Template deleted.', 'traydigita-core')]);
    }

    /**
     * Render a live preview of posted blocks with the real renderer.
     *
     * POST: blocks (JSON string of a block list). The preview uses
     * exactly the same TemplateBuilder renderer as sending, so what
     * the admin sees is what subscribers receive.
     *
     * @return void
     */
    public static function previewTemplate(): void
    {
        self::authorizeAdmin();
        $decoded = json_decode(self::postRaw('blocks'), true);
        if (!is_array($decoded)) {
            wp_send_json_error(['message' => esc_html__('No blocks to preview.', 'traydigita-core')], 400);
        }
        $template = TemplateBuilder::sanitizeTemplate([
            'name' => 'preview',
            'blocks' => $decoded,
        ]);
        if ($template === null) {
            wp_send_json_error(['message' => esc_html__('No blocks to preview.', 'traydigita-core')], 400);
        }
        wp_send_json_success(['html' => TemplateBuilder::renderTemplate($template)]);
    }

    /**
     * Start a newsletter send job.
     *
     * POST: subject, template_id, intro (HTML, tight allowlist).
     * Returns the job id and the confirmed-recipient total; the UI
     * then polls traydigita_email_send_chunk until done.
     *
     * @return void
     */
    public static function sendStart(): void
    {
        self::authorizeAdmin();
        $subject = self::postString('subject');
        $templateId = sanitize_key(self::postString('template_id'));
        if ($subject === '') {
            wp_send_json_error(['message' => esc_html__('The newsletter needs a subject.', 'traydigita-core')], 400);
        }
        if (TemplateBuilder::getTemplate($templateId) === null) {
            wp_send_json_error(['message' => esc_html__('Template not found.', 'traydigita-core')], 404);
        }
        $intro = wp_kses(self::postRaw('intro'), TemplateBuilder::ALLOWED_HTML);
        $job = Newsletter::startSend($subject, $templateId, $intro);
        wp_send_json_success($job);
    }

    /**
     * Send one chunk (at most 25 mails) of a send job.
     *
     * POST: job_id. Returns {job_id, sent, failed, total, done}.
     *
     * @return void
     */
    public static function sendChunk(): void
    {
        self::authorizeAdmin();
        $jobId = sanitize_key(self::postString('job_id'));
        if ($jobId === '') {
            wp_send_json_error(['message' => esc_html__('Missing send job.', 'traydigita-core')], 400);
        }
        $result = Newsletter::sendChunk($jobId);
        if ($result === null) {
            wp_send_json_error(['message' => esc_html__('Send job not found or expired.', 'traydigita-core')], 404);
        }
        wp_send_json_success($result);
    }

    /**
     * Export all subscribers as a CSV download (admin only).
     *
     * GET: action=traydigita_email_export_csv, nonce=<nonce for
     * 'traydigita-admin'>. Streams the file with fputcsv and exits.
     * Subscriber emails are never exposed to non-admins: this
     * endpoint requires manage_options.
     *
     * @return void
     */
    public static function exportCsv(): void
    {
        $nonce = isset($_GET['nonce']) && is_string($_GET['nonce']) ? wp_unslash($_GET['nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION) || !current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'traydigita-core'), '', ['response' => 403]);
        }
        $filename = 'traydigita-subscribers-' . gmdate('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('Could not start the download.', 'traydigita-core'), '', ['response' => 500]);
        }
        fputcsv($output, ['email', 'name', 'confirmed', 'created_at']);
        foreach (Newsletter::getSubscribers() as $subscriber) {
            fputcsv($output, [
                $subscriber['email'],
                $subscriber['name'],
                $subscriber['confirmed'] ? 'yes' : 'no',
                $subscriber['created_at'] > 0 ? gmdate('Y-m-d H:i:s', $subscriber['created_at']) : '',
            ]);
        }
        fclose($output);
        exit;
    }

    /**
     * Add a subscriber from the admin (immediately confirmed).
     *
     * POST: email, name.
     *
     * @return void
     */
    public static function addSubscriber(): void
    {
        self::authorizeAdmin();
        $result = Newsletter::addSubscriber(
            sanitize_email(self::postString('email')),
            self::postString('name')
        );
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * Remove a subscriber from the admin.
     *
     * POST: email.
     *
     * @return void
     */
    public static function removeSubscriber(): void
    {
        self::authorizeAdmin();
        $email = sanitize_email(self::postString('email'));
        if (!Newsletter::removeSubscriber($email)) {
            wp_send_json_error(['message' => esc_html__('Subscriber not found.', 'traydigita-core')], 404);
        }
        wp_send_json_success(['message' => esc_html__('Subscriber removed.', 'traydigita-core')]);
    }

    /**
     * Public subscribe endpoint (double opt-in).
     *
     * POST: email, name. Requires the public nonce action
     * 'traydigita-email-public'. Also rate-limited per IP so the
     * endpoint cannot be used to spam confirmation mails.
     *
     * @return void
     */
    public static function subscribe(): void
    {
        check_ajax_referer(EmailModule::PUBLIC_NONCE_ACTION);
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if (!Newsletter::checkRateLimit($ip)) {
            wp_send_json_error(['message' => esc_html__('Too many requests. Please try again later.', 'traydigita-core')], 429);
        }
        $result = Newsletter::subscribe(
            sanitize_email(self::postString('email')),
            self::postString('name')
        );
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']], 400);
        }
        wp_send_json_success(['message' => $result['message']]);
    }

    /**
     * Confirm a subscription from the emailed link (GET, no login).
     *
     * GET: email, token. No nonce: the token IS the secret and is
     * compared with hash_equals(). Rate-limited per IP (10/hour).
     * Responds with a plain confirmation page via wp_die().
     *
     * @return void
     */
    public static function confirm(): void
    {
        $email = isset($_GET['email']) && is_string($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        $token = isset($_GET['token']) && is_string($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if (!Newsletter::checkRateLimit($ip)) {
            wp_die(esc_html__('Too many requests. Please try again later.', 'traydigita-core'), '', ['response' => 429]);
        }
        if (Newsletter::confirm($email, $token)) {
            wp_die(
                esc_html__('Your subscription is confirmed. Thank you!', 'traydigita-core'),
                esc_html__('Subscription confirmed', 'traydigita-core'),
                ['response' => 200]
            );
        }
        wp_die(
            esc_html__('This confirmation link is invalid or has expired.', 'traydigita-core'),
            esc_html__('Subscription not confirmed', 'traydigita-core'),
            ['response' => 400]
        );
    }

    /**
     * Unsubscribe from the emailed link (GET, no login).
     *
     * GET: email, token. No nonce: the token IS the secret and is
     * compared with hash_equals(). Rate-limited per IP (10/hour).
     *
     * @return void
     */
    public static function unsubscribe(): void
    {
        $email = isset($_GET['email']) && is_string($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        $token = isset($_GET['token']) && is_string($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : '';
        $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if (!Newsletter::checkRateLimit($ip)) {
            wp_die(esc_html__('Too many requests. Please try again later.', 'traydigita-core'), '', ['response' => 429]);
        }
        if (Newsletter::unsubscribe($email, $token)) {
            wp_die(
                esc_html__('You have been unsubscribed.', 'traydigita-core'),
                esc_html__('Unsubscribed', 'traydigita-core'),
                ['response' => 200]
            );
        }
        wp_die(
            esc_html__('This unsubscribe link is invalid or has expired.', 'traydigita-core'),
            esc_html__('Not unsubscribed', 'traydigita-core'),
            ['response' => 400]
        );
    }
}
