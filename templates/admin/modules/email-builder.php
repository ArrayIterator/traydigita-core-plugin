<?php
/**
 * Shared WYSIWYG email template editor.
 *
 * Used by BOTH the Newsletter Email page and the FormBuilder
 * template editor. The editor IS the live rendered email:
 * blocks are rendered with the same padding, colours and type
 * scale TemplateBuilder::renderBlock() uses and edited inline
 * in place; the preview pane below shows the exact
 * server-rendered email (TemplateBuilder::renderEmail()),
 * refreshed as you type.
 *
 * The source of truth stays the flat-block shape: saves,
 * autosaves and previews post {id, name, blocks} through
 * the Email AJAX endpoints, and the sent mail is rendered
 * by TemplateBuilder::renderEmail(), never by JavaScript.
 *
 * Variables (set by the caller before requiring this file):
 * @var string $editorContext 'newsletter' or 'form-builder'.
 * @var array<string, array<string, mixed>>|null $editorTemplates All templates (newsletter list) or null.
 * @var array<string, mixed>|null $editorInitial Template being edited (form-builder) or null.
 * @var string $editorAjaxUrl admin-ajax.php URL.
 * @var string $editorNonce Nonce for the 'traydigita-admin' action.
 * @var string $editorNameSelector CSS selector of the template-name input.
 * @var string $editorIdSelector CSS selector of the template-id input.
 * @var string|null $editorFormSelector CSS selector of the wrapping form (form-builder) or null.
 * @var string $editorSiteName Site name (footer preview line).
 * @var string $editorSiteUrl Site URL (unused in the editor; kept for symmetry).
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateBuilder;

$traydigita_editor_config = [
    'ajaxUrl' => $editorAjaxUrl,
    'nonce' => $editorNonce,
    'context' => $editorContext,
    'templates' => $editorTemplates,
    'initial' => $editorInitial,
    'nameSelector' => $editorNameSelector,
    'idSelector' => $editorIdSelector,
    'formSelector' => $editorFormSelector,
    'siteName' => $editorSiteName,
    'siteUrl' => $editorSiteUrl,
    'strings' => [
        'emptyCanvas' => __(
            'No blocks yet. Add one from the palette above.',
            'traydigita-core'
        ),
    ],
];
$traydigita_block_labels = TemplateBuilder::blockLabels();
?>
<div class="td-email-builder" data-context="<?php echo esc_attr($editorContext); ?>">
    <script type="application/json" class="td-email-builder-config"><?php echo wp_json_encode(
        $traydigita_editor_config,
        JSON_HEX_TAG | JSON_HEX_AMP
    ); ?></script>

    <p class="td-eb-message" aria-live="polite"></p>

    <div class="td-eb-restore" hidden>
        <span class="td-eb-restore-text"></span>
        <button type="button" class="button td-eb-restore-accept"><?php esc_html_e(
            'Restore',
            'traydigita-core'
        ); ?></button>
        <button type="button" class="button td-eb-restore-discard"><?php esc_html_e(
            'Discard',
            'traydigita-core'
        ); ?></button>
    </div>

    <div class="td-eb-palette" role="group" aria-label="<?php echo esc_attr__('Add a block', 'traydigita-core'); ?>">
        <?php foreach (TemplateBuilder::BLOCK_TYPES as $traydigita_block_type) : ?>
            <button type="button" class="td-eb-palette-item"
                data-block-type="<?php echo esc_attr($traydigita_block_type); ?>">
                <?php echo esc_html($traydigita_block_labels[$traydigita_block_type] ?? $traydigita_block_type); ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="td-eb-workspace">
        <div class="td-eb-canvas-wrap">
            <div class="td-eb-canvas"></div>
        </div>
        <div class="td-eb-inspector">
            <h3><?php esc_html_e('Block settings', 'traydigita-core'); ?></h3>
            <p class="td-eb-inspector-desc"><?php esc_html_e(
                'Select a block in the email to edit its settings.',
                'traydigita-core'
            ); ?></p>
        </div>
    </div>

    <div class="td-eb-actions">
        <button type="button" class="button td-eb-save-draft"><?php esc_html_e(
            'Save draft',
            'traydigita-core'
        ); ?></button>
        <button type="button" class="button button-primary td-eb-save-publish"><?php esc_html_e(
            'Save & publish',
            'traydigita-core'
        ); ?></button>
        <?php if ($editorContext === 'newsletter') : ?>
            <button type="button"
                class="button td-eb-new"><?php esc_html_e('New template', 'traydigita-core'); ?></button>
        <?php endif; ?>
    </div>

    <div class="td-eb-preview-wrap">
        <h3><?php esc_html_e('Exact email preview', 'traydigita-core'); ?></h3>
        <p class="td-eb-preview-note">
            <?php
            esc_html_e(
                'Rendered by the same code that sends the email, refreshed as you type.',
                'traydigita-core'
            );
            ?>
        </p>
        <iframe class="td-eb-preview-frame" title="<?php echo esc_attr__(
            'Email preview',
            'traydigita-core'
        ); ?>"></iframe>
    </div>
</div>
