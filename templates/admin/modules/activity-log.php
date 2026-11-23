<?php
/**
 * Activity Log module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by ActivityLogModule::renderSettingsPage()):
 * @var array{max_rows: int, retention_days: int} $activitySettings Retention settings.
 * @var bool $activityTableExists Whether the log table exists.
 * @var int $activityTableVersion Installed table schema version (0 when never created).
 * @var int $activityRowCount Total rows in the log.
 * @var array<int, array<string, mixed>> $activityRows Rows for the current page (newest first).
 * @var int $activityTotal Rows matching the current filters.
 * @var int $activityPage Current viewer page.
 * @var int $activityPerPage Rows per viewer page.
 * @var array{module: string, event_type: string, user_id: int, from: string, to: string, search: string, page: int, per_page: int} $activityFilters Current filter values.
 * @var array<int, string> $activityModules Modules present in the log.
 * @var array<int, string> $activityEvents Event types present in the log.
 * @var array<int, string> $activityUsers User ids present in the log, mapped to display names.
 * @var string $activityNonceAction Nonce action (`traydigita-admin`).
 * @var string $activityCreateAction AJAX action that creates the table (`traydigita_setup_create_activity_log`).
 * @var string $activityDeleteAction AJAX action that deletes one row.
 * @var string $activityClearAction AJAX action that clears the whole log.
 * @var string $activitySaveAction AJAX action for the shell generic save endpoint (`traydigita_admin_save`).
 *
 * Forms:
 * - Retention form: posts to the shell generic save endpoint
 *   (`data-action` + `data-module="activity-log"`); the shell
 *   passes the fields to ActivityLogModule::saveSettings().
 * - Filter form: a plain GET form back to this page; the
 *   viewer reads the filters from the URL.
 * - Row delete / clear-all: buttons handled by
 *   resources/js/modules/admin-activity-log.ts (fetch to
 *   admin-ajax.php with the nonce); both carry a `data-confirm`
 *   message, so the browser asks once more before anything is
 *   deleted. The create-table button posts to the Setup
 *   create-table endpoint the same way.
 */
declare(strict_types=1);

$totalPages = $activityPerPage > 0 ? (int) ceil($activityTotal / $activityPerPage) : 1;
if ($totalPages < 1) {
    $totalPages = 1;
}
$filterArgs = [];
foreach (['module' => 'log_module', 'event_type' => 'log_event', 'from' => 'log_from', 'to' => 'log_to', 'search' => 'log_search'] as $key => $param) {
    $value = (string) ($activityFilters[$key] ?? '');
    if ($value !== '') {
        $filterArgs[$param] = $value;
    }
}
if ((int) $activityFilters['user_id'] > 0) {
    $filterArgs['log_user'] = (string) (int) $activityFilters['user_id'];
}
$baseUrl = admin_url('admin.php?page=' . \TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule::settingsPageSlug());
?>
<div class="traydigita-activity-log-settings" data-activity-log-admin
    data-ajax-url="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>"
    data-nonce="<?php echo esc_attr(wp_create_nonce($activityNonceAction)); ?>"
    data-action-create="<?php echo esc_attr($activityCreateAction); ?>"
    data-action-delete="<?php echo esc_attr($activityDeleteAction); ?>"
    data-action-clear="<?php echo esc_attr($activityClearAction); ?>"
    data-msg-creating="<?php echo esc_attr(__('Creating the log table…', 'traydigita-core')); ?>"
    data-msg-deleting="<?php echo esc_attr(__('Deleting…', 'traydigita-core')); ?>"
    data-msg-clearing="<?php echo esc_attr(__('Clearing the log…', 'traydigita-core')); ?>"
    data-msg-error="<?php echo esc_attr(__('Something went wrong. Please try again.', 'traydigita-core')); ?>">
    <div class="traydigita-activity-log-status" role="status">
        <?php if ($activityTableExists) : ?>
            <span class="traydigita-activity-log-badge traydigita-activity-log-badge-ok"><?php esc_html_e('Table ready', 'traydigita-core'); ?></span>
            <p class="traydigita-activity-log-status-text">
                <?php
                /* translators: 1: number of log rows, 2: table schema version. */
                echo esc_html(sprintf(_n('%1$d entry logged (table version %2$d).', '%1$d entries logged (table version %2$d).', $activityRowCount, 'traydigita-core'), $activityRowCount, $activityTableVersion));
                ?>
            </p>
        <?php else : ?>
            <span class="traydigita-activity-log-badge traydigita-activity-log-badge-missing"><?php esc_html_e('Table missing', 'traydigita-core'); ?></span>
            <p class="traydigita-activity-log-status-text"><?php esc_html_e('The log table does not exist yet, so nothing is being recorded. Create it below — events are stored from then on, and a missing table heals itself the next time an event is logged.', 'traydigita-core'); ?></p>
            <p class="traydigita-activity-log-form-actions">
                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-activity-log-create-table><?php esc_html_e('Create the log table', 'traydigita-core'); ?></button>
                <span class="traydigita-activity-log-action-status" data-activity-log-action-status role="status"></span>
            </p>
        <?php endif; ?>
    </div>

    <form class="traydigita-activity-log-filters" method="get" action="<?php echo esc_url($baseUrl); ?>">
        <input type="hidden" name="page" value="<?php echo esc_attr(\TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule::settingsPageSlug()); ?>">
        <p class="traydigita-activity-log-field">
            <label for="activity-log-filter-module"><?php esc_html_e('Module', 'traydigita-core'); ?></label>
            <select id="activity-log-filter-module" name="log_module">
                <option value=""><?php esc_html_e('All modules', 'traydigita-core'); ?></option>
                <?php foreach ($activityModules as $module) : ?>
                    <option value="<?php echo esc_attr($module); ?>" <?php selected($activityFilters['module'], $module); ?>><?php echo esc_html($module); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="traydigita-activity-log-field">
            <label for="activity-log-filter-event"><?php esc_html_e('Event', 'traydigita-core'); ?></label>
            <select id="activity-log-filter-event" name="log_event">
                <option value=""><?php esc_html_e('All events', 'traydigita-core'); ?></option>
                <?php foreach ($activityEvents as $event) : ?>
                    <option value="<?php echo esc_attr($event); ?>" <?php selected($activityFilters['event_type'], $event); ?>><?php echo esc_html($event); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="traydigita-activity-log-field">
            <label for="activity-log-filter-user"><?php esc_html_e('User', 'traydigita-core'); ?></label>
            <select id="activity-log-filter-user" name="log_user">
                <option value="0"><?php esc_html_e('All users', 'traydigita-core'); ?></option>
                <?php foreach ($activityUsers as $userId => $displayName) : ?>
                    <option value="<?php echo esc_attr((string) $userId); ?>" <?php selected((int) $activityFilters['user_id'], (int) $userId); ?>><?php echo esc_html($displayName); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="traydigita-activity-log-field">
            <label for="activity-log-filter-from"><?php esc_html_e('From', 'traydigita-core'); ?></label>
            <input type="date" id="activity-log-filter-from" name="log_from" value="<?php echo esc_attr($activityFilters['from']); ?>">
        </p>
        <p class="traydigita-activity-log-field">
            <label for="activity-log-filter-to"><?php esc_html_e('To', 'traydigita-core'); ?></label>
            <input type="date" id="activity-log-filter-to" name="log_to" value="<?php echo esc_attr($activityFilters['to']); ?>">
        </p>
        <p class="traydigita-activity-log-field">
            <label for="activity-log-filter-search"><?php esc_html_e('Search', 'traydigita-core'); ?></label>
            <input type="search" id="activity-log-filter-search" name="log_search" value="<?php echo esc_attr($activityFilters['search']); ?>" autocomplete="off">
        </p>
        <p class="traydigita-activity-log-form-actions">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Filter', 'traydigita-core'); ?></button>
            <a class="traydigita-admin-button" href="<?php echo esc_url($baseUrl); ?>"><?php esc_html_e('Clear filters', 'traydigita-core'); ?></a>
        </p>
    </form>

    <?php if ($activityRows === []) : ?>
        <p class="traydigita-activity-log-empty"><?php esc_html_e('No log entries match the current filters.', 'traydigita-core'); ?></p>
    <?php else : ?>
        <table class="traydigita-activity-log-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Time', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('User', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Module', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Event', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Message', 'traydigita-core'); ?></th>
                    <th><span class="screen-reader-text"><?php esc_html_e('Actions', 'traydigita-core'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activityRows as $row) : ?>
                    <tr data-activity-log-row>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime((string) ($row['created_at'] ?? '')) ?: time())); ?></td>
                        <td><?php echo esc_html((int) ($row['user_id'] ?? 0) > 0 ? ($activityUsers[(int) $row['user_id']] ?? sprintf('User #%d', (int) $row['user_id'])) : '—'); ?></td>
                        <td><?php echo esc_html((string) ($row['module'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['event_type'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['message'] ?? '')); ?></td>
                        <td>
                            <button type="button" class="traydigita-admin-button traydigita-activity-log-delete"
                                data-activity-log-delete data-id="<?php echo esc_attr((string) ($row['id'] ?? 0)); ?>"
                                data-confirm="<?php esc_attr_e('Delete this log entry?', 'traydigita-core'); ?>">
                                <?php esc_html_e('Delete', 'traydigita-core'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <nav class="traydigita-activity-log-pagination" aria-label="<?php esc_attr_e('Log pages', 'traydigita-core'); ?>">
            <span class="traydigita-activity-log-page-info">
                <?php
                /* translators: 1: current page, 2: total pages, 3: total entries. */
                echo esc_html(sprintf(__('Page %1$d of %2$d (%3$d entries)', 'traydigita-core'), $activityPage, $totalPages, $activityTotal));
                ?>
            </span>
            <?php if ($activityPage > 1) : ?>
                <a class="traydigita-admin-button" href="<?php echo esc_url(add_query_arg(array_merge($filterArgs, ['log_page' => $activityPage - 1]), $baseUrl)); ?>"><?php esc_html_e('Previous', 'traydigita-core'); ?></a>
            <?php endif; ?>
            <?php if ($activityPage < $totalPages) : ?>
                <a class="traydigita-admin-button" href="<?php echo esc_url(add_query_arg(array_merge($filterArgs, ['log_page' => $activityPage + 1]), $baseUrl)); ?>"><?php esc_html_e('Next', 'traydigita-core'); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <p class="traydigita-activity-log-form-actions">
        <button type="button" class="traydigita-admin-button"
            data-activity-log-clear
            data-confirm="<?php esc_attr_e('Delete all log entries? This cannot be undone.', 'traydigita-core'); ?>">
            <?php esc_html_e('Clear the log', 'traydigita-core'); ?>
        </button>
        <span class="traydigita-activity-log-action-status" data-activity-log-action-status role="status"></span>
    </p>

    <form class="traydigita-activity-log-retention-form" method="post" data-action="<?php echo esc_attr($activitySaveAction); ?>" data-module="activity-log">
        <input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr(wp_create_nonce($activityNonceAction)); ?>">
        <input type="hidden" name="module" value="activity-log">

        <section id="td-section-retention" class="traydigita-ui-section td-settings-section" aria-label="<?php esc_attr_e('Retention', 'traydigita-core'); ?>">
            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Retention', 'traydigita-core'); ?></h2>

            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                'id' => 'activity-log-max-rows',
                'name' => 'max_rows',
                'label' => __('Maximum entries kept', 'traydigita-core'),
                'value' => (string) $activitySettings['max_rows'],
                'help' => __('When the log grows past this many entries, the oldest ones are deleted automatically. From 500 to 20,000.', 'traydigita-core'),
                'attrs' => ['min' => '500', 'max' => '20000', 'step' => '1'],
            ]);
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::number([
                'id' => 'activity-log-retention-days',
                'name' => 'retention_days',
                'label' => __('Keep entries for (days)', 'traydigita-core'),
                'value' => (string) $activitySettings['retention_days'],
                'help' => __('Entries older than this many days are deleted automatically. 0 keeps entries by count only. At most 365 days.', 'traydigita-core'),
                'attrs' => ['min' => '0', 'max' => '365', 'step' => '1'],
            ]);
            ?>
        </section>

        <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
    </form>
</div>
