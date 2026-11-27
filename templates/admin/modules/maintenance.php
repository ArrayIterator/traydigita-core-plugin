<?php
/**
 * Maintenance module settings UI partial.
 *
 * Rendered by MaintenanceModule::renderSettingsPage() inside the
 * admin shell content area. The shell owns tab switching (hash
 * routing) and the AJAX save; this partial only renders the form,
 * so every panel is visible without JavaScript and the shell
 * enhances the tab navigation.
 *
 * Framework (R4-5): the sections are framework sections
 * (`td-section-*`, matching MaintenanceModule::settingsSections())
 * and the fields are rendered with SettingsControls (toggle, text,
 * textarea, checkbox group). The bypass-link block, the preview
 * section and the copy-link script keep their own markup.
 *
 * Variables:
 * @var array{enabled: bool, title: string, message: string, countdown_to: string, allowed_roles: array<int, string>, allowed_ips: array<int, string>, bypass_secret: string} $settings Current settings.
 * @var array<string, string> $roles Editable roles as slug => display name.
 * @var string $previewUrl Nonce-protected admin preview URL.
 * @var string $bypassHint Masked bypass-secret hint (first/last 4 characters).
 * @var string $bypassUrl Full bypass URL (admin-only page; carries the derived token, never the secret).
 * @var bool $isConfigured Whether the module is enabled with a title and message.
 * @var array<string, mixed> $design Current page design (Maintenance Page Builder).
 * @var array<string, string> $designLabels Block type => label.
 * @var array<string, string> $designHints Block type => hint.
 * @var string $designPreview Rendered preview HTML for the design.
 */
declare(strict_types=1);

// No `use` imports in this file: it is required from inside a method,
// where an import statement would be a parse error. The module class
// is referenced by its fully qualified name below.
$datetimeValue = '';
if ($settings['countdown_to'] !== '') {
    $countdownTimestamp = strtotime($settings['countdown_to']);
    if ($countdownTimestamp !== false) {
        $datetimeValue = date('Y-m-d\TH:i', $countdownTimestamp);
    }
}
$bypassTokenHint = $bypassUrl !== ''
    ? \TrayDigita\WP\Plugin\Core\Modules\Maintenance\MaintenanceModule::maskToken(
        \TrayDigita\WP\Plugin\Core\Modules\Maintenance\MaintenanceModule::bypassToken($settings['bypass_secret'])
    )
    : '';
?>
<div class="traydigita-module-settings maintenance-settings">
    <div class="maintenance-status">
        <?php if ($settings['enabled']) : ?>
            <p class="maintenance-status-badge maintenance-status-enabled">
                <?php esc_html_e('Maintenance mode is ON. Visitors see the maintenance page.', 'traydigita-core'); ?>
            </p>
        <?php else : ?>
            <p class="maintenance-status-badge maintenance-status-disabled">
                <?php esc_html_e('Maintenance mode is OFF. The site is visible to everyone.', 'traydigita-core'); ?>
            </p>
        <?php endif; ?>
        <?php if (!$isConfigured && $settings['enabled']) : ?>
            <p class="maintenance-status-warning">
                <?php esc_html_e('Maintenance mode is enabled but the title or message is empty. Add both before relying on it.', 'traydigita-core'); ?>
            </p>
        <?php endif; ?>
    </div>

    <form class="maintenance-settings-form" data-traydigita-module-form data-traydigita-module="maintenance" data-module="maintenance" method="post" action="">
        <?php wp_nonce_field(\TrayDigita\WP\Plugin\Core\Modules\Maintenance\MaintenanceModule::SETTINGS_NONCE_ACTION); ?>

        <nav class="maintenance-tabs" aria-label="<?php esc_attr_e('Maintenance settings sections', 'traydigita-core'); ?>">
            <a class="maintenance-tab-link" href="#td-section-general">
                <?php esc_html_e('General', 'traydigita-core'); ?>
            </a>
            <a class="maintenance-tab-link" href="#td-section-design">
                <?php esc_html_e('Design', 'traydigita-core'); ?>
            </a>
            <a class="maintenance-tab-link" href="#td-section-access">
                <?php esc_html_e('Access', 'traydigita-core'); ?>
            </a>
            <a class="maintenance-tab-link" href="#td-section-preview">
                <?php esc_html_e('Preview', 'traydigita-core'); ?>
            </a>
        </nav>

        <section class="traydigita-ui-section td-settings-section maintenance-tab-panel" id="td-section-general" aria-label="<?php esc_attr_e('General settings', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('General', 'traydigita-core'); ?></h2>

            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
                'id' => 'maintenance-enabled',
                'name' => 'enabled',
                'label' => __('Enable maintenance mode', 'traydigita-core'),
                'checked' => $settings['enabled'],
                'help' => __('While enabled, visitors see the maintenance page with a 503 status.', 'traydigita-core'),
                'help_long' => __('Logged-in administrators always bypass it.', 'traydigita-core'),
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'id' => 'maintenance-title',
                'name' => 'title',
                'label' => __('Title', 'traydigita-core'),
                'value' => $settings['title'],
                'attrs' => ['maxlength' => '200'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::textarea([
                'id' => 'maintenance-message',
                'name' => 'message',
                'label' => __('Message', 'traydigita-core'),
                'value' => $settings['message'],
                'help' => __('Basic formatting is allowed (paragraphs, links, bold, lists).', 'traydigita-core'),
                'help_long' => __('Scripts and other unsafe HTML are removed on save.', 'traydigita-core'),
                'attrs' => ['rows' => '4'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'type' => 'datetime-local',
                'id' => 'maintenance-countdown-to',
                'name' => 'countdown_to',
                'label' => __('Countdown to (optional)', 'traydigita-core'),
                'value' => $datetimeValue,
                'help' => __('When set, the maintenance page shows a live countdown and the Retry-After header counts down to this time.', 'traydigita-core'),
                'help_long' => __('When the countdown passes, maintenance mode stays on until you turn it off here.', 'traydigita-core'),
            ]);
            ?>
        </section>

        <section class="traydigita-ui-section td-settings-section maintenance-tab-panel" id="td-section-design" aria-label="<?php esc_attr_e('Page design', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Page design', 'traydigita-core'); ?></h2>
            <p class="traydigita-ui-field-help">
                <?php esc_html_e('Block-based page builder, same pattern as the Login Designer. An empty heading uses the maintenance title; an empty text block uses the maintenance message; the countdown reads the date from General. Image fields take a media library attachment ID (find it in Media → Library → the image → copy the ID from the URL).', 'traydigita-core'); ?>
            </p>
            <?php
            $maintenance_blocks = is_array($design['blocks'] ?? null) ? $design['blocks'] : [];
            foreach ($maintenance_blocks as $maintenance_index => $maintenance_block) :
                if (!is_array($maintenance_block)) {
                    continue;
                }
                $maintenance_type = is_string($maintenance_block['type'] ?? null) ? $maintenance_block['type'] : '';
                $maintenance_block_settings = is_array($maintenance_block['settings'] ?? null) ? $maintenance_block['settings'] : [];
                $maintenance_prefix = 'design[blocks][' . $maintenance_index . ']';
                ?>
                <fieldset class="maintenance-design-block">
                    <legend><?php echo esc_html($designLabels[$maintenance_type] ?? $maintenance_type); ?></legend>
                    <p class="traydigita-ui-field-help"><?php echo esc_html($designHints[$maintenance_type] ?? ''); ?></p>
                    <input type="hidden" name="<?php echo esc_attr($maintenance_prefix); ?>[type]" value="<?php echo esc_attr($maintenance_type); ?>">
                    <?php if ($maintenance_type === 'background') : ?>
                        <p>
                            <label for="maintenance-bg-mode-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Mode', 'traydigita-core'); ?></label><br>
                            <select id="maintenance-bg-mode-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][mode]">
                                <?php foreach (['color' => __('Colour', 'traydigita-core'), 'image' => __('Image', 'traydigita-core'), 'gradient' => __('Gradient', 'traydigita-core')] as $maintenance_mode => $maintenance_mode_label) : ?>
                                    <option value="<?php echo esc_attr($maintenance_mode); ?>"<?php selected((string) ($maintenance_block_settings['mode'] ?? 'color'), $maintenance_mode); ?>><?php echo esc_html($maintenance_mode_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="maintenance-bg-color-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Colour', 'traydigita-core'); ?></label><br>
                            <input type="color" id="maintenance-bg-color-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][color]" value="<?php echo esc_attr((string) ($maintenance_block_settings['color'] ?? '#f8f7f4')); ?>">
                        </p>
                        <p>
                            <label for="maintenance-bg-image-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Image attachment ID', 'traydigita-core'); ?></label><br>
                            <input type="number" id="maintenance-bg-image-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][attachment_id]" value="<?php echo esc_attr((string) absint($maintenance_block_settings['attachment_id'] ?? 0)); ?>" min="0">
                        </p>
                        <p>
                            <label for="maintenance-bg-gradient-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Gradient', 'traydigita-core'); ?></label><br>
                            <select id="maintenance-bg-gradient-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][gradient]">
                                <?php foreach (['forest' => __('Forest', 'traydigita-core'), 'sunrise' => __('Sunrise', 'traydigita-core'), 'ocean' => __('Ocean', 'traydigita-core'), 'mono' => __('Mono', 'traydigita-core')] as $maintenance_gradient => $maintenance_gradient_label) : ?>
                                    <option value="<?php echo esc_attr($maintenance_gradient); ?>"<?php selected((string) ($maintenance_block_settings['gradient'] ?? 'forest'), $maintenance_gradient); ?>><?php echo esc_html($maintenance_gradient_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    <?php elseif ($maintenance_type === 'logo') : ?>
                        <p>
                            <label for="maintenance-logo-id-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Logo attachment ID', 'traydigita-core'); ?></label><br>
                            <input type="number" id="maintenance-logo-id-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][attachment_id]" value="<?php echo esc_attr((string) absint($maintenance_block_settings['attachment_id'] ?? 0)); ?>" min="0">
                        </p>
                        <p>
                            <label for="maintenance-logo-url-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Link URL', 'traydigita-core'); ?></label><br>
                            <input type="url" id="maintenance-logo-url-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][link_url]" value="<?php echo esc_attr((string) ($maintenance_block_settings['link_url'] ?? '')); ?>" autocomplete="off" spellcheck="false">
                        </p>
                        <p>
                            <label for="maintenance-logo-alt-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Alt text', 'traydigita-core'); ?></label><br>
                            <input type="text" id="maintenance-logo-alt-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][alt]" value="<?php echo esc_attr((string) ($maintenance_block_settings['alt'] ?? '')); ?>" autocomplete="off">
                        </p>
                        <p>
                            <label for="maintenance-logo-width-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Width (px)', 'traydigita-core'); ?></label><br>
                            <input type="number" id="maintenance-logo-width-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][width]" value="<?php echo esc_attr((string) absint($maintenance_block_settings['width'] ?? 280)); ?>" min="80" max="600">
                        </p>
                    <?php elseif ($maintenance_type === 'heading') : ?>
                        <p>
                            <label for="maintenance-heading-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Heading', 'traydigita-core'); ?></label><br>
                            <input type="text" id="maintenance-heading-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][heading]" value="<?php echo esc_attr((string) ($maintenance_block_settings['heading'] ?? '')); ?>" autocomplete="off">
                        </p>
                        <p>
                            <label for="maintenance-subtext-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Subtext', 'traydigita-core'); ?></label><br>
                            <input type="text" id="maintenance-subtext-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][subtext]" value="<?php echo esc_attr((string) ($maintenance_block_settings['subtext'] ?? '')); ?>" autocomplete="off">
                        </p>
                    <?php elseif ($maintenance_type === 'text') : ?>
                        <p>
                            <label for="maintenance-text-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Text', 'traydigita-core'); ?></label><br>
                            <input type="text" id="maintenance-text-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][text]" value="<?php echo esc_attr((string) ($maintenance_block_settings['text'] ?? '')); ?>" autocomplete="off">
                        </p>
                    <?php elseif ($maintenance_type === 'countdown') : ?>
                        <p>
                            <input type="hidden" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][show]" value="0">
                            <input type="checkbox" id="maintenance-countdown-show-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][show]" value="1"<?php checked(!empty($maintenance_block_settings['show'])); ?>>
                            <label for="maintenance-countdown-show-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Show the countdown', 'traydigita-core'); ?></label>
                        </p>
                    <?php elseif ($maintenance_type === 'button') : ?>
                        <p>
                            <label for="maintenance-button-label-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Label', 'traydigita-core'); ?></label><br>
                            <input type="text" id="maintenance-button-label-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][label]" value="<?php echo esc_attr((string) ($maintenance_block_settings['label'] ?? '')); ?>" autocomplete="off">
                        </p>
                        <p>
                            <label for="maintenance-button-url-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('URL', 'traydigita-core'); ?></label><br>
                            <input type="url" id="maintenance-button-url-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][url]" value="<?php echo esc_attr((string) ($maintenance_block_settings['url'] ?? '')); ?>" autocomplete="off" spellcheck="false">
                        </p>
                    <?php elseif ($maintenance_type === 'social') : ?>
                        <?php
                        $maintenance_links = is_array($maintenance_block_settings['links'] ?? null) ? $maintenance_block_settings['links'] : [];
                        while (count($maintenance_links) < 4) {
                            $maintenance_links[] = ['label' => '', 'url' => ''];
                        }
                        $maintenance_links = array_slice($maintenance_links, 0, 8);
                        foreach ($maintenance_links as $maintenance_link_index => $maintenance_link) :
                            $maintenance_link = is_array($maintenance_link) ? $maintenance_link : ['label' => '', 'url' => ''];
                            ?>
                            <p>
                                <label><?php esc_html_e('Label', 'traydigita-core'); ?>
                                    <input type="text" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][links][<?php echo esc_attr((string) $maintenance_link_index); ?>][label]" value="<?php echo esc_attr((string) ($maintenance_link['label'] ?? '')); ?>" autocomplete="off">
                                </label>
                                <label><?php esc_html_e('URL', 'traydigita-core'); ?>
                                    <input type="url" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][links][<?php echo esc_attr((string) $maintenance_link_index); ?>][url]" value="<?php echo esc_attr((string) ($maintenance_link['url'] ?? '')); ?>" autocomplete="off" spellcheck="false">
                                </label>
                            </p>
                        <?php endforeach; ?>
                    <?php elseif ($maintenance_type === 'newsletter') : ?>
                        <p>
                            <input type="hidden" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][show]" value="0">
                            <input type="checkbox" id="maintenance-newsletter-show-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][show]" value="1"<?php checked(!empty($maintenance_block_settings['show'])); ?>>
                            <label for="maintenance-newsletter-show-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Show the newsletter signup form', 'traydigita-core'); ?></label>
                        </p>
                        <p>
                            <label for="maintenance-newsletter-heading-<?php echo esc_attr((string) $maintenance_index); ?>"><?php esc_html_e('Heading', 'traydigita-core'); ?></label><br>
                            <input type="text" id="maintenance-newsletter-heading-<?php echo esc_attr((string) $maintenance_index); ?>" name="<?php echo esc_attr($maintenance_prefix); ?>[settings][heading]" value="<?php echo esc_attr((string) ($maintenance_block_settings['heading'] ?? '')); ?>" autocomplete="off">
                        </p>
                    <?php elseif ($maintenance_type === 'custom_css') : ?>
                        <?php
                        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::textarea([
                            'id' => 'maintenance-custom-css-' . $maintenance_index,
                            'name' => $maintenance_prefix . '[settings][css]',
                            'label' => __('Custom CSS', 'traydigita-core'),
                            'value' => (string) ($maintenance_block_settings['css'] ?? ''),
                            'variant' => 'code',
                            'help' => __('Plain CSS declarations only. At-rules other than @media, dangerous url() schemes and unbalanced braces are removed on save. The CSS is stored as a single line.', 'traydigita-core'),
                            'attrs' => ['rows' => '6'],
                        ]);
                        ?>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>
        </section>

        <section class="traydigita-ui-section td-settings-section maintenance-tab-panel" id="td-section-access" aria-label="<?php esc_attr_e('Access settings', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Access', 'traydigita-core'); ?></h2>

            <?php if ($roles === []) : ?>
                <p class="traydigita-ui-field-help">
                    <?php esc_html_e('No editable roles are available.', 'traydigita-core'); ?>
                </p>
            <?php else : ?>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::checkboxGroup([
                    'id' => 'maintenance-roles',
                    'name' => 'allowed_roles[]',
                    'label' => __('Roles that bypass maintenance mode', 'traydigita-core'),
                    'options' => $roles,
                    'checked' => $settings['allowed_roles'],
                    'help' => __('Administrators bypass maintenance mode automatically, even without selecting a role here.', 'traydigita-core'),
                ]);
                ?>
            <?php endif; ?>

            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::textarea([
                'id' => 'maintenance-allowed-ips',
                'name' => 'allowed_ips',
                'label' => __('IP addresses that bypass maintenance mode', 'traydigita-core'),
                'value' => implode("\n", $settings['allowed_ips']),
                'help' => __('IPv4 and IPv6 addresses are supported.', 'traydigita-core'),
                'help_long' => __('Only the direct remote address is checked; forwarded-for headers are never trusted.', 'traydigita-core'),
                'attrs' => ['rows' => '4', 'placeholder' => __('One IP address per line, for example 203.0.113.10', 'traydigita-core')],
            ]);
            ?>

            <div class="traydigita-ui-field td-settings-field">
                <h3 class="traydigita-field-heading"><?php esc_html_e('Bypass link', 'traydigita-core'); ?></h3>
                <?php if ($bypassHint === '') : ?>
                    <span class="maintenance-field-help">
                        <?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('The bypass secret is created when you first save these settings.', 'traydigita-core'),
                                __('Anyone with the bypass link can view the site while maintenance mode is on, so share it sparingly.', 'traydigita-core')
                            );
                        ?>
                    </span>
                <?php else : ?>
                    <span class="maintenance-field-help">
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s is the masked bypass-secret hint, e.g. "a1b2...9f3e". */
                                __('Bypass secret: %s (the full secret is never shown).', 'traydigita-core'),
                                $bypassHint
                            )
                        );
                        ?>
                    </span>
                    <?php if ($bypassTokenHint !== '') : ?>
                        <span class="maintenance-field-help">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %s is the masked bypass-token hint, e.g. "ab12...ef90". */
                                    __('Bypass token: %s', 'traydigita-core'),
                                    $bypassTokenHint
                                )
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                    <label for="maintenance-bypass-url"><?php esc_html_e('Bypass URL', 'traydigita-core'); ?></label>
                    <input type="text" id="maintenance-bypass-url" class="traydigita-ui-input" readonly value="<?php echo esc_attr($bypassUrl); ?>">
                    <button type="button" class="traydigita-admin-button maintenance-copy-bypass" data-bypass-url="<?php echo esc_attr($bypassUrl); ?>" data-copied-label="<?php esc_attr_e('Copied', 'traydigita-core'); ?>">
                        <?php esc_html_e('Copy bypass link', 'traydigita-core'); ?>
                    </button>
                    <span class="maintenance-field-help">
                        <?php esc_html_e('Opening this link sets a 24-hour bypass cookie and then removes the token from the address bar.', 'traydigita-core'); ?>
                    </span>
                <?php endif; ?>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
                    'id' => 'maintenance-regenerate-bypass',
                    'name' => 'regenerate_bypass',
                    'label' => __('Regenerate the bypass secret on save', 'traydigita-core'),
                    'checked' => false,
                    'help' => __('Regenerating invalidates the current bypass link and every bypass cookie immediately.', 'traydigita-core'),
                    'hidden_zero' => false,
                ]);
                ?>
            </div>
        </section>

        <section class="traydigita-ui-section td-settings-section maintenance-tab-panel" id="td-section-preview" aria-label="<?php esc_attr_e('Preview', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Preview', 'traydigita-core'); ?></h2>
            <p>
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Open the maintenance page exactly as visitors see it.', 'traydigita-core'),
                        __('The preview link works only for administrators and only with its one-time nonce; it is not a public URL.', 'traydigita-core')
                    );
                    ?>
            </p>
            <p>
                <a class="traydigita-admin-button traydigita-admin-button-secondary" href="<?php echo esc_url($previewUrl); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e('Preview maintenance page', 'traydigita-core'); ?>
                </a>
            </p>
            <p class="maintenance-field-help">
                <?php esc_html_e('The preview uses the saved settings. Save your changes first to preview them.', 'traydigita-core'); ?>
            </p>
            <h3><?php esc_html_e('Design preview', 'traydigita-core'); ?></h3>
            <div class="maintenance-design-preview">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MaintenanceDesigner::renderPreview() escapes every value it prints.
                echo $designPreview;
                ?>
            </div>
        </section>

        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
    </form>
</div>
<script>
(function () {
    var button = document.querySelector('.maintenance-copy-bypass');
    if (!button) {
        return;
    }
    button.addEventListener('click', function () {
        var url = button.getAttribute('data-bypass-url') || '';
        var done = function () {
            var label = button.getAttribute('data-copied-label');
            if (label) {
                button.textContent = label;
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, function () {
                var input = document.getElementById('maintenance-bypass-url');
                if (input) {
                    input.select();
                    document.execCommand('copy');
                    done();
                }
            });
            return;
        }
        var input = document.getElementById('maintenance-bypass-url');
        if (input) {
            input.select();
            document.execCommand('copy');
            done();
        }
    });
})();
</script>
