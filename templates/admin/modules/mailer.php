<?php
/**
 * Mailer module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by MailerModule::renderSettingsPage()):
 * @var array<string, mixed> $mailerSettings Settings without the secret keys.
 * @var array<string, array<string, mixed>> $mailerProviders Provider definitions (id => definition).
 * @var array<string, bool> $mailerProviderHasSecret Whether a secret is stored per provider.
 * @var bool $mailerConfigured Whether the selected provider is configured.
 * @var bool $mailerHasPassword Whether an SMTP password is currently stored.
 * @var bool $mailerGmailConnected Whether a Gmail account is connected via OAuth.
 * @var string $mailerGmailEmail Connected Gmail account address, or ''.
 * @var array{time: int, success: bool}|null $mailerLastTest Last test-email result, if any.
 * @var array<int, array{time: int, to: string, subject: string, provider: string, result: string, error: string}> $mailerSendLog Last send-log entries (newest first).
 * @var string $mailerNonceAction Nonce action (`traydigita-admin`).
 * @var string $mailerTestAction AJAX action for the test email (`traydigita_mailer_test`).
 * @var string $mailerSaveAction AJAX action for the shell generic save endpoint (`traydigita_admin_save`).
 * @var string $mailerGmailStartUrl URL that starts the Gmail OAuth flow (nonce in the URL).
 * @var string $mailerGmailDisconnectUrl URL that disconnects the Gmail account (nonce in the URL).
 * @var string $mailerGmailRedirectUri Redirect URI for the Google Cloud OAuth client (GmailOAuth::redirectUri()).
 * @var string $mailerResult Gmail OAuth redirect result flag (`connected`, `disconnected`, `error`, or '').
 * @var string $mailerResultCode Gmail OAuth redirect detail code, or ''.
 *
 * Forms:
 * - Settings form: posts to the shell generic save endpoint
 *   (`data-action` + `data-module="smtp"` — the module id is
 *   still `smtp`, see MailerModule::id()); the shell passes the
 *   fields to MailerModule::saveSettings(). Field names are flat.
 *   Secret fields (SMTP password, API keys, client secret) are
 *   always rendered empty: the stored secret is never printed,
 *   not even as a value attribute. An empty submission keeps
 *   the stored secret.
 * - Test form: posts to `admin-ajax.php` with
 *   `data-action="traydigita_mailer_test"`. Field names: `to`,
 *   `subject`, `body`.
 *
 * Both forms send the nonce as a hidden `_ajax_nonce` field.
 * Tabs (Provider / Connection / From / Deliverability / Test &
 * Log) are anchor links to the panel sections, so they work
 * with the shell's hash routing and with plain anchor
 * navigation when JavaScript is off.
 *
 * Framework: the sections are framework sections
 * (`td-section-*`, matching MailerModule::settingsSections())
 * and the fields are rendered with SettingsControls. Every
 * provider field group is rendered (not shown/hidden with
 * JavaScript), each labelled with the provider it belongs to,
 * so the page works with no JavaScript at all.
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\Mailer\MailerModule;

$encryptionLabels = [
    'none' => __('None', 'traydigita-core'),
    'ssl' => 'SSL',
    'tls' => 'TLS',
];
$encryptionLabel = $encryptionLabels[$mailerSettings['encryption']] ?? $encryptionLabels['tls'];
$providerLabels = [];
foreach ($mailerProviders as $providerId => $definition) {
    $providerLabels[$providerId] = (string) ($definition['label'] ?? $providerId);
}
$presetDescriptions = MailerModule::SMTP_PRESET_DESCRIPTIONS;
// Picker items for the provider / preset popups (same shared
// modal component as the module info popup): logo, label, a
// short meta line (free tier / best-for for providers, the
// one-line description for presets) and the full description
// for search. The logo URLs come from MailerModule's allowlisted
// maps, with a generic envelope fallback for unknown ids.
$pickerProviders = [];
foreach ($mailerProviders as $providerId => $definition) {
    $freeTier = (string) ($definition['free_tier'] ?? '');
    $bestFor = (string) ($definition['best_for'] ?? '');
    if ($freeTier !== '' && $bestFor !== '') {
        $providerMeta = $freeTier . ' · ' . $bestFor;
    } elseif ($bestFor !== '') {
        $providerMeta = $bestFor;
    } else {
        $providerMeta = $freeTier;
    }
    $pickerProviders[] = [
        'id' => $providerId,
        'label' => (string) ($definition['label'] ?? $providerId),
        'description' => (string) ($definition['description'] ?? ''),
        'meta' => $providerMeta,
        'logo' => MailerModule::providerLogoUrl($providerId),
        // Readiness travels with the item so the shared modal
        // can disable unverified providers (see
        // traydigita-modal.js); SMTP presets carry no flags and
        // are always pickable.
        'ready' => !empty($definition['is_ready']),
        'proven' => !empty($definition['ready_proven']),
    ];
}
$pickerPresets = [];
foreach ($presetDescriptions as $presetId => $preset) {
    $pickerPresets[] = [
        'id' => $presetId,
        'label' => (string) $preset['label'],
        'description' => (string) $preset['description'],
        'meta' => (string) $preset['description'],
        'logo' => MailerModule::presetLogoUrl($presetId),
    ];
}
$selectedProviderLabel = $providerLabels[$mailerSettings['provider']] ?? (string) $mailerSettings['provider'];
$selectedProviderId = (string) $mailerSettings['provider'];
$selectedProviderReady = !empty($mailerProviders[$selectedProviderId]['is_ready']);
$selectedProviderProven = !empty($mailerProviders[$selectedProviderId]['ready_proven']);
$gmailResultMessage = MailerModule::gmailResultMessage($mailerResult, $mailerResultCode);
?>
<div class="traydigita-mailer-settings">
    <?php if ($gmailResultMessage !== '') : ?>
        <div class="traydigita-admin-notice <?php echo $mailerResult === 'error' ? 'traydigita-admin-notice-error' : 'traydigita-admin-notice-success'; ?>">
            <p><?php echo esc_html($gmailResultMessage); ?></p>
        </div>
    <?php endif; ?>

    <div class="traydigita-mailer-status" role="status">
        <?php if ($mailerConfigured) : ?>
            <span class="traydigita-mailer-status-badge traydigita-mailer-status-badge-configured"><?php esc_html_e('Configured', 'traydigita-core'); ?></span>
            <?php if ($selectedProviderProven) : ?>
                <span class="traydigita-mailer-status-badge traydigita-mailer-status-badge-proven"><?php esc_html_e('Verified live', 'traydigita-core'); ?></span>
            <?php elseif ($selectedProviderReady) : ?>
                <span class="traydigita-mailer-status-badge traydigita-mailer-status-badge-ready"><?php esc_html_e('Ready', 'traydigita-core'); ?></span>
            <?php else : ?>
                <span class="traydigita-mailer-status-badge traydigita-mailer-status-badge-unverified"><?php esc_html_e('Unverified', 'traydigita-core'); ?></span>
            <?php endif; ?>
            <p class="traydigita-mailer-status-text">
                <?php if ($mailerSettings['provider'] === 'gmail') : ?>
                    <?php
                    /* translators: %s is the connected Gmail account address. */
                    echo esc_html(sprintf(__('Sending mail through Gmail as %s.', 'traydigita-core'), $mailerGmailEmail));
                    ?>
                <?php elseif ($mailerSettings['provider'] === 'smtp') : ?>
                    <?php
                    /* translators: %1$s is the SMTP host, %2$d is the port, %3$s is the encryption label. */
                    echo esc_html(sprintf(__('Sending mail through %1$s:%2$d (%3$s).', 'traydigita-core'), $mailerSettings['host'], (int) $mailerSettings['port'], $encryptionLabel));
                    ?>
                <?php else : ?>
                    <?php
                    /* translators: %s is the selected mail provider name. */
                    echo esc_html(sprintf(__('Sending mail through %s.', 'traydigita-core'), $selectedProviderLabel));
                    ?>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <span class="traydigita-mailer-status-badge traydigita-mailer-status-badge-unconfigured"><?php esc_html_e('Not configured', 'traydigita-core'); ?></span>
            <p class="traydigita-mailer-status-text"><?php esc_html_e('Pick a provider and enter its details below. Until then, WordPress keeps sending mail with its default mailer.', 'traydigita-core'); ?></p>
        <?php endif; ?>
        <?php if ($mailerConfigured && !$selectedProviderReady) : ?>
            <p class="traydigita-mailer-status-text"><?php esc_html_e('This provider was selected before verification and keeps sending. Pick a ready provider to switch.', 'traydigita-core'); ?></p>
        <?php endif; ?>
        <?php if ($mailerLastTest !== null) : ?>
            <p class="traydigita-mailer-last-test">
                <?php
                /* translators: %s is the date and time of the last test email. */
                echo esc_html(sprintf(__('Last test: %s', 'traydigita-core'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $mailerLastTest['time'])));
                ?>
                <?php if ($mailerLastTest['success']) : ?>
                    <span class="traydigita-mailer-test-badge traydigita-mailer-test-badge-success"><?php esc_html_e('Succeeded', 'traydigita-core'); ?></span>
                <?php else : ?>
                    <span class="traydigita-mailer-test-badge traydigita-mailer-test-badge-failed"><?php esc_html_e('Failed', 'traydigita-core'); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

    <form class="traydigita-mailer-form" method="post" data-action="<?php echo esc_attr($mailerSaveAction); ?>" data-module="smtp">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($mailerNonceAction)); ?>">
        <input type="hidden" name="module" value="smtp">

        <section id="td-section-provider" class="traydigita-ui-section td-settings-section traydigita-mailer-tab-panel" aria-label="<?php esc_attr_e('Provider', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Provider', 'traydigita-core'); ?></h2>

            <?php
            // Popup pickers (shared modal component): a dropdown
            // cannot show the provider logos, so the provider and
            // the SMTP preset are picked from a searchable,
            // scrollable popup instead. Each picker writes its
            // value into the hidden input with the same field
            // name the old select used, so the save flow is
            // untouched.
            SettingsControls::picker([
                'id' => 'mailer-provider',
                'name' => 'provider',
                'label' => __('Mail provider', 'traydigita-core'),
                'value' => (string) $mailerSettings['provider'],
                'title' => __('Choose a mail provider', 'traydigita-core'),
                'search' => __('Search providers', 'traydigita-core'),
                'empty' => __('No providers match your search.', 'traydigita-core'),
                'items' => $pickerProviders,
                'help' => __('Every provider is built into Core — no extra plugin is needed. Pick one, then enter its credentials under Connection. Third-party plugins can add more providers through the traydigita:core:mailer/providers filter.', 'traydigita-core'),
            ]);
            // Readiness, in one place: the rule lives behind
            // Help so the Provider section stays short.
            SettingsControls::about(
                __('About provider readiness', 'traydigita-core'),
                __('Only ready providers can be picked. Unverified providers cannot be selected for a new setup.', 'traydigita-core'),
                '<p>' . esc_html__('A provider is ready when its endpoint answered in the 2026-11-24 health audit, it is well-known with public docs and a standard API key or OAuth flow, and its send path uses the shared helpers.', 'traydigita-core') . '</p>'
                    . '<p>' . esc_html__('Ready does not mean a live send was proven — no real send has been tested from this site yet. A provider that was already selected before verification keeps sending.', 'traydigita-core') . '</p>'
            );
            SettingsControls::picker([
                'id' => 'mailer-smtp-preset',
                'name' => 'smtp_preset',
                'label' => __('SMTP preset', 'traydigita-core'),
                'value' => (string) $mailerSettings['smtp_preset'],
                'title' => __('Choose an SMTP preset', 'traydigita-core'),
                'search' => __('Search presets', 'traydigita-core'),
                'empty' => __('No presets match your search.', 'traydigita-core'),
                'items' => $pickerPresets,
                'help' => __('Only used with the SMTP provider. A preset fills in the host, port, and encryption below when you save.', 'traydigita-core'),
            ]);
            ?>
        </section>

        <section id="td-section-connection" class="traydigita-ui-section td-settings-section traydigita-mailer-tab-panel" aria-label="<?php esc_attr_e('Connection', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Connection', 'traydigita-core'); ?></h2>

            <?php
            SettingsControls::input([
                'id' => 'mailer-host',
                'name' => 'host',
                'label' => __('SMTP host', 'traydigita-core'),
                'value' => (string) $mailerSettings['host'],
                'help' => __('Hostname or IP address of your SMTP server, for example smtp.example.com. Only used with the SMTP provider. Leave empty to disable SMTP.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
            ]);
            SettingsControls::number([
                'id' => 'mailer-port',
                'name' => 'port',
                'label' => __('Port', 'traydigita-core'),
                'value' => (string) $mailerSettings['port'],
                'help' => __('Usually 587 for TLS, 465 for SSL, or 25 without encryption.', 'traydigita-core'),
                'attrs' => ['min' => '1', 'max' => '65535', 'step' => '1'],
            ]);
            SettingsControls::select([
                'id' => 'mailer-encryption',
                'name' => 'encryption',
                'label' => __('Encryption', 'traydigita-core'),
                'value' => (string) $mailerSettings['encryption'],
                'options' => ['none' => __('None', 'traydigita-core'), 'ssl' => 'SSL', 'tls' => 'TLS'],
            ]);
            SettingsControls::toggle([
                'id' => 'mailer-auth',
                'name' => 'auth',
                'label' => __('Use SMTP authentication', 'traydigita-core'),
                'checked' => (bool) $mailerSettings['auth'],
            ]);
            SettingsControls::input([
                'id' => 'mailer-username',
                'name' => 'username',
                'label' => __('Username', 'traydigita-core'),
                'value' => (string) $mailerSettings['username'],
                'attrs' => ['autocomplete' => 'username', 'spellcheck' => 'false'],
            ]);
            SettingsControls::input([
                'type' => 'password',
                'id' => 'mailer-password',
                'name' => 'password',
                'label' => __('Password', 'traydigita-core'),
                'value' => '',
                'help' => __('Leave empty to keep the saved password. The password is never shown.', 'traydigita-core'),
                'attrs' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => $mailerHasPassword ? __('•••••••• (saved)', 'traydigita-core') : __('Enter SMTP password', 'traydigita-core'),
                ],
            ]);

            // API provider credentials: one field group per
            // provider, driven by the provider definitions, so a
            // new built-in or third-party provider gets its
            // settings fields with no template change.
            foreach ($mailerProviders as $providerId => $definition) :
                if ($providerId === 'smtp') {
                    continue;
                }
                $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
                if ($fields === []) {
                    continue;
                }
                ?>
                <h3 class="traydigita-mailer-provider-heading">
                    <?php
                    /* translators: %s is the mail provider name. */
                    echo esc_html(sprintf(__('Only used with %s', 'traydigita-core'), (string) ($definition['label'] ?? $providerId)));
                    if (empty($definition['is_ready'])) {
                        echo ' (' . esc_html__('Unverified', 'traydigita-core') . ')';
                    }
                    ?>
                </h3>
                <?php
                foreach ($fields as $field) :
                    if (!is_array($field) || empty($field['key']) || !is_string($field['key'])) {
                        continue;
                    }
                    $fieldKey = $field['key'];
                    $fieldType = isset($field['type']) && is_string($field['type']) ? $field['type'] : 'text';
                    $isSecret = !empty($field['secret']);
                    $fieldValue = $isSecret ? '' : (string) ($mailerSettings[$fieldKey] ?? '');
                    $fieldId = 'mailer-' . $fieldKey;
                    $fieldArgs = [
                        'id' => $fieldId,
                        'name' => $fieldKey,
                        'label' => isset($field['label']) && is_string($field['label']) ? $field['label'] : $fieldKey,
                        'value' => $fieldValue,
                        'help' => isset($field['hint']) && is_string($field['hint']) ? $field['hint'] : '',
                        'mono' => true,
                        'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                    ];
                    if ($fieldType === 'select' && is_array($field['options'] ?? null)) {
                        $fieldArgs['options'] = $field['options'];
                        SettingsControls::select($fieldArgs);
                    } else {
                        if ($isSecret) {
                            $fieldArgs['type'] = 'password';
                            $fieldArgs['attrs']['autocomplete'] = 'new-password';
                            $fieldArgs['attrs']['placeholder'] = !empty($mailerProviderHasSecret[$providerId])
                                ? __('•••••••• (saved)', 'traydigita-core')
                                : __('Enter value', 'traydigita-core');
                        }
                        SettingsControls::input($fieldArgs);
                    }
                endforeach;
                if ($providerId === 'gmail') :
                    // Gmail OAuth setup notes: the redirect URI
                    // is read-only (no name, so it never submits)
                    // with the framework copy button, and the
                    // five setup steps sit behind the framework
                    // Help toggle — only one short line is
                    // visible inline. The URI comes from
                    // GmailOAuth::redirectUri(), the same value
                    // the authorization request and the code
                    // exchange send to Google.
                    $gmailRedirectControl = '<span class="td-settings-code-wrap">'
                        . '<input class="traydigita-ui-input traydigita-ui-input-mono" type="text"'
                        . ' id="mailer-gmail-redirect-uri"'
                        . ' value="' . esc_attr($mailerGmailRedirectUri) . '"'
                        . ' readonly spellcheck="false" autocomplete="off">'
                        . SettingsControls::copyButton('mailer-gmail-redirect-uri')
                        . '</span>';
                    SettingsControls::field(
                        'mailer-gmail-redirect-uri',
                        __('Redirect URI', 'traydigita-core'),
                        $gmailRedirectControl,
                        __(
                            'Add this URL as an authorized redirect URI in the Google Cloud OAuth client.',
                            'traydigita-core'
                        )
                    );
                    SettingsControls::about(
                        __('How to connect Gmail', 'traydigita-core'),
                        __(
                            'Create a Google Cloud OAuth app, then paste its client ID and secret into the fields above.',
                            'traydigita-core'
                        ),
                        '<ol>'
                            . '<li>' . esc_html__(
                                'Create a project in the Google Cloud console.',
                                'traydigita-core'
                            ) . '</li>'
                            . '<li>' . esc_html__(
                                'Configure the OAuth consent screen for the project.',
                                'traydigita-core'
                            ) . '</li>'
                            . '<li>' . esc_html__(
                                'Create OAuth credentials of type "Web application".',
                                'traydigita-core'
                            ) . '</li>'
                            . '<li>' . esc_html__('Paste the client ID into "Google OAuth client ID" and the client secret into "Google OAuth client secret" above.', 'traydigita-core') . '</li>'
                            . '<li>' . esc_html__('Copy the redirect URI above and add it as an authorized redirect URI in the OAuth client.', 'traydigita-core') . '</li>'
                            . '</ol>'
                            . '<p>' . esc_html__('Save the settings, then click "Connect Gmail". Google asks for permission to send mail only — the token can never read the mailbox.', 'traydigita-core') . '</p>'
                            . '<p>' . esc_html__('The redirect URI must use https on a public site — Google only accepts plain http on localhost. If the URI above shows http on a live site, switch the site to https first (or serve the admin over https) before registering it in Google Cloud, or Google will reject the callback.', 'traydigita-core') . '</p>'
                            . '<p>' . esc_html__('If the connect fails, check that you are still logged in to this site: when the login session expired while authorizing on Google (or you authorized in another browser), the callback arrives logged out and the connect fails — just log in again and reconnect.', 'traydigita-core') . '</p>'
                    );
                endif;
            endforeach;
            ?>
            <p class="traydigita-mailer-gmail-connect">
                <?php if ($mailerGmailConnected) : ?>
                    <span class="traydigita-mailer-gmail-account">
                        <?php
                        /* translators: %s is the connected Gmail account address. */
                        echo esc_html(sprintf(__('Connected as %s.', 'traydigita-core'), $mailerGmailEmail));
                        ?>
                    </span>
                    <a class="traydigita-admin-button" href="<?php echo esc_url($mailerGmailDisconnectUrl); ?>"><?php esc_html_e('Disconnect Gmail', 'traydigita-core'); ?></a>
                <?php else : ?>
                    <a class="traydigita-admin-button traydigita-admin-button-primary" href="<?php echo esc_url($mailerGmailStartUrl); ?>"><?php esc_html_e('Connect Gmail', 'traydigita-core'); ?></a>
                    <span class="description"><?php esc_html_e('Save the client ID and secret first, then connect. Google asks for permission to send mail only — the token can never read the mailbox.', 'traydigita-core'); ?></span>
                <?php endif; ?>
            </p>
        </section>

        <section id="td-section-from" class="traydigita-ui-section td-settings-section traydigita-mailer-tab-panel" aria-label="<?php esc_attr_e('From', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('From', 'traydigita-core'); ?></h2>

            <?php
            SettingsControls::input([
                'id' => 'mailer-from-name',
                'name' => 'from_name',
                'label' => __('From name', 'traydigita-core'),
                'value' => (string) $mailerSettings['from_name'],
                'attrs' => ['autocomplete' => 'off'],
            ]);
            SettingsControls::input([
                'type' => 'email',
                'id' => 'mailer-from-email',
                'name' => 'from_email',
                'label' => __('From email', 'traydigita-core'),
                'value' => (string) $mailerSettings['from_email'],
                'help' => __('Must be a valid email address. Leave empty to use the WordPress default sender. Gmail always sends from the connected account, whatever is entered here.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'email', 'spellcheck' => 'false'],
            ]);
            ?>
        </section>

        <section id="td-section-headers" class="traydigita-ui-section td-settings-section traydigita-mailer-tab-panel" aria-label="<?php esc_attr_e('Deliverability', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Deliverability headers', 'traydigita-core'); ?></h2>
            <p class="traydigita-mailer-headers-intro">
                <?php esc_html_e('These headers are added to every outgoing WordPress email so it does not land in spam. Each group can be switched off, but leaving them on is recommended.', 'traydigita-core'); ?>
            </p>

            <?php
            SettingsControls::toggle([
                'id' => 'mailer-headers-from',
                'name' => 'headers_from',
                'label' => __('Reply-To aligned with From', 'traydigita-core'),
                'checked' => (bool) ($mailerSettings['headers_from'] ?? true),
                'help' => __('Why: replies go to the site’s From address instead of bouncing from a no-reply address, and a matching Reply-To is a trust signal for spam filters.', 'traydigita-core'),
            ]);
            SettingsControls::toggle([
                'id' => 'mailer-headers-unsubscribe',
                'name' => 'headers_unsubscribe',
                'label' => __('List-Unsubscribe + one-click unsubscribe', 'traydigita-core'),
                'checked' => (bool) ($mailerSettings['headers_unsubscribe'] ?? true),
                'help' => __('Why: mailbox providers show an unsubscribe button instead of a spam button, and Gmail requires a one-click unsubscribe header for bulk senders. The link points to the From address by email, plus the site’s unsubscribe page when a module provides one.', 'traydigita-core'),
            ]);
            SettingsControls::toggle([
                'id' => 'mailer-headers-message-id',
                'name' => 'headers_message_id',
                'label' => __('Unique Message-ID', 'traydigita-core'),
                'checked' => (bool) ($mailerSettings['headers_message_id'] ?? true),
                'help' => __('Why: every mail gets a unique, domain-aligned Message-ID, so providers can thread it, deduplicate it, and trace bounces back to this site.', 'traydigita-core'),
            ]);
            SettingsControls::toggle([
                'id' => 'mailer-headers-x-mailer',
                'name' => 'headers_x_mailer',
                'label' => __('X-Mailer identifier', 'traydigita-core'),
                'checked' => (bool) ($mailerSettings['headers_x_mailer'] ?? true),
                'help' => __('Why: identifies the sending software (TrayDigita Core Mailer) in the headers, which helps postmasters and support diagnose delivery problems.', 'traydigita-core'),
            ]);
            SettingsControls::toggle([
                'id' => 'mailer-headers-auto',
                'name' => 'headers_auto',
                'label' => __('Auto-Submitted + Precedence: bulk (bulk mail only)', 'traydigita-core'),
                'checked' => (bool) ($mailerSettings['headers_auto'] ?? true),
                'help' => __('Why: Auto-Submitted: auto-generated stops auto-replies and out-of-office replies from looping. Bulk mail such as newsletters also gets Precedence: bulk, which tells receivers it is not a one-to-one message.', 'traydigita-core'),
            ]);
            SettingsControls::toggle([
                'id' => 'mailer-headers-feedback',
                'name' => 'headers_feedback',
                'label' => __('Feedback-ID (bulk mail only)', 'traydigita-core'),
                'checked' => (bool) ($mailerSettings['headers_feedback'] ?? true),
                'help' => __('Why: lets the site owner track spam complaints per list in the provider dashboard, so a bad list can be found and fixed instead of hurting the whole domain.', 'traydigita-core'),
            ]);
            ?>
        </section>

        <?php SettingsControls::saveBar(); ?>
    </form>

    <form class="traydigita-mailer-test-form" method="post" data-action="<?php echo esc_attr($mailerTestAction); ?>" data-mailer-test-form>
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($mailerNonceAction)); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr($mailerTestAction); ?>">

        <section id="td-section-test" class="traydigita-ui-section td-settings-section traydigita-mailer-tab-panel" aria-label="<?php esc_attr_e('Test & Log', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Test & Log', 'traydigita-core'); ?></h2>

            <?php if (!$mailerConfigured) : ?>
                <p class="traydigita-mailer-test-note"><?php esc_html_e('Save the provider settings first — the test email can only run once Mailer is configured.', 'traydigita-core'); ?></p>
            <?php else : ?>
                <p class="traydigita-mailer-test-note">
                    <?php if ($selectedProviderProven) : ?>
                        <?php esc_html_e('Verified live: a real test send succeeded with this provider.', 'traydigita-core'); ?>
                    <?php elseif ($selectedProviderReady) : ?>
                        <?php esc_html_e('Ready provider. No live send has been proven for it yet.', 'traydigita-core'); ?>
                    <?php else : ?>
                        <?php esc_html_e('Unverified provider. It keeps sending because it was selected before verification — pick a ready provider to switch.', 'traydigita-core'); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php
            SettingsControls::input([
                'type' => 'email',
                'id' => 'mailer-test-to',
                'name' => 'to',
                'label' => __('Send test email to', 'traydigita-core'),
                'value' => '',
                'attrs' => [
                    'required' => true,
                    'autocomplete' => 'email',
                    'spellcheck' => 'false',
                    'disabled' => !$mailerConfigured,
                ],
            ]);
            SettingsControls::input([
                'id' => 'mailer-test-subject',
                'name' => 'subject',
                'label' => __('Subject', 'traydigita-core'),
                'value' => '',
                'help' => __('Leave empty to use the default test subject.', 'traydigita-core'),
                'attrs' => [
                    'autocomplete' => 'off',
                    'disabled' => !$mailerConfigured,
                ],
            ]);
            SettingsControls::textarea([
                'id' => 'mailer-test-body',
                'name' => 'body',
                'label' => __('Message', 'traydigita-core'),
                'value' => '',
                'help' => __('Leave empty to use the default test message. Limited to one test email every 30 seconds.', 'traydigita-core'),
                'attrs' => [
                    'rows' => '4',
                    'disabled' => !$mailerConfigured,
                ],
            ]);
            ?>

            <p class="traydigita-mailer-form-actions">
                <button class="traydigita-admin-button traydigita-admin-button-secondary" type="submit" <?php disabled(!$mailerConfigured); ?>><?php esc_html_e('Send test email', 'traydigita-core'); ?></button>
            </p>

            <div class="traydigita-mailer-test-result" data-mailer-test-result aria-live="polite"></div>

            <h3 class="traydigita-mailer-log-title"><?php esc_html_e('Send log', 'traydigita-core'); ?></h3>
            <?php if ($mailerSendLog === []) : ?>
                <p class="traydigita-mailer-log-empty"><?php esc_html_e('No mail has been sent through Mailer yet. The last 50 sends appear here with the time, recipient, subject, provider, and result.', 'traydigita-core'); ?></p>
            <?php else : ?>
                <table class="traydigita-mailer-log">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('To', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Subject', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Provider', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Result', 'traydigita-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mailerSendLog as $entry) : ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['time'])); ?></td>
                                <td><?php echo esc_html($entry['to']); ?></td>
                                <td>
                                    <?php echo esc_html($entry['subject']); ?>
                                    <?php if ($entry['error'] !== '') : ?>
                                        <br><span class="traydigita-mailer-log-error"><?php echo esc_html($entry['error']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($providerLabels[$entry['provider']] ?? $entry['provider']); ?></td>
                                <td>
                                    <?php if ($entry['result'] === 'sent') : ?>
                                        <span class="traydigita-mailer-test-badge traydigita-mailer-test-badge-success"><?php esc_html_e('Sent', 'traydigita-core'); ?></span>
                                    <?php else : ?>
                                        <span class="traydigita-mailer-test-badge traydigita-mailer-test-badge-failed"><?php esc_html_e('Failed', 'traydigita-core'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </form>
</div>
