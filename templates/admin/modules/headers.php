<?php
/**
 * Custom Headers settings page.
 *
 * Rendered inside the admin shell content area by
 * HeadersModule::renderSettingsPage().
 *
 * Variables:
 * @var array<string, mixed> $settings           Sanitized module settings.
 * @var array<string, string> $effectiveHeaders  Headers this module will send (name => value).
 * @var array<string, string> $deferredHeaders   Headers deferred to the Security Headers module.
 * @var bool                  $securityHeadersActive Whether the Security Headers module is enabled.
 * @var bool                  $isHttps             Whether the site runs over HTTPS.
 * @var array<string, array{ name: string }> $roles Editable user roles (slug => role data).
 * @var string                $checkUrl            Home URL checked by the header checker.
 * @var string                $snippetsHtaccess    Generated .htaccess snippet.
 * @var string                $snippetsNginx       Generated Nginx snippet.
 *
 * The settings form is marked with `data-traydigita-module-form`
 * so the admin shell JavaScript submits it through the generic
 * module save endpoint. Field names match the option keys. Each
 * checkbox is preceded by a hidden input with the same name and
 * value `0`, so an unchecked box still submits a value; the
 * path-exception and role lists carry a hidden empty entry for
 * the same reason (an emptied list must clear the stored list).
 * The header checker is driven by resources/js/modules/admin-headers.ts.
 *
 * Framework (R4-5): the fields are rendered with SettingsControls
 * (toggles, select, textarea, number, checkbox group) and the
 * form ends in the sticky framework save bar. The module declares
 * four tabs, so the shell shows them as vertical tab buttons in
 * the left navigation; the panels keep their
 * [data-traydigita-panel] attributes.
 */
declare(strict_types=1);

?>
<div class="traydigita-module-settings" data-traydigita-module="headers" data-headers-admin
    data-check-url="<?php echo esc_url($checkUrl); ?>"
    data-msg-checking="<?php echo esc_attr(__('Checking headers…', 'traydigita-core')); ?>"
    data-msg-check-failed="<?php echo esc_attr(__('The server check failed. Try checking from the browser instead.', 'traydigita-core')); ?>"
    data-msg-browser-checking="<?php echo esc_attr(__('Checking from the browser…', 'traydigita-core')); ?>"
    data-msg-browser-failed="<?php echo esc_attr(__('The browser check failed. The site may block requests from this browser, or the address may be unreachable.', 'traydigita-core')); ?>"
    data-msg-present="<?php echo esc_attr(__('Present', 'traydigita-core')); ?>"
    data-msg-missing="<?php echo esc_attr(__('Missing', 'traydigita-core')); ?>"
    data-msg-not-applicable="<?php echo esc_attr(__('Not applicable', 'traydigita-core')); ?>"
    data-msg-copied="<?php echo esc_attr(__('Copied.', 'traydigita-core')); ?>"
    data-msg-copy-failed="<?php echo esc_attr(__('Copy failed — select the text manually.', 'traydigita-core')); ?>"
    data-msg-col-header="<?php echo esc_attr(__('Header', 'traydigita-core')); ?>"
    data-msg-col-status="<?php echo esc_attr(__('Status', 'traydigita-core')); ?>"
    data-msg-col-value="<?php echo esc_attr(__('Value', 'traydigita-core')); ?>"
    data-msg-col-explanation="<?php echo esc_attr(__('What it means', 'traydigita-core')); ?>"
    data-msg-remove-path="<?php echo esc_attr(__('Remove this path exception', 'traydigita-core')); ?>">
    <?php
    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::intro(
        __('Add the recommended security headers to frontend responses.', 'traydigita-core'),
        __('Headers are only sent on the frontend — never in wp-admin, for AJAX or REST requests, or on the login page.', 'traydigita-core')
    );
    ?>

    <?php if ($securityHeadersActive) : ?>
        <div class="traydigita-notice traydigita-notice-info">
            <p>
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('The Security Headers module is enabled, so it sends the overlapping headers and this module defers to it.', 'traydigita-core'),
                        __('The headers below are sent by Security Headers, not by this module — each header is sent exactly once.', 'traydigita-core')
                    );
                ?>
            </p>
            <?php if ($deferredHeaders !== []) : ?>
                <ul class="traydigita-headers-list">
                    <?php foreach ($deferredHeaders as $headerName => $headerValue) : ?>
                        <li class="traydigita-headers-item">
                            <code><?php echo esc_html($headerName); ?>: <?php echo esc_html($headerValue); ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p>
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Strict-Transport-Security is never deferred: the Security Headers module does not send it, so this module owns it.', 'traydigita-core'),
                        __('Disable the Security Headers module if you want this module to send the overlapping headers itself.', 'traydigita-core')
                    );
                ?>
            </p>
        </div>
    <?php else : ?>
        <div class="traydigita-notice traydigita-notice-info">
            <p>
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('The Security Headers module is disabled, so this module sends the headers you enable below.', 'traydigita-core'),
                        __('If you enable Security Headers later, it takes over the overlapping headers automatically and this module defers to it.', 'traydigita-core')
                    );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <form class="traydigita-module-form" data-traydigita-module-form data-module="headers" method="post">
        <div data-traydigita-panel="headers" class="traydigita-ui-section td-settings-section">
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
                'id' => 'traydigita-headers-hsts',
                'name' => 'hsts',
                'label' => __('Strict-Transport-Security (HSTS)', 'traydigita-core'),
                'checked' => !empty($settings['hsts']),
            'help' => __('Tells browsers to only ever reach this site over HTTPS for the next year, including subdomains: max-age=31536000; includeSubDomains', 'traydigita-core'),
            'help_long' => !$isHttps ? __('Unavailable: this site does not run over HTTPS yet. HSTS only works on HTTPS, so turn on HTTPS first.', 'traydigita-core') : null,
                'attrs' => ['disabled' => !$isHttps],
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
            ?>
            <div class="traydigita-field">
                <h3 class="traydigita-field-heading">
                    <?php esc_html_e('Headers this module will send', 'traydigita-core'); ?>
                </h3>
                <?php if ($effectiveHeaders !== []) : ?>
                    <ul class="traydigita-headers-list">
                        <?php foreach ($effectiveHeaders as $headerName => $headerValue) : ?>
                            <li class="traydigita-headers-item">
                                <code><?php echo esc_html($headerName); ?>: <?php echo esc_html($headerValue); ?></code>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="traydigita-field-description">
                        <?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('No headers are enabled, or every enabled header is deferred to the Security Headers module.', 'traydigita-core'),
                                __('Turn on at least one header above.', 'traydigita-core')
                            );
                        ?>
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
        </div>

        <div data-traydigita-panel="rules" class="traydigita-ui-section td-settings-section" hidden>
            <div class="traydigita-field">
                <h3 class="traydigita-field-heading">
                    <?php esc_html_e('Per-path exceptions', 'traydigita-core'); ?>
                </h3>
                <p class="traydigita-field-description">
                    <?php
                        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                            __('Paths that start with one of these prefixes get no headers from this module.', 'traydigita-core'),
                            __('One path per row, starting with a slash — for example /embed/ for pages meant to be embedded elsewhere.', 'traydigita-core')
                        );
                        ?>
                </p>
                <input type="hidden" name="path_exceptions[]" value="">
                <div data-headers-path-list>
                    <?php foreach ($settings['path_exceptions'] as $pathException) : ?>
                        <p class="traydigita-field-row" data-headers-path-row>
                            <input class="traydigita-field-text"
                                type="text"
                                name="path_exceptions[]"
                                value="<?php echo esc_attr((string) $pathException); ?>"
                                placeholder="/embed/"
                                aria-label="<?php echo esc_attr__('Path exception', 'traydigita-core'); ?>">
                            <button type="button"
                                class="traydigita-admin-button"
                                data-headers-path-remove
                                aria-label="<?php echo esc_attr__('Remove this path exception', 'traydigita-core'); ?>">
                                <?php esc_html_e('Remove', 'traydigita-core'); ?>
                            </button>
                        </p>
                    <?php endforeach; ?>
                </div>
                <template data-headers-path-template>
                    <p class="traydigita-field-row" data-headers-path-row>
                        <input class="traydigita-field-text"
                            type="text"
                            name="path_exceptions[]"
                            value=""
                            placeholder="/embed/"
                            aria-label="<?php echo esc_attr__('Path exception', 'traydigita-core'); ?>">
                        <button type="button"
                            class="traydigita-admin-button"
                            data-headers-path-remove
                            aria-label="<?php echo esc_attr__('Remove this path exception', 'traydigita-core'); ?>">
                            <?php esc_html_e('Remove', 'traydigita-core'); ?>
                        </button>
                    </p>
                </template>
                <p>
                    <button type="button" class="traydigita-admin-button" data-headers-path-add>
                        <?php esc_html_e('Add path exception', 'traydigita-core'); ?>
                    </button>
                </p>
            </div>
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
                'id' => 'traydigita-headers-bypass-logged-in',
                'name' => 'bypass_logged_in',
                'label' => __('Skip headers for logged-in users', 'traydigita-core'),
                'checked' => !empty($settings['bypass_logged_in']),
                'help' => __('Useful while testing embeds or tools that need framing.', 'traydigita-core'),
                'help_long' => __('Staff see the site without the headers, visitors still get them.', 'traydigita-core'),
            ]);
            ?>
            <?php
            $roleOptions = [];
            foreach ($roles as $roleSlug => $roleData) {
                $roleOptions[$roleSlug] = (string) ($roleData['name'] ?? $roleSlug);
            }
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::checkboxGroup([
                'id' => 'traydigita-headers-roles',
                'name' => 'bypass_roles[]',
                'label' => __('Skip headers for these roles', 'traydigita-core'),
                'options' => $roleOptions,
                'checked' => $settings['bypass_roles'],
                'help' => __('A logged-in user with any checked role gets no headers from this module.', 'traydigita-core'),
                'help_long' => __('Leave all roles unchecked to send the headers to everyone.', 'traydigita-core'),
            ]);
            ?>
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                'id' => 'traydigita-headers-timeout',
                'name' => 'check_timeout',
                'label' => __('Header-check timeout (seconds)', 'traydigita-core'),
                'value' => (string) $settings['check_timeout'],
                'help' => __('How long the header checker waits for the site to answer, from 3 to 30 seconds.', 'traydigita-core'),
                'help_long' => __('Raise it if the check times out on a slow host.', 'traydigita-core'),
                'attrs' => ['min' => '3', 'max' => '30', 'step' => '1'],
            ]);
            ?>
        </div>

        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
    </form>

    <div data-traydigita-panel="checker" class="traydigita-ui-section td-settings-section" hidden>
        <p class="traydigita-field-description">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s is the site home URL. */
                    __('Checks the headers on %s, the same way a visitor’s browser sees them.', 'traydigita-core'),
                    $checkUrl
                )
            );
            ?>
        </p>
        <p>
            <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-headers-check>
                <?php esc_html_e('Check headers now', 'traydigita-core'); ?>
            </button>
        </p>
        <div data-headers-results aria-live="polite"></div>
        <div data-headers-browser-fallback hidden>
            <p class="traydigita-field-description">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('The server could not check the site — some hosts block requests the site makes to itself.', 'traydigita-core'),
                        __('The browser can check instead: it fetches the home page from here and reports the headers it sees.', 'traydigita-core')
                    );
                    ?>
            </p>
            <p>
                <button type="button" class="traydigita-admin-button" data-headers-browser-check>
                    <?php esc_html_e('Check from the browser instead', 'traydigita-core'); ?>
                </button>
            </p>
        </div>
    </div>

    <div data-traydigita-panel="snippets" class="traydigita-ui-section td-settings-section" hidden>
        <p class="traydigita-field-description">
            <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('The same headers, as server configuration.', 'traydigita-core'),
                    __('This is display only — the theme never writes server files. Paste a snippet into your server configuration only after reviewing it, and only if the headers are not already sent from PHP.', 'traydigita-core')
                );
                ?>
        </p>
        <?php if ($effectiveHeaders === []) : ?>
            <p class="traydigita-field-description">
                <?php esc_html_e('No headers are enabled, so there is nothing to show yet. Enable a header on the Headers tab first.', 'traydigita-core'); ?>
            </p>
        <?php else : ?>
            <div class="traydigita-field">
                <h3 class="traydigita-field-heading">
                    <?php esc_html_e('.htaccess (Apache / OpenLiteSpeed)', 'traydigita-core'); ?>
                </h3>
                <pre class="traydigita-field-code-block" id="traydigita-headers-snippet-htaccess"><?php echo esc_html($snippetsHtaccess); ?></pre>
                <p>
                    <button type="button" class="traydigita-admin-button" data-headers-copy data-headers-copy-target="traydigita-headers-snippet-htaccess">
                        <?php esc_html_e('Copy .htaccess snippet', 'traydigita-core'); ?>
                    </button>
                </p>
            </div>
            <div class="traydigita-field">
                <h3 class="traydigita-field-heading">
                    <?php esc_html_e('Nginx', 'traydigita-core'); ?>
                </h3>
                <pre class="traydigita-field-code-block" id="traydigita-headers-snippet-nginx"><?php echo esc_html($snippetsNginx); ?></pre>
                <p>
                    <button type="button" class="traydigita-admin-button" data-headers-copy data-headers-copy-target="traydigita-headers-snippet-nginx">
                        <?php esc_html_e('Copy Nginx snippet', 'traydigita-core'); ?>
                    </button>
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>
