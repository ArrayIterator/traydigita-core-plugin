<?php
/**
 * Contact submissions list page.
 *
 * The module's own admin page in the TrayDigita submenu
 * (registered as a hidden page, linked from the submenu):
 * filterable list of stored submissions, detail view,
 * read/unread + delete actions, CSV export, and a print view.
 *
 * Variables passed in:
 * @var array<string, mixed> $filters
 * @var int $page
 * @var int $perPage
 * @var int $total
 * @var array<int, array<string, mixed>> $submissions
 * @var int $viewId
 * @var array<string, mixed>|null $viewSubmission
 * @var bool $printView
 * @var array<string, array<string, mixed>> $forms
 * @var string $settingsUrl
 * @var string $listUrl
 * @var string $actionNonce
 * @var int $unreadCount
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use TrayDigita\WP\Plugin\Core\Modules\FormBuilder\FormBuilderModule;

$traydigita_pages = (int) ceil($total / $perPage);
$traydigita_submissions_page = FormBuilderModule::CONTACT_SUBMISSIONS_SLUG;
$traydigita_export_url = add_query_arg(
    array_merge(
        ['export' => 'csv', '_wpnonce' => $actionNonce],
        $filters
    ),
    $listUrl
);
?>
<?php if ($printView && $viewSubmission !== null) : ?>
<style>
    @media print {
        #adminmenuwrap, #wpadminbar, .traydigita-submissions-filters, .traydigita-submissions-actions, .traydigita-submissions-back { display: none !important; }
        #wpcontent { margin-left: 0 !important; }
    }
</style>
<?php endif; ?>
<div class="wrap traydigita-submissions">
    <h1 class="wp-heading-inline"><?php esc_html_e('Contact Submissions', 'traydigita-core'); ?></h1>
    <a href="<?php echo esc_url($settingsUrl); ?>" class="page-title-action"><?php esc_html_e('Form settings', 'traydigita-core'); ?></a>
    <p>
        <?php
        printf(
            /* translators: 1: total submissions, 2: unread submissions. */
            esc_html__('%1$d submissions (%2$d unread).', 'traydigita-core'),
            (int) $total,
            (int) $unreadCount
        );
        ?>
    </p>

    <?php if ($viewSubmission !== null) : ?>
        <p class="traydigita-submissions-back">
            <a href="<?php echo esc_url($listUrl); ?>">&larr; <?php esc_html_e('Back to all submissions', 'traydigita-core'); ?></a>
        </p>
        <h2><?php echo esc_html((string) ($viewSubmission['form_title'] ?? '')); ?></h2>
        <table class="widefat striped">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Date', 'traydigita-core'); ?></th>
                    <td><?php echo esc_html((string) ($viewSubmission['created_at'] ?? '')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Name', 'traydigita-core'); ?></th>
                    <td><?php echo esc_html((string) ($viewSubmission['name'] ?? '')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email', 'traydigita-core'); ?></th>
                    <td>
                        <?php if ((string) ($viewSubmission['email'] ?? '') !== '') : ?>
                            <a href="mailto:<?php echo esc_attr((string) $viewSubmission['email']); ?>"><?php echo esc_html((string) $viewSubmission['email']); ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Subject', 'traydigita-core'); ?></th>
                    <td><?php echo esc_html((string) ($viewSubmission['subject'] ?? '')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                    <td><?php echo esc_html((string) ($viewSubmission['status'] ?? '')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Notification email sent', 'traydigita-core'); ?></th>
                    <td><?php echo !empty($viewSubmission['email_sent']) ? esc_html__('Yes', 'traydigita-core') : esc_html__('No', 'traydigita-core'); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('IP address', 'traydigita-core'); ?></th>
                    <td><?php echo esc_html((string) ($viewSubmission['ip'] ?? '')); ?></td>
                </tr>
                <?php foreach ((array) ($viewSubmission['fields'] ?? []) as $traydigita_field_id => $traydigita_field_value) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html((string) $traydigita_field_id); ?></th>
                        <td>
                            <?php
                            if (is_array($traydigita_field_value)) {
                                echo esc_html(implode(', ', array_map('strval', $traydigita_field_value)));
                            } else {
                                echo esc_html((string) $traydigita_field_value);
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="traydigita-submissions-actions">
            <a class="button" href="<?php echo esc_url(add_query_arg(['view' => (int) $viewSubmission['id'], 'print' => '1'], $listUrl)); ?>"><?php esc_html_e('Print view', 'traydigita-core'); ?></a>
            <?php if ($printView) : ?>
                <span class="description"><?php esc_html_e('Use your browser\u2019s Print to save this submission as a document.', 'traydigita-core'); ?></span>
            <?php endif; ?>
        </p>
        <form method="post" class="traydigita-admin-inline-form">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($actionNonce); ?>">
            <input type="hidden" name="submission_id" value="<?php echo esc_attr((string) $viewSubmission['id']); ?>">
            <button type="submit" name="submission_action" value="<?php echo ((string) ($viewSubmission['status'] ?? '') === 'read') ? 'mark_unread' : 'mark_read'; ?>" class="button">
                <?php echo ((string) ($viewSubmission['status'] ?? '') === 'read') ? esc_html__('Mark as unread', 'traydigita-core') : esc_html__('Mark as read', 'traydigita-core'); ?>
            </button>
            <button type="submit" name="submission_action" value="delete" class="button button-link-delete"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
        </form>
    <?php else : ?>
        <form method="get" class="traydigita-submissions-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr($traydigita_submissions_page); ?>">
            <label>
                <?php esc_html_e('Form', 'traydigita-core'); ?>
                <select name="form_id">
                    <option value=""><?php esc_html_e('All forms', 'traydigita-core'); ?></option>
                    <?php foreach ($forms as $traydigita_form) : ?>
                        <option value="<?php echo esc_attr((string) $traydigita_form['id']); ?>"<?php selected((string) ($filters['form_id'] ?? ''), (string) $traydigita_form['id']); ?>><?php echo esc_html((string) $traydigita_form['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e('Status', 'traydigita-core'); ?>
                <select name="status">
                    <option value=""><?php esc_html_e('All', 'traydigita-core'); ?></option>
                    <option value="unread"<?php selected((string) ($filters['status'] ?? ''), 'unread'); ?>><?php esc_html_e('Unread', 'traydigita-core'); ?></option>
                    <option value="read"<?php selected((string) ($filters['status'] ?? ''), 'read'); ?>><?php esc_html_e('Read', 'traydigita-core'); ?></option>
                </select>
            </label>
            <label>
                <?php esc_html_e('From', 'traydigita-core'); ?>
                <input type="date" name="from" value="<?php echo esc_attr((string) ($filters['from'] ?? '')); ?>">
            </label>
            <label>
                <?php esc_html_e('To', 'traydigita-core'); ?>
                <input type="date" name="to" value="<?php echo esc_attr((string) ($filters['to'] ?? '')); ?>">
            </label>
            <label>
                <?php esc_html_e('Search', 'traydigita-core'); ?>
                <input type="search" name="s" value="<?php echo esc_attr((string) ($filters['search'] ?? '')); ?>" autocomplete="off">
            </label>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'traydigita-core'); ?></button>
            <a class="button" href="<?php echo esc_url($traydigita_export_url); ?>"><?php esc_html_e('Export CSV', 'traydigita-core'); ?></a>
        </form>

        <?php if ($submissions === []) : ?>
            <p><?php esc_html_e('No submissions match the filters.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Date', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Form', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Name', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Email', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Subject', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Email sent', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($submissions as $traydigita_submission) : ?>
                        <tr<?php echo ((string) ($traydigita_submission['status'] ?? '') === 'unread') ? ' class="traydigita-submission-unread"' : ''; ?>>
                            <td><?php echo esc_html((string) ($traydigita_submission['created_at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_submission['form_title'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_submission['name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_submission['email'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_submission['subject'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($traydigita_submission['status'] ?? '')); ?></td>
                            <td><?php echo !empty($traydigita_submission['email_sent']) ? esc_html__('Yes', 'traydigita-core') : esc_html__('No', 'traydigita-core'); ?></td>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('view', (int) $traydigita_submission['id'], $listUrl)); ?>"><?php esc_html_e('View', 'traydigita-core'); ?></a>
                                |
                                <form method="post" class="traydigita-admin-inline-form">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($actionNonce); ?>">
                                    <input type="hidden" name="submission_id" value="<?php echo esc_attr((string) $traydigita_submission['id']); ?>">
                                    <button type="submit" name="submission_action" value="<?php echo ((string) ($traydigita_submission['status'] ?? '') === 'read') ? 'mark_unread' : 'mark_read'; ?>" class="button-link">
                                        <?php echo ((string) ($traydigita_submission['status'] ?? '') === 'read') ? esc_html__('Mark unread', 'traydigita-core') : esc_html__('Mark read', 'traydigita-core'); ?>
                                    </button>
                                    |
                                    <button type="submit" name="submission_action" value="delete" class="button-link-delete"><?php esc_html_e('Delete', 'traydigita-core'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($traydigita_pages > 1) : ?>
                <p class="traydigita-submissions-pagination">
                    <?php if ($page > 1) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($filters, ['paged' => $page - 1]), $listUrl)); ?>">&larr; <?php esc_html_e('Previous', 'traydigita-core'); ?></a>
                    <?php endif; ?>
                    <?php
                    printf(
                        /* translators: 1: current page, 2: total pages. */
                        esc_html__('Page %1$d of %2$d', 'traydigita-core'),
                        (int) $page,
                        (int) $traydigita_pages
                    );
                    ?>
                    <?php if ($page < $traydigita_pages) : ?>
                        <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($filters, ['paged' => $page + 1]), $listUrl)); ?>"><?php esc_html_e('Next', 'traydigita-core'); ?> &rarr;</a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
