<?php
/**
 * Email module settings UI (rendered inside the admin shell content area).
 *
 * Variables (set by EmailModule::renderSettingsPage()):
 * @var array<string, array<string, mixed>> $templates   Published templates keyed by id (newsletter compose).
 * @var array<string, array<string, mixed>> $allTemplates All templates incl. drafts (list + editor).
 * @var string                               $defaultId  Id of the default template.
 * @var array{double_opt_in: bool, from_name: string, from_email: string} $newsletterSettings Newsletter settings.
 * @var list<array{email: string, name: string, token: string, confirmed: bool, created_at: int}> $subscribers
 * @var list<array{job_id: string, subject: string, total: int, sent: int, failed: int, started_at: int, finished_at: int}> $log
 * @var string                               $adminNonce Nonce for the 'traydigita-admin' action.
 *
 * Tabs: Templates (WYSIWYG builder + list + set default), Newsletter (compose
 * + subscribers + export), Log (send log). The builder is the shared
 * WYSIWYG editor (templates/admin/modules/email-builder.php, driven by
 * assets/js/traydigita-email-builder.js); template create/update/
 * delete, preview and chunked sending run through the EmailAjax
 * endpoints; the default-template choice is also saved through
 * EmailModule::saveSettings() by the shell (data key: default_id).
 *
 * Framework (R4-5): the page renders inside the framework shell
 * (breadcrumb, left navigation, content cards). Its tabs are the
 * module's own JavaScript panels (data-tab), not shell tabs, so
 * the left navigation shows the single module entry and the
 * in-page tab bar is unchanged.
 */
declare(strict_types=1);

?>
<div class="email-module">
    <div class="email-module-tabs" role="tablist">
        <button type="button" class="email-module-tab is-active" data-tab="templates" role="tab">
            <?php esc_html_e('Templates', 'traydigita-core'); ?>
        </button>
        <button type="button" class="email-module-tab" data-tab="newsletter" role="tab">
            <?php esc_html_e('Newsletter', 'traydigita-core'); ?>
        </button>
        <button type="button" class="email-module-tab" data-tab="log" role="tab">
            <?php esc_html_e('Log', 'traydigita-core'); ?>
        </button>
    </div>

    <p class="email-module-message" aria-live="polite"></p>

    <section class="email-module-panel is-active" data-panel="templates">
        <h2 class="email-module-heading"><?php esc_html_e('Email templates', 'traydigita-core'); ?></h2>
        <p class="email-module-description">
            <?php esc_html_e('Build a template from blocks. The template marked as default wraps all WordPress notification emails.', 'traydigita-core'); ?>
        </p>

        <div class="email-template-list">
            <?php if ($allTemplates === []) : ?>
                <p class="email-module-empty"><?php esc_html_e('No templates yet. Build your first template below.', 'traydigita-core'); ?></p>
            <?php endif; ?>
            <?php foreach ($allTemplates as $template) : ?>
                <article class="email-template-card">
                    <h3 class="email-template-name"><?php echo esc_html((string) ($template['name'] ?? '')); ?></h3>
                    <p class="email-template-meta">
                        <?php
                        /* translators: %d: number of blocks in the template. */
                        printf(esc_html(_n('%d block', '%d blocks', count((array) ($template['blocks'] ?? [])), 'traydigita-core')), count((array) ($template['blocks'] ?? [])));
                        ?>
                    </p>
                    <?php if ((string) ($template['id'] ?? '') === $defaultId) : ?>
                        <span class="email-template-badge"><?php esc_html_e('Default', 'traydigita-core'); ?></span>
                    <?php endif; ?>
                    <?php if ((string) ($template['status'] ?? 'published') === 'draft') : ?>
                        <span class="email-template-badge"><?php esc_html_e('Draft', 'traydigita-core'); ?></span>
                    <?php endif; ?>
                    <div class="email-template-actions">
                        <button type="button" class="email-template-load"
                            data-template-id="<?php echo esc_attr((string) ($template['id'] ?? '')); ?>">
                            <?php esc_html_e('Edit', 'traydigita-core'); ?>
                        </button>
                        <?php if ((string) ($template['id'] ?? '') !== $defaultId
                            && (string) ($template['status'] ?? 'published') === 'published'
                        ) : ?>
                            <button type="button" class="email-template-default"
                                data-template-id="<?php echo esc_attr((string) ($template['id'] ?? '')); ?>">
                                <?php esc_html_e('Set as default', 'traydigita-core'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="email-template-delete"
                            data-template-id="<?php echo esc_attr((string) ($template['id'] ?? '')); ?>"
                            data-template-name="<?php echo esc_attr((string) ($template['name'] ?? '')); ?>">
                            <?php esc_html_e('Delete', 'traydigita-core'); ?>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <label class="email-builder-label" for="email-template-name">
            <?php esc_html_e('Template name', 'traydigita-core'); ?>
        </label>
        <input class="email-template-name-input" type="text" id="email-template-name"
            value="" maxlength="100"
            placeholder="<?php echo esc_attr__('e.g. Newsletter default', 'traydigita-core'); ?>" />
        <input type="hidden" id="email-template-id" value="" />

        <?php
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required editor partial below, in the same scope.
        $editorContext = 'newsletter';
        $editorTemplates = $allTemplates;
        $editorInitial = null;
        $editorAjaxUrl = admin_url('admin-ajax.php');
        $editorNonce = $adminNonce;
        $editorNameSelector = '#email-template-name';
        $editorIdSelector = '#email-template-id';
        $editorFormSelector = null;
        $editorSiteName = (string) get_bloginfo('name');
        $editorSiteUrl = home_url('/');
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        require \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/email-builder.php';
        ?>
    </section>

    <section class="email-module-panel" data-panel="newsletter">
        <h2 class="email-module-heading"><?php esc_html_e('Newsletter', 'traydigita-core'); ?></h2>

        <form class="email-newsletter-settings" method="post" data-action="traydigita_admin_save" data-module="email">
            <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr($adminNonce); ?>">
            <input type="hidden" name="module" value="email">
            <h3 class="email-builder-heading"><?php esc_html_e('Newsletter settings', 'traydigita-core'); ?></h3>
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::toggle([
                'id' => 'email-double-opt-in',
                'name' => 'double_opt_in',
                'label' => __('Double opt-in', 'traydigita-core'),
                'checked' => (bool) ($newsletterSettings['double_opt_in'] ?? true),
                'help' => __('When on, new subscribers must confirm by email before they receive newsletters. When off, subscriptions are confirmed immediately (single opt-in).', 'traydigita-core'),
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'id' => 'email-from-name',
                'name' => 'from_name',
                'label' => __('Sender name', 'traydigita-core'),
                'value' => (string) ($newsletterSettings['from_name'] ?? ''),
                'help' => __('Sender identity for newsletter mail. Leave empty to use the Mailer From name.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'off'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'type' => 'email',
                'id' => 'email-from-email',
                'name' => 'from_email',
                'label' => __('Sender email', 'traydigita-core'),
                'value' => (string) ($newsletterSettings['from_email'] ?? ''),
                'help' => __('Sender email for newsletter mail. Leave empty to use the Mailer From email. Gmail always sends from the connected account, whatever is entered here.', 'traydigita-core'),
                'attrs' => ['autocomplete' => 'email', 'spellcheck' => 'false'],
            ]);
            ?>
            <p class="email-module-description">
                <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('Unsubscribe: every newsletter carries an unsubscribe link in its footer, and the Mailer List-Unsubscribe header points to the same link for each subscriber.', 'traydigita-core'),
                    __('Newsletters are sent through the Core Mailer provider and appear in the Mailer send log.', 'traydigita-core')
                );
                ?>
            </p>
            <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
        </form>

        <div class="email-newsletter-compose">
            <h3 class="email-builder-heading"><?php esc_html_e('Compose and send', 'traydigita-core'); ?></h3>
            <label class="email-builder-label" for="email-newsletter-template">
                <?php esc_html_e('Template', 'traydigita-core'); ?>
            </label>
            <select class="traydigita-ui-select email-newsletter-template" id="email-newsletter-template">
                <?php foreach ($templates as $template) : ?>
                    <option value="<?php echo esc_attr((string) ($template['id'] ?? '')); ?>"
                        <?php selected((string) ($template['id'] ?? ''), $defaultId); ?>>
                        <?php echo esc_html((string) ($template['name'] ?? '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="email-builder-label" for="email-newsletter-subject">
                <?php esc_html_e('Subject', 'traydigita-core'); ?>
            </label>
            <input class="email-newsletter-subject" type="text" id="email-newsletter-subject" maxlength="200" />
            <label class="email-builder-label" for="email-newsletter-intro">
                <?php esc_html_e('Intro text (optional)', 'traydigita-core'); ?>
            </label>
            <textarea class="email-newsletter-intro" id="email-newsletter-intro" rows="4"></textarea>
            <p class="email-module-description">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Mails are sent in batches of 25 to confirmed subscribers only.', 'traydigita-core'),
                        __('Unconfirmed addresses never receive newsletters.', 'traydigita-core')
                    );
                    ?>
            </p>
            <button type="button" class="email-newsletter-send"><?php esc_html_e('Send newsletter', 'traydigita-core'); ?></button>
            <div class="email-send-progress" hidden>
                <div class="email-send-progress-bar"><span class="email-send-progress-fill"></span></div>
                <p class="email-send-progress-text"></p>
            </div>
        </div>

        <div class="email-subscribers">
            <h3 class="email-builder-heading">
                <?php esc_html_e('Subscribers', 'traydigita-core'); ?>
                <span class="email-subscriber-count">(<?php echo esc_html((string) count($subscribers)); ?>)</span>
            </h3>
            <a class="email-subscribers-export"
                href="<?php echo esc_url(admin_url('admin-ajax.php?action=traydigita_email_export_csv&nonce=' . $adminNonce)); ?>">
                <?php esc_html_e('Export CSV', 'traydigita-core'); ?>
            </a>
            <form class="email-subscriber-add">
                <input class="email-subscriber-add-email" type="email" required
                    placeholder="<?php echo esc_attr__('Email address', 'traydigita-core'); ?>" />
                <input class="email-subscriber-add-name" type="text"
                    placeholder="<?php echo esc_attr__('Name (optional)', 'traydigita-core'); ?>" />
                <button type="submit"><?php esc_html_e('Add subscriber', 'traydigita-core'); ?></button>
            </form>
            <?php if ($subscribers === []) : ?>
                <p class="email-module-empty"><?php esc_html_e('No subscribers yet.', 'traydigita-core'); ?></p>
            <?php else : ?>
                <table class="email-subscriber-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Email', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Name', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Subscribed', 'traydigita-core'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscribers as $subscriber) : ?>
                            <tr>
                                <td><?php echo esc_html($subscriber['email']); ?></td>
                                <td><?php echo esc_html($subscriber['name']); ?></td>
                                <td>
                                    <?php if ($subscriber['confirmed']) : ?>
                                        <span class="email-subscriber-badge is-confirmed"><?php esc_html_e('Confirmed', 'traydigita-core'); ?></span>
                                    <?php else : ?>
                                        <span class="email-subscriber-badge is-pending"><?php esc_html_e('Pending', 'traydigita-core'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $subscriber['created_at'] > 0 ? esc_html(date_i18n(get_option('date_format'), $subscriber['created_at'])) : '&mdash;'; ?>
                                </td>
                                <td>
                                    <button type="button" class="email-subscriber-remove"
                                        data-email="<?php echo esc_attr($subscriber['email']); ?>">
                                        <?php esc_html_e('Remove', 'traydigita-core'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <p class="email-module-description">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Public subscribe form: visitors confirm via a double opt-in email.', 'traydigita-core'),
                        __('Themes and widgets can print it with EmailModule::subscribeFormHtml().', 'traydigita-core')
                    );
                    ?>
            </p>
        </div>
    </section>

    <section class="email-module-panel" data-panel="log">
        <h2 class="email-module-heading"><?php esc_html_e('Send log', 'traydigita-core'); ?></h2>
        <?php if ($log === []) : ?>
            <p class="email-module-empty"><?php esc_html_e('No newsletters sent yet.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="email-log-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Subject', 'traydigita-core'); ?></th>
                        <th><?php esc_html_e('Sent', 'traydigita-core'); ?></th>
                        <th><?php esc_html_e('Failed', 'traydigita-core'); ?></th>
                        <th><?php esc_html_e('Finished', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['subject']); ?></td>
                            <td><?php echo esc_html((string) $entry['sent'] . ' / ' . (string) $entry['total']); ?></td>
                            <td><?php echo esc_html((string) $entry['failed']); ?></td>
                            <td>
                                <?php echo $entry['finished_at'] > 0 ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry['finished_at'])) : '&mdash;'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    'use strict';
    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var nonce = <?php echo wp_json_encode($adminNonce); ?>;
    var root = document.querySelector('.email-module');
    if (!root) {
        return;
    }
    var messageEl = root.querySelector('.email-module-message');

    function showMessage(text, isError) {
        messageEl.textContent = text;
        messageEl.classList.toggle('is-error', !!isError);
    }

    function post(action, fields) {
        var body = new FormData();
        body.append('action', action);
        body.append('_ajax_nonce', nonce);
        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });
        return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); });
    }

    function reloadAfterSuccess(result) {
        if (result && result.success) {
            window.location.reload();
            return true;
        }
        showMessage((result && result.data && result.data.message) || 'Error', true);
        return false;
    }

    /* Tabs. */
    root.querySelectorAll('.email-module-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            root.querySelectorAll('.email-module-tab').forEach(function (t) {
                t.classList.toggle('is-active', t === tab);
            });
            root.querySelectorAll('.email-module-panel').forEach(function (panel) {
                panel.classList.toggle('is-active', panel.dataset.panel === tab.dataset.tab);
            });
        });
    });

    /* Template list actions. Edit is handled by the
     * WYSIWYG builder script (traydigita-email-builder.js). */
    root.querySelectorAll('.email-template-default').forEach(function (button) {
        button.addEventListener('click', function () {
            post('traydigita_email_set_default', { template_id: button.dataset.templateId })
                .then(reloadAfterSuccess);
        });
    });

    root.querySelectorAll('.email-template-delete').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm('Delete template "' + button.dataset.templateName + '"?')) {
                return;
            }
            post('traydigita_email_delete_template', { template_id: button.dataset.templateId })
                .then(reloadAfterSuccess);
        });
    });

    /* Newsletter send (chunked, 25 mails per request). */
    var progressBox = root.querySelector('.email-send-progress');
    var progressFill = root.querySelector('.email-send-progress-fill');
    var progressText = root.querySelector('.email-send-progress-text');

    function pollChunk(jobId) {
        post('traydigita_email_send_chunk', { job_id: jobId }).then(function (result) {
            if (!result || !result.success) {
                showMessage((result && result.data && result.data.message) || 'Error', true);
                progressBox.hidden = true;
                return;
            }
            var data = result.data;
            var percent = data.total > 0 ? Math.round((data.sent + data.failed) / data.total * 100) : 100;
            progressFill.style.width = percent + '%';
            progressText.textContent = data.sent + ' / ' + data.total + ' sent'
                + (data.failed > 0 ? ' (' + data.failed + ' failed)' : '');
            if (data.done) {
                showMessage('Newsletter sent: ' + data.sent + ' of ' + data.total + ' delivered.');
                window.setTimeout(function () { window.location.reload(); }, 1500);
                return;
            }
            window.setTimeout(function () { pollChunk(jobId); }, 1200);
        });
    }

    root.querySelector('.email-newsletter-send').addEventListener('click', function () {
        var subject = root.querySelector('.email-newsletter-subject').value.trim();
        var templateId = root.querySelector('.email-newsletter-template').value;
        if (!subject) {
            showMessage('Please enter a subject.', true);
            return;
        }
        if (!templateId) {
            showMessage('Please create a template first.', true);
            return;
        }
        progressBox.hidden = false;
        progressFill.style.width = '0%';
        progressText.textContent = 'Starting…';
        post('traydigita_email_send_start', {
            subject: subject,
            template_id: templateId,
            intro: root.querySelector('.email-newsletter-intro').value
        }).then(function (result) {
            if (!result || !result.success) {
                showMessage((result && result.data && result.data.message) || 'Error', true);
                progressBox.hidden = true;
                return;
            }
            if (result.data.total === 0) {
                showMessage('No confirmed subscribers to send to.', true);
                progressBox.hidden = true;
                return;
            }
            pollChunk(result.data.job_id);
        });
    });

    /* Subscriber management. */
    var addForm = root.querySelector('.email-subscriber-add');
    if (addForm) {
        addForm.addEventListener('submit', function (event) {
            event.preventDefault();
            post('traydigita_email_add_subscriber', {
                email: addForm.querySelector('.email-subscriber-add-email').value,
                name: addForm.querySelector('.email-subscriber-add-name').value
            }).then(reloadAfterSuccess);
        });
    }
    root.querySelectorAll('.email-subscriber-remove').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!window.confirm('Remove subscriber ' + button.dataset.email + '?')) {
                return;
            }
            post('traydigita_email_remove_subscriber', { email: button.dataset.email })
                .then(reloadAfterSuccess);
        });
    });

})();
</script>
