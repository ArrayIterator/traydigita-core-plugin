<?php
/**
 * Form Builder settings page.
 *
 * Lists the named forms and email templates, and edits one form
 * or one template at a time (selected with ?form=<id> /
 * ?template=<id>). Forms are edited in a plain server-rendered
 * form: one row per field, posted to the shell save endpoint.
 * Rows are removed with a Delete checkbox (no JavaScript
 * needed); an empty row at the end adds a new field.
 * Email templates are edited in the shared WYSIWYG editor
 * (templates/admin/modules/email-builder.php), which saves
 * through the Email AJAX endpoints: the shell's generic save
 * endpoint sanitizes every posted string with
 * sanitize_text_field(), which strips line breaks and block
 * HTML, so it cannot carry the flat-block shape.
 *
 * Variables passed in:
 * @var array<string, array{id: string, title: string, definition: array<string, mixed>}> $forms
 * @var array<string, array<string, mixed>> $templates Shared published templates (contact-form picker).
 * @var array<string, array<string, mixed>> $allTemplates All shared templates incl. drafts (list + editor).
 * @var array{id: string, title: string, definition: array<string, mixed>}|null $editingForm
 * @var array<string, mixed>|null $editingTemplate Shared template being edited.
 * @var string $adminNonce
 * @var array<string, string> $fieldLabels
 * @var array<int, string> $fieldTypes
 * @var array<string, array<string, mixed>> $contactForms
 * @var array<string, mixed> $contactSettings
 * @var array<string, mixed>|null $editingContactForm
 * @var bool $turnstileActive
 * @var bool $recaptchaConfigured
 * @var string $recaptchaSettingsUrl
 * @var array<int, string> $contactSpamModes
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilder;

$traydigita_settings_url = admin_url('admin.php?page=traydigita-form-builder');
?>
<div class="form-builder-module">
    <div class="traydigita-admin-section">
        <h2><?php esc_html_e('Forms', 'traydigita-core'); ?></h2>
        <p class="traydigita-admin-description">
            <?php esc_html_e('Forms built here can be used by the contact forms below and rendered anywhere with the shortcode shown below. A form stores its submissions in the submissions table when "Store submissions" is on for it; every submission is also handed to the traydigita:core:form-builder/submitted action.', 'traydigita-core'); ?>
        </p>
        <?php if ($forms === []) : ?>
            <p><?php esc_html_e('No forms yet. Create the first one below.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Title', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('ID', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Fields', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Shortcode', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $traydigita_form) : ?>
                        <tr>
                            <td><?php echo esc_html($traydigita_form['title']); ?></td>
                            <td><code><?php echo esc_html($traydigita_form['id']); ?></code></td>
                            <td><?php echo esc_html((string) count($traydigita_form['definition']['fields'])); ?></td>
                            <td><code>[traydigita_form id="<?php echo esc_attr($traydigita_form['id']); ?>"]</code></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('form', $traydigita_form['id'], $traydigita_settings_url)); ?>"><?php esc_html_e('Edit', 'traydigita-core'); ?></a>
                                |
                                <form method="post" data-action="traydigita_admin_save" data-module="form-builder" class="traydigita-admin-inline-form">
                                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                                    <input type="hidden" name="module" value="form-builder">
                                    <input type="hidden" name="builder_action" value="delete_form">
                                    <input type="hidden" name="form_id" value="<?php echo esc_attr($traydigita_form['id']); ?>">
                                    <button type="submit" class="button-link-delete"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" data-action="traydigita_admin_save" data-module="form-builder" class="traydigita-admin-inline-form">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="form-builder">
            <input type="hidden" name="builder_action" value="create_form">
            <label>
                <?php esc_html_e('New form title', 'traydigita-core'); ?>
                <input type="text" name="title" value="" required autocomplete="off">
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Create form', 'traydigita-core'); ?></button>
        </form>
    </div>

    <?php if ($editingForm !== null) : ?>
        <div class="traydigita-admin-section">
            <h2><?php esc_html_e('Edit form', 'traydigita-core'); ?> — <?php echo esc_html($editingForm['title']); ?></h2>
            <form method="post" data-action="traydigita_admin_save" data-module="form-builder">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                <input type="hidden" name="module" value="form-builder">
                <input type="hidden" name="builder_action" value="save_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr($editingForm['id']); ?>">
                <p>
                    <label>
                        <?php esc_html_e('Title', 'traydigita-core'); ?>
                        <input type="text" name="title" value="<?php echo esc_attr($editingForm['title']); ?>" required autocomplete="off">
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="store_submissions" value="1"
                            <?php checked(!empty($editingForm['store_submissions'])); ?>>
                        <?php esc_html_e('Store submissions', 'traydigita-core'); ?>
                    </label>
                    <span class="traydigita-admin-description"><?php esc_html_e('When on, every submission of this form is stored in the submissions table (listed on the submissions page, with CSV export and retention prune). When off, submissions are only handed to the traydigita:core:form-builder/submitted action.', 'traydigita-core'); ?></span>
                </p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Type', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('ID', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Label', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Required', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Placeholder', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Default', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Options (one per box, choice fields only)', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Delete', 'traydigita-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $traydigita_rows = $editingForm['definition']['fields'];
                        $traydigita_rows[] = ['id' => '', 'type' => 'text', 'label' => '', 'required' => false, 'placeholder' => '', 'options' => [], 'default' => ''];
                        foreach ($traydigita_rows as $traydigita_index => $traydigita_field) :
                            $traydigita_prefix = 'fields[' . $traydigita_index . ']';
                            $traydigita_options = is_array($traydigita_field['options'] ?? null) ? $traydigita_field['options'] : [];
                            while (count($traydigita_options) < 5) {
                                $traydigita_options[] = '';
                            }
                            ?>
                            <tr>
                                <td>
                                    <select name="<?php echo esc_attr($traydigita_prefix); ?>[type]">
                                        <?php foreach ($fieldTypes as $traydigita_type) : ?>
                                            <option value="<?php echo esc_attr($traydigita_type); ?>"<?php selected($traydigita_field['type'], $traydigita_type); ?>><?php echo esc_html($fieldLabels[$traydigita_type] ?? $traydigita_type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[id]" value="<?php echo esc_attr((string) $traydigita_field['id']); ?>" autocomplete="off" spellcheck="false"></td>
                                <td><input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[label]" value="<?php echo esc_attr((string) $traydigita_field['label']); ?>" autocomplete="off"></td>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr($traydigita_prefix); ?>[required]" value="0">
                                    <input type="checkbox" name="<?php echo esc_attr($traydigita_prefix); ?>[required]" value="1"<?php checked(!empty($traydigita_field['required'])); ?>>
                                </td>
                                <td><input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[placeholder]" value="<?php echo esc_attr((string) ($traydigita_field['placeholder'] ?? '')); ?>" autocomplete="off"></td>
                                <td><input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[default]" value="<?php echo esc_attr((string) ($traydigita_field['default'] ?? '')); ?>" autocomplete="off"></td>
                                <td>
                                    <?php foreach ($traydigita_options as $traydigita_option) : ?>
                                        <input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[options][]" value="<?php echo esc_attr((string) $traydigita_option); ?>" autocomplete="off">
                                    <?php endforeach; ?>
                                </td>
                                <td><input type="checkbox" name="<?php echo esc_attr($traydigita_prefix); ?>[delete]" value="1"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="traydigita-admin-description">
                    <?php esc_html_e('Field IDs must start with a letter and contain only lowercase letters, digits and underscores. Choice fields (dropdown, radio, checkboxes) need at least one option. Leave the last empty row blank to ignore it.', 'traydigita-core'); ?>
                </p>
                <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
            </form>
            <h3><?php esc_html_e('Preview', 'traydigita-core'); ?></h3>
            <div class="traydigita-admin-preview">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- FormBuilder::renderForm() escapes every value it prints.
                echo FormBuilder::renderForm($editingForm['definition'], '#', __('Submit', 'traydigita-core'));
                ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="traydigita-admin-section">
        <h2><?php esc_html_e('Email templates', 'traydigita-core'); ?></h2>
        <p class="traydigita-admin-description">
            <?php esc_html_e('Block-based email templates shared with the Newsletter — a template built here can also be picked for newsletter sends, and vice versa. Text supports {{field_id}} placeholders, replaced with the submitted values when the mail is sent. Image blocks are supported too. Use one text block per paragraph.', 'traydigita-core'); ?>
        </p>
        <?php if ($allTemplates === []) : ?>
            <p><?php esc_html_e('No email templates yet. Create the first one below.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Title', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('ID', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Blocks', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allTemplates as $traydigita_template) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($traydigita_template['name'] ?? '')); ?>
                                <?php if ((string) ($traydigita_template['status'] ?? 'published') === 'draft') : ?>
                                    <?php esc_html_e('(Draft)', 'traydigita-core'); ?>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo esc_html((string) ($traydigita_template['id'] ?? '')); ?></code></td>
                            <td><?php echo esc_html((string) count((array) ($traydigita_template['blocks'] ?? []))); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('template', $traydigita_template['id'], $traydigita_settings_url)); ?>"><?php esc_html_e('Edit', 'traydigita-core'); ?></a>
                                |
                                <form method="post" data-action="traydigita_admin_save" data-module="form-builder" class="traydigita-admin-inline-form">
                                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                                    <input type="hidden" name="module" value="form-builder">
                                    <input type="hidden" name="builder_action" value="delete_template">
                                    <input type="hidden" name="template_id" value="<?php echo esc_attr($traydigita_template['id']); ?>">
                                    <button type="submit" class="button-link-delete"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" data-action="traydigita_admin_save" data-module="form-builder" class="traydigita-admin-inline-form">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="form-builder">
            <input type="hidden" name="builder_action" value="create_template">
            <label>
                <?php esc_html_e('New template title', 'traydigita-core'); ?>
                <input type="text" name="title" value="" required autocomplete="off">
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Create template', 'traydigita-core'); ?></button>
        </form>
    </div>

    <?php if ($editingTemplate !== null) : ?>
        <div class="traydigita-admin-section">
            <h2><?php esc_html_e('Edit email template', 'traydigita-core'); ?> — <?php echo esc_html((string) ($editingTemplate['name'] ?? '')); ?></h2>
            <form method="post" class="traydigita-template-edit-form">
                <input type="hidden" name="template_id" value="<?php echo esc_attr($editingTemplate['id']); ?>">
                <p>
                    <label>
                        <?php esc_html_e('Title', 'traydigita-core'); ?>
                        <input type="text" name="title" value="<?php echo esc_attr((string) ($editingTemplate['name'] ?? '')); ?>" required autocomplete="off">
                    </label>
                </p>
                <?php
                // The shared WYSIWYG editor (same as the Newsletter page).
                // It saves through the Email AJAX endpoints, not through
                // this form: the shell's generic save would strip block
                // HTML (see AdminAjax::deepSanitize()), while the Email
                // endpoints keep it intact via TemplateBuilder.
                // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required editor partial below, in the same scope.
                $editorContext = 'form-builder';
                $editorTemplates = null;
                $editorInitial = $editingTemplate;
                $editorAjaxUrl = admin_url('admin-ajax.php');
                $editorNonce = $adminNonce;
                $editorNameSelector = '.traydigita-template-edit-form input[name="title"]';
                $editorIdSelector = '.traydigita-template-edit-form input[name="template_id"]';
                $editorFormSelector = '.traydigita-template-edit-form';
                $editorSiteName = (string) get_bloginfo('name');
                $editorSiteUrl = home_url('/');
                // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
                require \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/email-builder.php';
                ?>
            </form>
        </div>
    <?php endif; ?>

<?php
$traydigita_settings_url = admin_url('admin.php?page=traydigita-form-builder');
$traydigita_submissions_url = admin_url('admin.php?page=traydigita-contact-submissions');
$traydigita_spam_labels = [
    'auto' => __('Automatic (Turnstile first, then reCAPTCHA v3)', 'traydigita-core'),
    'turnstile' => __('Turnstile', 'traydigita-core'),
    'recaptcha-v2' => __('reCAPTCHA v2 (checkbox)', 'traydigita-core'),
    'recaptcha-v3' => __('reCAPTCHA v3 (score)', 'traydigita-core'),
    'none' => __('None', 'traydigita-core'),
];
?>
    <div class="traydigita-admin-section" id="td-section-contact-forms">
        <h2><?php esc_html_e('Contact forms', 'traydigita-core'); ?></h2>
        <p class="traydigita-admin-description">
            <?php esc_html_e('Each contact form uses a form built in the Form Builder. Submissions are stored in their own table first, then the notification email is sent via the Core Mailer (when send-to-email is on).', 'traydigita-core'); ?>
            <a href="<?php echo esc_url($traydigita_submissions_url); ?>"><?php esc_html_e('View submissions', 'traydigita-core'); ?></a>
        </p>
        <?php if ($contactForms === []) : ?>
            <p><?php esc_html_e('No contact forms yet. Create the first one below.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Title', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Form Builder form', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Recipient', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Send email', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Spam protection', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Shortcode', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contactForms as $traydigita_form) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $traydigita_form['title']); ?></td>
                            <td><?php echo esc_html($forms[(string) $traydigita_form['form_builder_id']]['title'] ?? __('— not set —', 'traydigita-core')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_form['recipient'] ?: $contactSettings['default_recipient'])); ?></td>
                            <td><?php echo !empty($traydigita_form['send_email']) ? esc_html__('Yes', 'traydigita-core') : esc_html__('Store only', 'traydigita-core'); ?></td>
                            <td><?php echo esc_html($traydigita_spam_labels[(string) $traydigita_form['spam']] ?? (string) $traydigita_form['spam']); ?></td>
                            <td><code>[traydigita_contact_form id="<?php echo esc_attr((string) $traydigita_form['id']); ?>"]</code></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg(
                                    'contact_form',
                                    (string) $traydigita_form['id'],
                                    $traydigita_settings_url
                                )); ?>"><?php esc_html_e('Edit', 'traydigita-core'); ?></a>
                                |
                                <form method="post" data-action="traydigita_admin_save" data-module="form-builder" class="traydigita-admin-inline-form">
                                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                                    <input type="hidden" name="module" value="form-builder">
                                    <input type="hidden" name="contact_action" value="delete_form">
                                    <input type="hidden" name="form_id" value="<?php echo esc_attr((string) $traydigita_form['id']); ?>">
                                    <button type="submit" class="button-link-delete"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" data-action="traydigita_admin_save" data-module="form-builder" class="traydigita-admin-inline-form">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="form-builder">
            <input type="hidden" name="contact_action" value="create_form">
            <label>
                <?php esc_html_e('New contact form title', 'traydigita-core'); ?>
                <input type="text" name="title" value="" required autocomplete="off">
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Create contact form', 'traydigita-core'); ?></button>
        </form>
    </div>

    <?php if ($editingContactForm !== null) : ?>
        <div class="traydigita-admin-section">
            <h2><?php esc_html_e('Edit contact form', 'traydigita-core'); ?> — <?php echo esc_html((string) $editingContactForm['title']); ?></h2>
            <form method="post" data-action="traydigita_admin_save" data-module="form-builder">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                <input type="hidden" name="module" value="form-builder">
                <input type="hidden" name="contact_action" value="save_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr((string) $editingContactForm['id']); ?>">
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'contact-form-title',
                    'name' => 'title',
                    'label' => __('Title', 'traydigita-core'),
                    'value' => (string) $editingContactForm['title'],
                    'attrs' => ['required' => 'required', 'autocomplete' => 'off'],
                ]);
                ?>
                <?php
                $traydigita_builder_options = ['' => __('— Select a form —', 'traydigita-core')];
                foreach ($forms as $traydigita_builder_form) {
                    $traydigita_builder_options[(string) $traydigita_builder_form['id']] = (string) $traydigita_builder_form['title'];
                }
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'contact-form-builder-id',
                    'name' => 'form_builder_id',
                    'label' => __('Form Builder form', 'traydigita-core'),
                    'value' => (string) $editingContactForm['form_builder_id'],
                    'options' => $traydigita_builder_options,
                ]);
                ?>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'type' => 'email',
                    'id' => 'contact-form-recipient',
                    'name' => 'recipient',
                    'label' => __('Recipient email', 'traydigita-core'),
                    'value' => (string) $editingContactForm['recipient'],
                    'help' => __('Leave empty to use the default recipient below.', 'traydigita-core'),
                    'attrs' => ['autocomplete' => 'email', 'spellcheck' => 'false'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
                    'id' => 'contact-form-send-email',
                    'name' => 'send_email',
                    'label' => __('Send to email', 'traydigita-core'),
                    'checked' => !empty($editingContactForm['send_email']),
                    'help' => __('When on, each submission is emailed to the recipient via the Core Mailer (and appears in the Mailer send log). When off, submissions are stored only — sending is prevented per form.', 'traydigita-core'),
                ]);
                ?>
                <?php
                $traydigita_spam_options = [];
                foreach ($contactSpamModes as $traydigita_mode) {
                    $traydigita_spam_options[(string) $traydigita_mode] = $traydigita_spam_labels[(string) $traydigita_mode] ?? (string) $traydigita_mode;
                }
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'contact-form-spam',
                    'name' => 'spam',
                    'label' => __('Spam protection', 'traydigita-core'),
                    'value' => (string) $editingContactForm['spam'],
                    'options' => $traydigita_spam_options,
                ]);
                ?>
                <?php
                $traydigita_template_options = ['' => __('— Plain list of values —', 'traydigita-core')];
                foreach ($templates as $traydigita_template) {
                    $traydigita_template_options[(string) $traydigita_template['id']] = (string) $traydigita_template['name'];
                }
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'contact-form-email-template',
                    'name' => 'email_template_id',
                    'label' => __('Notification email template', 'traydigita-core'),
                    'value' => (string) $editingContactForm['email_template_id'],
                    'options' => $traydigita_template_options,
                ]);
                ?>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'contact-form-subject-prefix',
                    'name' => 'subject_prefix',
                    'label' => __('Email subject prefix', 'traydigita-core'),
                    'value' => (string) $editingContactForm['subject_prefix'],
                    'help' => __('Optional prefix for the notification email subject, e.g. [Website].', 'traydigita-core'),
                    'attrs' => ['autocomplete' => 'off'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar();
                ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="traydigita-admin-section" id="td-section-contact-settings">
        <h2><?php esc_html_e('Spam protection & retention', 'traydigita-core'); ?></h2>
        <p class="traydigita-admin-description">
            <?php if ($turnstileActive) : ?>
                <?php esc_html_e('Turnstile is active and configured — forms using Automatic or Turnstile protection will show the Turnstile challenge.', 'traydigita-core'); ?>
            <?php else : ?>
                <?php esc_html_e('Turnstile is not active or not configured. Turnstile keys are managed in the Turnstile module settings.', 'traydigita-core'); ?>
            <?php endif; ?>
            <?php if ($recaptchaConfigured) : ?>
                <?php esc_html_e('reCAPTCHA keys are set.', 'traydigita-core'); ?>
            <?php else : ?>
                <?php esc_html_e('reCAPTCHA keys are not set yet.', 'traydigita-core'); ?>
            <?php endif; ?>
        </p>
        <form method="post" data-action="traydigita_admin_save" data-module="form-builder">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="form-builder">
            <input type="hidden" name="contact_action" value="save_settings">
            <?php
            // reCAPTCHA keys live in the Recaptcha module; this page
            // only links to its settings.
            ?>
            <p>
                <?php if ($recaptchaConfigured) : ?>
                    <?php esc_html_e('reCAPTCHA keys are set in the Google reCAPTCHA module.', 'traydigita-core'); ?>
                <?php else : ?>
                    <?php esc_html_e('reCAPTCHA keys are not set yet. Contact forms using reCAPTCHA protection will have no spam protection until the keys are saved.', 'traydigita-core'); ?>
                <?php endif; ?>
                <a href="<?php echo esc_url($recaptchaSettingsUrl); ?>"><?php esc_html_e('Manage reCAPTCHA keys', 'traydigita-core'); ?></a>
            </p>
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                'id' => 'contact-form-retention',
                'name' => 'retention_days',
                'label' => __('Keep submissions (days)', 'traydigita-core'),
                'value' => (int) $contactSettings['retention_days'],
                'help' => __('Submissions older than this are deleted automatically (daily prune). 0 keeps submissions forever.', 'traydigita-core'),
                'attrs' => ['min' => '0'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'type' => 'email',
                'id' => 'contact-form-default-recipient',
                'name' => 'default_recipient',
                'label' => __('Default recipient email', 'traydigita-core'),
                'value' => (string) $contactSettings['default_recipient'],
                'help' => __('Used by forms without their own recipient. Leave empty to use the site admin email.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'email', 'spellcheck' => 'false'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar();
            ?>
        </form>
    </div>
</div>
