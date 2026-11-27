<?php
/**
 * DB Tools settings page partial.
 *
 * Rendered by DbToolsModule::renderSettingsPage() inside the admin
 * shell content area. Variables in scope: $tables (table list
 * from TableInspector::getTables()), $counts (cleaner counts per
 * category), $labels / $descriptions (cleaner category copy),
 * $settings (module settings), $serverVersion, $isMariaDb,
 * $supportsInnodbFulltext, $adminNonce.
 *
 * Three tabs: Tables, Cleaner, Optimize. Destructive buttons are
 * wired by resources/js/modules/admin-db-tools.ts, which reads
 * its strings from the data-msg-* attributes on the root element
 * below; the typed confirmation word is rendered as a data
 * attribute because the JavaScript compares the typed word
 * client-side before sending (the server re-checks it).
 *
 * Framework (R4-5): the module declares its four tabs through
 * DbToolsModule::tabs(), so the shell renders them as vertical
 * tab buttons in the left navigation and the in-page tab bar is
 * gone. The confirmation-word field in the Settings panel is
 * rendered with SettingsControls and the form ends in the sticky
 * framework save bar.
 *
 * @var array<int, array{name: string, engine: string, rows: int, data_length: int, index_length: int, data_free: int, total_length: int, collation: string, fulltext_indexes: array<int, string>}> $tables
 * @var array<string, int> $counts
 * @var array<string, string> $labels
 * @var array<string, string> $descriptions
 * @var array{confirm_word: string} $settings
 * @var string $serverVersion
 * @var bool $isMariaDb
 * @var bool $supportsInnodbFulltext
 * @var string $adminNonce
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\DbTools
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\DbTools\Cleaner;
use TrayDigita\WP\Plugin\Core\Modules\DbTools\DbToolsAjax;
use TrayDigita\WP\Plugin\Core\Modules\DbTools\DbToolsModule;

?>
<div class="traydigita-module-settings db-tools-settings" data-db-tools-admin
    data-action-tables="<?php echo esc_attr(DbToolsAjax::ACTION_TABLES); ?>"
    data-action-counts="<?php echo esc_attr(DbToolsAjax::ACTION_COUNTS); ?>"
    data-action-clean="<?php echo esc_attr(DbToolsAjax::ACTION_CLEAN); ?>"
    data-action-convert="<?php echo esc_attr(DbToolsAjax::ACTION_CONVERT); ?>"
    data-action-optimize="<?php echo esc_attr(DbToolsAjax::ACTION_OPTIMIZE); ?>"
    data-confirm-word="<?php echo esc_attr($settings['confirm_word']); ?>"
    data-msg-clean-confirm="<?php echo esc_attr(__('This will permanently delete the selected rows. Type the confirmation word to continue.', 'traydigita-core')); ?>"
    data-msg-clean-nothing="<?php echo esc_attr(__('Select at least one category to clean.', 'traydigita-core')); ?>"
    data-msg-clean-wrong-word="<?php echo esc_attr(sprintf(__('Type %s to confirm this delete.', 'traydigita-core'), $settings['confirm_word'])); ?>"
    data-msg-optimize-all-confirm="<?php echo esc_attr(__('This will optimize every table. Type the confirmation word to continue.', 'traydigita-core')); ?>"
    data-msg-optimize-all-wrong-word="<?php echo esc_attr(sprintf(__('Type %s to confirm optimizing all tables.', 'traydigita-core'), $settings['confirm_word'])); ?>"
    data-msg-working="<?php echo esc_attr(__('Working…', 'traydigita-core')); ?>"
    data-msg-error="<?php echo esc_attr(__('Request failed. Please try again.', 'traydigita-core')); ?>"
    data-msg-convert-confirm="<?php echo esc_attr(__('Convert table %s from MyISAM to InnoDB? This alters the table.', 'traydigita-core')); ?>"
    data-msg-optimize-confirm="<?php echo esc_attr(__('Optimize table %s?', 'traydigita-core')); ?>"
    data-msg-convert-label="<?php echo esc_attr(__('Convert to InnoDB', 'traydigita-core')); ?>"
    data-msg-optimize-label="<?php echo esc_attr(__('Optimize', 'traydigita-core')); ?>"
    data-msg-none="<?php echo esc_attr(__('None', 'traydigita-core')); ?>"
    data-msg-count-singular="<?php echo esc_attr(_x('%d row', 'db-tools table row count, singular form for JavaScript', 'traydigita-core')); ?>"
    data-msg-count-plural="<?php echo esc_attr(_x('%d rows', 'db-tools table row count, plural form for JavaScript', 'traydigita-core')); ?>">
    <p class="db-tools-warning">
        <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                __('DB Tools is a power tool. Conversions and deletes cannot be undone.', 'traydigita-core'),
                __('Back up the database before changing anything.', 'traydigita-core')
            );
            ?>
    </p>
    <p class="db-tools-server">
        <?php
        echo esc_html(
            sprintf(
                /* translators: %1$s is the database server kind (MySQL or MariaDB), %2$s is the server version, %3$s is "yes" or "no". */
                __('Database server: %1$s %2$s. InnoDB FULLTEXT support: %3$s.', 'traydigita-core'),
                $isMariaDb ? 'MariaDB' : 'MySQL',
                $serverVersion,
                $supportsInnodbFulltext ? __('yes', 'traydigita-core') : __('no', 'traydigita-core')
            )
        );
        ?>
    </p>

    <p class="db-tools-status" data-db-tools-status role="status" aria-live="polite"></p>

    <div class="db-tools-panel traydigita-ui-section td-settings-section" data-traydigita-panel="tables">
        <?php if ($tables === []) : ?>
            <p><?php esc_html_e('No tables found for this site.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="widefat striped db-tools-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Table', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Engine', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Rows', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Data', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Index', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Overhead', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('FULLTEXT', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody data-db-tools-tbody>
                    <?php foreach ($tables as $table) : ?>
                        <tr data-db-tools-row="<?php echo esc_attr($table['name']); ?>">
                            <td><code><?php echo esc_html($table['name']); ?></code></td>
                            <td data-db-tools-engine><?php echo esc_html($table['engine']); ?></td>
                            <td><?php echo esc_html(number_format_i18n($table['rows'])); ?></td>
                            <td><?php echo esc_html((string) size_format($table['data_length'])); ?></td>
                            <td><?php echo esc_html((string) size_format($table['index_length'])); ?></td>
                            <td><?php echo esc_html((string) size_format($table['data_free'])); ?></td>
                            <td>
                                <?php if ($table['fulltext_indexes'] === []) : ?>
                                    <?php esc_html_e('None', 'traydigita-core'); ?>
                                <?php else : ?>
                                    <?php echo esc_html(implode(', ', $table['fulltext_indexes'])); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($table['engine'] === 'MyISAM') : ?>
                                    <button type="button" class="traydigita-admin-button traydigita-admin-button-secondary" data-db-tools-convert data-table="<?php echo esc_attr($table['name']); ?>" data-confirm="<?php echo esc_attr(sprintf(__('Convert table %s from MyISAM to InnoDB? This alters the table.', 'traydigita-core'), $table['name'])); ?>">
                                        <?php esc_html_e('Convert to InnoDB', 'traydigita-core'); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="traydigita-admin-button traydigita-admin-button-secondary" data-db-tools-optimize data-table="<?php echo esc_attr($table['name']); ?>" data-confirm="<?php echo esc_attr(sprintf(__('Optimize table %s?', 'traydigita-core'), $table['name'])); ?>">
                                    <?php esc_html_e('Optimize', 'traydigita-core'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="db-tools-help">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Conversion is refused when a table has a FULLTEXT index that this server’s InnoDB cannot carry — a conversion never loses an index.', 'traydigita-core'),
                        __('Only tables with the site’s table prefix are listed.', 'traydigita-core')
                    );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="db-tools-panel traydigita-ui-section td-settings-section" data-traydigita-panel="cleaner" hidden>
        <p class="db-tools-help">
            <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('Each category shows how many rows it will delete before anything is deleted.', 'traydigita-core'),
                    __('Tick only the categories you want, type the confirmation word, then delete. The counts refresh after every clean.', 'traydigita-core')
                );
                ?>
        </p>
        <ul class="db-tools-cleaner-list">
            <?php foreach (Cleaner::CATEGORIES as $category) : ?>
                <li class="db-tools-cleaner-item">
                    <label class="db-tools-cleaner-label" for="db-tools-clean-<?php echo esc_attr($category); ?>">
                        <input class="traydigita-ui-checkbox" type="checkbox" id="db-tools-clean-<?php echo esc_attr($category); ?>" name="categories[]" value="<?php echo esc_attr($category); ?>" data-db-tools-category="<?php echo esc_attr($category); ?>">
                        <?php echo esc_html($labels[$category] ?? $category); ?>
                        <span class="db-tools-cleaner-count" data-db-tools-count="<?php echo esc_attr($category); ?>">
                            <?php
                            echo esc_html(
                                sprintf(
                                    /* translators: %d is the number of rows that would be deleted. */
                                    _n('%d row', '%d rows', $counts[$category] ?? 0, 'traydigita-core'),
                                    $counts[$category] ?? 0
                                )
                            );
                            ?>
                        </span>
                    </label>
                    <span class="db-tools-cleaner-help"><?php echo esc_html($descriptions[$category] ?? ''); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="db-tools-field">
            <label for="db-tools-clean-confirm">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s is the confirmation word the admin must type. */
                        __('Type %s to confirm', 'traydigita-core'),
                        $settings['confirm_word']
                    )
                );
                ?>
            </label>
            <input type="text" id="db-tools-clean-confirm" class="db-tools-confirm-input" data-db-tools-confirm autocomplete="off" autocapitalize="off" spellcheck="false">
        </p>
        <p class="db-tools-form-actions">
            <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-db-tools-clean>
                <?php esc_html_e('Delete selected', 'traydigita-core'); ?>
            </button>
            <button type="button" class="traydigita-admin-button traydigita-admin-button-secondary" data-db-tools-refresh-counts>
                <?php esc_html_e('Refresh counts', 'traydigita-core'); ?>
            </button>
        </p>
    </div>

    <div class="db-tools-panel traydigita-ui-section td-settings-section" data-traydigita-panel="optimize" hidden>
        <p class="db-tools-help">
            <?php
                \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                    __('OPTIMIZE TABLE reclaims overhead and rebuilds the table.', 'traydigita-core'),
                    __('Optimizing all tables runs one table at a time and needs the typed confirmation word; a single table can be optimized from the Tables tab instead.', 'traydigita-core')
                );
                ?>
        </p>
        <p class="db-tools-field">
            <label for="db-tools-optimize-all-confirm">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s is the confirmation word the admin must type. */
                        __('Type %s to confirm', 'traydigita-core'),
                        $settings['confirm_word']
                    )
                );
                ?>
            </label>
            <input type="text" id="db-tools-optimize-all-confirm" class="db-tools-confirm-input" data-db-tools-confirm-all autocomplete="off" autocapitalize="off" spellcheck="false">
        </p>
        <p class="db-tools-form-actions">
            <button type="button" class="traydigita-admin-button traydigita-admin-button-primary" data-db-tools-optimize-all>
                <?php esc_html_e('Optimize all tables', 'traydigita-core'); ?>
            </button>
        </p>
    </div>

    <div class="db-tools-panel traydigita-ui-section td-settings-section" data-traydigita-panel="settings" hidden>
        <form class="db-tools-settings-form" data-traydigita-module-form data-traydigita-module="db-tools" data-module="db-tools" method="post" action="">
            <?php wp_nonce_field(DbToolsModule::NONCE_ACTION); ?>
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::input([
                'id' => 'db-tools-confirm-word',
                'name' => 'confirm_word',
                'label' => __('Confirmation word', 'traydigita-core'),
                'value' => $settings['confirm_word'],
                'help' => __('The word an administrator must type before a bulk delete or optimize-all.', 'traydigita-core'),
                'help_long' => __('3–20 letters or digits, no spaces. Compared exactly, including case.', 'traydigita-core'),
                'attrs' => ['pattern' => '[A-Za-z0-9]{3,20}', 'maxlength' => '20', 'required' => true],
            ]);
            ?>
            <span class="db-tools-field-error" data-field-error="confirm_word" hidden></span>
            <?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::saveBar(); ?>
        </form>
    </div>
</div>
