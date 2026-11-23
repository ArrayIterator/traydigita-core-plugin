<?php
/**
 * Cookie Consent settings page.
 *
 * Rendered inside the admin shell content area by
 * CookieConsentModule::renderSettingsPage().
 *
 * Variables:
 * @var array<string, mixed> $settings    Sanitized module settings.
 * @var array<string, mixed> $defaults    Built-in default settings.
 * @var string               $previewHtml Live-preview banner markup
 *                                        from ConsentScript::renderPreview().
 *
 * Layout: the form (grouped sections) sits on the left and the
 * live preview on the right on wide screens, stacked on narrow
 * ones. The form is marked with `data-traydigita-module-form` so
 * the admin shell JavaScript submits it through the generic module
 * save endpoint (which calls CookieConsentModule::saveSettings()
 * with the flat field values). Field names match the option keys.
 *
 * The preview is rendered from the same banner markup as the
 * frontend and updates live from the form fields (see
 * resources/js/modules/admin-cookie-consent.ts). It never sets a
 * consent cookie: the preview markup ships without the frontend
 * banner's inline script.
 *
 * Framework (R4-5): the four sections are framework sections
 * (`td-section-*`, matching CookieConsentModule::settingsSections())
 * and the fields are rendered with SettingsControls (textarea,
 * text, colour, select, number), keeping the `data-cookie-preview`
 * hooks and the `traydigita-cookie-color-field` class the live
 * preview JavaScript binds to. The form ends in the sticky
 * framework save bar. The live preview aside is unchanged.
 */
declare(strict_types=1);

?>
<div class="traydigita-module-settings traydigita-cookie-settings"
    data-traydigita-module="cookie-consent"
    data-traydigita-cookie-admin
    data-default-message="<?php echo esc_attr((string) $defaults['message']); ?>"
    data-default-accept="<?php echo esc_attr((string) $defaults['accept_text']); ?>"
    data-default-decline="<?php echo esc_attr((string) $defaults['decline_text']); ?>"
    data-default-customize="<?php echo esc_attr((string) $defaults['customize_text']); ?>"
    data-policy-label="<?php echo esc_attr__('Read the Privacy Policy', 'traydigita-core'); ?>">
    <?php
    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::intro(
        __('Show a dismissible cookie consent banner to first-time visitors and remember their choice in a cookie.', 'traydigita-core'),
        __('The banner only appears while the visitor has no consent cookie yet.', 'traydigita-core')
    );
    ?>
    <div class="traydigita-ui-notice traydigita-ui-notice-info">
        <span class="traydigita-ui-notice-icon" aria-hidden="true">i</span>
        <div class="traydigita-ui-notice-body">
            <p class="traydigita-ui-notice-title">
                <?php esc_html_e('Shared with the Customizer', 'traydigita-core'); ?>
            </p>
            <p class="traydigita-ui-notice-message">
                <?php
                printf(
                    /* translators: %s: link to the Cookie Consent Customizer section. */
                    esc_html__('The banner message, button labels, colours and position are the same settings as in the %s — changing a value here changes it there too.', 'traydigita-core'),
                    '<a href="' . esc_url(admin_url('customize.php?autofocus[section]=traydigita_cookie_consent')) . '">' . esc_html__('Cookie Consent section of the Customizer', 'traydigita-core') . '</a>'
                );
                ?>
            </p>
        </div>
    </div>
    <div class="traydigita-cookie-layout">
        <form class="traydigita-module-form traydigita-cookie-form" data-traydigita-module-form method="post">
            <section class="traydigita-ui-section td-settings-section" id="td-section-text" aria-labelledby="traydigita-cookie-section-text">
                <h2 class="traydigita-ui-section-title" id="traydigita-cookie-section-text">
                    <?php esc_html_e('Banner text', 'traydigita-core'); ?>
                </h2>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::textarea([
                    'id' => 'traydigita-cookie-message',
                    'name' => 'message',
                    'label' => __('Banner message', 'traydigita-core'),
                    'value' => (string) $settings['message'],
                    'help' => __('Links are allowed. Keep it short — one or two sentences.', 'traydigita-core'),
                    'attrs' => ['rows' => '5', 'data-cookie-preview' => 'message'],
                ]);
                ?>
            </section>
            <section class="traydigita-ui-section td-settings-section" id="td-section-buttons" aria-labelledby="traydigita-cookie-section-buttons">
                <h2 class="traydigita-ui-section-title" id="traydigita-cookie-section-buttons">
                    <?php esc_html_e('Buttons', 'traydigita-core'); ?>
                </h2>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'traydigita-cookie-accept-text',
                    'name' => 'accept_text',
                    'label' => __('Accept button text', 'traydigita-core'),
                    'value' => (string) $settings['accept_text'],
                    'attrs' => ['data-cookie-preview' => 'accept_text'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'traydigita-cookie-decline-text',
                    'name' => 'decline_text',
                    'label' => __('Decline button text', 'traydigita-core'),
                    'value' => (string) $settings['decline_text'],
                    'attrs' => ['data-cookie-preview' => 'decline_text'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'id' => 'traydigita-cookie-customize-text',
                    'name' => 'customize_text',
                    'label' => __('Customize button text', 'traydigita-core'),
                    'value' => (string) $settings['customize_text'],
                    'help' => __('The Customize button opens a short explanation panel with a link to the privacy policy.', 'traydigita-core'),
                    'help_long' => __('It does not add extra cookie choices.', 'traydigita-core'),
                    'attrs' => ['data-cookie-preview' => 'customize_text'],
                ]);
                ?>
            </section>
            <section class="traydigita-ui-section td-settings-section" id="td-section-appearance" aria-labelledby="traydigita-cookie-section-appearance">
                <h2 class="traydigita-ui-section-title" id="traydigita-cookie-section-appearance">
                    <?php esc_html_e('Appearance', 'traydigita-core'); ?>
                </h2>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::color([
                    'id' => 'traydigita-cookie-background-color',
                    'name' => 'background_color',
                    'label' => __('Banner background colour', 'traydigita-core'),
                    'value' => (string) $settings['background_color'],
                    'help' => __('Leave empty to use the default banner background.', 'traydigita-core'),
                    'input_class' => 'traydigita-cookie-color-field',
                    'attrs' => ['placeholder' => '#ffffff', 'data-cookie-preview' => 'background_color'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::color([
                    'id' => 'traydigita-cookie-text-color',
                    'name' => 'text_color',
                    'label' => __('Banner text colour', 'traydigita-core'),
                    'value' => (string) $settings['text_color'],
                    'help' => __('Leave empty to use the default banner text colour.', 'traydigita-core'),
                    'input_class' => 'traydigita-cookie-color-field',
                    'attrs' => ['placeholder' => '#1f2937', 'data-cookie-preview' => 'text_color'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::color([
                    'id' => 'traydigita-cookie-accept-color',
                    'name' => 'accept_color',
                    'label' => __('Accept button colour', 'traydigita-core'),
                    'value' => (string) $settings['accept_color'],
                    'help' => __('Background colour of the Accept button. Leave empty to use the default blue.', 'traydigita-core'),
                    'input_class' => 'traydigita-cookie-color-field',
                    'attrs' => ['placeholder' => '#0369a1', 'data-cookie-preview' => 'accept_color'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
                    'id' => 'traydigita-cookie-position',
                    'name' => 'position',
                    'label' => __('Banner position', 'traydigita-core'),
                    'value' => (string) $settings['position'],
                    'options' => ['bottom' => __('Bottom', 'traydigita-core'), 'top' => __('Top', 'traydigita-core')],
                    'help' => __('Where the banner appears on the page.', 'traydigita-core'),
                    'attrs' => ['data-cookie-preview' => 'position'],
                ]);
                ?>
            </section>
            <section class="traydigita-ui-section td-settings-section" id="td-section-behaviour" aria-labelledby="traydigita-cookie-section-behaviour">
                <h2 class="traydigita-ui-section-title" id="traydigita-cookie-section-behaviour">
                    <?php esc_html_e('Behaviour', 'traydigita-core'); ?>
                </h2>
                <div class="traydigita-ui-field td-settings-field">
                    <label class="traydigita-ui-field-label td-settings-label" for="traydigita-cookie-policy-page">
                        <?php esc_html_e('Privacy Policy page', 'traydigita-core'); ?>
                    </label>
                    <div class="td-settings-control">
                    <?php
                    wp_dropdown_pages([
                        'selected' => (int) $settings['policy_page_id'],
                        'name' => 'policy_page_id',
                        'id' => 'traydigita-cookie-policy-page',
                        'class' => 'traydigita-ui-select',
                        'show_option_none' => __('No page selected', 'traydigita-core'),
                        'option_none_value' => '0',
                        'echo' => 1,
                    ]);
                    ?>
                    </div>
                    <p class="traydigita-ui-field-help td-settings-help">
                        <?php esc_html_e('When a page is selected, the banner links to it. Leave it empty to use the URL below instead.', 'traydigita-core'); ?>
                    </p>
                </div>
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                    'type' => 'url',
                    'id' => 'traydigita-cookie-policy-url',
                    'name' => 'policy_url',
                    'label' => __('Privacy Policy URL', 'traydigita-core'),
                    'value' => (string) $settings['policy_url'],
                    'help' => __('Optional. Only used when no Privacy Policy page is selected above.', 'traydigita-core'),
                    'attrs' => ['placeholder' => 'https://', 'data-cookie-preview' => 'policy_url'],
                ]);
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                    'id' => 'traydigita-cookie-duration',
                    'name' => 'duration_days',
                    'label' => __('Remember the choice for (days)', 'traydigita-core'),
                    'value' => (string) $settings['duration_days'],
                    'help' => __('Between 30 and 365 days. After this period the banner is shown again.', 'traydigita-core'),
                    'attrs' => ['min' => '30', 'max' => '365', 'step' => '1'],
                ]);
                ?>
            </section>
            <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
        </form>
        <aside class="traydigita-ui-card traydigita-cookie-preview" aria-labelledby="traydigita-cookie-preview-title">
            <h2 class="traydigita-ui-section-title" id="traydigita-cookie-preview-title">
                <?php esc_html_e('Live preview', 'traydigita-core'); ?>
            </h2>
            <div class="traydigita-cookie-preview-frame traydigita-cookie-preview-frame--<?php echo esc_attr((string) $settings['position']); ?>"
                data-traydigita-cookie-preview-frame>
                <?php echo $previewHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderPreview() returns fully escaped banner markup. ?>
            </div>
            <p class="traydigita-ui-field-help">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Preview only — this banner never sets a consent cookie.', 'traydigita-core'),
                        __('Its buttons do nothing here except Customize, which opens the explanation panel.', 'traydigita-core')
                    );
                    ?>
            </p>
            <p class="traydigita-ui-field-help">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Text, colours and position update live as you type.', 'traydigita-core'),
                        __('The privacy policy link and the duration apply after saving.', 'traydigita-core')
                    );
                    ?>
            </p>
        </aside>
    </div>
</div>
