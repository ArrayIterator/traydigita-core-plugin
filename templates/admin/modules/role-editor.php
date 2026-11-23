<?php
/**
 * Custom Role Editor settings page.
 *
 * Rendered inside the admin shell content area by
 * RoleEditorModule::renderSettingsPage().
 *
 * Variables:
 * @var array<string, array{name: string, capabilities: array<int, string>, users: int, builtin: bool, protected: bool, deletable: bool}> $roles
 *     Every role keyed by slug, with display name, granted
 *     capabilities, user count, and safety flags.
 * @var string $view Current view: `list`, `edit`, `clone`, or `delete`.
 * @var string $selectedRole Slug of the role the edit/clone/delete
 *     view acts on (empty on the list view).
 * @var array{name: string, capabilities: array<int, string>, users: int, builtin: bool, protected: bool, deletable: bool}|null $selected
 *     The selected role's row, or null on the list view.
 * @var array<string, array{label: string, caps: array<int, string>}> $groups
 *     Capability groups with translated labels.
 * @var array<int, array{time: int, user_id: int, user: string, action: string, role: string, details: string}> $log
 *     Change log, newest first.
 *
 * Safety is shown on the page, not just enforced on the server:
 * the administrator role has no Edit link, built-in roles have
 * no Delete link, and the delete view shows the affected user
 * count and requires typing the role slug. The edit form posts
 * the complete desired capability set (`caps[]`); the generic
 * admin shell submits it through `traydigita_admin_save` and
 * the module refuses unsafe saves server-side.
 *
 * After a successful save the page reloads (see
 * resources/js/modules/admin-role-editor.ts) so the role list
 * and the change log show the new state.
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Modules\RoleEditor\RoleEditorModule;

$pageUrl = admin_url('admin.php?page=' . RoleEditorModule::settingsPageSlug());
$actionLabels = [
    'updated' => __('Updated', 'traydigita-core'),
    'created' => __('Created', 'traydigita-core'),
    'deleted' => __('Deleted', 'traydigita-core'),
];
?>
<div class="traydigita-module-settings role-editor-settings"
    data-traydigita-module="role-editor"
    data-role-editor
    data-msg-confirm-mismatch="<?php echo esc_attr(__('Type the role slug exactly to confirm.', 'traydigita-core')); ?>"
    data-msg-manage-warning="<?php echo esc_attr(__('Removing manage_options from this role: the save will be refused if no other user keeps it.', 'traydigita-core')); ?>"
    data-msg-grant-warning="<?php echo esc_attr(__('Granting manage_options gives the new role full control of the site.', 'traydigita-core')); ?>">
    <?php
    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::intro(
        __('Clone and edit user roles and their capabilities.', 'traydigita-core'),
        __('Roles decide what every user on the site can do, so every change here is guarded: the administrator role can never be edited or deleted, built-in roles can never be deleted, and no save that would leave the site without an administrator is ever applied.', 'traydigita-core')
    );
    ?>
    <p class="traydigita-module-intro role-editor-safety-note">
        <?php
        \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
            __('Safety rules: only capability names WordPress itself knows are accepted — anything else is rejected and nothing is saved.', 'traydigita-core'),
            __('Every change is written to the change log below, with who made it and when. Nothing here touches wp-config.php or the database directly; all changes go through the WordPress roles API.', 'traydigita-core')
        );
        ?>
    </p>

    <?php if ($view === 'list') : ?>
        <h2 class="role-editor-heading" id="td-section-roles"><?php esc_html_e('Roles', 'traydigita-core'); ?></h2>
        <table class="role-editor-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Role', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Users', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Capabilities', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Type', 'traydigita-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $slug => $role) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($role['name']); ?></strong>
                            <br><code><?php echo esc_html($slug); ?></code>
                        </td>
                        <td><?php echo esc_html((string) $role['users']); ?></td>
                        <td><?php echo esc_html((string) count($role['capabilities'])); ?></td>
                        <td>
                            <?php if ($role['protected']) : ?>
                                <?php esc_html_e('Administrator — protected', 'traydigita-core'); ?>
                            <?php elseif ($role['builtin']) : ?>
                                <?php esc_html_e('Built-in', 'traydigita-core'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Custom', 'traydigita-core'); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$role['protected']) : ?>
                                <a href="<?php echo esc_url($pageUrl . '&view=edit&role=' . $slug); ?>"><?php esc_html_e('Edit', 'traydigita-core'); ?></a>
                                |
                            <?php endif; ?>
                            <a href="<?php echo esc_url($pageUrl . '&view=clone&role=' . $slug); ?>"><?php esc_html_e('Clone', 'traydigita-core'); ?></a>
                            <?php if ($role['deletable']) : ?>
                                | <a href="<?php echo esc_url($pageUrl . '&view=delete&role=' . $slug); ?>"><?php esc_html_e('Delete', 'traydigita-core'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="role-editor-heading" id="td-section-log"><?php esc_html_e('Change log', 'traydigita-core'); ?></h2>
        <?php if ($log === []) : ?>
            <p><?php esc_html_e('No role changes have been logged yet.', 'traydigita-core'); ?></p>
        <?php else : ?>
            <table class="role-editor-table role-editor-log widefat striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('When', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Who', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Action', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Role', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('What changed', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html($entry['time'] > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry['time']) : '—'); ?></td>
                            <td><?php echo esc_html($entry['user'] !== '' ? $entry['user'] : sprintf(__('User #%d', 'traydigita-core'), $entry['user_id'])); ?></td>
                            <td><?php echo esc_html($actionLabels[$entry['action']] ?? $entry['action']); ?></td>
                            <td><code><?php echo esc_html($entry['role']); ?></code></td>
                            <td><?php echo esc_html($entry['details']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($view === 'edit' && $selected !== null) : ?>
        <p class="role-editor-back">
            <a href="<?php echo esc_url($pageUrl); ?>">&larr; <?php esc_html_e('Back to roles', 'traydigita-core'); ?></a>
        </p>
        <h2 class="role-editor-heading" id="td-section-capabilities">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s is the role display name. */
                    __('Edit capabilities: %s', 'traydigita-core'),
                    $selected['name']
                )
            );
            ?>
        </h2>
        <p class="traydigita-module-intro">
            <?php
            [$roleShort, $roleLong] = \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::splitHelp(
                sprintf(
                    /* translators: %d is the number of users with this role. */
                    __('%d user(s) hold this role. Tick every capability the role should have; unticked capabilities are removed when you save.', 'traydigita-core'),
                    $selected['users']
                )
            );
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText($roleShort, $roleLong);
            ?>
        </p>
        <form class="role-editor-form" data-traydigita-module-form data-traydigita-module="role-editor" data-module="role-editor" method="post" action=""
            data-role-editor-edit-form
            data-users-count="<?php echo esc_attr((string) $selected['users']); ?>"
            data-has-manage-options="<?php echo esc_attr(in_array(RoleEditorModule::ADMIN_CAPABILITY, $selected['capabilities'], true) ? '1' : '0'); ?>">
            <input type="hidden" name="operation" value="edit">
            <input type="hidden" name="role" value="<?php echo esc_attr($selectedRole); ?>">

            <p class="role-editor-field">
                <label for="role-editor-cap-search"><?php esc_html_e('Find a capability', 'traydigita-core'); ?></label>
                <input type="search" id="role-editor-cap-search" data-role-editor-search autocomplete="off"
                    placeholder="<?php echo esc_attr(__('Type to filter capabilities…', 'traydigita-core')); ?>">
            </p>

            <?php foreach ($groups as $groupKey => $group) : ?>
                <fieldset class="role-editor-group" data-role-editor-group="<?php echo esc_attr($groupKey); ?>">
                    <legend><?php echo esc_html($group['label']); ?></legend>
                    <p class="role-editor-group-actions">
                        <button type="button" class="traydigita-admin-button" data-role-editor-toggle-group="<?php echo esc_attr($groupKey); ?>" data-checked="1">
                            <?php esc_html_e('Select all', 'traydigita-core'); ?>
                        </button>
                        <button type="button" class="traydigita-admin-button" data-role-editor-toggle-group="<?php echo esc_attr($groupKey); ?>" data-checked="0">
                            <?php esc_html_e('Select none', 'traydigita-core'); ?>
                        </button>
                    </p>
                    <?php foreach ($group['caps'] as $cap) : ?>
                        <p class="role-editor-cap" data-role-editor-cap="<?php echo esc_attr($cap); ?>">
                            <label><input class="traydigita-ui-checkbox" type="checkbox" name="caps[]" value="<?php echo esc_attr($cap); ?>"
                                    <?php checked(in_array($cap, $selected['capabilities'], true)); ?>
                                    <?php echo $cap === RoleEditorModule::ADMIN_CAPABILITY ? 'data-role-editor-manage-options' : ''; ?>><code><?php echo esc_html($cap); ?></code></label>
                        </p>
                    <?php endforeach; ?>
                    <?php if ($groupKey === 'general') : ?>
                        <p class="role-editor-warning" data-role-editor-manage-warning hidden>
                            <?php esc_html_e('Removing manage_options from this role: the save will be refused if no other user keeps it, so you cannot lock yourself out — but check that another administrator still exists before saving.', 'traydigita-core'); ?>
                        </p>
                    <?php endif; ?>
                </fieldset>
            <?php endforeach; ?>

            <p class="role-editor-form-actions">
                <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                    <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                </button>
            </p>
        </form>
    <?php endif; ?>

    <?php if ($view === 'clone' && $selected !== null) : ?>
        <p class="role-editor-back">
            <a href="<?php echo esc_url($pageUrl); ?>">&larr; <?php esc_html_e('Back to roles', 'traydigita-core'); ?></a>
        </p>
        <h2 class="role-editor-heading">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s is the source role display name. */
                    __('Clone role: %s', 'traydigita-core'),
                    $selected['name']
                )
            );
            ?>
        </h2>
        <p class="traydigita-module-intro">
            <?php
            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                __('The new role copies the source role’s capabilities, except manage_options, which is left out unless you explicitly grant it below.', 'traydigita-core'),
                __('Capabilities are edited after cloning, on the new role’s Edit page.', 'traydigita-core')
            );
            ?>
        </p>
        <form class="role-editor-form" data-traydigita-module-form data-traydigita-module="role-editor" data-module="role-editor" method="post" action=""
            data-role-editor-clone-form>
            <input type="hidden" name="operation" value="clone">
            <input type="hidden" name="source_role" value="<?php echo esc_attr($selectedRole); ?>">

            <p class="role-editor-field">
                <label for="role-editor-new-slug"><?php esc_html_e('New role slug', 'traydigita-core'); ?></label>
                <input type="text" id="role-editor-new-slug" name="new_slug" required pattern="[a-z0-9_]{2,60}" maxlength="60" autocomplete="off">
                <span class="role-editor-field-help"><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Lowercase letters, digits, or underscores, 2–60 characters.', 'traydigita-core'), __('This is the role’s machine name and cannot be changed later.', 'traydigita-core')); ?></span>
            </p>

            <p class="role-editor-field">
                <label for="role-editor-new-name"><?php esc_html_e('Display name', 'traydigita-core'); ?></label>
                <input type="text" id="role-editor-new-name" name="new_name" required maxlength="100" autocomplete="off">
            </p>

            <p class="role-editor-field">
                <label class="traydigita-ui-toggle"><input class="traydigita-ui-toggle-input" type="checkbox" name="grant_manage_options" value="1" data-role-editor-grant-manage-options><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Grant manage_options to the new role', 'traydigita-core'); ?></span></label>
                <span class="role-editor-field-help"><?php \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(__('Gives the new role full control of the site.', 'traydigita-core'), __('Leave this off unless the new role really is an administrator role.', 'traydigita-core')); ?></span>
            </p>
            <p class="role-editor-warning" data-role-editor-grant-warning hidden>
                <?php esc_html_e('Granting manage_options gives the new role full control of the site, including editing roles.', 'traydigita-core'); ?>
            </p>

            <p class="role-editor-form-actions">
                <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                    <?php esc_html_e('Create role', 'traydigita-core'); ?>
                </button>
            </p>
        </form>
    <?php endif; ?>

    <?php if ($view === 'delete' && $selected !== null) : ?>
        <p class="role-editor-back">
            <a href="<?php echo esc_url($pageUrl); ?>">&larr; <?php esc_html_e('Back to roles', 'traydigita-core'); ?></a>
        </p>
        <h2 class="role-editor-heading">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s is the role display name. */
                    __('Delete role: %s', 'traydigita-core'),
                    $selected['name']
                )
            );
            ?>
        </h2>
        <p class="role-editor-warning">
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %1$d is the number of users with this role, %2$s is the role slug. */
                    __('This will permanently delete the role %2$s. %1$d user(s) hold this role — they keep their accounts but lose the role and its capabilities. Deleting a role that is the last one granting manage_options is refused.', 'traydigita-core'),
                    $selected['users'],
                    $selectedRole
                )
            );
            ?>
        </p>
        <form class="role-editor-form" data-traydigita-module-form data-traydigita-module="role-editor" data-module="role-editor" method="post" action=""
            data-role-editor-delete-form
            data-expected-slug="<?php echo esc_attr($selectedRole); ?>">
            <input type="hidden" name="operation" value="delete">
            <input type="hidden" name="role" value="<?php echo esc_attr($selectedRole); ?>">

            <p class="role-editor-field">
                <label for="role-editor-confirm">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s is the role slug to type. */
                            __('Type %s to confirm', 'traydigita-core'),
                            $selectedRole
                        )
                    );
                    ?>
                </label>
                <input type="text" id="role-editor-confirm" name="confirm" required autocomplete="off"
                    placeholder="<?php echo esc_attr($selectedRole); ?>">
                <span class="role-editor-field-error" data-role-editor-confirm-error hidden></span>
            </p>

            <p class="role-editor-form-actions">
                <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                    <?php esc_html_e('Delete role', 'traydigita-core'); ?>
                </button>
            </p>
        </form>
    <?php endif; ?>
</div>
