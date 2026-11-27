<?php
/**
 * Security Monitor settings page.
 *
 * Rendered inside the admin shell content area by
 * SecurityModule::renderSettingsPage().
 *
 * Variables:
 * @var array<int, array<string, mixed>> $checks Check results from
 *     Checks::run(), each with `id`, `title`, `status` (`pass`,
 *     `warning`, `fail`), `explanation`, `fix`, optional `code`,
 *     and optional `details` (file-permissions breakdown).
 * @var array{pass: int, warning: int, fail: int} $summary Status counts.
 * @var string $recheckAction AJAX action for the Re-check button
 *     (`traydigita_security_monitor_recheck`).
 *
 * Checks only: this page never changes anything. Every fix is a
 * manual step; the code snippets are copyable, and the page says
 * why wp-config.php is never edited automatically. No fix is
 * offered as a button.
 *
 * The Re-check button is a plain button (type="button"), not a
 * form submit: resources/js/modules/admin-security-monitor.ts
 * posts it to admin-ajax.php with the shell nonce and re-renders
 * the check list from the JSON response without a reload.
 * There is deliberately no save form on this page: the module
 * keeps no settings, so a save button would be a no-op - the
 * shell's generic save endpoint still accepts the module (see
 * SecurityModule::saveSettings()) for the dispatcher contract.
 */
declare(strict_types=1);

$statusLabels = [
    'pass' => __('Passed', 'traydigita-core'),
    'warning' => __('Warning', 'traydigita-core'),
    'fail' => __('Failed', 'traydigita-core'),
];
?>
<div class="traydigita-module-settings security-monitor-settings"
    data-traydigita-module="security-monitor"
    data-security-monitor
    data-recheck-action="<?php echo esc_attr($recheckAction); ?>"
    data-msg-rechecking="<?php echo esc_attr(__('Checking…', 'traydigita-core')); ?>"
    data-msg-error="<?php echo esc_attr(__('Request failed. Please try again.', 'traydigita-core')); ?>"
    data-msg-copied="<?php echo esc_attr(__('Copied to clipboard.', 'traydigita-core')); ?>"
    data-msg-copy="<?php echo esc_attr(__('Copy', 'traydigita-core')); ?>"
    data-msg-fix="<?php echo esc_attr(__('Recommended fix', 'traydigita-core')); ?>"
    data-msg-status-pass="<?php echo esc_attr(__('Passed', 'traydigita-core')); ?>"
    data-msg-status-warning="<?php echo esc_attr(__('Warning', 'traydigita-core')); ?>"
    data-msg-status-fail="<?php echo esc_attr(__('Failed', 'traydigita-core')); ?>"
    data-msg-checked-at="<?php echo esc_attr(__('Last checked: %s', 'traydigita-core')); ?>">
    <?php
    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::intro(
        __('This module only checks — it never changes anything on your site.', 'traydigita-core'),
        __('Each check below shows its status, what it means in plain language, and how to fix it by hand.', 'traydigita-core')
    );
    ?>
    <p class="traydigita-module-intro security-monitor-note">
        <?php
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
            __('Why nothing is fixed automatically: wp-config.php is outside WordPress’ control, and one typo there can white-screen the whole site.', 'traydigita-core'),
            __('File permissions and user accounts are the same — the web server may not own those files, and deleting a user cannot be undone. So every fix is a manual step, with the exact code shown for you to copy.', 'traydigita-core')
        );
        ?>
    </p>
    <p class="traydigita-module-intro security-monitor-note">
        <?php
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
            __('The Security Headers module is separate: it sends HTTP response headers, while this module only audits.', 'traydigita-core'),
            __('The two do not overlap or conflict.', 'traydigita-core')
        );
        ?>
    </p>

    <p class="security-monitor-summary" data-security-monitor-summary>
        <?php
        echo esc_html(
            sprintf(
                /* translators: %1$d passed checks, %2$d warnings, %3$d failed checks. */
                __('%1$d passed · %2$d warnings · %3$d failed', 'traydigita-core'),
                $summary['pass'],
                $summary['warning'],
                $summary['fail']
            )
        );
        ?>
    </p>
    <p class="security-monitor-checked-at" data-security-monitor-checked-at>
        <?php
        echo esc_html(
            sprintf(
                /* translators: %s is the date and time the checks last ran. */
                __('Last checked: %s', 'traydigita-core'),
                date_i18n(get_option('date_format') . ' ' . get_option('time_format'))
            )
        );
        ?>
    </p>

    <ul class="security-monitor-list" id="td-section-checks" data-security-monitor-results>
        <?php foreach ($checks as $check) : ?>
            <?php $status = isset($check['status']) && is_string($check['status']) ? $check['status'] : 'warning'; ?>
            <li class="security-monitor-check security-monitor-check-<?php echo esc_attr($status); ?>"
                data-check-id="<?php echo esc_attr((string) ($check['id'] ?? '')); ?>">
                <p class="security-monitor-check-title">
                    <span class="security-monitor-badge security-monitor-badge-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html($statusLabels[$status] ?? $statusLabels['warning']); ?>
                    </span>
                    <?php echo esc_html((string) ($check['title'] ?? '')); ?>
                </p>
                <p class="security-monitor-check-explanation">
                    <?php echo esc_html((string) ($check['explanation'] ?? '')); ?>
                </p>
                <?php if (!empty($check['details']) && is_array($check['details'])) : ?>
                    <ul class="security-monitor-details">
                        <?php foreach ($check['details'] as $detail) : ?>
                            <li class="security-monitor-detail security-monitor-detail-<?php echo esc_attr((string) ($detail['status'] ?? 'warning')); ?>">
                                <code><?php echo esc_html((string) ($detail['label'] ?? '')); ?></code>
                                <?php if (!empty($detail['perms'])) : ?>
                                    <code><?php echo esc_html((string) $detail['perms']); ?></code>
                                <?php endif; ?>
                                <span><?php echo esc_html((string) ($detail['note'] ?? '')); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="security-monitor-check-fix">
                    <strong><?php esc_html_e('Recommended fix', 'traydigita-core'); ?>:</strong>
                    <?php echo esc_html((string) ($check['fix'] ?? '')); ?>
                </p>
                <?php if (!empty($check['code']) && is_string($check['code'])) : ?>
                    <div class="security-monitor-code">
                        <pre><code><?php echo esc_html($check['code']); ?></code></pre>
                        <button type="button" class="traydigita-admin-button security-monitor-copy" data-security-monitor-copy>
                            <?php esc_html_e('Copy', 'traydigita-core'); ?>
                        </button>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="traydigita-module-actions security-monitor-actions">
        <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-security-monitor-recheck>
            <?php esc_html_e('Re-check now', 'traydigita-core'); ?>
        </button>
        <span class="security-monitor-status" data-security-monitor-status aria-live="polite"></span>
    </div>
</div>
