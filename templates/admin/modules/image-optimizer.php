<?php
/**
 * Image Optimizer module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by ImageOptimizerModule::renderSettingsPage()):
 * @var array{format: string, quality: int, keep_original: bool, optimize_on_upload: bool} $imageOptimizerSettings Current settings.
 * @var array{webp: bool, avif: bool} $imageOptimizerSupported Whether the image editor can write each format.
 * @var bool $imageOptimizerPhoton Whether Photon is serving images (local optimization is skipped then).
 * @var array{total: int, optimized: int, skipped: int, failed: int, unprocessed: int} $imageOptimizerStats Library counts by optimization status.
 * @var array{status: string, total: int, done: int, optimized: int, skipped: int, failed: int, started: int} $imageOptimizerProgress Last bulk-run progress.
 * @var bool $imageOptimizerRunning Whether a bulk run is in progress right now.
 * @var bool $imageOptimizerConfigured Whether the selected format is supported by the image editor.
 * @var string $imageOptimizerNonceAction Nonce action (`traydigita-admin`).
 * @var string $imageOptimizerStartAction AJAX action starting a bulk run.
 * @var string $imageOptimizerChunkAction AJAX action processing the next chunk.
 * @var string $imageOptimizerCancelAction AJAX action cancelling a bulk run.
 * @var string $imageOptimizerSaveAction AJAX action for the shell generic save endpoint (`traydigita_admin_save`).
 *
 * Forms:
 * - Settings form: posts to the shell generic save endpoint
 *   (`data-traydigita-module-form` + `data-module="image-optimizer"`);
 *   the shell passes the fields to ImageOptimizerModule::saveSettings().
 *   Field names are flat: `format`, `quality`, `keep_original`,
 *   `optimize_on_upload`. Hidden `0` fields before the checkboxes
 *   guarantee the fields are always submitted, even unchecked.
 * - Bulk run: the start/cancel buttons are driven by
 *   `resources/js/modules/admin-image-optimizer.ts`, which posts
 *   to the three AJAX actions above with the shell nonce and
 *   updates the progress bar and status region (`data-image-optimizer-*`).
 *   All user-visible strings for the bulk UI come from the
 *   `data-msg-*` attributes on the root element.
 *
 * Framework (R4-5): the settings fields are rendered with
 * SettingsControls (select, number, toggles) inside framework
 * sections (`td-section-*`), and the form ends in the sticky
 * framework save bar. The support/library/bulk blocks keep their
 * own markup — they are status displays, not settings fields.
 */
declare(strict_types=1);

$progressPercent = $imageOptimizerProgress['total'] > 0
    ? (int) round($imageOptimizerProgress['done'] / $imageOptimizerProgress['total'] * 100)
    : 0;
?>
<div
    class="traydigita-image-optimizer-settings"
    data-image-optimizer
    data-msg-starting="<?php esc_attr_e('Starting bulk optimization…', 'traydigita-core'); ?>"
    data-msg-running="<?php esc_attr_e('Optimizing images…', 'traydigita-core'); ?>"
    data-msg-finished="<?php esc_attr_e('Bulk optimization finished.', 'traydigita-core'); ?>"
    data-msg-cancelled="<?php esc_attr_e('Bulk optimization cancelled.', 'traydigita-core'); ?>"
    data-msg-error="<?php esc_attr_e('Something went wrong. Please try again.', 'traydigita-core'); ?>"
    data-msg-no-run="<?php esc_attr_e('No bulk optimization is running.', 'traydigita-core'); ?>"
    data-msg-confirm-cancel="<?php esc_attr_e('Cancel the running bulk optimization? Images already processed stay optimized.', 'traydigita-core'); ?>"
>
    <?php if ($imageOptimizerPhoton) : ?>
        <div class="traydigita-image-optimizer-notice traydigita-image-optimizer-notice-photon" role="status">
            <p>
                <strong><?php esc_html_e('Photon is serving your images — local optimization is unnecessary while it is on.', 'traydigita-core'); ?></strong>
            </p>
            <p><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Images are already served through Jetpack Photon (i0.wp.com), which converts and compresses them on the fly.', 'traydigita-core'), __('Uploads and bulk optimization stay switched off until Photon is disabled in the Customizer (Images section). Your settings below are kept for when Photon is off again.', 'traydigita-core')); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$imageOptimizerConfigured) : ?>
        <div class="traydigita-image-optimizer-notice traydigita-image-optimizer-notice-warning" role="status">
            <p><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('The selected format is not supported by this server’s image editor, so nothing can be optimized yet.', 'traydigita-core'), __('Choose the other format, or ask your host to enable WebP/AVIF support in Imagick or GD.', 'traydigita-core')); ?></p>
        </div>
    <?php endif; ?>

    <section class="traydigita-ui-section td-settings-section" id="td-section-support" aria-labelledby="td-section-support-title">
        <h2 class="traydigita-ui-section-title" id="td-section-support-title"><?php esc_html_e('Format support', 'traydigita-core'); ?></h2>
        <ul class="traydigita-image-optimizer-support-list">
            <li>
                <?php esc_html_e('WebP', 'traydigita-core'); ?>:
                <?php if ($imageOptimizerSupported['webp']) : ?>
                    <span class="traydigita-image-optimizer-supported"><?php esc_html_e('Supported', 'traydigita-core'); ?></span>
                <?php else : ?>
                    <span class="traydigita-image-optimizer-unsupported"><?php esc_html_e('Not supported', 'traydigita-core'); ?></span>
                <?php endif; ?>
            </li>
            <li>
                <?php esc_html_e('AVIF', 'traydigita-core'); ?>:
                <?php if ($imageOptimizerSupported['avif']) : ?>
                    <span class="traydigita-image-optimizer-supported"><?php esc_html_e('Supported', 'traydigita-core'); ?></span>
                <?php else : ?>
                    <span class="traydigita-image-optimizer-unsupported"><?php esc_html_e('Not supported', 'traydigita-core'); ?></span>
                <?php endif; ?>
            </li>
        </ul>
        <p class="traydigita-image-optimizer-support-help"><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Support is detected from the active image editor (Imagick or GD).', 'traydigita-core'), __('An unsupported format is skipped gracefully — uploads keep working, and the bulk runner refuses to start with it.', 'traydigita-core')); ?></p>
    </section>

    <section class="traydigita-ui-section td-settings-section" id="td-section-library" aria-labelledby="td-section-library-title">
        <h2 class="traydigita-ui-section-title" id="td-section-library-title"><?php esc_html_e('Library', 'traydigita-core'); ?></h2>
        <ul class="traydigita-image-optimizer-stats-list">
            <li>
                <?php
                /* translators: %d is the number of image attachments in the media library. */
                echo esc_html(sprintf(__('Total images: %d', 'traydigita-core'), $imageOptimizerStats['total']));
                ?>
            </li>
            <li>
                <?php
                /* translators: %d is the number of optimized images. */
                echo esc_html(sprintf(__('Optimized: %d', 'traydigita-core'), $imageOptimizerStats['optimized']));
                ?>
            </li>
            <li>
                <?php
                /* translators: %d is the number of skipped images. */
                echo esc_html(sprintf(__('Skipped: %d', 'traydigita-core'), $imageOptimizerStats['skipped']));
                ?>
            </li>
            <li>
                <?php
                /* translators: %d is the number of images whose optimization failed. */
                echo esc_html(sprintf(__('Failed: %d', 'traydigita-core'), $imageOptimizerStats['failed']));
                ?>
            </li>
            <li>
                <?php
                /* translators: %d is the number of images never processed. */
                echo esc_html(sprintf(__('Not yet processed: %d', 'traydigita-core'), $imageOptimizerStats['unprocessed']));
                ?>
            </li>
        </ul>
    </section>

    <form class="traydigita-image-optimizer-form" method="post" data-traydigita-module-form data-traydigita-module="image-optimizer" data-module="image-optimizer">
        <?php wp_nonce_field($imageOptimizerNonceAction); ?>

        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::sectionOpen('settings', __('Settings', 'traydigita-core')); ?>
        <?php
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
            'id' => 'image-optimizer-format',
            'name' => 'format',
            'label' => __('Target format', 'traydigita-core'),
            'value' => $imageOptimizerSettings['format'],
            'options' => ['webp' => 'WebP', 'avif' => 'AVIF'],
            'help' => __('WebP works everywhere modern browsers do.', 'traydigita-core'),
            'help_long' => __('AVIF compresses smaller but needs a newer image editor and is not supported by older browsers.', 'traydigita-core'),
            'attrs' => ['disabled' => $imageOptimizerPhoton],
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
            'id' => 'image-optimizer-quality',
            'name' => 'quality',
            'label' => __('Quality', 'traydigita-core'),
            'value' => $imageOptimizerSettings['quality'],
            'help' => __('1–100. 82 is a good balance between file size and visible quality.', 'traydigita-core'),
            'help_long' => __('Lower values save more space but show more compression artefacts.', 'traydigita-core'),
            'attrs' => ['min' => '1', 'max' => '100', 'step' => '1', 'disabled' => $imageOptimizerPhoton],
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'image-optimizer-keep-original',
            'name' => 'keep_original',
            'label' => __('Keep the original file', 'traydigita-core'),
            'checked' => $imageOptimizerSettings['keep_original'],
            'help' => __('When on, the original JPEG or PNG stays on disk as a backup after conversion.', 'traydigita-core'),
            'help_long' => __('When off, the original is deleted — but only after the converted file was verified, so a failed conversion never deletes anything.', 'traydigita-core'),
            'attrs' => ['disabled' => $imageOptimizerPhoton],
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'image-optimizer-on-upload',
            'name' => 'optimize_on_upload',
            'label' => __('Optimize new uploads automatically', 'traydigita-core'),
            'checked' => $imageOptimizerSettings['optimize_on_upload'],
            'help' => __('Convert each image right after upload, together with its generated sizes.', 'traydigita-core'),
            'help_long' => __('A failed conversion never breaks the upload.', 'traydigita-core'),
            'attrs' => ['disabled' => $imageOptimizerPhoton],
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::sectionClose();
        ?>

        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(null, $imageOptimizerPhoton); ?>
    </form>

    <section class="traydigita-ui-section td-settings-section traydigita-image-optimizer-bulk" id="td-section-bulk" aria-labelledby="td-section-bulk-title">
        <h2 class="traydigita-ui-section-title" id="td-section-bulk-title"><?php esc_html_e('Bulk optimize existing media', 'traydigita-core'); ?></h2>
        <p><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Convert every JPEG, PNG, and WebP image in the media library to the target format, five attachments at a time.', 'traydigita-core'), __('Images that are already optimized for the current format and quality are skipped, so running it again is cheap. Only one bulk run can be active at a time.', 'traydigita-core')); ?></p>

        <?php if ($imageOptimizerRunning) : ?>
            <p class="traydigita-image-optimizer-notice traydigita-image-optimizer-notice-running" role="status"><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('A bulk optimization is currently running.', 'traydigita-core'), __('You can keep this page open and watch the progress below, or cancel it.', 'traydigita-core')); ?></p>
        <?php endif; ?>

        <p class="traydigita-image-optimizer-bulk-actions">
            <button
                class="traydigita-admin-button traydigita-admin-button-primary"
                type="button"
                data-image-optimizer-start
                data-start-action="<?php echo esc_attr($imageOptimizerStartAction); ?>"
                data-chunk-action="<?php echo esc_attr($imageOptimizerChunkAction); ?>"
                <?php disabled($imageOptimizerPhoton || $imageOptimizerRunning || !$imageOptimizerConfigured); ?>
            >
                <?php esc_html_e('Start bulk optimization', 'traydigita-core'); ?>
            </button>
            <button
                class="traydigita-admin-button"
                type="button"
                data-image-optimizer-cancel
                data-cancel-action="<?php echo esc_attr($imageOptimizerCancelAction); ?>"
                <?php disabled(!$imageOptimizerRunning); ?>
            >
                <?php esc_html_e('Cancel', 'traydigita-core'); ?>
            </button>
        </p>

        <div class="traydigita-image-optimizer-progress" data-image-optimizer-progress <?php echo $imageOptimizerProgress['status'] === 'idle' ? 'hidden' : ''; ?>>
            <div class="traydigita-image-optimizer-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr((string) $progressPercent); ?>" data-image-optimizer-bar>
                <div class="traydigita-image-optimizer-progress-fill" style="width: <?php echo esc_attr((string) $progressPercent); ?>%;" data-image-optimizer-fill></div>
            </div>
            <p class="traydigita-image-optimizer-progress-text" aria-live="polite" data-image-optimizer-status>
                <?php
                if ($imageOptimizerProgress['status'] === 'running') {
                    /* translators: %1$d is the number of processed images, %2$d is the total. */
                    echo esc_html(sprintf(__('Optimizing… %1$d of %2$d images.', 'traydigita-core'), $imageOptimizerProgress['done'], $imageOptimizerProgress['total']));
                } elseif ($imageOptimizerProgress['status'] === 'done') {
                    /* translators: %1$d optimized, %2$d skipped, %3$d failed. */
                    echo esc_html(sprintf(__('Finished: %1$d optimized, %2$d skipped, %3$d failed.', 'traydigita-core'), $imageOptimizerProgress['optimized'], $imageOptimizerProgress['skipped'], $imageOptimizerProgress['failed']));
                } elseif ($imageOptimizerProgress['status'] === 'cancelled') {
                    /* translators: %1$d is the number of processed images, %2$d is the total. */
                    echo esc_html(sprintf(__('Cancelled after %1$d of %2$d images.', 'traydigita-core'), $imageOptimizerProgress['done'], $imageOptimizerProgress['total']));
                }
                ?>
            </p>
        </div>
    </section>
</div>
