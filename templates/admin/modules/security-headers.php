<?php
/**
 * Security Headers settings page.
 *
 * Rendered inside the admin shell content area by
 * SecurityHeadersModule::renderSettingsPage().
 *
 * Variables:
 * @var array<string, mixed> $settings Sanitized module settings.
 * @var array<string, string> $headers Headers that will be sent
 *     with the current settings (name => value).
 *
 * The form is marked with `data-traydigita-module-form` so the
 * admin shell JavaScript submits it through the generic module
 * save endpoint (which calls SecurityHeadersModule::saveSettings()
 * with the flat field values). Field names match the option keys.
 * Each checkbox is preceded by a hidden input with the same name
 * and value `0`, so an unchecked box still submits a value.
 *
 * Framework (R4-5): the fields are rendered with SettingsControls
 * (toggles, select, textarea) inside framework sections
 * (`td-section-*`, matching SecurityHeadersModule::settingsSections())
 * and the form ends in the sticky framework save bar.
 */
declare(strict_types=1);

?>
<div class="traydigita-module-settings" data-traydigita-module="security-headers">
    <?php
    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::intro(
        __('Add defensive HTTP headers to frontend responses.', 'traydigita-core'),
        __('Headers are only sent on the frontend — never in wp-admin, for AJAX or REST requests, or on the login page.', 'traydigita-core')
    );
    ?>
    <form class="traydigita-module-form" data-traydigita-module-form method="post">
        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::sectionOpen('headers', __('Security headers', 'traydigita-core')); ?>
        <?php
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'traydigita-headers-nosniff',
            'name' => 'x_content_type_options',
            'label' => __('X-Content-Type-Options: nosniff', 'traydigita-core'),
            'checked' => !empty($settings['x_content_type_options']),
            'help' => __('Stops browsers from guessing a file’s type.', 'traydigita-core'),
                'help_long' => __('Prevents a plain-text file from being treated as JavaScript, for example.', 'traydigita-core'),
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'traydigita-headers-frame',
            'name' => 'x_frame_options',
            'label' => __('X-Frame-Options: SAMEORIGIN', 'traydigita-core'),
            'checked' => !empty($settings['x_frame_options']),
            'help' => __('Only pages from this site may embed the site in a frame.', 'traydigita-core'),
                'help_long' => __('Protects against clickjacking. Turn it off if another site must embed this one.', 'traydigita-core'),
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::select([
            'id' => 'traydigita-headers-referrer',
            'name' => 'referrer_policy',
            'label' => __('Referrer-Policy', 'traydigita-core'),
            'value' => (string) $settings['referrer_policy'],
            'options' => [
                '' => __('Disabled', 'traydigita-core'),
                'no-referrer' => 'no-referrer',
                'same-origin' => 'same-origin',
                'strict-origin' => 'strict-origin',
                'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin',
            ],
            'help' => __('Controls how much of the page address is sent to other sites when a visitor follows a link.', 'traydigita-core'),
                'help_long' => __('The default sends the full address to this site and only the site address to other sites.', 'traydigita-core'),
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'traydigita-headers-permissions',
            'name' => 'permissions_policy',
            'label' => __('Permissions-Policy', 'traydigita-core'),
            'checked' => !empty($settings['permissions_policy']),
            'help' => __('Blocks the page and any embedded frame from using the camera, microphone, and geolocation: camera=(), microphone=(), geolocation=()', 'traydigita-core'),
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
            'id' => 'traydigita-headers-csp',
            'name' => 'csp_report_only',
            'label' => __('Content-Security-Policy (report-only)', 'traydigita-core'),
            'checked' => !empty($settings['csp_report_only']),
            'help' => __('Report-only mode only: the browser reports what the policy would block, but nothing is actually blocked.', 'traydigita-core'),
                'help_long' => __('An enforcing policy is never sent, so enabling this cannot break the site.', 'traydigita-core'),
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::textarea([
            'id' => 'traydigita-headers-csp-policy',
            'name' => 'csp_policy',
            'label' => __('Report-only policy', 'traydigita-core'),
            'value' => (string) $settings['csp_policy'],
            'help' => __('One line, at most 2000 characters, no line breaks.', 'traydigita-core'),
                'help_long' => __('The default allows the site itself, Google Fonts, and the Open-Meteo and CoinGecko hosts used by the theme widgets.', 'traydigita-core'),
            'attrs' => ['rows' => '5'],
            'input_class' => 'traydigita-field-code',
        ]);
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::sectionClose();
        ?>
        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::sectionOpen('sent', __('Headers that will be sent', 'traydigita-core')); ?>
        <div class="traydigita-field">
            <?php if ($headers !== []) : ?>
                <ul class="traydigita-headers-list">
                    <?php foreach ($headers as $headerName => $headerValue) : ?>
                        <li class="traydigita-headers-item">
                            <code><?php echo esc_html($headerName); ?>: <?php echo esc_html($headerValue); ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="traydigita-field-description">
                    <?php esc_html_e('No headers are enabled. Turn on at least one header above.', 'traydigita-core'); ?>
                </p>
            <?php endif; ?>
            <p class="traydigita-field-description">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('If the server (or Cloudflare) already sends one of these headers, both values may reach the browser.', 'traydigita-core'),
                        __('Configure each header in one place only — here or at the server level, not both.', 'traydigita-core')
                    );
                    ?>
            </p>
        </div>
        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::sectionClose(); ?>
        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
    </form>
</div>
