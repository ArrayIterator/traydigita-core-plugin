<?php
/**
 * Form Builder settings page.
 *
 * Lists the named forms and email templates, and edits one form
 * or one template at a time (selected with ?form=<id> /
 * ?template=<id>). The editor is a plain server-rendered form:
 * one row per field/block, posted to the shell save endpoint.
 * Rows are removed with a Delete checkbox (no JavaScript
 * needed); an empty row at the end adds a new field/block.
 *
 * Text is single-line per block: the shell's generic save
 * endpoint sanitizes every posted string with
 * sanitize_text_field(), which strips line breaks. Use one text
 * block per paragraph.
 *
 * Variables passed in:
 * @var array<string, array{id: string, title: string, definition: array<string, mixed>}> $forms
 * @var array<string, array<string, mixed>> $templates Shared templates (id, name, blocks, created_at).
 * @var array{id: string, title: string, definition: array<string, mixed>}|null $editingForm
 * @var array<string, mixed>|null $editingTemplate Shared template being edited.
 * @var string $adminNonce
 * @var array<string, string> $fieldLabels
 * @var array<string, string> $blockLabels
 * @var array<int, string> $fieldTypes
 * @var array<int, string> $blockTypes
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateBuilder;
use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilder;

$traydigita_settings_url = admin_url('admin.php?page=traydigita-form-builder');
?>
<div class="form-builder-module">
    <div class="traydigita-admin-section">
        <h2><?php esc_html_e('Forms', 'traydigita-core'); ?></h2>
        <p class="traydigita-admin-description">
            <?php esc_html_e('Forms built here can be used by the Contact Form module and rendered anywhere with the shortcode shown below. The Contact Form module owns its own submissions; the shortcode form below only validates and hands the values to the traydigita:core:form-builder/submitted action.', 'traydigita-core'); ?>
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
        <?php if ($templates === []) : ?>
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
                    <?php foreach ($templates as $traydigita_template) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($traydigita_template['name'] ?? '')); ?></td>
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
            <form method="post" data-action="traydigita_admin_save" data-module="form-builder">
                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
                <input type="hidden" name="module" value="form-builder">
                <input type="hidden" name="builder_action" value="save_template">
                <input type="hidden" name="template_id" value="<?php echo esc_attr($editingTemplate['id']); ?>">
                <p>
                    <label>
                        <?php esc_html_e('Title', 'traydigita-core'); ?>
                        <input type="text" name="title" value="<?php echo esc_attr((string) ($editingTemplate['name'] ?? '')); ?>" required autocomplete="off">
                    </label>
                </p>
                <table class="widefat striped traydigita-template-editor-table">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Type', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Text', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Button label', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Button URL', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Image URL', 'traydigita-core'); ?></th>
                            <th scope="col"><?php esc_html_e('Delete', 'traydigita-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $traydigita_blocks = is_array($editingTemplate['blocks'] ?? null) ? $editingTemplate['blocks'] : [];
                        $traydigita_blocks[] = ['type' => 'text', 'text' => '', 'label' => '', 'url' => '', 'image_url' => ''];
                        foreach ($traydigita_blocks as $traydigita_index => $traydigita_block) :
                            $traydigita_prefix = 'blocks[' . $traydigita_index . ']';
                            $traydigita_type = (string) ($traydigita_block['type'] ?? 'text');
                            // Text column: heading/text/footer text, or the image alt text.
                            $traydigita_text = $traydigita_type === 'image'
                                ? (string) ($traydigita_block['alt'] ?? '')
                                : (string) ($traydigita_block['text'] ?? $traydigita_block['content'] ?? '');
                            // Button URL column: button link, or the image click-through link.
                            $traydigita_url = $traydigita_type === 'image'
                                ? (string) ($traydigita_block['link'] ?? '')
                                : (string) ($traydigita_block['url'] ?? '');
                            $traydigita_image_url = $traydigita_type === 'image'
                                ? (string) ($traydigita_block['url'] ?? '')
                                : '';
                            ?>
                            <tr>
                                <td>
                                    <select name="<?php echo esc_attr($traydigita_prefix); ?>[type]">
                                        <?php foreach ($blockTypes as $traydigita_option_type) : ?>
                                            <option value="<?php echo esc_attr($traydigita_option_type); ?>"<?php selected($traydigita_type, $traydigita_option_type); ?>><?php echo esc_html($blockLabels[$traydigita_option_type] ?? $traydigita_option_type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[text]"
                                    value="<?php echo esc_attr($traydigita_text); ?>"
                                    autocomplete="off"></td>
                                <td><input type="text" name="<?php echo esc_attr($traydigita_prefix); ?>[label]"
                                    value="<?php echo esc_attr((string) ($traydigita_block['label'] ?? '')); ?>"
                                    autocomplete="off"></td>
                                <td><input type="url" name="<?php echo esc_attr($traydigita_prefix); ?>[url]"
                                    value="<?php echo esc_attr($traydigita_url); ?>"
                                    autocomplete="off" spellcheck="false"></td>
                                <td><input type="url" name="<?php echo esc_attr($traydigita_prefix); ?>[image_url]"
                                    value="<?php echo esc_attr($traydigita_image_url); ?>"
                                    autocomplete="off" spellcheck="false"></td>
                                <td><input type="checkbox" name="<?php echo esc_attr($traydigita_prefix); ?>[delete]" value="1"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="traydigita-admin-description">
                    <?php esc_html_e('Button blocks need both a label and a URL. Image blocks need an image URL. Leave the last empty row blank to ignore it.', 'traydigita-core'); ?>
                </p>
                <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
            </form>
            <h3><?php esc_html_e('Preview', 'traydigita-core'); ?></h3>
            <div class="traydigita-admin-preview">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TemplateBuilder::renderEmail() escapes every value it prints.
                echo TemplateBuilder::renderEmail($editingTemplate, ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'message' => 'Hello, this is a sample message.']);
                ?>
            </div>
        </div>
    <?php endif; ?>
</div>
