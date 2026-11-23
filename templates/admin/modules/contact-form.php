<?php
/**
 * Contact Form settings page.
 *
 * Two parts: the contact forms (each one points at a Form
 * Builder form, a recipient, a send-to-email toggle, a spam
 * mode and an optional Email Builder template) and the
 * module-level settings (retention days, default recipient).
 * reCAPTCHA keys live in the Recaptcha module — this page
 * only links to its settings.
 *
 * Variables passed in:
 * @var array<string, mixed> $settings
 * @var array<string, array<string, mixed>> $forms
 * @var array<string, array{id: string, title: string, definition: array<string, mixed>}> $builderForms
 * @var array<string, array{id: string, title: string, template: array<string, mixed>}> $emailTemplates
 * @var array<string, mixed>|null $editingForm
 * @var bool $turnstileActive
 * @var bool $recaptchaConfigured
 * @var string $recaptchaSettingsUrl
 * @var string $adminNonce
 * @var array<int, string> $spamModes
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$traydigita_settings_url = admin_url('admin.php?page=traydigita-contact-form');
$traydigita_submissions_url = admin_url('admin.php?page=traydigita-contact-submissions');
$traydigita_spam_labels = [
    'auto' => __('Automatic (Turnstile first, then reCAPTCHA v3)', 'traydigita-core'),
    'turnstile' => __('Turnstile', 'traydigita-core'),
    'recaptcha-v2' => __('reCAPTCHA v2 (checkbox)', 'traydigita-core'),
    'recaptcha-v3' => __('reCAPTCHA v3 (score)', 'traydigita-core'),
    'none' => __('None', 'traydigita-core'),
];
?>
<div class="contact-form-module">
    <div class="traydigita-admin-section" id="td-section-forms">
        <h2><?php esc_html_e('Contact forms', 'traydigita-core'); ?></h2>
        <p class="traydigita-admin-description">
            <?php esc_html_e('Each contact form uses a form built in the Form Builder. Submissions are stored in their own table first, then the notification email is sent via the Core Mailer (when send-to-email is on).', 'traydigita-core'); ?>
            <a href="<?php echo esc_url($traydigita_submissions_url); ?>"><?php esc_html_e('View submissions', 'traydigita-core'); ?></a>
        </p>
        <?php if ($forms === []) : ?>
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
                    <?php foreach ($forms as $traydigita_form) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $traydigita_form['title']); ?></td>
                            <td><?php echo esc_html($builderForms[(string) $traydigita_form['form_builder_id']]['title'] ?? __('— not set —', 'traydigita-core')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_form['recipient'] ?: $settings['default_recipient'])); ?></td>
                            <td><?php echo !empty($traydigita_form['send_email']) ? esc_html__('Yes', 'traydigita-core') : esc_html__('Store only', 'traydigita-core'); ?></td>
                            <td><?php echo esc_html($traydigita_spam_labels[(string) $traydigita_form['spam']] ?? (string) $traydigita_form['spam']); ?></td>
                            <td><code>[traydigita_contact_form id="<?php echo esc_attr((string) $traydigita_form['id']); ?>"]</code></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('form', (string) $traydigita_form['id'], $traydigita_settings_url)); ?>"><?php esc_html_e('Edit', 'traydigita-core'); ?></a>
                                |
                                <form method="post" data-action="traydigita_admin_save" data-module="contact-form" class="traydigita-admin-inline-form">
                                    <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                                    <input type="hidden" name="module" value="contact-form">
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
        <form method="post" data-action="traydigita_admin_save" data-module="contact-form" class="traydigita-admin-inline-form">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="contact-form">
            <input type="hidden" name="contact_action" value="create_form">
            <label>
                <?php esc_html_e('New contact form title', 'traydigita-core'); ?>
                <input type="text" name="title" value="" required autocomplete="off">
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Create contact form', 'traydigita-core'); ?></button>
        </form>
    </div>

    <?php if ($editingForm !== null) : ?>
        <div class="traydigita-admin-section">
            <h2><?php esc_html_e('Edit contact form', 'traydigita-core'); ?> — <?php echo esc_html((string) $editingForm['title']); ?></h2>
            <form method="post" data-action="traydigita_admin_save" data-module="contact-form">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                <input type="hidden" name="module" value="contact-form">
                <input type="hidden" name="contact_action" value="save_form">
                <input type="hidden" name="form_id" value="<?php echo esc_attr((string) $editingForm['id']); ?>">
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'contact-form-title',
                    'name' => 'title',
                    'label' => __('Title', 'traydigita-core'),
                    'value' => (string) $editingForm['title'],
                    'attrs' => ['required' => 'required', 'autocomplete' => 'off'],
                ]);
                ?>
                <?php
                $traydigita_builder_options = ['' => __('— Select a form —', 'traydigita-core')];
                foreach ($builderForms as $traydigita_builder_form) {
                    $traydigita_builder_options[(string) $traydigita_builder_form['id']] = (string) $traydigita_builder_form['title'];
                }
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'contact-form-builder-id',
                    'name' => 'form_builder_id',
                    'label' => __('Form Builder form', 'traydigita-core'),
                    'value' => (string) $editingForm['form_builder_id'],
                    'options' => $traydigita_builder_options,
                ]);
                ?>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'type' => 'email',
                    'id' => 'contact-form-recipient',
                    'name' => 'recipient',
                    'label' => __('Recipient email', 'traydigita-core'),
                    'value' => (string) $editingForm['recipient'],
                    'help' => __('Leave empty to use the default recipient below.', 'traydigita-core'),
                    'attrs' => ['autocomplete' => 'email', 'spellcheck' => 'false'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
                    'id' => 'contact-form-send-email',
                    'name' => 'send_email',
                    'label' => __('Send to email', 'traydigita-core'),
                    'checked' => !empty($editingForm['send_email']),
                    'help' => __('When on, each submission is emailed to the recipient via the Core Mailer (and appears in the Mailer send log). When off, submissions are stored only — sending is prevented per form.', 'traydigita-core'),
                ]);
                ?>
                <?php
                $traydigita_spam_options = [];
                foreach ($spamModes as $traydigita_mode) {
                    $traydigita_spam_options[(string) $traydigita_mode] = $traydigita_spam_labels[(string) $traydigita_mode] ?? (string) $traydigita_mode;
                }
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'contact-form-spam',
                    'name' => 'spam',
                    'label' => __('Spam protection', 'traydigita-core'),
                    'value' => (string) $editingForm['spam'],
                    'options' => $traydigita_spam_options,
                ]);
                ?>
                <?php
                $traydigita_template_options = ['' => __('— Plain list of values —', 'traydigita-core')];
                foreach ($emailTemplates as $traydigita_template) {
                    $traydigita_template_options[(string) $traydigita_template['id']] = (string) $traydigita_template['title'];
                }
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'contact-form-email-template',
                    'name' => 'email_template_id',
                    'label' => __('Notification email template', 'traydigita-core'),
                    'value' => (string) $editingForm['email_template_id'],
                    'options' => $traydigita_template_options,
                ]);
                ?>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'contact-form-subject-prefix',
                    'name' => 'subject_prefix',
                    'label' => __('Email subject prefix', 'traydigita-core'),
                    'value' => (string) $editingForm['subject_prefix'],
                    'help' => __('Optional prefix for the notification email subject, e.g. [Website].', 'traydigita-core'),
                    'attrs' => ['autocomplete' => 'off'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar();
                ?>
            </form>
        </div>
    <?php endif; ?>

    <div class="traydigita-admin-section" id="td-section-spam">
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
        <form method="post" data-action="traydigita_admin_save" data-module="contact-form">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="contact-form">
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
                'value' => (int) $settings['retention_days'],
                'help' => __('Submissions older than this are deleted automatically (daily prune). 0 keeps submissions forever.', 'traydigita-core'),
                'attrs' => ['min' => '0'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'type' => 'email',
                'id' => 'contact-form-default-recipient',
                'name' => 'default_recipient',
                'label' => __('Default recipient email', 'traydigita-core'),
                'value' => (string) $settings['default_recipient'],
                'help' => __('Used by forms without their own recipient. Leave empty to use the site admin email.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'email', 'spellcheck' => 'false'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar();
            ?>
        </form>
    </div>
</div>
