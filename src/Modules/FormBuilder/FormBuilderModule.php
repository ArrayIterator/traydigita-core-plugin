<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FormBuilder;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateBuilder;
use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateStore;
use TrayDigita\WP\Plugin\Core\Modules\Recaptcha\RecaptchaModule;
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
use function do_action;
use function esc_html__;
use function file_exists;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function sanitize_email;
use function sanitize_key;
use function sanitize_text_field;
use function time;
use function update_option;
use function wp_create_nonce;
use function wp_die;
use function wp_next_scheduled;
use function wp_schedule_event;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Form Builder module.
 *
 * A reusable builder for forms, used by the Contact Form
 * records and any other module that needs a form. The builder
 * itself stores named forms (field definitions); rendering and
 * validation live in FormBuilder, so the same definition is used
 * everywhere and can never drift between the editor, the
 * preview and the frontend.
 *
 * Email templates are NOT stored here: they live in the one
 * shared store owned by the Email module's TemplateBuilder
 * (option 'traydigita_email_templates'), listed and edited from
 * this module's Email templates section and picked by the
 * contact forms for their notification mail. A template made
 * here works in the Newsletter, and vice versa. The old
 * FormBuilder-only store is migrated into the shared store once
 * by TemplateBuilder::migrateLegacyTemplates().
 *
 * Submissions ARE stored here (MUST-8): the SubmissionStore
 * table (`{$wpdb->prefix}traydigita_contact_submissions`) is
 * owned by this module. A generic [traydigita_form] form stores
 * its submissions when its per-form "store submissions" flag is
 * on; a contact form always stores first and mails after.
 * Contact forms themselves are config records owned by this
 * module (SHOULD-18, folded in from the old Contact Form
 * module): each one points at a Form Builder form, a recipient,
 * a send-to-email toggle, a spam mode and an optional email
 * template, and is rendered by the [traydigita_contact_form]
 * shortcode alias. The old module id 'contact-form' is gone;
 * sites that had it enabled get 'form-builder' enabled by the
 * one-time migration in migrateEnabledFromContactForm().
 *
 * Status: recommended, default inactive. A form does nothing
 * until a module uses it, so the module ships off and the
 * module list suggests it (ModuleRegistry::isRecommended()).
 *
 * The module also registers the [traydigita_form] shortcode,
 * which renders a saved form and validates submissions through
 * the `traydigita:core:form-builder/submitted` action — other
 * modules hook that action to store or mail the values.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\FormBuilder
 */
final class FormBuilderModule implements ModuleInterface
{
    /**
     * Option holding the named forms.
     *
     * Shape: [formId => ['id' => string, 'title' => string,
     * 'definition' => ['fields' => [...]]]].
     */
    public const string OPTION_FORMS = 'traydigita_form_builder_forms';

    /**
     * Option holding the contact-form settings (spam keys,
     * retention, default recipient). Unchanged since the
     * Contact Form module owned it, so existing settings survive
     * the fold with no migration.
     */
    public const string CONTACT_OPTION = 'traydigita_contact_form_settings';

    /**
     * Option holding the contact forms.
     *
     * Shape: [formId => ['id', 'title', 'form_builder_id',
     * 'recipient', 'send_email' (bool), 'spam'
     * ('auto'|'turnstile'|'recaptcha-v2'|'recaptcha-v3'|'none'),
     * 'email_template_id', 'subject_prefix']]. Unchanged since
     * the Contact Form module owned it.
     */
    public const string CONTACT_OPTION_FORMS = 'traydigita_contact_forms';

    /**
     * Admin page slug of the submissions list.
     */
    public const string CONTACT_SUBMISSIONS_SLUG = 'traydigita-contact-submissions';

    /**
     * AJAX action for contact form submissions.
     */
    public const string CONTACT_SUBMIT_ACTION = 'traydigita_contact_form_submit';

    /**
     * Nonce action for submissions list actions (delete,
     * read/unread, export).
     */
    public const string CONTACT_SUBMISSIONS_NONCE_ACTION = 'traydigita_contact_submissions';

    /**
     * reCAPTCHA v3 action name sent to Google.
     */
    public const string RECAPTCHA_V3_ACTION = 'traydigita_contact_form';

    /**
     * Spam protection choices per contact form.
     *
     * @var array<int, string>
     */
    public const array CONTACT_SPAM_MODES = [
        'auto',
        'turnstile',
        'recaptcha-v2',
        'recaptcha-v3',
        'none',
    ];

    /**
     * AJAX action for generic shortcode form submissions.
     */
    public const string SUBMIT_ACTION = 'traydigita_form_builder_submit';

    /**
     * Nonce action for the admin save endpoint.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'form-builder';
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Form Builder', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Build reusable forms and email templates (field builder + email template builder) for contact forms, '
            . 'the Newsletter and other modules. Submissions are stored in their own table, '
            . 'with a CSV export and retention prune.',
            'traydigita-core'
        );
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'feedback';
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
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Reusable form builder with fields.', 'traydigita-core'),
            __('Email template builder.', 'traydigita-core'),
            __('Contact forms with recipient, spam protection and notification mail.', 'traydigita-core'),
            __('Submissions stored in their own table, with CSV export and retention prune.', 'traydigita-core'),
            __('Powers the Contact Form, Newsletter, and other modules.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
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
        return 'traydigita-form-builder';
    }

    /**
     * Whether the module is recommended.
     *
     * Optional: ModuleRegistry::isRecommended() calls it when it
     * exists. Recommended modules ship inactive but carry a
     * "Recommended" note on their module card, so the admin sees
     * the suggestion without the module turning itself on.
     *
     * @return bool Always true.
     */
    public static function isRecommended(): bool
    {
        return true;
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
     * Configured means at least one form or one email template
     * exists. An empty builder reports as unconfigured so the
     * module card shows the "needs setup" state while enabled.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::getForms() !== [] || self::getEmailTemplates() !== [] || self::getContactForms() !== [];
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
            ['id' => 'forms', 'label' => __('Forms', 'traydigita-core'), 'icon' => 'feedback'],
            ['id' => 'templates', 'label' => __('Email templates', 'traydigita-core'), 'icon' => 'email'],
            ['id' => 'contact-forms', 'label' => __('Contact forms', 'traydigita-core'), 'icon' => 'email-alt'],
            ['id' => 'contact-settings', 'label' => __('Spam & retention', 'traydigita-core'), 'icon' => 'shield'],
        ];
    }

    /**
     * Register the module's frontend hooks.
     *
     * Registers the [traydigita_form] shortcode and the generic
     * submission endpoint, the [traydigita_contact_form] alias
     * (kept from the folded Contact Form module) and its
     * submission endpoint, the hidden submissions page and the
     * daily retention prune in the admin. The generic endpoint
     * validates and stores (when the form opts into storage),
     * then fires the submitted action.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_shortcode('traydigita_form', [self::class, 'renderShortcode']);
        add_action('wp_ajax_' . self::SUBMIT_ACTION, [self::class, 'handleShortcodeSubmit']);
        add_action('wp_ajax_nopriv_' . self::SUBMIT_ACTION, [self::class, 'handleShortcodeSubmit']);
        add_shortcode('traydigita_contact_form', [self::class, 'renderContactShortcode']);
        add_action('wp_ajax_' . self::CONTACT_SUBMIT_ACTION, [ContactFormAjax::class, 'handleSubmit']);
        add_action('wp_ajax_nopriv_' . self::CONTACT_SUBMIT_ACTION, [ContactFormAjax::class, 'handleSubmit']);
        add_action('admin_menu', [self::class, 'registerSubmissionsPage']);
        add_action(SubmissionStore::PRUNE_CRON_HOOK, [self::class, 'pruneSubmissions']);
        if (!wp_next_scheduled(SubmissionStore::PRUNE_CRON_HOOK)) {
            wp_schedule_event(time(), 'daily', SubmissionStore::PRUNE_CRON_HOOK);
        }
    }

    /**
     * One-time migration from the folded Contact Form module.
     *
     * Sites that had the old 'contact-form' module enabled get
     * 'form-builder' enabled, so their contact forms, shortcode
     * and submissions page keep working after the fold. The old
     * id is dropped from the stored list (ModuleRegistry drops
     * unknown ids on read anyway). Runs from
     * ModuleRegistry::bootEnabled() before the boot loop — NOT
     * from boot(), which only runs when FormBuilder is already
     * enabled, so a site with 'contact-form' enabled but
     * 'form-builder' disabled would otherwise never migrate.
     *
     * @return void
     */
    public static function migrateEnabledFromContactForm(): void
    {
        $stored = get_option(ModuleRegistry::OPTION, null);
        if (!is_array($stored) || !in_array('contact-form', $stored, true)) {
            return;
        }
        $stored = array_values(array_filter(
            $stored,
            static fn (mixed $id): bool => $id !== 'contact-form'
        ));
        if (!in_array(self::id(), $stored, true)) {
            $stored[] = self::id();
        }
        update_option(ModuleRegistry::OPTION, $stored, false);
    }

    /**
     * Register the submissions page (hidden from the menu).
     *
     * Registered with a null parent so WordPress keeps it
     * reachable at its deep link without listing it a second
     * time in the menu; the Form Builder settings page links to
     * it from the Contact forms section.
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
            self::CONTACT_SUBMISSIONS_SLUG,
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
        $settings = self::getContactSettings();
        SubmissionStore::ensureTable();
        SubmissionStore::prune($settings['retention_days']);
    }

    /**
     * Read all named forms.
     *
     * @return array<string, array{id: string, title: string,
     *     definition: array<string, mixed>, store_submissions: bool}>
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
            if (($form['id'] ?? '') !== $id || !isset($form['title']) || !is_string($form['title'])) {
                continue;
            }
            $definition = $form['definition'] ?? null;
            if (!is_array($definition) || !FormBuilder::validateDefinition($definition)) {
                continue;
            }
            $forms[$id] = [
                'id' => $id,
                'title' => $form['title'],
                'definition' => $definition,
                'store_submissions' => !empty($form['store_submissions']),
            ];
        }
        return $forms;
    }

    /**
     * Read one named form by id, or null.
     *
     * @param string $id Form id.
     * @return array{id: string, title: string, definition: array<string, mixed>, store_submissions: bool}|null
     */
    public static function getForm(string $id): ?array
    {
        $forms = self::getForms();
        return $forms[$id] ?? null;
    }

    /**
     * Read all email templates from the shared store.
     *
     * Delegates to TemplateBuilder (Email module): the shape is
     * the shared one, [id => ['id', 'name', 'blocks',
     * 'created_at']], so templates made in the Newsletter
     * builder show up here and vice versa.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getEmailTemplates(): array
    {
        return TemplateBuilder::getTemplates();
    }

    /**
     * Read one email template by id from the shared store, or null.
     *
     * @param string $id Template id.
     * @return array<string, mixed>|null
     */
    public static function getEmailTemplate(string $id): ?array
    {
        return TemplateBuilder::getTemplate($id);
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * The page lists the named forms and email templates, and
     * edits one form or one template at a time (selected with
     * ?form=<id> / ?template=<id>). The editor is a plain
     * server-rendered form: one row per field/block, posted to
     * the shell save endpoint.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $forms = self::getForms();
        $templates = self::getEmailTemplates();
        $editingFormId = isset($_GET['form']) ? sanitize_key((string) wp_unslash($_GET['form'])) : '';
        $editingTemplateId = isset($_GET['template']) ? sanitize_key((string) wp_unslash($_GET['template'])) : '';
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $allTemplates = TemplateBuilder::getTemplates(TemplateStore::TYPE_EMAIL, null);
        $editingForm = $editingFormId !== '' ? ($forms[$editingFormId] ?? null) : null;
        $editingTemplate = $editingTemplateId !== '' ? ($templates[$editingTemplateId] ?? null) : null;
        $adminNonce = wp_create_nonce(self::NONCE_ACTION);
        $fieldLabels = FormBuilder::fieldLabels();
        $blockLabels = TemplateBuilder::blockLabels();
        $fieldTypes = FormBuilder::FIELD_TYPES;
        $blockTypes = TemplateBuilder::BLOCK_TYPES;
        $contactForms = self::getContactForms();
        $contactSettings = self::getContactSettings();
        $editingContactFormId = isset($_GET['contact_form'])
            ? sanitize_key((string) wp_unslash($_GET['contact_form']))
            : '';
        $editingContactForm = $editingContactFormId !== '' ? ($contactForms[$editingContactFormId] ?? null) : null;
        $turnstileActive = ModuleRegistry::isEnabled('turnstile') && TurnstileModule::isConfigured();
        $recaptchaConfigured = ModuleRegistry::isEnabled('recaptcha') && RecaptchaModule::isConfigured();
        $recaptchaSettingsUrl = admin_url('admin.php?page=' . RecaptchaModule::settingsPageSlug());
        $contactSpamModes = self::CONTACT_SPAM_MODES;
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = Core::pluginDir() . '/templates/admin/modules/form-builder.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Form Builder settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Validate and save builder data from a submitted form.
     *
     * Expected keys in `$data`:
     * - `builder_action`: create_form | save_form | delete_form |
     *   create_template | save_template | delete_template
     * - `contact_action`: save_settings | create_form | save_form
     *   | delete_form (contact forms, folded in from the old
     *   Contact Form module — dispatched first when present)
     * - `form_id` / `template_id`: the target id
     * - `title`: title for a new form/template
     * - `fields`: nested field rows for save_form
     * - `blocks`: nested block rows for save_template
     *
     * @param array<string, mixed> $data Posted builder data.
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Form Builder settings.', 'traydigita-core'),
            ];
        }
        $contactAction = isset($data['contact_action']) && is_string($data['contact_action'])
            ? $data['contact_action']
            : '';
        if ($contactAction !== '') {
            return self::saveContactSettings($data);
        }
        $action = isset($data['builder_action']) && is_string($data['builder_action']) ? $data['builder_action'] : '';
        return match ($action) {
            'create_form' => self::createForm($data),
            'save_form' => self::saveForm($data),
            'delete_form' => self::deleteForm($data),
            'create_template' => self::createEmailTemplate($data),
            'save_template' => self::saveEmailTemplate($data),
            'delete_template' => self::deleteEmailTemplate($data),
            default => [
                'success' => false,
                'message' => __('Unknown Form Builder action.', 'traydigita-core'),
            ],
        };
    }

    /**
     * Create a new named form with one empty text field.
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
                'message' => __('Enter a title for the new form.', 'traydigita-core'),
            ];
        }
        $forms = self::getForms();
        $id = self::uniqueId($title, array_keys($forms));
        $forms[$id] = [
            'id' => $id,
            'title' => $title,
            'definition' => [
                'fields' => [
                    [
                        'id' => 'name',
                        'type' => 'text',
                        'label' => __('Name', 'traydigita-core'),
                        'required' => true,
                        'placeholder' => '',
                        'options' => [],
                        'default' => '',
                    ],
                ],
            ],
            'store_submissions' => false,
        ];
        update_option(self::OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Form created.', 'traydigita-core'),
            'data' => ['form_id' => $id],
        ];
    }

    /**
     * Save the fields of an existing form.
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
                'message' => __('Unknown form.', 'traydigita-core'),
            ];
        }
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field($data['title']) : '';
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('The form title must not be empty.', 'traydigita-core'),
            ];
        }
        $result = FormBuilder::sanitizeDefinition(['fields' => self::dropDeletedRows($data['fields'] ?? null)]);
        if ($result['definition'] === null) {
            return [
                'success' => false,
                'message' => $result['error'] ?? __('The form definition is invalid.', 'traydigita-core'),
            ];
        }
        $forms[$id]['title'] = $title;
        $forms[$id]['definition'] = $result['definition'];
        $forms[$id]['store_submissions'] = !empty($data['store_submissions']);
        update_option(self::OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Form saved.', 'traydigita-core'),
        ];
    }

    /**
     * Delete a named form.
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
                'message' => __('Unknown form.', 'traydigita-core'),
            ];
        }
        unset($forms[$id]);
        update_option(self::OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Form deleted.', 'traydigita-core'),
        ];
    }

    /**
     * Create a new email template in the shared store.
     *
     * The template starts from the shared default blocks (empty
     * header/text/footer rows for the editor to fill in).
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    private static function createEmailTemplate(array $data): array
    {
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field($data['title']) : '';
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('Enter a title for the new email template.', 'traydigita-core'),
            ];
        }
        $templates = self::getEmailTemplates();
        $id = self::uniqueId($title, array_keys($templates));
        TemplateBuilder::saveTemplate([
            'id' => $id,
            'name' => $title,
            'blocks' => TemplateBuilder::defaultBlocks(),
            'created_at' => time(),
        ]);
        return [
            'success' => true,
            'message' => __('Email template created.', 'traydigita-core'),
            'data' => ['template_id' => $id],
        ];
    }

    /**
     * Save the blocks of an existing email template.
     *
     * The editor posts one flat row per block (type, text, label,
     * url, image_url); the rows are mapped to the shared
     * flat-block shape and sanitized by TemplateBuilder.
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function saveEmailTemplate(array $data): array
    {
        $id = isset($data['template_id']) && is_string($data['template_id']) ? sanitize_key($data['template_id']) : '';
        $templates = self::getEmailTemplates();
        if ($id === '' || !isset($templates[$id])) {
            return [
                'success' => false,
                'message' => __('Unknown email template.', 'traydigita-core'),
            ];
        }
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field($data['title']) : '';
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('The template title must not be empty.', 'traydigita-core'),
            ];
        }
        $template = TemplateBuilder::sanitizeTemplate([
            'id' => $id,
            'name' => $title,
            'blocks' => self::editorRowsToBlocks(self::dropDeletedRows($data['blocks'] ?? null)),
        ]);
        if ($template === null) {
            return [
                'success' => false,
                'message' => __('The email template is invalid.', 'traydigita-core'),
            ];
        }
        TemplateBuilder::saveTemplate($template);
        return [
            'success' => true,
            'message' => __('Email template saved.', 'traydigita-core'),
        ];
    }

    /**
     * Map posted editor rows to shared flat blocks.
     *
     * Two shapes are accepted. The old table editor posted one
     * flat row per block (type, text, label, url, image_url);
     * the WYSIWYG editor posts the flat-block shape itself
     * (type, text/content, label, url, align, background,
     * style, color, attachment_id, width, alt, link, subtitle).
     * Both are mapped to the shared flat-block shape and
     * sanitized by TemplateBuilder. Empty rows are kept here;
     * TemplateBuilder::sanitizeTemplate() drops them.
     *
     * Note: rows arriving through the shell's generic save are
     * deep-sanitized first (AdminAjax::deepSanitize()), so block
     * HTML and line breaks do not survive that path — the
     * WYSIWYG editor saves through the Email AJAX endpoints
     * instead, which keep them intact.
     *
     * @param array<int, mixed>|null $rows Posted block rows.
     * @return list<array<string, mixed>>
     */
    private static function editorRowsToBlocks(?array $rows): array
    {
        if ($rows === null) {
            return [];
        }
        $blocks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = sanitize_key((string) ($row['type'] ?? ''));
            $text = isset($row['text']) && is_string($row['text']) ? $row['text'] : '';
            if ($text === '' && isset($row['content']) && is_string($row['content'])) {
                $text = $row['content'];
            }
            $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : '';
            $url = isset($row['url']) && is_string($row['url']) ? $row['url'] : '';
            $imageUrl = isset($row['image_url']) && is_string($row['image_url']) ? $row['image_url'] : '';
            if ($imageUrl === '' && $type === 'image' && $url !== '') {
                $imageUrl = $url;
            }
            // Flat-shape extras (ignored by the old table rows).
            $flat = [];
            foreach (['align', 'background', 'style', 'color', 'subtitle', 'alt', 'link'] as $key) {
                if (isset($row[$key]) && is_string($row[$key])) {
                    $flat[$key] = $row[$key];
                }
            }
            foreach (['attachment_id', 'width'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $flat[$key] = (int) $row[$key];
                }
            }
            $blocks[] = match ($type) {
                'header' => array_merge(['type' => 'header', 'text' => $text], $flat),
                'text' => array_merge(['type' => 'text', 'content' => $text], $flat),
                'button' => array_merge(['type' => 'button', 'label' => $label, 'url' => $url], $flat),
                'image' => array_merge(['type' => 'image', 'url' => $imageUrl, 'alt' => $text, 'link' => $url], $flat),
                'divider' => array_merge(['type' => 'divider'], $flat),
                'footer' => array_merge(['type' => 'footer', 'text' => $text], $flat),
                default => ['type' => $type, 'text' => $text],
            };
        }
        return $blocks;
    }

    /**
     * Delete an email template from the shared store.
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function deleteEmailTemplate(array $data): array
    {
        $id = isset($data['template_id']) && is_string($data['template_id']) ? sanitize_key($data['template_id']) : '';
        if ($id === '' || !TemplateBuilder::deleteTemplate($id)) {
            return [
                'success' => false,
                'message' => __('Unknown email template.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('Email template deleted.', 'traydigita-core'),
        ];
    }

    /**
     * Drop editor rows flagged for deletion.
     *
     * The editor marks a row for removal with a `delete` checkbox
     * instead of removing it client-side, so the form works with
     * no JavaScript at all.
     *
     * @param mixed $rows Posted rows.
     * @return array<int, mixed>|null Rows without deleted ones.
     */
    private static function dropDeletedRows(mixed $rows): ?array
    {
        if (!is_array($rows)) {
            return null;
        }
        $kept = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['delete'])) {
                continue;
            }
            $kept[] = $row;
        }
        return $kept;
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
     * Render the [traydigita_form] shortcode.
     *
     * Renders a saved form by id. The form posts to the generic
     * AJAX endpoint; the submitted values are handed to the
     * `traydigita:core:form-builder/submitted` action and stored
     * when the form opts into storage (per-form "store
     * submissions" flag).
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
        $submitLabel = isset($atts['submit']) && is_string($atts['submit']) && $atts['submit'] !== '' ? sanitize_text_field($atts['submit']) : '';
        return FormBuilder::renderForm(
            $form['definition'],
            admin_url('admin-ajax.php'),
            $submitLabel,
            [
                'action' => self::SUBMIT_ACTION,
                'form_id' => $id,
            ]
        );
    }

    /**
     * Handle a generic shortcode form submission (AJAX).
     *
     * Verifies the nonce, validates the submission against the
     * saved definition, stores it when the form opts into
     * storage (per-form "store submissions" flag), then fires
     * the submitted action with the cleaned values.
     *
     * @return void
     */
    public static function handleShortcodeSubmit(): void
    {
        $nonce = isset($_POST['traydigita_form_nonce']) ? sanitize_text_field((string) wp_unslash($_POST['traydigita_form_nonce'])) : '';
        if (!wp_verify_nonce($nonce, FormBuilder::NONCE_ACTION)) {
            wp_send_json_error(['message' => __('Security check failed. Please reload the page and try again.', 'traydigita-core')], 403);
        }
        $formId = isset($_POST['form_id']) ? sanitize_key((string) wp_unslash($_POST['form_id'])) : '';
        $form = $formId !== '' ? self::getForm($formId) : null;
        if ($form === null) {
            wp_send_json_error(['message' => __('Unknown form.', 'traydigita-core')], 400);
        }
        $posted = isset($_POST['td_field']) && is_array($_POST['td_field']) ? wp_unslash($_POST['td_field']) : [];
        $posted[FormBuilder::HONEYPOT_FIELD] = isset($_POST[FormBuilder::HONEYPOT_FIELD]) ? sanitize_text_field((string) wp_unslash($_POST[FormBuilder::HONEYPOT_FIELD])) : '';
        $result = FormBuilder::validateSubmission($form['definition'], $posted);
        if ($result['errors'] !== []) {
            wp_send_json_error(
                [
                    'message' => __('Please check the form and try again.', 'traydigita-core'),
                    'errors' => $result['errors'],
                ],
                422
            );
        }
        $submissionId = 0;
        if (!empty($form['store_submissions'])) {
            // Store FIRST: the submission must survive anything a
            // submitted-action consumer does with the values.
            $submissionId = ContactFormAjax::storeSubmission($form['id'], $form['title'], $result['values']);
            if ($submissionId === 0) {
                wp_send_json_error(
                    ['message' => __('Your submission could not be saved. Please try again later.', 'traydigita-core')],
                    500
                );
            }
            $settings = self::getContactSettings();
            if ($settings['retention_days'] > 0) {
                SubmissionStore::prune($settings['retention_days']);
            }
        }
        /**
         * Fires when a generic [traydigita_form] form is submitted.
         *
         * @param string $formId Form id.
         * @param array<string, mixed> $values Cleaned values keyed by field id.
         */
        do_action(Core::HOOK_PREFIX . ':form-builder/submitted', $formId, $result['values']);
        wp_send_json_success(['message' => __('Thank you. Your submission was received.', 'traydigita-core')]);
    }

    // -----------------------------------------------------------------
    // Contact forms (folded in from the Contact Form module).
    //
    // A contact form is a config record: it points at a Form
    // Builder form, a recipient, a send-to-email toggle, a spam
    // mode and an optional shared email template. Submissions are
    // stored FIRST in the SubmissionStore table and only then is
    // the notification mail sent via the Core Mailer — a
    // submission is never lost even when the email fails. Spam
    // protection is Turnstile (when that module is active and
    // configured) and reCAPTCHA v2/v3 (keys in the Recaptcha
    // module's settings), chosen per form. This module never
    // embeds a verifier or a key: it links to the Turnstile and
    // Recaptcha modules, which own their widgets and
    // verification. The frontend form is the
    // [traydigita_contact_form] shortcode alias.
    // -----------------------------------------------------------------

    /**
     * Default contact-form settings.
     *
     * @return array<string, mixed>
     */
    public static function defaultsContactSettings(): array
    {
        return [
            'retention_days' => 90,
            'default_recipient' => '',
        ];
    }

    /**
     * Read the contact-form settings, merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function getContactSettings(): array
    {
        $stored = get_option(self::CONTACT_OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::defaultsContactSettings(), $stored);
        $settings['retention_days'] = max(0, (int) $settings['retention_days']);
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
    public static function getContactForms(): array
    {
        $stored = get_option(self::CONTACT_OPTION_FORMS, []);
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
            $forms[$id] = self::sanitizeContactFormConfig($form);
        }
        return $forms;
    }

    /**
     * Read one contact form by id, or null.
     *
     * @param string $id Form id.
     * @return array<string, mixed>|null
     */
    public static function getContactForm(string $id): ?array
    {
        $forms = self::getContactForms();
        return $forms[$id] ?? null;
    }

    /**
     * Sanitize one stored contact form config into its canonical shape.
     *
     * @param array<string, mixed> $form Raw config.
     * @return array<string, mixed>
     */
    private static function sanitizeContactFormConfig(array $form): array
    {
        $spam = isset($form['spam']) && is_string($form['spam'])
            && in_array($form['spam'], self::CONTACT_SPAM_MODES, true)
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
     * Resolve the spam mode for one contact form.
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
        $turnstileReady = ModuleRegistry::isEnabled('turnstile') && TurnstileModule::isConfigured();
        $recaptchaReady = ModuleRegistry::isEnabled('recaptcha') && RecaptchaModule::isConfigured();
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
    public static function renderContactShortcode(array $atts): string
    {
        $id = isset($atts['id']) && is_string($atts['id']) ? sanitize_key($atts['id']) : '';
        $form = $id !== '' ? self::getContactForm($id) : null;
        if ($form === null) {
            return '';
        }
        $builderForm = self::getForm($form['form_builder_id']);
        if ($builderForm === null) {
            return '';
        }
        $mode = self::resolveSpamMode($form);
        $beforeSubmit = '';
        if ($mode === 'turnstile') {
            TurnstileModule::enqueueLoginScript();
            $beforeSubmit = TurnstileModule::widgetHtml('contact-form');
        } elseif ($mode === 'recaptcha-v2') {
            RecaptchaModule::enqueueScript('v2');
            $beforeSubmit = RecaptchaModule::widgetHtml('v2');
        } elseif ($mode === 'recaptcha-v3') {
            RecaptchaModule::enqueueScript('v3', self::RECAPTCHA_V3_ACTION);
            $beforeSubmit = RecaptchaModule::widgetHtml('v3');
        }
        $submitLabel = isset($atts['submit']) && is_string($atts['submit']) && $atts['submit'] !== '' ? sanitize_text_field($atts['submit']) : '';
        return FormBuilder::renderForm(
            $builderForm['definition'],
            admin_url('admin-ajax.php'),
            $submitLabel,
            [
                'action' => self::CONTACT_SUBMIT_ACTION,
                'form_id' => $id,
            ],
            $beforeSubmit
        );
    }

    /**
     * Validate and save contact-form settings from a submitted form.
     *
     * Expected keys in `$data`:
     * - `contact_action`: save_settings | create_form | save_form | delete_form
     * - settings keys: retention_days, default_recipient
     *   (reCAPTCHA keys live in the Recaptcha module)
     * - form keys: form_id, title, form_builder_id, recipient,
     *   send_email, spam, email_template_id, subject_prefix
     *
     * @param array<string, mixed> $data Posted settings data.
     * @return array{success: bool, message: string, data?: array<string, mixed>}
     */
    public static function saveContactSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Contact Form settings.', 'traydigita-core'),
            ];
        }
        $action = isset($data['contact_action']) && is_string($data['contact_action']) ? $data['contact_action'] : '';
        return match ($action) {
            'save_settings' => self::saveContactModuleSettings($data),
            'create_form' => self::createContactForm($data),
            'save_form' => self::saveContactForm($data),
            'delete_form' => self::deleteContactForm($data),
            default => [
                'success' => false,
                'message' => __('Unknown Contact Form action.', 'traydigita-core'),
            ],
        };
    }

    /**
     * Save the contact-form module-level settings (retention,
     * default recipient).
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function saveContactModuleSettings(array $data): array
    {
        $settings = self::getContactSettings();
        if (isset($data['retention_days'])) {
            $settings['retention_days'] = max(0, (int) $data['retention_days']);
        }
        if (isset($data['default_recipient']) && is_string($data['default_recipient'])) {
            $settings['default_recipient'] = sanitize_email($data['default_recipient']);
        }
        update_option(self::CONTACT_OPTION, $settings, false);
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
    private static function createContactForm(array $data): array
    {
        $title = isset($data['title']) && is_string($data['title']) ? sanitize_text_field($data['title']) : '';
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('Enter a title for the new contact form.', 'traydigita-core'),
            ];
        }
        $forms = self::getContactForms();
        $id = self::uniqueId($title, array_keys($forms));
        $settings = self::getContactSettings();
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
        update_option(self::CONTACT_OPTION_FORMS, $forms, false);
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
    private static function saveContactForm(array $data): array
    {
        $id = isset($data['form_id']) && is_string($data['form_id']) ? sanitize_key($data['form_id']) : '';
        $forms = self::getContactForms();
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
        if ($builderId !== '' && self::getForm($builderId) === null) {
            return [
                'success' => false,
                'message' => __('Unknown Form Builder form.', 'traydigita-core'),
            ];
        }
        $templateId = isset($data['email_template_id']) && is_string($data['email_template_id']) ? sanitize_key($data['email_template_id']) : '';
        if ($templateId !== '' && self::getEmailTemplate($templateId) === null) {
            return [
                'success' => false,
                'message' => __('Unknown email template.', 'traydigita-core'),
            ];
        }
        $forms[$id] = self::sanitizeContactFormConfig([
            'id' => $id,
            'title' => $title,
            'form_builder_id' => $builderId,
            'recipient' => isset($data['recipient']) && is_string($data['recipient']) ? $data['recipient'] : '',
            'send_email' => !empty($data['send_email']),
            'spam' => isset($data['spam']) && is_string($data['spam']) ? $data['spam'] : 'auto',
            'email_template_id' => $templateId,
            'subject_prefix' => isset($data['subject_prefix']) && is_string($data['subject_prefix']) ? $data['subject_prefix'] : '',
        ]);
        update_option(self::CONTACT_OPTION_FORMS, $forms, false);
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
    private static function deleteContactForm(array $data): array
    {
        $id = isset($data['form_id']) && is_string($data['form_id']) ? sanitize_key($data['form_id']) : '';
        $forms = self::getContactForms();
        if ($id === '' || !isset($forms[$id])) {
            return [
                'success' => false,
                'message' => __('Unknown contact form.', 'traydigita-core'),
            ];
        }
        unset($forms[$id]);
        update_option(self::CONTACT_OPTION_FORMS, $forms, false);
        return [
            'success' => true,
            'message' => __('Contact form deleted. Its submissions are kept.', 'traydigita-core'),
        ];
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
        $forms = self::getContactForms();
        $settingsUrl = admin_url('admin.php?page=' . self::settingsPageSlug());
        $listUrl = admin_url('admin.php?page=' . self::CONTACT_SUBMISSIONS_SLUG);
        $actionNonce = wp_create_nonce(self::CONTACT_SUBMISSIONS_NONCE_ACTION);
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
