<?php
/**
 * Dynamic Sidebars module settings page (rendered inside the admin shell content area).
 *
 * Variables (set by DynamicSidebarsModule::renderSettingsPage()):
 * @var array<int, array{id: string, name: string, description: string, area: string,
 *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}> $dsSidebars
 *     Custom sidebars, sorted by priority (lowest first).
 * @var string $dsEditId Sidebar id being edited, 'new' when adding, '' when listing.
 * @var array{id: string, name: string, description: string, area: string,
 *     priority: int, conditions: array{category: int, tag: int, author: int, post: int}}|null $dsEditSidebar
 *     Sidebar being edited, or null when listing.
 * @var bool $dsIsNew True when the form creates a new sidebar.
 * @var bool $dsInvalidEdit True when ?edit= names a sidebar that does not exist.
 * @var array<int, \WP_Term> $dsCategories All categories (for the rule picker).
 * @var array<int, \WP_Term> $dsTags All tags (for the rule picker).
 * @var array<int, object{ID: int, display_name: string}> $dsAuthors Users who can author posts.
 * @var string $dsPostTitle Title of the post picked in the rule being edited ('' when none).
 * @var array<string, int> $dsWidgetCounts Widget counts per sidebar id.
 * @var array<string, string> $dsAreas Replaceable default areas (id => label).
 * @var array<int, string> $dsConditionAreas Areas whose rules may carry conditions.
 * @var string $dsSaveAction AJAX action saving one sidebar.
 * @var string $dsDeleteAction AJAX action deleting one sidebar.
 * @var string $dsNonceAction Nonce action (unused here - the shell nonce is used by the script).
 * @var string $dsBaseUrl This settings page URL.
 *
 * Behaviour:
 * - List: one row per sidebar (name, replaced area, rule summary,
 *   priority, widget count) with Edit links (`?edit=<id>`) and
 *   Delete buttons (confirm in JS, delete via AJAX, reload).
 * - Edit form: posts to the module's OWN AJAX action
 *   (`data-dynamic-sidebars-form`, handled by
 *   admin-dynamic-sidebars.ts) - NOT the generic dispatcher -
 *   and reloads the page on success so the list shows the saved
 *   sidebar. Condition pickers are plain server-rendered selects
 *   (categories, tags, authors) plus a post/page ID field: the
 *   Select2 AJAX picker pattern lives in widgets-admin.ts, which
 *   is only enqueued on widgets.php and the Customizer, so it is
 *   not available on this page.
 * - Conditions only apply to the article sidebar: when the area
 *   select names a homepage area, the script disables the
 *   condition fields (the server forces them to "any" on save).
 * - Deleting a sidebar never deletes its widgets: WordPress keeps
 *   them under Inactive Widgets.
 */
declare(strict_types=1);

$dsConditionSummary = static function (array $sidebar) use ($dsCategories, $dsTags, $dsAuthors): string {
    $conditions = $sidebar['conditions'];
    if ($conditions['category'] === 0 && $conditions['tag'] === 0 && $conditions['author'] === 0 && $conditions['post'] === 0) {
        return __('Any article', 'traydigita-core');
    }
    $parts = [];
    foreach ($dsCategories as $term) {
        if ((int) $term->term_id === $conditions['category']) {
            $parts[] = sprintf(
                /* translators: %s: Category name. */
                __('Category: %s', 'traydigita-core'),
                $term->name
            );
            break;
        }
    }
    foreach ($dsTags as $term) {
        if ((int) $term->term_id === $conditions['tag']) {
            $parts[] = sprintf(
                /* translators: %s: Tag name. */
                __('Tag: %s', 'traydigita-core'),
                $term->name
            );
            break;
        }
    }
    foreach ($dsAuthors as $author) {
        if ((int) $author->ID === $conditions['author']) {
            $parts[] = sprintf(
                /* translators: %s: Author display name. */
                __('Author: %s', 'traydigita-core'),
                $author->display_name
            );
            break;
        }
    }
    if ($conditions['post'] !== 0) {
        $title = get_the_title($conditions['post']);
        $parts[] = sprintf(
            /* translators: 1: Post ID. 2: Post title. */
            __('Post #%1$d (%2$s)', 'traydigita-core'),
            $conditions['post'],
            is_string($title) && $title !== '' ? $title : __('(deleted)', 'traydigita-core')
        );
    }
    return implode(' · ', $parts);
};
$dsIsArticleArea = $dsEditSidebar !== null && in_array($dsEditSidebar['area'], $dsConditionAreas, true);
?>
<div
    class="traydigita-dynamic-sidebars-settings dynamic-sidebars-admin"
    data-dynamic-sidebars-admin
    data-save-action="<?php echo esc_attr($dsSaveAction); ?>"
    data-delete-action="<?php echo esc_attr($dsDeleteAction); ?>"
    data-condition-area="traydigita-sidebar-article"
    data-msg-saved="<?php esc_attr_e('Sidebar saved.', 'traydigita-core'); ?>"
    data-msg-save-error="<?php esc_attr_e('The sidebar could not be saved. Please check the form.', 'traydigita-core'); ?>"
    data-msg-deleted="<?php esc_attr_e('Sidebar deleted. Its widgets are kept under Inactive Widgets.', 'traydigita-core'); ?>"
    data-msg-delete-confirm="<?php esc_attr_e('Delete this sidebar? Its widgets will be kept under Inactive Widgets.', 'traydigita-core'); ?>"
    data-msg-delete-error="<?php esc_attr_e('The sidebar could not be deleted.', 'traydigita-core'); ?>"
>
    <?php
    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::intro(
        __('Custom widget areas shown by rule: pick which default area a sidebar replaces, then narrow it with conditions.', 'traydigita-core'),
        __('Rules are ordered by priority — the first matching rule wins, and anything else falls back to the theme default for that spot.', 'traydigita-core')
    );
    ?>

    <?php if ($dsInvalidEdit) : ?>
        <div class="traydigita-admin-notice traydigita-admin-notice-error traydigita-admin-notice-inline">
            <p><?php esc_html_e('That sidebar no longer exists.', 'traydigita-core'); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($dsEditSidebar !== null) : ?>
        <h2 id="td-section-add">
            <?php echo $dsIsNew ? esc_html__('Add sidebar', 'traydigita-core') : esc_html__('Edit sidebar', 'traydigita-core'); ?>
        </h2>
        <p data-dynamic-sidebars-notice hidden></p>
        <form data-dynamic-sidebars-form method="post" action="">
            <input type="hidden" name="sidebar_id" value="<?php echo esc_attr($dsEditSidebar['id']); ?>">
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="ds-name"><?php esc_html_e('Name', 'traydigita-core'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ds-name" name="name" class="traydigita-ui-input" maxlength="120" required value="<?php echo esc_attr($dsEditSidebar['name']); ?>">
                            <p class="description">
                                <?php
                                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                        __('Shown in Appearance → Widgets.', 'traydigita-core'),
                                        __('The sidebar ID is made from this name once and never changes, so widgets stay assigned when you rename it.', 'traydigita-core')
                                    );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ds-description"><?php esc_html_e('Description', 'traydigita-core'); ?></label>
                        </th>
                        <td>
                            <textarea id="ds-description" name="description" rows="2" class="traydigita-ui-textarea" maxlength="500"><?php echo esc_textarea($dsEditSidebar['description']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ds-area"><?php esc_html_e('Replaces', 'traydigita-core'); ?></label>
                        </th>
                        <td>
                            <select id="ds-area" name="area" data-dynamic-sidebars-area>
                                <?php foreach ($dsAreas as $areaId => $areaLabel) : ?>
                                    <option value="<?php echo esc_attr($areaId); ?>" <?php selected($dsEditSidebar['area'], $areaId); ?>>
                                        <?php echo esc_html($areaLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e('Which default area this sidebar replaces when its rule matches.', 'traydigita-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ds-priority"><?php esc_html_e('Priority', 'traydigita-core'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ds-priority" name="priority" class="small-text" min="1" max="999" step="1" value="<?php echo esc_attr((string) $dsEditSidebar['priority']); ?>">
                            <p class="description">
                                <?php esc_html_e('Lower wins: when several sidebars replace the same area, the lowest priority that matches is shown.', 'traydigita-core'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>

            <fieldset data-dynamic-sidebars-conditions <?php echo $dsIsArticleArea ? '' : 'disabled'; ?>>
                <legend><h2><?php esc_html_e('Rule conditions', 'traydigita-core'); ?></h2></legend>
                <p class="description" data-dynamic-sidebars-conditions-note <?php echo $dsIsArticleArea ? 'hidden' : ''; ?>>
                    <?php
                        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                            __('Conditions only apply to the article sidebar.', 'traydigita-core'),
                            __('The homepage has no current post, so a homepage sidebar always shows.', 'traydigita-core')
                        );
                    ?>
                </p>
                <p class="description">
                    <?php esc_html_e('All conditions must match at once. Leave everything on “Any” to show this sidebar for every article.', 'traydigita-core'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="ds-category"><?php esc_html_e('Category', 'traydigita-core'); ?></label>
                            </th>
                            <td>
                                <select id="ds-category" name="category">
                                    <option value="0"><?php esc_html_e('Any category', 'traydigita-core'); ?></option>
                                    <?php foreach ($dsCategories as $term) : ?>
                                        <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($dsEditSidebar['conditions']['category'], (int) $term->term_id); ?>>
                                            <?php echo esc_html($term->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ds-tag"><?php esc_html_e('Tag', 'traydigita-core'); ?></label>
                            </th>
                            <td>
                                <select id="ds-tag" name="tag">
                                    <option value="0"><?php esc_html_e('Any tag', 'traydigita-core'); ?></option>
                                    <?php foreach ($dsTags as $term) : ?>
                                        <option value="<?php echo esc_attr((string) $term->term_id); ?>" <?php selected($dsEditSidebar['conditions']['tag'], (int) $term->term_id); ?>>
                                            <?php echo esc_html($term->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ds-author"><?php esc_html_e('Author', 'traydigita-core'); ?></label>
                            </th>
                            <td>
                                <select id="ds-author" name="author">
                                    <option value="0"><?php esc_html_e('Any author', 'traydigita-core'); ?></option>
                                    <?php foreach ($dsAuthors as $author) : ?>
                                        <option value="<?php echo esc_attr((string) $author->ID); ?>" <?php selected($dsEditSidebar['conditions']['author'], (int) $author->ID); ?>>
                                            <?php echo esc_html($author->display_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ds-post"><?php esc_html_e('Specific post or page', 'traydigita-core'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="ds-post" name="post" class="small-text" min="0" step="1" value="<?php echo esc_attr((string) $dsEditSidebar['conditions']['post']); ?>">
                                <p class="description">
                                    <?php
                                        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                            __('Enter the post or page ID (0 for any).', 'traydigita-core'),
                                            __('Find the ID in Posts → All Posts by hovering a title and reading the post= number in the link.', 'traydigita-core')
                                        );
                                    ?>
                                    <?php if ($dsPostTitle !== '') : ?>
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                /* translators: %s: Post title. */
                                                __('Currently selected: %s', 'traydigita-core'),
                                                $dsPostTitle
                                            )
                                        );
                                        ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </fieldset>

            <p class="submit">
                <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                    <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                </button>
                <a class="traydigita-admin-button" href="<?php echo esc_url($dsBaseUrl); ?>">
                    <?php esc_html_e('Cancel', 'traydigita-core'); ?>
                </a>
            </p>
        </form>
    <?php else : ?>
        <p id="td-section-sidebars">
            <a class="traydigita-admin-button traydigita-admin-button-primary" href="<?php echo esc_url($dsBaseUrl . '&edit=new'); ?>">
                <?php esc_html_e('Add new sidebar', 'traydigita-core'); ?>
            </a>
        </p>
        <?php if ($dsSidebars === []) : ?>
            <p><?php esc_html_e('No custom sidebars yet. Add one to show a different widget area by rule.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Sidebar', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Replaces', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Rule', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Priority', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Widgets', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dsSidebars as $sidebar) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($sidebar['name']); ?></strong>
                                <br>
                                <code><?php echo esc_html($sidebar['id']); ?></code>
                                <?php if ($sidebar['description'] !== '') : ?>
                                    <br>
                                    <span class="description"><?php echo esc_html($sidebar['description']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($dsAreas[$sidebar['area']] ?? $sidebar['area']); ?></td>
                            <td><?php echo esc_html($dsConditionSummary($sidebar)); ?></td>
                            <td><?php echo esc_html((string) $sidebar['priority']); ?></td>
                            <td><?php echo esc_html((string) ($dsWidgetCounts[$sidebar['id']] ?? 0)); ?></td>
                            <td>
                                <a class="traydigita-admin-button traydigita-admin-button-small" href="<?php echo esc_url($dsBaseUrl . '&edit=' . $sidebar['id']); ?>">
                                    <?php esc_html_e('Edit', 'traydigita-core'); ?>
                                </a>
                                <button type="button" class="traydigita-admin-button traydigita-admin-button-small traydigita-admin-button-danger" data-dynamic-sidebars-delete data-id="<?php echo esc_attr($sidebar['id']); ?>" data-name="<?php echo esc_attr($sidebar['name']); ?>">
                                    <?php esc_html_e('Delete', 'traydigita-core'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Deleting a sidebar never deletes its widgets.', 'traydigita-core'),
                        __('WordPress keeps them under Inactive Widgets in Appearance → Widgets.', 'traydigita-core')
                    );
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
