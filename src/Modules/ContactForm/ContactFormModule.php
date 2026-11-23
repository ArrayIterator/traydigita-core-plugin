<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ContactForm;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilder;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilderModule;
use TrayDigita\WP\Plugin\Core\Modules\Turnstile\TurnstileModule;
use function __;
use function absint;
use function add_action;
use function add_shortcode;
use function add_submenu_page;
use function admin_url;
use function array_keys;
use function array_merge;
use function current_user_can;
use function esc_attr;
use function esc_html__;
use function esc_js;
use function file_exists;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function rawurlencode;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function time;
use function update_option;
use function wp_add_inline_script;
use function wp_create_nonce;
use function wp_die;
use function wp_enqueue_script;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_unslash;

/**
 * Contact Form module (Flamingo pattern).
 *
 * Configurable contact forms built with the Form Builder:
 * submissions are stored in their own table FIRST (see
 * SubmissionStore) and only then is the notification email
 * sent via the Core Mailer — a submission is never lost even
 * when the email fails. Each form has its own "send to email"
 * toggle: when off, submissions are stored only (send is
 * caught and prevented). Spam protection is Turnstile (when
 * that module is active and configured) and reCAPTCHA v2/v3
 * (site + secret keys in the settings, score threshold for
 * v3), chosen per form.
 *
 * Admin: the module's own page in the TrayDigita submenu is
 * the submissions list (it appears only while the module is
 * active); the form/spam settings live on the module settings
 * page, reachable from the Settings hub and from a link on the
 * submissions page. The frontend form is the
 * [traydigita_contact_form] shortcode.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\ContactForm
 */
final class ContactFormModule implements ModuleInterface
{
    /**
     * Option holding the module settings (spam keys, retention,
     * default recipient).
     */
    public const string OPTION = 'traydigita_contact_form_settings';

    /**
     * Option holding the contact forms.
     *
     * Shape: [formId => ['id', 'title', 'form_builder_id',
     * 'recipient', 'send_email' (bool), 'spam'
     * ('auto'|'turnstile'|'recaptcha-v2'|'recaptcha-v3'|'none'),
     * 'email_template_id', 'subject_prefix']].
     */
    public const string OPTION_FORMS = 'traydigita_contact_forms';

    /**
     * Admin page slug of the submissions list.
     */
    public const string SUBMISSIONS_SLUG = 'traydigita-contact-submissions';

    /**
     * AJAX action for frontend form submissions.
     */
    public const string SUBMIT_ACTION = 'traydigita_contact_form_submit';

    /**
     * Nonce action for the admin save endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Nonce action for submissions list actions (delete, read/unread, export).
     */
    public const string SUBMISSIONS_NONCE_ACTION = 'traydigita_contact_submissions';

    /**
     * reCAPTCHA v3 action name sent to Google.
     */
    public const string RECAPTCHA_V3_ACTION = 'traydigita_contact_form';

    /**
     * reCAPTCHA JavaScript API URL (v3, render mode).
     */
    public const string RECAPTCHA_SCRIPT_URL = 'https://www.google.com/recaptcha/api.js';

    /**
     * Spam protection choices per form.
     *
     * @var array<int, string>
     */
    public const array SPAM_MODES = [
        'auto',
        'turnstile',
        'recaptcha-v2',
        'recaptcha-v3',
        'none',
    ];

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'contact-form';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'communication';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Contact Form', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Contact forms built with the Form Builder: submissions are stored in their own table, sent via the Core Mailer, and protected by Turnstile or reCAPTCHA.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'email-alt';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-contact-form';
    }

    /**
     * Whether the module appears in the TrayDigita submenu.
     *
     * Only while the module is active: a disabled module must
     * not show its submissions page in the menu.
     *
     * @return bool
     */
    public static function showInSubmenu(): bool
    {
        return ModuleRegistry::isEnabled(self::id());
    }

    /**
     * Submenu position (after Site Health / SEO).
     *
     * @return int
     */
    public static function submenuPosition(): int
    {
        return 20;
    }

    /**
     * The submenu entry opens the submissions list.
     *
     * @return string
     */
    public static function openLink(): string
    {
        return 'admin.php?page=' . self::SUBMISSIONS_SLUG;
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured.
     *
     * Configured means at least one contact form exists whose
     * Form Builder form still exists.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        foreach (self::getForms() as $form) {
            if (FormBuilderModule::getForm($form['form_builder_id']) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether this is a core module.
     *
     * @return bool Always false.
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Framework sections for the left navigation.
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'forms', 'label' => __('Forms', 'traydigita-core'), 'icon' => 'email-alt'],
            ['id' => 'spam', 'label' => __('Spam protection', 'traydigita-core'), 'icon' => 'shield'],
        ];
    }

    /**
     * Register the module's hooks.
     *
     * Shortcode + submission endpoint on the frontend; the
     * hidden submissions page and the daily retention prune in
     * the admin.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_shortcode('traydigita_contact_form', [self::class, 'renderShortcode']);
        add_action('wp_ajax_' . self::SUBMIT_ACTION, [ContactFormAjax::class, 'handleSubmit']);
        add_action('wp_ajax_nopriv_' . self::SUBMIT_ACTION, [ContactFormAjax::class, 'handleSubmit']);
        add_action('admin_menu', [self::class, 'registerSubmissionsPage']);
        add_action(SubmissionStore::PRUNE_CRON_HOOK, [self::class, 'pruneSubmissions']);
        if (!wp_next_scheduled(SubmissionStore::PRUNE_CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', SubmissionStore::PRUNE_CRON_HOOK);
        }
    }

    /**
     * Register the submissions page (hidden from the menu).
     *
     * The visible menu entry comes from showInSubmenu() /
     * openLink() above; this page is registered with a null
     * parent so WordPress keeps it reachable at its deep link
     * without listing it a second time in the menu.
     *
     * @return void
     */
    public static function registerSubmissionsPage(): void
    {
        add_submenu_page(
            null,
            __('Contact Submissions', 'traydigita-core'),
            __('Contact Submissions', 'traydigita-core'),
            'manage_options',
            self::SUBMISSIONS_SLUG,
            [self::class, 'renderSubmissionsPage']
        );
    }

    /**
     * Daily retention prune (cron callback).
     *
     * @return void
     */
    public static function pruneSubmissions(): void
    {
        $settings = self::getSettings();
        SubmissionStore::ensureTable();
        SubmissionStore::prune($settings['retention_days']);
    }

    /**
     * Default settings.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
            'recaptcha_v3_threshold' => 0.5,
            'retention_days' => 90,
            'default_recipient' => '',
        ];
    }

    /**
     * Read the module settings, merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::defaults(), $stored);
        $settings['recaptcha_v3_threshold'] = max(0.0, min(1.0, (float) $settings['recaptcha_v3_threshold']));
        $settings['retention_days'] = max(0, (int) $settings['retention_days']);
        if (!is_string($settings['recaptcha_site_key'])) {
            $settings['recaptcha_site_key'] = '';
        }
        if (!is_string($settings['recaptcha_secret_key'])) {
            $settings['recaptcha_secret_key'] = '';
        }
        if (!is_string($settings['default_recipient'])) {
            $settings['default_recipient'] = '';
        }
        return $settings;
    }

    /**
     * Read all contact forms.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getForms(): array
    {
        $stored = get_option(self::OPTION_FORMS, []);
        if (!is_array($stored)) {
            return [];
        }
        $forms = [];
        foreach ($stored as $id => $form) {
            if (!is_string($id) || !is_array($form)) {
                continue;
            }
            if (($form['id'] ?? '') !== $id) {
                continue;
            }
            $forms[$id] = self::sanitizeFormConfig($form);
        }
        return $forms;
    }

    /**
     * Read one contact form by id, or null.
     *
     * @param string $id Form id.
     * @return array<string, mixed>|null
     */
    public static function getForm(string $id): ?array
    {
        $forms = self::getForms();
        return $forms[$id] ?? null;
    }

    /**
     * Sanitize one stored form config into its canonical shape.
     *
     * @param array<string, mixed> $form Raw config.
     * @return array<string, mixed>
     */
    private static function sanitizeFormConfig(array $form): array
    {
        $spam = isset($form['spam']) && is_string($form['spam']) && in_array($form['spam'], self::SPAM_MODES, true)
            ? $form['spam']
            : 'auto';
        return [
            'id' => isset($form['id']) && is_string($form['id']) ? $form['id'] : '',
            'title' => isset($form['title']) && is_string($form['title']) ? sanitize_text_field($form['title']) : '',
            'form_builder_id' => isset($form['form_builder_id']) && is_string($form['form_builder_id']) ? sanitize_key($form['form_builder_id']) : '',
            'recipient' => isset($form['recipient']) && is_string($form['recipient']) ? sanitize_email($form['recipient']) : '',
            'send_email' => !empty($form['send_email']),
            'spam' => $spam,
            'email_template_id' => isset($form['email_template_id']) && is_string($form['email_template_id']) ? sanitize_key($form['email_template_id']) : '',
            'subject_prefix' => isset($form['subject_prefix']) && is_string($form['subject_prefix']) ? sanitize_text_field($form['subject_prefix']) : '',
        ];
    }

    /**
     * Resolve the spam mode for one form.
     *
     * 'auto' picks Turnstile when that module is active and
     * configured, else reCAPTCHA v3 when its keys exist, else
     * none. An explicit mode is used as-is, except that
     * Turnstile falls back to reCAPTCHA v3 (then none) when the
     * Turnstile module is not configured.
     *
     * @param array<string, mixed> $form Form config.
     * @return string Resolved mode.
     */
    public static function resolveSpamMode(array $form): string
    {
        $mode = (string) ($form['spam'] ?? 'auto');
        $settings = self::getSettings();
        $turnstileReady = ModuleRegistry::isEnabled('turnstile') && TurnstileModule::isConfigured();
        $recaptchaReady = RecaptchaVerifier::isConfigured($settings);
        if ($mode === 'turnstile') {
            if ($turnstileReady) {
                return 'turnstile';
            }
            return $recaptchaReady ? 'recaptcha-v3' : 'none';
        }
        if ($mode === 'recaptcha-v2') {
            return $recaptchaReady ? 'recaptcha-v2' : 'none';
        }
        if ($mode === 'recaptcha-v3') {
            return $recaptchaReady ? 'recaptcha-v3' : 'none';
        }
        if ($mode === 'none') {
            return 'none';
        }
        // auto
        if ($turnstileReady) {
            return 'turnstile';
        }
        return $recaptchaReady ? 'recaptcha-v3' : 'none';
    }

    /**
     * Render the [traydigita_contact_form] shortcode.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     * @return string Form HTML, or an empty string when unknown.
     */
    public static function renderShortcode(array $atts): string
    {
        $id = isset($atts['id']) && is_string($atts['id']) ? sanitize_key($atts['id']) : '';
        $form = $id !== '' ? self::getForm($id) : null;
        if ($form === null) {
            return '';
        }
        $builderForm = FormBuilderModule::getForm($form['form_builder_id']);
        if ($builderForm === null) {
            return '';
        }
        $mode = self::resolveSpamMode($form);
        $beforeSubmit = '';
        if ($mode === 'turnstile') {
            TurnstileModule::enqueueLoginScript();
            $beforeSubmit = TurnstileModule::widgetHtml('contact-form');
        } elseif ($mode === 'recaptcha-v2') {
            $settings = self::getSettings();
            wp_enqueue_script(
                'traydigita-recaptcha',
                self::RECAPTCHA_SCRIPT_URL,
                [],
                null,
                true
            );
            $beforeSubmit = '<div class="g-recaptcha" data-sitekey="' . esc_attr($settings['recaptcha_site_key']) . '"></div>';
        } elseif ($mode === 'recaptcha-v3') {
            $settings = self::getSettings();
            wp_enqueue_script(
                'traydigita-recaptcha',
                self::RECAPTCHA_SCRIPT_URL . '?render=' . rawurlencode($settings['recaptcha_site_key']),
                [],
                null,
                true
            );
            wp_add_inline_script(
                'traydigita-recaptcha',
                'document.addEventListener("submit",function(e){var f=e.target;if(!f.classList.contains("traydigita-form"))return;e.preventDefault();grecaptcha.ready(function(){grecaptcha.execute("' . esc_js($settings['recaptcha_site_key']) . '",{action:"' . esc_js(self::RECAPTCHA_V3_ACTION) . '"}).then(function(t){var i=f.querySelector("[name=g-recaptcha-response]");if(i)i.value=t;f.submit();});});});'
            );
            $beforeSubmit = '<input type="hidden" name="g-recaptcha-response" value="">';
        }
        $submitLabel = isset($atts['submit']) && is_string($atts['submit']) && $atts['submit'] !== '' ? sanitize_text_field($atts['submit']) : '';
        return FormBuilder::renderForm(
            $builderForm['definition'],
            admin_url('admin-ajax.php'),
            $submitLabel,
            [
                'action' => self::SUBMIT_ACTION,
                'form_id' => $id,
            ],
            $beforeSubmit
        );
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $forms = self::getForms();
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $builderForms = FormBuilderModule::getForms();
        $emailTemplates = FormBuilderModule::getEmailTemplates();
        $editingFormId = isset($_GET['form']) ? sanitize_key((string) wp_unslash($_GET['form'])) : '';
        $editingForm = $editingFormId !== '' ? ($forms[$editingFormId] ?? null) : null;
        $turnstileActive = ModuleRegistry::isEnabled('turnstile') && TurnstileModule::isConfigured();
        $recaptchaConfigured = RecaptchaVerifier::isConfigured($settings);
        $adminNonce = wp_create_nonce(self::NONCE_ACTION);
        $spamModes = self::SPAM_MODES;
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = Core::pluginDir() . '/templates/admin/modules/contact-form.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Contact Form settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Validate and save module settings from a submitted form.
     *
     * Expected keys in `$data`:
     * - `contact_action`: save_settings | create_form | save_form | delete_form
     * - settings keys: recaptcha_site_key, recaptcha_secret_key,
     *   recaptcha_v3_threshold, retention_days, default_recipient
     * - form keys: form_id, title, form_builder_id, recipient,
     *   send_email, spam, email_template_id, subject_prefix
     *
     * @param array<string, mixed> $data Posted settings data.
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Contact Form settings.', 'traydigita-core'),
            ];
        }
        $action = isset($data['contact_action']) && is_string($data['contact_action']) ? $data['contact_action'] : '';
        return match ($action) {
            'save_settings' => self::saveModuleSettings($data),
            'create_form' => self::createForm($data),
            'save_form' => self::saveForm($data),
            'delete_form' => self::deleteForm($data),
            default => [
                'success' => false,
                'message' => __('Unknown Contact Form action.', 'traydigita-core'),
            ],
        };
    }

    /**
     * Save the module-level settings (reCAPTCHA keys, retention,
     * default recipient).
     *
     * Secrets are only overwritten when a non-empty value is
     * posted, so a masked field never wipes the stored key.
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function saveModuleSettings(array $data): array
    {
        $settings = self::getSettings();
        if (isset($data['recaptcha_site_key']) && is_string($data['recaptcha_site_key'])) {
            $settings['recaptcha_site_key'] = sanitize_text_field($data['recaptcha_site_key']);
        }
        if (isset($data['recaptcha_secret_key']) && is_string($data['recaptcha_secret_key']) && $data['recaptcha_secret_key'] !== '') {
            $settings['recaptcha_secret_key'] = sanitize_text_field($data['recaptcha_secret_key']);
        }
        if (isset($data['recaptcha_v3_threshold'])) {
            $threshold = (float) $data['recaptcha_v3_threshold'];
            $settings['recaptcha_v3_threshold'] = max(0.0, min(1.0, $threshold));
        }
        if (isset($data['retention_days'])) {
            $settings['retention_days'] = max(0, (int) $data['retention_days']);
        }
        if (isset($data['default_recipient']) && is_string($data['default_recipient'])) {
            $settings['default_recipient'] = sanitize_email($data['default_recipient']);
        }
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Contact Form settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Create a new contact form.
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    private static function createForm(array $data): array
    {
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field($data['title']) : '';
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('Enter a title for the new contact form.', 'traydigita-core'),
            ];
        }
        $forms = self::getForms();
        $id = self::uniqueId($title, array_keys($forms));
        $settings = self::getSettings();
        $forms[$id] = [
            'id' => $id,
            'title' => $title,
            'form_builder_id' => '',
            'recipient' => $settings['default_recipient'],
            'send_email' => true,
            'spam' => 'auto',
            'email_template_id' => '',
            'subject_prefix' => '',
        ];
        update_option(self::OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Contact form created.', 'traydigita-core'),
            'data' => ['form_id' => $id],
        ];
    }

    /**
     * Save one contact form's config.
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function saveForm(array $data): array
    {
        $id = isset($data['form_id']) && is_string($data['form_id']) ? sanitize_key($data['form_id']) : '';
        $forms = self::getForms();
        if ($id === '' || !isset($forms[$id])) {
            return [
                'success' => false,
                'message' => __('Unknown contact form.', 'traydigita-core'),
            ];
        }
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field($data['title']) : '';
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('The form title must not be empty.', 'traydigita-core'),
            ];
        }
        $builderId = isset($data['form_builder_id']) && is_string($data['form_builder_id']) ? sanitize_key($data['form_builder_id']) : '';
        if ($builderId !== '' && FormBuilderModule::getForm($builderId) === null) {
            return [
                'success' => false,
                'message' => __('Unknown Form Builder form.', 'traydigita-core'),
            ];
        }
        $templateId = isset($data['email_template_id']) && is_string($data['email_template_id']) ? sanitize_key($data['email_template_id']) : '';
        if ($templateId !== '' && FormBuilderModule::getEmailTemplate($templateId) === null) {
            return [
                'success' => false,
                'message' => __('Unknown email template.', 'traydigita-core'),
            ];
        }
        $forms[$id] = self::sanitizeFormConfig([
            'id' => $id,
            'title' => $title,
            'form_builder_id' => $builderId,
            'recipient' => isset($data['recipient']) && is_string($data['recipient']) ? $data['recipient'] : '',
            'send_email' => !empty($data['send_email']),
            'spam' => isset($data['spam']) && is_string($data['spam']) ? $data['spam'] : 'auto',
            'email_template_id' => $templateId,
            'subject_prefix' => isset($data['subject_prefix']) && is_string($data['subject_prefix']) ? $data['subject_prefix'] : '',
        ]);
        update_option(self::OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Contact form saved.', 'traydigita-core'),
        ];
    }

    /**
     * Delete one contact form (submissions are kept).
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function deleteForm(array $data): array
    {
        $id = isset($data['form_id']) && is_string($data['form_id']) ? sanitize_key($data['form_id']) : '';
        $forms = self::getForms();
        if ($id === '' || !isset($forms[$id])) {
            return [
                'success' => false,
                'message' => __('Unknown contact form.', 'traydigita-core'),
            ];
        }
        unset($forms[$id]);
        update_option(self::OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Contact form deleted. Its submissions are kept.', 'traydigita-core'),
        ];
    }

    /**
     * Derive a unique machine id from a title.
     *
     * @param string $title Human title.
     * @param array<int, string> $taken Already used ids.
     * @return string Unique id.
     */
    private static function uniqueId(string $title, array $taken): string
    {
        $base = sanitize_key($title);
        if ($base === '') {
            $base = 'form';
        }
        $id = $base;
        $suffix = 2;
        while (in_array($id, $taken, true)) {
            $id = $base . '-' . $suffix;
            $suffix++;
        }
        return $id;
    }

    /**
     * Render the submissions list page.
     *
     * Handles the list filters, the detail view, the print view,
     * the CSV export and the read/unread/delete actions (each
     * guarded by a nonce + manage_options). The page has its own
     * WordPress admin wrapper because it is registered as a
     * hidden page, not through the shell.
     *
     * @return void
     */
    public static function renderSubmissionsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view contact submissions.', 'traydigita-core'));
        }
        SubmissionStore::ensureTable();
        $result = ContactFormAjax::handleSubmissionsActions();
        if ($result === 'csv') {
            return;
        }
        $filters = ContactFormAjax::submissionsFilters();
        $page = isset($_GET['paged']) ? max(1, absint((string) wp_unslash($_GET['paged']))) : 1;
        $perPage = 20;
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $total = SubmissionStore::count($filters);
        $submissions = SubmissionStore::list($filters, $page, $perPage);
        $viewId = isset($_GET['view']) ? absint((string) wp_unslash($_GET['view'])) : 0;
        $viewSubmission = $viewId > 0 ? SubmissionStore::get($viewId) : null;
        $printView = isset($_GET['print']) && $_GET['print'] === '1';
        $forms = self::getForms();
        $settingsUrl = admin_url('admin.php?page=' . self::settingsPageSlug());
        $listUrl = admin_url('admin.php?page=' . self::SUBMISSIONS_SLUG);
        $actionNonce = wp_create_nonce(self::SUBMISSIONS_NONCE_ACTION);
        $unreadCount = SubmissionStore::count(['status' => 'unread']);
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = Core::pluginDir() . '/templates/admin/contact-submissions.php';
        if (!file_exists($partial)) {
            echo '<div class="wrap"><p>' . esc_html__('The submissions template is missing.', 'traydigita-core') . '</p></div>';
            return;
        }
        require $partial;
    }
}
