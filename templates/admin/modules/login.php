<?php
/**
 * Login builder admin partial.
 *
 * Rendered by LoginModule::renderSettingsPage() inside the admin
 * shell content area. Variables:
 *
 * @var array<string, mixed> $design Effective design (saved or default).
 *
 * The builder JavaScript (resources/js/modules/login-builder.ts,
 * exposed as window.TrayDigitaLoginBuilder) reads the design from
 * the root element's data-design attribute, renders the canvas and
 * the settings panel from the <template> elements below, and saves
 * through the shell's generic save endpoint (action
 * traydigita_admin_save, module "login", nonce "traydigita-admin").
 * The live preview is rendered server-side once by
 * Designer::renderPreview() and then updated live by the JavaScript
 * from the same block data.
 *
 * Only semantic classes are used here; styling lives in
 * resources/css/components/admin-login.css.
 *
 * Framework (R4-5): the custom drag-drop builder is kept as-is
 * inside the framework shell - the shell provides the breadcrumb,
 * the left navigation (one "Login designer" anchor, matching
 * LoginModule::settingsSections()) and the content cards. The
 * builder's own toolbar Save button stays the save action; there
 * is no settings form here, so no framework save bar.
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\Login\Designer;
use TrayDigita\WP\Plugin\Core\Modules\Login\LoginModule;

$labels = Designer::blockLabels();
$hints = Designer::blockHints();
$blocks = is_array($design['blocks'] ?? null) ? $design['blocks'] : [];
$gradientOptions = [
    'forest' => __('Forest', 'traydigita-core'),
    'sunrise' => __('Sunrise', 'traydigita-core'),
    'ocean' => __('Ocean', 'traydigita-core'),
    'mono' => __('Mono', 'traydigita-core'),
];
?>
<div class="login-builder" id="td-section-designer"
    data-login-builder
    data-traydigita-module="login"
    data-design="<?php echo esc_attr((string) wp_json_encode($design)); ?>"
    data-nonce="<?php echo esc_attr(LoginModule::saveNonce()); ?>"
    data-ajax-url="<?php echo esc_attr(LoginModule::ajaxUrl()); ?>"
    data-save-action="traydigita_admin_save">
    <div class="login-builder-toolbar">
        <p class="login-builder-intro">
            <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('Drag blocks onto the canvas, click a block to edit it, and watch the live preview update.', 'traydigita-core'),
                    __('Save to apply the design to the WordPress login page.', 'traydigita-core')
                );
                ?>
        </p>
        <div class="login-builder-actions">
            <button type="button" class="traydigita-admin-button login-builder-reset" data-login-builder-reset>
                <?php esc_html_e('Reset to defaults', 'traydigita-core'); ?>
            </button>
            <button type="button" class="traydigita-admin-button traydigita-admin-button-primary login-builder-save" data-login-builder-save>
                <?php esc_html_e('Save changes', 'traydigita-core'); ?>
            </button>
            <span class="login-builder-status" data-login-builder-status role="status" aria-live="polite"></span>
        </div>
    </div>

    <div class="login-builder-layout">
        <section class="login-builder-palette" aria-label="<?php esc_attr_e('Blocks', 'traydigita-core'); ?>">
            <h3 class="login-builder-panel-title"><?php esc_html_e('Blocks', 'traydigita-core'); ?></h3>
            <p class="login-builder-panel-hint">
                <?php esc_html_e('Drag a block onto the canvas, or click it to add it.', 'traydigita-core'); ?>
            </p>
            <ul class="login-builder-palette-list">
                <?php foreach (Designer::BLOCK_TYPES as $type) : ?>
                    <li>
                        <button type="button" class="login-builder-palette-item"
                            draggable="true" data-block-type="<?php echo esc_attr($type); ?>">
                            <span class="login-builder-palette-label"><?php echo esc_html($labels[$type]); ?></span>
                            <span class="login-builder-palette-hint"><?php echo esc_html($hints[$type]); ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <div class="login-builder-main">
            <section class="login-builder-canvas-wrap" aria-label="<?php esc_attr_e('Canvas', 'traydigita-core'); ?>">
                <h3 class="login-builder-panel-title"><?php esc_html_e('Canvas', 'traydigita-core'); ?></h3>
                <p class="login-builder-panel-hint">
                    <?php esc_html_e('Drag blocks to reorder them, or focus a block and use the arrow keys. Click a block to edit its settings.', 'traydigita-core'); ?>
                </p>
                <ul class="login-builder-canvas" data-login-builder-canvas
                    data-aria-label-template="<?php echo esc_attr(__('%s block. Press Enter to edit, arrow keys to reorder.', 'traydigita-core')); ?>">
                    <?php foreach ($blocks as $index => $block) : ?>
                        <?php if (!is_array($block) || !isset($labels[$block['type'] ?? ''])) {
                            continue;
                        } ?>
                        <li class="login-builder-canvas-item" draggable="true" tabindex="0" role="button"
                            aria-label="<?php echo esc_attr(sprintf(
                                /* translators: %s is the block name. */
                                __('%s block. Press Enter to edit, arrow keys to reorder.', 'traydigita-core'),
                                $labels[$block['type']]
                            )); ?>"
                            data-block-type="<?php echo esc_attr((string) $block['type']); ?>"
                            data-block-index="<?php echo esc_attr((string) $index); ?>">
                            <span class="login-builder-canvas-handle" aria-hidden="true">&#8597;</span>
                            <span class="login-builder-canvas-label"><?php echo esc_html($labels[$block['type']]); ?></span>
                            <button type="button" class="login-builder-block-remove"
                                data-login-builder-remove="<?php echo esc_attr((string) $index); ?>"
                                aria-label="<?php echo esc_attr(sprintf(
                                    /* translators: %s is the block name. */
                                    __('Remove %s block', 'traydigita-core'),
                                    $labels[$block['type']]
                                )); ?>">
                                &times;
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="login-builder-canvas-empty" data-login-builder-canvas-empty hidden>
                    <?php esc_html_e('The canvas is empty. Add a block from the palette.', 'traydigita-core'); ?>
                </p>
            </section>

            <section class="login-builder-settings" aria-label="<?php esc_attr_e('Block settings', 'traydigita-core'); ?>">
                <h3 class="login-builder-panel-title"><?php esc_html_e('Block settings', 'traydigita-core'); ?></h3>
                <p class="login-builder-settings-empty" data-login-builder-settings-empty>
                    <?php esc_html_e('Select a block on the canvas to edit its settings.', 'traydigita-core'); ?>
                </p>
                <div class="login-builder-settings-form" data-login-builder-settings-form></div>
            </section>
        </div>

        <section class="login-builder-preview-wrap" aria-label="<?php esc_attr_e('Live preview', 'traydigita-core'); ?>">
            <h3 class="login-builder-panel-title"><?php esc_html_e('Live preview', 'traydigita-core'); ?></h3>
            <p class="login-builder-panel-hint">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('A preview of the login page.', 'traydigita-core'),
                        __('The form fields are disabled here; the real login form on wp-login.php keeps working as usual.', 'traydigita-core')
                    );
                    ?>
            </p>
            <p><span class="login-builder-preview-badge"><?php esc_html_e('Preview only', 'traydigita-core'); ?></span></p>
            <div class="login-builder-preview" data-login-builder-preview>
                <?php echo Designer::renderPreview($design); ?>
            </div>
        </section>
    </div>

    <?php /* Settings form templates, cloned by the builder JavaScript. */ ?>

    <template data-settings-template="logo">
        <div class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Logo image', 'traydigita-core'); ?></span>
            <input type="hidden" data-field="attachment_id" value="0">
            <span class="login-builder-media-preview" data-media-preview></span>
            <button type="button" class="traydigita-admin-button login-builder-media-button" data-media-picker="logo">
                <?php esc_html_e('Choose from Media Library', 'traydigita-core'); ?>
            </button>
            <button type="button" class="traydigita-admin-button login-builder-media-remove" data-media-remove="logo" hidden>
                <?php esc_html_e('Remove image', 'traydigita-core'); ?>
            </button>
        </div>
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Logo link URL', 'traydigita-core'); ?></span>
            <input type="url" class="login-builder-input" data-field="link_url"
                placeholder="<?php esc_attr_e('https://example.com', 'traydigita-core'); ?>">
        </label>
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Logo alt text', 'traydigita-core'); ?></span>
            <input type="text" class="login-builder-input" data-field="alt">
        </label>
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Logo width (px)', 'traydigita-core'); ?></span>
            <input type="number" class="login-builder-input" data-field="width" min="80" max="600" step="1">
        </label>
    </template>

    <template data-settings-template="background">
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Background type', 'traydigita-core'); ?></span>
            <select class="login-builder-input" data-field="mode">
                <option value="color"><?php esc_html_e('Colour', 'traydigita-core'); ?></option>
                <option value="image"><?php esc_html_e('Image', 'traydigita-core'); ?></option>
                <option value="gradient"><?php esc_html_e('Gradient', 'traydigita-core'); ?></option>
            </select>
        </label>
        <label class="login-builder-field" data-background-field="color">
            <span class="login-builder-field-label"><?php esc_html_e('Background colour', 'traydigita-core'); ?></span>
            <input type="text" class="login-builder-input login-builder-color-field" data-field="color">
        </label>
        <div class="login-builder-field" data-background-field="image">
            <span class="login-builder-field-label"><?php esc_html_e('Background image', 'traydigita-core'); ?></span>
            <input type="hidden" data-field="attachment_id" value="0">
            <span class="login-builder-media-preview" data-media-preview></span>
            <button type="button" class="traydigita-admin-button login-builder-media-button" data-media-picker="background">
                <?php esc_html_e('Choose from Media Library', 'traydigita-core'); ?>
            </button>
            <button type="button" class="traydigita-admin-button login-builder-media-remove" data-media-remove="background" hidden>
                <?php esc_html_e('Remove image', 'traydigita-core'); ?>
            </button>
        </div>
        <label class="login-builder-field" data-background-field="gradient">
            <span class="login-builder-field-label"><?php esc_html_e('Gradient preset', 'traydigita-core'); ?></span>
            <select class="login-builder-input" data-field="gradient">
                <?php foreach ($gradientOptions as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </template>

    <template data-settings-template="welcome">
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Heading', 'traydigita-core'); ?></span>
            <input type="text" class="login-builder-input" data-field="heading" maxlength="200">
        </label>
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Subtext', 'traydigita-core'); ?></span>
            <input type="text" class="login-builder-input" data-field="subtext" maxlength="500">
        </label>
    </template>

    <template data-settings-template="form">
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Form background colour', 'traydigita-core'); ?></span>
            <input type="text" class="login-builder-input login-builder-color-field" data-field="background_color">
        </label>
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Button colour', 'traydigita-core'); ?></span>
            <input type="text" class="login-builder-input login-builder-color-field" data-field="button_color">
        </label>
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Corner radius (px)', 'traydigita-core'); ?></span>
            <input type="number" class="login-builder-input" data-field="border_radius" min="0" max="24" step="1">
        </label>
    </template>

    <template data-settings-template="social">
        <div class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Links', 'traydigita-core'); ?></span>
            <div class="login-builder-social-rows" data-social-rows></div>
            <button type="button" class="traydigita-admin-button login-builder-social-add" data-social-add>
                <?php esc_html_e('Add link', 'traydigita-core'); ?>
            </button>
            <p class="login-builder-field-hint">
                <?php esc_html_e('Up to 8 links. Empty rows are ignored when saving.', 'traydigita-core'); ?>
            </p>
        </div>
        <template data-social-row-template>
            <div class="login-builder-social-row">
                <input type="text" class="login-builder-input" data-social-field="label"
                    placeholder="<?php esc_attr_e('Label', 'traydigita-core'); ?>" maxlength="50">
                <input type="url" class="login-builder-input" data-social-field="url"
                    placeholder="<?php esc_attr_e('https://example.com', 'traydigita-core'); ?>">
                <button type="button" class="login-builder-social-remove" data-social-remove
                    aria-label="<?php esc_attr_e('Remove link', 'traydigita-core'); ?>">&times;</button>
            </div>
        </template>
    </template>

    <template data-settings-template="custom_css">
        <label class="login-builder-field">
            <span class="login-builder-field-label"><?php esc_html_e('Custom CSS', 'traydigita-core'); ?></span>
            <textarea class="login-builder-input login-builder-css" data-field="css" rows="10" spellcheck="false"></textarea>
        </label>
        <p class="login-builder-field-hint">
            <?php esc_html_e('Plain CSS declarations only. Tags, @import, @font-face and javascript: or data: URLs are removed when saving.', 'traydigita-core'); ?>
        </p>
    </template>
</div>
