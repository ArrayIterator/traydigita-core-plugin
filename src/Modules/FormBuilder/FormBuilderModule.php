<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FormBuilder;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function add_action;
use function add_shortcode;
use function admin_url;
use function array_keys;
use function current_user_can;
use function do_action;
use function esc_html__;
use function file_exists;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function update_option;
use function wp_create_nonce;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Form Builder / Email Builder module.
 *
 * A reusable builder for forms and email templates, used by the
 * Contact Form module, the Newsletter (Email module) and any
 * other module that needs a form or a mail. The builder itself
 * stores named forms (field definitions) and named email
 * templates (blocks); rendering and validation live in
 * FormBuilder and EmailBuilder, so the same definition is used
 * everywhere and can never drift between the editor, the
 * preview and the frontend.
 *
 * Status: recommended, default inactive. A form does nothing
 * until a module uses it, so the module ships off and the
 * module list suggests it (ModuleRegistry::isRecommended()).
 *
 * The module also registers the [traydigita_form] shortcode,
 * which renders a saved form and validates submissions through
 * the `traydigita:core:form-builder/submitted` action — other
 * modules hook that action to store or mail the values. The
 * shortcode form itself stores nothing.
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
     * Option holding the named email templates.
     *
     * Shape: [templateId => ['id' => string, 'title' => string,
     * 'template' => ['blocks' => [...]]]].
     */
    public const string OPTION_TEMPLATES = 'traydigita_form_builder_email_templates';

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
        return __('Build reusable forms and email templates (field builder + email template builder) for the Contact Form, Newsletter and other modules.', 'traydigita-core');
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
        return self::getForms() !== [] || self::getEmailTemplates() !== [];
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
        ];
    }

    /**
     * Register the module's frontend hooks.
     *
     * Registers the [traydigita_form] shortcode and the generic
     * submission endpoint. The endpoint only validates and fires
     * the submitted action; storage and mailing belong to the
     * module that consumes the form.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_shortcode('traydigita_form', [self::class, 'renderShortcode']);
        add_action('wp_ajax_' . self::SUBMIT_ACTION, [self::class, 'handleShortcodeSubmit']);
        add_action('wp_ajax_nopriv_' . self::SUBMIT_ACTION, [self::class, 'handleShortcodeSubmit']);
    }

    /**
     * Read all named forms.
     *
     * @return array<string, array{id: string, title: string, definition: array<string, mixed>}>
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
            ];
        }
        return $forms;
    }

    /**
     * Read one named form by id, or null.
     *
     * @param string $id Form id.
     * @return array{id: string, title: string, definition: array<string, mixed>}|null
     */
    public static function getForm(string $id): ?array
    {
        $forms = self::getForms();
        return $forms[$id] ?? null;
    }

    /**
     * Read all named email templates.
     *
     * @return array<string, array{id: string, title: string, template: array<string, mixed>}>
     */
    public static function getEmailTemplates(): array
    {
        $stored = get_option(self::OPTION_TEMPLATES, []);
        if (!is_array($stored)) {
            return [];
        }
        $templates = [];
        foreach ($stored as $id => $template) {
            if (!is_string($id) || !is_array($template)) {
                continue;
            }
            if (($template['id'] ?? '') !== $id || !isset($template['title']) || !is_string($template['title'])) {
                continue;
            }
            $blocks = $template['template'] ?? null;
            if (!is_array($blocks) || !EmailBuilder::validateTemplate($blocks)) {
                continue;
            }
            $templates[$id] = [
                'id' => $id,
                'title' => $template['title'],
                'template' => $blocks,
            ];
        }
        return $templates;
    }

    /**
     * Read one named email template by id, or null.
     *
     * @param string $id Template id.
     * @return array{id: string, title: string, template: array<string, mixed>}|null
     */
    public static function getEmailTemplate(string $id): ?array
    {
        $templates = self::getEmailTemplates();
        return $templates[$id] ?? null;
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
        $editingForm = $editingFormId !== '' ? ($forms[$editingFormId] ?? null) : null;
        $editingTemplate = $editingTemplateId !== '' ? ($templates[$editingTemplateId] ?? null) : null;
        $adminNonce = wp_create_nonce(self::NONCE_ACTION);
        $fieldLabels = FormBuilder::fieldLabels();
        $blockLabels = EmailBuilder::blockLabels();
        $fieldTypes = FormBuilder::FIELD_TYPES;
        $blockTypes = EmailBuilder::BLOCK_TYPES;
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
     * Create a new named email template from the default.
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
        $templates[$id] = [
            'id' => $id,
            'title' => $title,
            'template' => EmailBuilder::defaultTemplate(),
        ];
        update_option(self::OPTION_TEMPLATES, $templates, false);
        return [
            'success' => true,
            'message' => __('Email template created.', 'traydigita-core'),
            'data' => ['template_id' => $id],
        ];
    }

    /**
     * Save the blocks of an existing email template.
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
        $result = EmailBuilder::sanitizeTemplate(['blocks' => self::dropDeletedRows($data['blocks'] ?? null)]);
        if ($result['template'] === null) {
            return [
                'success' => false,
                'message' => $result['error'] ?? __('The email template is invalid.', 'traydigita-core'),
            ];
        }
        $templates[$id]['title'] = $title;
        $templates[$id]['template'] = $result['template'];
        update_option(self::OPTION_TEMPLATES, $templates, false);
        return [
            'success' => true,
            'message' => __('Email template saved.', 'traydigita-core'),
        ];
    }

    /**
     * Delete a named email template.
     *
     * @param array<string, mixed> $data Posted data.
     * @return array{success: bool, message: string}
     */
    private static function deleteEmailTemplate(array $data): array
    {
        $id = isset($data['template_id']) && is_string($data['template_id']) ? sanitize_key($data['template_id']) : '';
        $templates = self::getEmailTemplates();
        if ($id === '' || !isset($templates[$id])) {
            return [
                'success' => false,
                'message' => __('Unknown email template.', 'traydigita-core'),
            ];
        }
        unset($templates[$id]);
        update_option(self::OPTION_TEMPLATES, $templates, false);
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
     * nowhere by this module.
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
     * saved definition, then fires the submitted action with the
     * cleaned values. The endpoint stores nothing itself.
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
        /**
         * Fires when a generic [traydigita_form] form is submitted.
         *
         * @param string $formId Form id.
         * @param array<string, mixed> $values Cleaned values keyed by field id.
         */
        do_action(Core::HOOK_PREFIX . ':form-builder/submitted', $formId, $result['values']);
        wp_send_json_success(['message' => __('Thank you. Your submission was received.', 'traydigita-core')]);
    }
}
