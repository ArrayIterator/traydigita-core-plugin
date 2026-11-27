<?php
/**
 * Agenda module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by AgendaModule::renderSettingsPage()):
 * @var array{archive_per_page: int, show_past_in_archive: bool, ics_enabled: bool} $agendaSettings Module settings.
 * @var bool $agendaTableReady Whether the agenda table exists.
 * @var string $agendaFilter Current list filter ('' or one of the AgendaTable STATUS_* values).
 * @var string $agendaSearch Current search term.
 * @var int $agendaPage Current list page.
 * @var int $agendaEditId Event id being edited (0 when adding).
 * @var array<string, mixed>|null $agendaEditEvent Event row being edited, or null.
 * @var array<int, array<string, mixed>> $agendaEvents Events on this page.
 * @var int $agendaTotal Total events matching the filter.
 * @var int $agendaPages Total list pages.
 * @var array<int, string> $agendaCategories Distinct categories.
 * @var string $agendaArchiveUrl Frontend archive URL.
 * @var string $agendaSaveAction AJAX action saving one event (`traydigita_agenda_save`).
 * @var string $agendaDeleteAction AJAX action deleting one event (`traydigita_agenda_delete`).
 * @var string $agendaDeleteAllAction AJAX action deleting all agenda data (`traydigita_agenda_delete_all`).
 * @var string $agendaDeleteAllWord Typed confirmation word for deleting all data (`DELETE`).
 * @var string $agendaNonceAction Nonce action (`traydigita-admin`).
 *
 * Behaviour:
 * - Settings form: posts to the shell generic save endpoint
 *   (`data-traydigita-module-form` + `data-module="agenda"`).
 *   Field names are flat: `archive_per_page`,
 *   `show_past_in_archive`, `ics_enabled`. The hidden `0` field
 *   before each checkbox guarantees the field is always submitted.
 * - Event form: posts to the module's OWN AJAX action
 *   (`data-agenda-event-form`, handled by admin-agenda.ts) - NOT
 *   the generic dispatcher - because the description is
 *   multi-line text and the dispatcher's recursive
 *   sanitize_text_field() would collapse its newlines.
 * - List: filter links (all / upcoming / ongoing / past) plus a
 *   GET search form; edit links reload this page with
 *   `?edit=<id>`; delete buttons ask for confirmation in JS and
 *   delete via AJAX without a reload.
 * - Danger zone: deleting ALL agenda data requires typing DELETE
 *   (the word is rendered as a data attribute so the script can
 *   compare it); deactivating the module never deletes data.
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Admin\AdminNotice;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaTable;

$agendaEditStart = '';
$agendaEditEnd = '';
if ($agendaEditEvent !== null) {
    $start = AgendaTable::parseStoredDatetime((string) ($agendaEditEvent['start_datetime'] ?? ''));
    $agendaEditStart = $start !== null ? AgendaTable::formatForInput($start) : '';
    $endRaw = (string) ($agendaEditEvent['end_datetime'] ?? '');
    if ($endRaw !== '') {
        $end = AgendaTable::parseStoredDatetime($endRaw);
        $agendaEditEnd = $end !== null ? AgendaTable::formatForInput($end) : '';
    }
}
$agendaBaseUrl = admin_url('admin.php?page=traydigita-agenda');
?>
<div
    class="traydigita-agenda-settings agenda-admin"
    data-agenda-admin
    data-save-action="<?php echo esc_attr($agendaSaveAction); ?>"
    data-delete-action="<?php echo esc_attr($agendaDeleteAction); ?>"
    data-delete-all-action="<?php echo esc_attr($agendaDeleteAllAction); ?>"
    data-confirm-word="<?php echo esc_attr($agendaDeleteAllWord); ?>"
    data-msg-saved="<?php esc_attr_e('Event saved.', 'traydigita-core'); ?>"
    data-msg-save-error="<?php esc_attr_e('The event could not be saved. Please check the form.', 'traydigita-core'); ?>"
    data-msg-deleted="<?php esc_attr_e('Event deleted.', 'traydigita-core'); ?>"
    data-msg-delete-confirm="<?php esc_attr_e('Delete this event?', 'traydigita-core'); ?>"
    data-msg-delete-error="<?php esc_attr_e('The event could not be deleted.', 'traydigita-core'); ?>"
    data-msg-delete-all-wrong-word="<?php esc_attr_e('Type DELETE to confirm deleting all agenda data.', 'traydigita-core'); ?>"
    data-msg-delete-all-done="<?php esc_attr_e('All agenda data has been deleted.', 'traydigita-core'); ?>"
    data-msg-delete-all-error="<?php esc_attr_e('Agenda data could not be deleted.', 'traydigita-core'); ?>"
    data-msg-cover-title="<?php esc_attr_e('Choose a cover image', 'traydigita-core'); ?>"
    data-msg-cover-select="<?php esc_attr_e('Use as cover', 'traydigita-core'); ?>"
>
    <?php if (!$agendaTableReady) : ?>
        <?php
        AdminNotice::render(
            'error',
            __('The agenda table could not be created. Events cannot be saved until the database allows creating the table.', 'traydigita-core')
        );
        ?>
    <?php endif; ?>

    <p class="agenda-admin-intro">
        <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                __('Events are stored in their own table, separate from posts.', 'traydigita-core'),
                __('Status (upcoming, ongoing, past) is computed from the start and end times.', 'traydigita-core')
            );
            ?>
        <a href="<?php echo esc_url($agendaArchiveUrl); ?>" target="_blank" rel="noopener">
            <?php esc_html_e('View the public agenda', 'traydigita-core'); ?>
        </a>
    </p>

    <form class="agenda-settings-form" data-traydigita-module-form data-traydigita-module="agenda" data-module="agenda" method="post" action="">
        <p class="agenda-field">
            <label for="agenda-archive-per-page"><?php esc_html_e('Events per archive page', 'traydigita-core'); ?></label>
            <input type="number" id="agenda-archive-per-page" name="archive_per_page" value="<?php echo esc_attr((string) $agendaSettings['archive_per_page']); ?>" min="1" max="100" step="1">
        </p>
        <p class="agenda-field">
            <input type="hidden" name="show_past_in_archive" value="0">
            <label class="traydigita-ui-toggle" for="agenda-show-past"><input class="traydigita-ui-toggle-input" type="checkbox" id="agenda-show-past" name="show_past_in_archive" value="1" <?php checked($agendaSettings['show_past_in_archive']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show past events in the unfiltered archive', 'traydigita-core'); ?></span></label>
        </p>
        <p class="agenda-field">
            <input type="hidden" name="ics_enabled" value="0">
            <label class="traydigita-ui-toggle" for="agenda-ics-enabled"><input class="traydigita-ui-toggle-input" type="checkbox" id="agenda-ics-enabled" name="ics_enabled" value="1" <?php checked($agendaSettings['ics_enabled']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Enable iCal (.ics) download per event', 'traydigita-core'); ?></span></label>
        </p>
        <p class="agenda-form-actions">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary"><?php esc_html_e('Save changes', 'traydigita-core'); ?></button>
        </p>
    </form>

    <h2 id="td-section-event"><?php echo $agendaEditEvent !== null ? esc_html__('Edit event', 'traydigita-core') : esc_html__('Add event', 'traydigita-core'); ?></h2>
    <form class="agenda-event-form" data-agenda-event-form method="post" action="">
        <input type="hidden" name="id" value="<?php echo esc_attr((string) ($agendaEditEvent['id'] ?? 0)); ?>">
        <p class="agenda-field">
            <label for="agenda-event-title"><?php esc_html_e('Title', 'traydigita-core'); ?></label>
            <input type="text" id="agenda-event-title" name="title" class="traydigita-ui-textarea" required maxlength="255" value="<?php echo esc_attr((string) ($agendaEditEvent['title'] ?? '')); ?>">
        </p>
        <p class="agenda-field">
            <label for="agenda-event-description"><?php esc_html_e('Description', 'traydigita-core'); ?></label>
            <textarea id="agenda-event-description" name="description" class="traydigita-ui-textarea" rows="4"><?php echo esc_textarea((string) ($agendaEditEvent['description'] ?? '')); ?></textarea>
        </p>
        <p class="agenda-field">
            <label for="agenda-event-start"><?php esc_html_e('Start date and time', 'traydigita-core'); ?></label>
            <input type="datetime-local" id="agenda-event-start" name="start_datetime" required value="<?php echo esc_attr($agendaEditStart); ?>">
        </p>
        <p class="agenda-field">
            <label for="agenda-event-end"><?php esc_html_e('End date and time (optional)', 'traydigita-core'); ?></label>
            <input type="datetime-local" id="agenda-event-end" name="end_datetime" value="<?php echo esc_attr($agendaEditEnd); ?>">
            <span class="agenda-field-help"><?php esc_html_e('Leave empty for an event with no end time. An event without an end is past from its start time onward.', 'traydigita-core'); ?></span>
        </p>
        <p class="agenda-field">
            <label for="agenda-event-location"><?php esc_html_e('Location', 'traydigita-core'); ?></label>
            <input type="text" id="agenda-event-location" name="location" class="traydigita-ui-textarea" maxlength="255" value="<?php echo esc_attr((string) ($agendaEditEvent['location'] ?? '')); ?>">
        </p>
        <p class="agenda-field">
            <label for="agenda-event-category"><?php esc_html_e('Category', 'traydigita-core'); ?></label>
            <input type="text" id="agenda-event-category" name="category" class="traydigita-ui-textarea" maxlength="100" list="agenda-category-list" value="<?php echo esc_attr((string) ($agendaEditEvent['category'] ?? '')); ?>">
            <datalist id="agenda-category-list">
                <?php foreach ($agendaCategories as $category) : ?>
                    <option value="<?php echo esc_attr($category); ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </p>
        <p class="agenda-field">
            <label for="agenda-event-cover"><?php esc_html_e('Cover image (media library id)', 'traydigita-core'); ?></label>
            <input type="number" id="agenda-event-cover" name="cover_image_id" min="0" step="1" value="<?php echo esc_attr((string) ($agendaEditEvent['cover_image_id'] ?? 0)); ?>">
            <button type="button" class="traydigita-admin-button" data-agenda-cover-picker><?php esc_html_e('Choose from media library', 'traydigita-core'); ?></button>
            <?php if ($agendaEditEvent !== null && (int) ($agendaEditEvent['cover_image_id'] ?? 0) > 0) : ?>
                <span class="agenda-cover-preview"><?php echo AgendaRenderer::coverHtml($agendaEditEvent, 'thumbnail'); ?></span>
            <?php endif; ?>
        </p>
        <p class="agenda-field">
            <label for="agenda-event-link"><?php esc_html_e('Link (optional)', 'traydigita-core'); ?></label>
            <input type="url" id="agenda-event-link" name="link" class="traydigita-ui-textarea" maxlength="2048" value="<?php echo esc_attr((string) ($agendaEditEvent['link'] ?? '')); ?>">
        </p>
        <p class="agenda-form-actions">
            <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                <?php echo $agendaEditEvent !== null ? esc_html__('Update event', 'traydigita-core') : esc_html__('Add event', 'traydigita-core'); ?>
            </button>
            <?php if ($agendaEditEvent !== null) : ?>
                <a class="traydigita-admin-button" href="<?php echo esc_url($agendaBaseUrl); ?>"><?php esc_html_e('Cancel', 'traydigita-core'); ?></a>
            <?php endif; ?>
        </p>
        <p class="agenda-form-notice" data-agenda-event-notice hidden></p>
    </form>

    <h2 id="td-section-events"><?php esc_html_e('Events', 'traydigita-core'); ?></h2>
    <ul class="agenda-filter-list subsubsub">
        <li>
            <a href="<?php echo esc_url($agendaBaseUrl); ?>" class="<?php echo $agendaFilter === '' ? 'current' : ''; ?>"><?php esc_html_e('All', 'traydigita-core'); ?></a> |
        </li>
        <?php foreach (AgendaTable::STATUSES as $statusKey) : ?>
            <li>
                <a href="<?php echo esc_url(add_query_arg('filter', $statusKey, $agendaBaseUrl)); ?>" class="<?php echo $agendaFilter === $statusKey ? 'current' : ''; ?>"><?php echo esc_html(AgendaRenderer::statusLabel($statusKey)); ?></a>
                <?php echo $statusKey !== AgendaTable::STATUS_PAST ? ' |' : ''; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <form class="agenda-search-form" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
        <input type="hidden" name="page" value="traydigita-agenda">
        <?php if ($agendaFilter !== '') : ?>
            <input type="hidden" name="filter" value="<?php echo esc_attr($agendaFilter); ?>">
        <?php endif; ?>
        <label class="screen-reader-text" for="agenda-search-input"><?php esc_html_e('Search events', 'traydigita-core'); ?></label>
        <input type="search" id="agenda-search-input" name="s" value="<?php echo esc_attr($agendaSearch); ?>">
        <button type="submit" class="traydigita-admin-button"><?php esc_html_e('Search events', 'traydigita-core'); ?></button>
        <?php if ($agendaSearch !== '') : ?>
            <a class="traydigita-admin-button" href="<?php echo esc_url(add_query_arg('filter', $agendaFilter, $agendaBaseUrl)); ?>"><?php esc_html_e('Clear search', 'traydigita-core'); ?></a>
        <?php endif; ?>
    </form>

    <?php if ($agendaEvents === []) : ?>
        <p><?php esc_html_e('No events found.', 'traydigita-core'); ?></p>
    <?php else : ?>
        <table class="agenda-admin-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Title', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Date', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Location', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Category', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                    <th><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agendaEvents as $event) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html((string) ($event['title'] ?? '')); ?></strong>
                            <br>
                            <a href="<?php echo esc_url(AgendaRenderer::eventUrl($event)); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'traydigita-core'); ?></a>
                            <?php if ($agendaSettings['ics_enabled']) : ?>
                                | <a href="<?php echo esc_url(AgendaRenderer::icsUrl($event)); ?>"><?php esc_html_e('Download .ics', 'traydigita-core'); ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(AgendaRenderer::formatRange($event)); ?></td>
                        <td><?php echo esc_html((string) ($event['location'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($event['category'] ?? '')); ?></td>
                        <td><?php echo esc_html(AgendaRenderer::statusLabel((string) ($event['status'] ?? ''))); ?></td>
                        <td>
                            <a class="traydigita-admin-button traydigita-admin-button-small" href="<?php echo esc_url(add_query_arg('edit', (int) $event['id'], $agendaBaseUrl)); ?>"><?php esc_html_e('Edit', 'traydigita-core'); ?></a>
                            <button type="button" class="traydigita-admin-button traydigita-admin-button-small traydigita-admin-button-danger" data-agenda-delete data-id="<?php echo esc_attr((string) $event['id']); ?>"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($agendaPages > 1) : ?>
            <div class="agenda-pagination tablenav">
                <?php
                $links = paginate_links([
                    'base' => add_query_arg('paged', '%#%', $agendaBaseUrl),
                    'format' => '',
                    'current' => $agendaPage,
                    'total' => $agendaPages,
                    'prev_text' => __('&laquo; Previous', 'traydigita-core'),
                    'next_text' => __('Next &raquo;', 'traydigita-core'),
                ]);
                if (is_string($links)) {
                    echo $links;
                }
                ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="agenda-danger-zone" id="td-section-danger">
        <h2><?php esc_html_e('Danger zone', 'traydigita-core'); ?></h2>
        <p><?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                __('Deleting all agenda data drops the agenda table and removes every event permanently.', 'traydigita-core'),
                __('Deactivating the module does not delete anything — this is the only way to remove the data.', 'traydigita-core')
            );
            ?></p>
        <p class="agenda-field">
            <label for="agenda-delete-all-confirm">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s is the confirmation word, e.g. "DELETE". */
                        __('Type %s to confirm:', 'traydigita-core'),
                        $agendaDeleteAllWord
                    )
                );
                ?>
            </label>
            <input type="text" id="agenda-delete-all-confirm" data-agenda-delete-all-confirm autocomplete="off">
            <button type="button" class="traydigita-admin-button traydigita-admin-button-danger" data-agenda-delete-all><?php esc_html_e('Delete all agenda data', 'traydigita-core'); ?></button>
        </p>
        <p class="agenda-form-notice" data-agenda-delete-all-notice hidden></p>
    </div>
</div>
