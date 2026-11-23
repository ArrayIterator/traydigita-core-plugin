<?php
/**
 * E-Paper module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by EPaperModule::renderSettingsPage()):
 * @var array{max_size_mb: int, issues_per_page: int} $epaperSettings Settings.
 * @var array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int, url: string, pdf_url: string, cover_url: string|null, size_label: string, date_label: string}> $epaperIssues Issues, newest first, with public URLs and labels.
 * @var string $epaperArchiveUrl Public URL of the issue archive.
 * @var bool $epaperDirOk Whether the wp-content/traydigita-epaper/ directory exists.
 * @var int $epaperMaxIssues Maximum number of stored issues.
 *
 * Forms:
 * - Settings form: posts to the shell generic save endpoint
 *   (`data-traydigita-module-form` + `data-module="epaper"`); the
 *   shell passes the fields to EPaperModule::saveSettings().
 *   Field names are flat: `max_size_mb`, `issues_per_page`.
 * - Upload form (`data-epaper-upload-form`): posts a multipart
 *   body to `traydigita_epaper_upload` via
 *   resources/js/modules/admin-epaper.ts (XMLHttpRequest, so the
 *   upload progress bar works). Field names: `title`, `date`,
 *   `cover_id`, `epaper_file`. The cover is a media-library
 *   attachment id picked with wp.media; 0 means no cover, and
 *   the issue then shows a generated placeholder — the first
 *   page of the PDF is never rendered as a cover.
 * - Per-issue edit form (`data-epaper-update-form`): posts
 *   `issue_id`, `title`, `date`, `cover_id` to
 *   `traydigita_epaper_update`. The PDF file itself is never
 *   replaced; to change the PDF, delete the issue and upload a
 *   new one.
 * - Per-issue delete button (`data-epaper-delete`): posts
 *   `issue_id` to `traydigita_epaper_delete`. The shell's global
 *   data-confirm handler asks for confirmation first.
 *
 * Every form sends the nonce as a hidden `_ajax_nonce` field
 * (action `traydigita-admin`). No absolute server path is ever
 * printed: the list shows public URLs only.
 */
declare(strict_types=1);

$epaperNonce = wp_create_nonce('traydigita-admin');
$epaperToday = current_time('Y-m-d');
?>
<div class="traydigita-epaper-settings" data-epaper-admin
    data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
    data-nonce="<?php echo esc_attr($epaperNonce); ?>"
    data-msg-uploading="<?php esc_attr_e('Uploading…', 'traydigita-core'); ?>"
    data-msg-saving="<?php esc_attr_e('Saving…', 'traydigita-core'); ?>"
    data-msg-saved="<?php esc_attr_e('Saved.', 'traydigita-core'); ?>"
    data-msg-deleted="<?php esc_attr_e('Issue deleted.', 'traydigita-core'); ?>"
    data-msg-no-file="<?php esc_attr_e('Choose a PDF file first.', 'traydigita-core'); ?>"
    data-msg-no-title="<?php esc_attr_e('Enter a title for the issue.', 'traydigita-core'); ?>"
    data-msg-no-media="<?php esc_attr_e('The Media Library is not available.', 'traydigita-core'); ?>"
    data-msg-error="<?php esc_attr_e('Something went wrong. Please try again.', 'traydigita-core'); ?>">
    <div class="epaper-status" role="status">
        <p class="epaper-status-text">
            <?php
            /* translators: %s is the public URL of the E-Paper archive. */
            echo esc_html(sprintf(__('Issues are published at %s.', 'traydigita-core'), $epaperArchiveUrl));
            ?>
            <a href="<?php echo esc_url($epaperArchiveUrl); ?>" target="_blank" rel="noopener"><?php esc_html_e('View archive', 'traydigita-core'); ?></a>
        </p>
        <?php if (!$epaperDirOk) : ?>
            <p class="epaper-status-warning">
                <?php esc_html_e('The E-Paper storage directory could not be created. Check that wp-content is writable, then try uploading again.', 'traydigita-core'); ?>
            </p>
        <?php endif; ?>
        <p class="epaper-status-help">
            <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('PDF files are stored under wp-content/traydigita-epaper/ (never in the uploads library).', 'traydigita-core'),
                    __('Only real PDF files are accepted — the file contents are checked, not just the extension. Covers are images picked from the Media Library; an issue without a cover shows a generated placeholder, because the first page of a PDF is never rendered as a cover. After enabling this module, save these settings once (or visit Settings → Permalinks) so the /epaper/ URLs start working.', 'traydigita-core')
                );
                ?>
        </p>
        <?php if (count($epaperIssues) >= $epaperMaxIssues) : ?>
            <p class="epaper-status-warning">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d is the maximum number of stored E-Paper issues. */
                        __('The library holds the maximum of %d issues. Delete an old issue before adding a new one.', 'traydigita-core'),
                        $epaperMaxIssues
                    )
                );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <section class="traydigita-ui-section td-settings-section" id="td-section-settings" aria-labelledby="td-section-settings-title">
        <h2 class="traydigita-ui-section-title" id="td-section-settings-title"><?php esc_html_e('Settings', 'traydigita-core'); ?></h2>
        <form class="epaper-settings-form" data-traydigita-module-form data-traydigita-module="epaper" data-module="epaper" method="post" action="">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($epaperNonce); ?>">
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                'id' => 'epaper-max-size-mb',
                'name' => 'max_size_mb',
                'label' => __('Maximum PDF size (MB)', 'traydigita-core'),
                'value' => (string) $epaperSettings['max_size_mb'],
                'help' => __('Uploads larger than this are refused. Hard cap is 100 MB.', 'traydigita-core'),
                'attrs' => ['min' => '1', 'max' => '100', 'step' => '1'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                'id' => 'epaper-issues-per-page',
                'name' => 'issues_per_page',
                'label' => __('Issues per archive page', 'traydigita-core'),
                'value' => (string) $epaperSettings['issues_per_page'],
                'attrs' => ['min' => '1', 'max' => '50', 'step' => '1'],
            ]);
            ?>
            <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
        </form>
    </section>

    <h2 id="td-section-upload"><?php esc_html_e('Upload a new issue', 'traydigita-core'); ?></h2>
    <form class="epaper-upload-form" data-epaper-upload-form method="post" enctype="multipart/form-data" action="">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($epaperNonce); ?>">
        <p class="epaper-field">
            <label for="epaper-upload-title"><?php esc_html_e('Title', 'traydigita-core'); ?></label>
            <input type="text" id="epaper-upload-title" name="title" class="traydigita-ui-input" required>
        </p>
        <p class="epaper-field">
            <label for="epaper-upload-date"><?php esc_html_e('Issue date', 'traydigita-core'); ?></label>
            <input type="date" id="epaper-upload-date" name="date" value="<?php echo esc_attr((string) $epaperToday); ?>" required>
        </p>
        <p class="epaper-field">
            <label for="epaper-upload-cover-id"><?php esc_html_e('Cover image (optional)', 'traydigita-core'); ?></label>
            <input type="hidden" id="epaper-upload-cover-id" name="cover_id" value="0" data-epaper-cover-id>
            <span class="epaper-cover-preview" data-epaper-cover-preview hidden>
                <img src="" alt="" data-epaper-cover-preview-img>
            </span>
            <button type="button" class="traydigita-admin-button" data-epaper-cover-picker><?php esc_html_e('Choose from Media Library', 'traydigita-core'); ?></button>
            <button type="button" class="traydigita-admin-button" data-epaper-cover-remove hidden><?php esc_html_e('Remove cover', 'traydigita-core'); ?></button>
            <span class="epaper-field-help"><?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('A media-library image used as the cover.', 'traydigita-core'),
                    __('Without one, the issue shows a placeholder icon — the PDF first page is not rendered.', 'traydigita-core')
                );
                ?></span>
        </p>
        <p class="epaper-field">
            <label for="epaper-upload-file"><?php esc_html_e('PDF file', 'traydigita-core'); ?></label>
            <input type="file" id="epaper-upload-file" name="epaper_file" accept="application/pdf,.pdf" required>
            <span class="epaper-field-help">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d is the maximum PDF upload size in megabytes. */
                        __('PDF only, up to %d MB.', 'traydigita-core'),
                        $epaperSettings['max_size_mb']
                    )
                );
                ?>
            </span>
        </p>
        <progress class="epaper-upload-progress" data-epaper-upload-progress max="100" value="0" hidden></progress>
        <p class="epaper-form-actions">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Upload issue', 'traydigita-core'); ?></button>
        </p>
        <p class="epaper-status-line" data-epaper-status role="status" aria-live="polite"></p>
    </form>

    <h2 id="td-section-issues"><?php esc_html_e('Issues', 'traydigita-core'); ?></h2>
    <?php if ($epaperIssues === []) : ?>
        <p class="epaper-empty"><?php esc_html_e('No issues yet. Upload the first PDF above.', 'traydigita-core'); ?></p>
    <?php else : ?>
        <table class="widefat striped epaper-issue-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Cover', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Title', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Date', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Size', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($epaperIssues as $epaperIssue) : ?>
                    <tr data-epaper-row data-issue-id="<?php echo esc_attr($epaperIssue['id']); ?>">
                        <td class="epaper-issue-cover">
                            <?php if ($epaperIssue['cover_url'] !== null) : ?>
                                <img src="<?php echo esc_url($epaperIssue['cover_url']); ?>" alt="" width="48" height="48">
                            <?php else : ?>
                                <span class="dashicons dashicons-media-document" aria-hidden="true"></span>
                                <span class="screen-reader-text"><?php esc_html_e('No cover — placeholder is shown', 'traydigita-core'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($epaperIssue['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($epaperIssue['title']); ?></a>
                            <br>
                            <a href="<?php echo esc_url($epaperIssue['pdf_url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Download PDF', 'traydigita-core'); ?></a>
                        </td>
                        <td><?php echo esc_html($epaperIssue['date_label']); ?></td>
                        <td><?php echo esc_html($epaperIssue['size_label']); ?></td>
                        <td>
                            <button type="button" class="traydigita-admin-button" data-epaper-edit-toggle aria-expanded="false"><?php esc_html_e('Edit', 'traydigita-core'); ?></button>
                            <button type="button" class="traydigita-admin-button traydigita-admin-button-danger" data-epaper-delete data-issue-id="<?php echo esc_attr($epaperIssue['id']); ?>" data-confirm="<?php esc_attr_e('Delete this issue? Its PDF file will be deleted too. This cannot be undone.', 'traydigita-core'); ?>"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
                        </td>
                    </tr>
                    <tr class="epaper-edit-row" data-epaper-edit-row data-issue-id="<?php echo esc_attr($epaperIssue['id']); ?>" hidden>
                        <td colspan="5">
                            <form class="epaper-update-form" data-epaper-update-form data-issue-id="<?php echo esc_attr($epaperIssue['id']); ?>" method="post" action="">
                                <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($epaperNonce); ?>">
                                <p class="epaper-field">
                                    <label><?php esc_html_e('Title', 'traydigita-core'); ?>
                                        <input type="text" name="title" class="traydigita-ui-input" value="<?php echo esc_attr($epaperIssue['title']); ?>" required>
                                    </label>
                                </p>
                                <p class="epaper-field">
                                    <label><?php esc_html_e('Issue date', 'traydigita-core'); ?>
                                        <input type="date" name="date" value="<?php echo esc_attr($epaperIssue['date']); ?>" required>
                                    </label>
                                </p>
                                <p class="epaper-field">
                                    <label><?php esc_html_e('Cover image (optional)', 'traydigita-core'); ?></label>
                                    <input type="hidden" name="cover_id" value="<?php echo esc_attr((string) $epaperIssue['cover_id']); ?>" data-epaper-cover-id>
                                    <span class="epaper-cover-preview" data-epaper-cover-preview <?php echo $epaperIssue['cover_url'] === null ? 'hidden' : ''; ?>>
                                        <img src="<?php echo esc_attr($epaperIssue['cover_url'] ?? ''); ?>" alt="" data-epaper-cover-preview-img>
                                    </span>
                                    <button type="button" class="traydigita-admin-button" data-epaper-cover-picker><?php esc_html_e('Choose from Media Library', 'traydigita-core'); ?></button>
                                    <button type="button" class="traydigita-admin-button" data-epaper-cover-remove <?php echo $epaperIssue['cover_id'] === 0 ? 'hidden' : ''; ?>><?php esc_html_e('Remove cover', 'traydigita-core'); ?></button>
                                </p>
                                <p class="epaper-form-actions">
                                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Save changes', 'traydigita-core'); ?></button>
                                </p>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
