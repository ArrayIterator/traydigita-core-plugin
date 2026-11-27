<?php
/**
 * Font Manager module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by FontsModule::renderSettingsPage()):
 * @var array<int, array{family: string, slug: string, source: string, files: array<int, array{weight: int, style: string, format: string}>}> $fontsList Installed font families with their variants.
 * @var array<string, string> $fontsChoices Installed families as slug => family name.
 * @var string $fontsBodyFont Slug selected as the body font, or '' for the system default.
 * @var string $fontsHeadingFont Slug selected as the heading font, or '' for the system default.
 * @var string $fontsDirLabel Font directory label, e.g. `wp-content/traydigita-fonts`.
 * @var string|null $fontsCssUrl Public URL of the generated stylesheet, or null when no fonts are installed.
 * @var string $fontsNonceAction Nonce action (`traydigita-admin`).
 * @var string $fontsDownloadAction AJAX action for downloading a Google Font (`traydigita_fonts_download`).
 * @var string $fontsUploadAction AJAX action for uploading a font file (`traydigita_fonts_upload`).
 * @var string $fontsDeleteAction AJAX action for deleting a font (`traydigita_fonts_delete`).
 * @var string $fontsSaveAction AJAX action for the shell generic save endpoint (`traydigita_admin_save`).
 *
 * Forms:
 * - Download form (`data-fonts-download-form`): posts to the
 *   module-owned download endpoint via resources/js/modules/
 *   admin-fonts.ts. Fields: `family`, `weights`, `italic`.
 * - Upload form (`data-fonts-upload-form`): posts to the
 *   module-owned upload endpoint via the same script. Fields:
 *   `family`, `weight`, `style`, `font_file`. Only woff2, woff,
 *   and ttf files are accepted; the server verifies the file
 *   contents before storing it.
 * - Apply form: posts to the shell generic save endpoint
 *   (`data-traydigita-module-form`, `data-module="fonts"`); the
 *   shell passes the fields to FontsModule::saveSettings(), which
 *   stores them as the `traydigita_body_font` /
 *   `traydigita_heading_font` theme mods (the same settings the
 *   Customizer exposes). Field names are flat: `body_font`,
 *   `heading_font`.
 * - Delete buttons (`data-fonts-delete`): post to the
 *   module-owned delete endpoint via the same script; the shell
 *   confirms them first through their `data-confirm` attribute.
 *
 * All three module forms send the nonce as a hidden `_ajax_nonce`
 * field. The download/upload/delete progress and errors are shown
 * in the `data-fonts-*-status` regions (aria-live) by the script.
 */
declare(strict_types=1);

$fontsSourceLabels = [
    'google' => __('Google Fonts', 'traydigita-core'),
    'upload' => __('Uploaded', 'traydigita-core'),
    'mixed' => __('Mixed', 'traydigita-core'),
];
?>
<div
    class="traydigita-fonts-settings"
    data-fonts-admin
    data-download-action="<?php echo esc_attr($fontsDownloadAction); ?>"
    data-upload-action="<?php echo esc_attr($fontsUploadAction); ?>"
    data-delete-action="<?php echo esc_attr($fontsDeleteAction); ?>"
    data-msg-downloading="<?php esc_attr_e('Downloading font…', 'traydigita-core'); ?>"
    data-msg-uploading="<?php esc_attr_e('Uploading font…', 'traydigita-core'); ?>"
    data-msg-deleting="<?php esc_attr_e('Deleting font…', 'traydigita-core'); ?>"
    data-msg-error="<?php esc_attr_e('Request failed. Please try again.', 'traydigita-core'); ?>"
    data-msg-no-file="<?php esc_attr_e('Choose a font file to upload.', 'traydigita-core'); ?>"
>
    <div class="traydigita-fonts-status" role="status">
        <?php if ($fontsList !== []) : ?>
            <p class="traydigita-admin-notice traydigita-admin-notice-success">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %d is the number of installed font families. */
                        _n('%d font family installed.', '%d font families installed.', count($fontsList), 'traydigita-core'),
                        count($fontsList)
                    )
                );
                ?>
            </p>
        <?php else : ?>
            <p class="traydigita-admin-notice traydigita-admin-notice-warning">
                <?php esc_html_e('No fonts installed yet. Download a Google Font below, or upload your own font files.', 'traydigita-core'); ?>
            </p>
        <?php endif; ?>
        <p class="traydigita-fonts-status-text">
            <?php
            [$fontsShort, $fontsLong] = \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::splitHelp(
                sprintf(
                    /* translators: %s is the font directory, e.g. "wp-content/traydigita-fonts". */
                    __('Font files are stored locally in %s and served from this site only — Google Fonts are never hotlinked on the frontend.', 'traydigita-core'),
                    $fontsDirLabel
                )
            );
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText($fontsShort, $fontsLong);
            ?>
        </p>
        <?php if ($fontsCssUrl !== null) : ?>
            <p class="traydigita-fonts-status-text">
                <?php esc_html_e('Generated stylesheet:', 'traydigita-core'); ?>
                <code><?php echo esc_html($fontsCssUrl); ?></code>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($fontsList !== []) : ?>
        <h2 id="td-section-installed"><?php esc_html_e('Installed fonts', 'traydigita-core'); ?></h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Family', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Source', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Variants', 'traydigita-core'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'traydigita-core'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fontsList as $fontsFamily) : ?>
                    <?php
                    $fontsVariants = [];
                    foreach ($fontsFamily['files'] as $fontsFile) {
                        $fontsVariants[] = $fontsFile['weight']
                            . ($fontsFile['style'] === 'italic' ? ' ' . __('italic', 'traydigita-core') : '')
                            . ' ' . $fontsFile['format'];
                    }
                    ?>
                    <tr>
                        <td><?php echo esc_html($fontsFamily['family']); ?></td>
                        <td><?php echo esc_html($fontsSourceLabels[$fontsFamily['source']] ?? $fontsFamily['source']); ?></td>
                        <td><?php echo esc_html(implode(', ', $fontsVariants)); ?></td>
                        <td>
                            <?php
                            $fontsDeleteConfirm = sprintf(
                                /* translators: %s is the font family name. */
                                __('Delete the “%s” font and all its files? Fonts using it will fall back to the system default.', 'traydigita-core'),
                                $fontsFamily['family']
                            );
                            ?>
                            <button
                                type="button"
                                class="traydigita-admin-button traydigita-admin-button-danger"
                                data-fonts-delete
                                data-family="<?php echo esc_attr($fontsFamily['slug']); ?>"
                                data-confirm="<?php echo esc_attr($fontsDeleteConfirm); ?>"
                            >
                                <?php esc_html_e('Delete', 'traydigita-core'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="traydigita-fonts-status-text" data-fonts-delete-status role="status" aria-live="polite"></p>
    <?php endif; ?>

    <h2 id="td-section-download"><?php esc_html_e('Download from Google Fonts', 'traydigita-core'); ?></h2>
    <form class="traydigita-fonts-download-form" data-fonts-download-form method="post" action="">
        <input type="hidden" name="action" value="<?php echo esc_attr($fontsDownloadAction); ?>">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($fontsNonceAction)); ?>">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="fonts-download-family"><?php esc_html_e('Family name', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fonts-download-family" name="family" class="traydigita-ui-input" placeholder="<?php esc_attr_e('Open Sans', 'traydigita-core'); ?>" required>
                        <p class="description"><?php esc_html_e('The family name on Google Fonts, for example “Inter” or “Open Sans”.', 'traydigita-core'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fonts-download-weights"><?php esc_html_e('Weights', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fonts-download-weights" name="weights" class="traydigita-ui-input" value="400,700">
                        <p class="description"><?php esc_html_e('Comma-separated weights from 100 to 900 (at most 6). Only the latin subset is stored.', 'traydigita-core'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Style', 'traydigita-core'); ?></th>
                    <td>
                        <label class="traydigita-ui-toggle" for="fonts-download-italic"><input class="traydigita-ui-toggle-input" type="checkbox" id="fonts-download-italic" name="italic" value="1"><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Include italic variants', 'traydigita-core'); ?></span></label>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Download font', 'traydigita-core'); ?></button>
        </p>
        <p class="traydigita-fonts-status-text" data-fonts-download-status role="status" aria-live="polite"></p>
    </form>

    <h2 id="td-section-upload"><?php esc_html_e('Upload a font file', 'traydigita-core'); ?></h2>
    <form class="traydigita-fonts-upload-form" data-fonts-upload-form method="post" action="" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?php echo esc_attr($fontsUploadAction); ?>">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($fontsNonceAction)); ?>">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="fonts-upload-family"><?php esc_html_e('Family name', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="fonts-upload-family" name="family" class="traydigita-ui-input" placeholder="<?php esc_html_e('My Font', 'traydigita-core'); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fonts-upload-weight"><?php esc_html_e('Weight', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <select id="fonts-upload-weight" name="weight">
                            <?php foreach ([100, 200, 300, 400, 500, 600, 700, 800, 900] as $fontsWeight) : ?>
                                <option value="<?php echo esc_attr((string) $fontsWeight); ?>" <?php selected($fontsWeight, 400); ?>>
                                    <?php echo esc_html((string) $fontsWeight); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fonts-upload-style"><?php esc_html_e('Style', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <select id="fonts-upload-style" name="style">
                            <option value="normal"><?php esc_html_e('Normal', 'traydigita-core'); ?></option>
                            <option value="italic"><?php esc_html_e('Italic', 'traydigita-core'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fonts-upload-file"><?php esc_html_e('Font file', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <input type="file" id="fonts-upload-file" name="font_file" accept=".woff2,.woff,.ttf" required>
                        <p class="description"><?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('Only woff2, woff, and ttf files are accepted.', 'traydigita-core'),
                                __('The file contents are verified before it is stored; anything else is rejected.', 'traydigita-core')
                            );
                            ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Upload font', 'traydigita-core'); ?></button>
        </p>
        <p class="traydigita-fonts-status-text" data-fonts-upload-status role="status" aria-live="polite"></p>
    </form>

    <h2 id="td-section-apply"><?php esc_html_e('Apply fonts', 'traydigita-core'); ?></h2>
    <form class="traydigita-fonts-apply-form" data-traydigita-module-form data-traydigita-module="fonts" data-module="fonts" method="post" action="">
        <input type="hidden" name="action" value="<?php echo esc_attr($fontsSaveAction); ?>">
        <input type="hidden" name="module" value="fonts">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($fontsNonceAction)); ?>">
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="fonts-body-font"><?php esc_html_e('Body font', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <select id="fonts-body-font" name="body_font">
                            <option value=""><?php esc_html_e('System default', 'traydigita-core'); ?></option>
                            <?php foreach ($fontsChoices as $fontsSlug => $fontsName) : ?>
                                <option value="<?php echo esc_attr($fontsSlug); ?>" <?php selected($fontsBodyFont, $fontsSlug); ?>>
                                    <?php echo esc_html($fontsName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Font used for body text. The same setting is available in the Customizer.', 'traydigita-core'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fonts-heading-font"><?php esc_html_e('Heading font', 'traydigita-core'); ?></label>
                    </th>
                    <td>
                        <select id="fonts-heading-font" name="heading_font">
                            <option value=""><?php esc_html_e('System default', 'traydigita-core'); ?></option>
                            <?php foreach ($fontsChoices as $fontsSlug => $fontsName) : ?>
                                <option value="<?php echo esc_attr($fontsSlug); ?>" <?php selected($fontsHeadingFont, $fontsSlug); ?>>
                                    <?php echo esc_html($fontsName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Font used for headings. The same setting is available in the Customizer.', 'traydigita-core'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
    </form>
</div>
