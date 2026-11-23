<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\RoleEditor;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function add_role;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_values;
use function count_users;
use function current_user_can;
use function esc_html__;
use function file_exists;
use function function_exists;
use function get_role;
use function get_super_admins;
use function get_users;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_multisite;
use function is_string;
use function preg_match;
use function remove_role;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function wp_roles;
use function wp_unslash;

/**
 * Custom Role Editor module.
 *
 * Clone and edit user roles and their capabilities, with grouped
 * capability checkboxes. The module is optional and DISABLED by
 * default: roles are the site's authorization boundary, and the
 * module must be switched on deliberately.
 *
 * SAFETY — the whole point of this module. Every rule is enforced
 * server-side in `saveSettings()`; the UI only mirrors the rules
 * so an administrator is warned before submitting:
 *
 * 1. The `administrator` role can NEVER be edited or deleted. Any
 *    attempt is refused with an error and nothing is written.
 * 2. Built-in roles (`administrator`, `editor`, `author`,
 *    `contributor`, `subscriber`) can NEVER be deleted. Their
 *    capabilities may be edited — except `administrator` (rule 1).
 *    Custom roles can be deleted.
 * 3. Before writing ANY change, the module verifies at least one
 *    user will still have `manage_options` afterwards, counting
 *    users per role against the PROPOSED capability map (so a
 *    role edit, a clone, and a delete are all checked the same
 *    way, including users who hold several roles). If the change
 *    would leave zero, the whole save is refused with a clear
 *    error and nothing is written. On multisite, an existing
 *    super admin also counts, because super admins hold
 *    `manage_options` regardless of roles.
 * 4. Capability names in a save request are validated against
 *    `CapabilityAllowlist` (WordPress core capabilities plus a
 *    small documented set of common plugin capabilities). An
 *    unknown name is rejected and nothing is written. Cloning
 *    copies capability names that already exist on the source
 *    role in the database — the request supplies no capability
 *    names at all on that path, only the slug, the display name,
 *    and whether `manage_options` is granted.
 * 5. The whole page requires `manage_options`: the admin menu
 *    already gates the page on it, `renderSettingsPage()` refuses
 *    to render without it, and every save re-checks it
 *    server-side. The nonce is the shared `traydigita-admin`
 *    action sent as `_ajax_nonce` by the generic dispatcher.
 * 6. Every change is logged by `RoleChangeLog` (last 50 entries:
 *    who, when, what role, what changed), shown on the list view.
 * 7. ONLY the roles API is used: `add_role()`, `remove_role()`,
 *    `WP_Role::add_cap()`, `WP_Role::remove_cap()`. No direct
 *    `wp-config.php` writes, no direct `$wpdb` writes to the
 *    options or usermeta tables for roles.
 *
 * All role work flows through the generic dispatcher
 * (`traydigita_admin_save`): the edit, clone, and delete forms
 * carry `data-traydigita-module-form` + `data-module="role-editor"`
 * and a hidden `operation` field (`edit`, `clone`, or `delete`).
 * Deleting a role additionally requires typing the role slug as
 * confirmation. After a successful save the page reloads (see
 * `resources/js/modules/admin-role-editor.ts`) so the role list
 * and the change log show the new state.
 */
final class RoleEditorModule implements ModuleInterface
{
    /**
     * Module id.
     */
    public const string ID = 'role-editor';

    /**
     * Nonce action shared with the admin dispatcher.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * The role that can never be edited or deleted.
     */
    public const string ADMIN_ROLE = 'administrator';

    /**
     * Built-in roles: never deletable (capabilities of all but
     * `administrator` may still be edited).
     *
     * @var array<int, string>
     */
    public const array BUILTIN_ROLES = [
        'administrator',
        'editor',
        'author',
        'contributor',
        'subscriber',
    ];

    /**
     * Capability that grants full site administration.
     */
    public const string ADMIN_CAPABILITY = 'manage_options';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return self::ID;
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'administration';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Custom Role Editor', 'traydigita-core');
    }

    /**
     * Module description shown on the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Clone and edit user roles and their capabilities, with grouped capability checkboxes. The administrator role can never be edited or deleted, built-in roles can never be deleted, and no save that would leave the site without an administrator is ever applied.', 'traydigita-core');
    }

    /**
     * Dashicons slug for the module card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-users';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Clone and edit user roles and their capabilities.', 'traydigita-core'),
            __('Grouped capability checkboxes.', 'traydigita-core'),
            __('The administrator role can never be edited or deleted.', 'traydigita-core'),
            __('Built-in roles can never be deleted.', 'traydigita-core'),
            __('No save that would leave the site without an administrator is ever applied.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-role-editor';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Always true: the editor needs no setup — roles already
     * exist, and the log starts empty.
     *
     * @return bool Always true.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and disabled by default.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'roles', 'label' => __('Roles', 'traydigita-core'), 'icon' => 'groups'],
            ['id' => 'log', 'label' => __('Change log', 'traydigita-core'), 'icon' => 'list-view'],
            ['id' => 'capabilities', 'label' => __('Capabilities', 'traydigita-core'), 'icon' => 'admin-network'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Refuses to render anything but an error when the current
     * user lacks `manage_options`. Otherwise loads
     * `templates/admin/modules/role-editor.php` with the role
     * list, the selected role for the edit/clone/delete view
     * (from `?view=` + `?role=`), the grouped capability list,
     * and the change log. An unknown view or role falls back to
     * the role list.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can(self::ADMIN_CAPABILITY)) {
            echo '<p>' . esc_html__('You are not allowed to edit user roles.', 'traydigita-core') . '</p>';
            return;
        }
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $roles = self::listRoles();
        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'list';
        if (!in_array($view, ['list', 'edit', 'clone', 'delete'], true)) {
            $view = 'list';
        }
        $selectedRole = isset($_GET['role']) && is_string($_GET['role']) ? sanitize_key(wp_unslash($_GET['role'])) : '';
        if ($selectedRole === '' || !isset($roles[$selectedRole])) {
            $selectedRole = '';
            $view = 'list';
        }
        $selected = $selectedRole !== '' ? $roles[$selectedRole] : null;
        $groups = self::capabilityGroups();
        $log = RoleChangeLog::get();
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/role-editor.php';
        if (!file_exists($partial)) {
            echo '<p>' . esc_html__('The Role Editor settings template is missing.', 'traydigita-core') . '</p>';
            return;
        }
        require $partial;
    }

    /**
     * Save one role operation from a submitted form.
     *
     * Dispatches on the hidden `operation` field (`edit`,
     * `clone`, `delete`). The capability is re-checked here
     * server-side even though the dispatcher already checked it,
     * and every operation re-validates its inputs, enforces the
     * safety rules, and refuses with an error before writing
     * anything when a rule fails.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can(self::ADMIN_CAPABILITY)) {
            return [
                'success' => false,
                'message' => __('You are not allowed to edit user roles.', 'traydigita-core'),
            ];
        }
        $operation = isset($data['operation']) && is_string($data['operation'])
            ? sanitize_key($data['operation'])
            : '';
        return match ($operation) {
            'edit' => self::editRole($data),
            'clone' => self::cloneRole($data),
            'delete' => self::deleteRole($data),
            default => [
                'success' => false,
                'message' => __('Unknown role operation.', 'traydigita-core'),
            ],
        };
    }

    /**
     * Register the module's hooks. Called only when enabled.
     *
     * The module has no frontend or login hooks: all role work
     * flows through the generic admin dispatcher and the
     * settings page, so there is nothing to register. The
     * enabled guard stays so a direct call is safe too.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
    }

    /**
     * Capability groups with translated labels, for the template.
     *
     * @return array<string, array{label: string, caps: array<int, string>}>
     */
    public static function capabilityGroups(): array
    {
        $labels = [
            'general' => __('General', 'traydigita-core'),
            'posts' => __('Posts', 'traydigita-core'),
            'pages' => __('Pages', 'traydigita-core'),
            'media' => __('Media', 'traydigita-core'),
            'comments' => __('Comments', 'traydigita-core'),
            'users' => __('Users', 'traydigita-core'),
            'appearance' => __('Appearance', 'traydigita-core'),
            'plugins' => __('Plugins', 'traydigita-core'),
            'advanced' => __('Advanced', 'traydigita-core'),
            'woocommerce' => __('WooCommerce', 'traydigita-core'),
            'seo' => __('SEO', 'traydigita-core'),
        ];
        $groups = [];
        foreach (CapabilityAllowlist::GROUPS as $key => $caps) {
            $groups[$key] = [
                'label' => $labels[$key] ?? $key,
                'caps' => $caps,
            ];
        }
        return $groups;
    }

    /**
     * List every role with its capabilities and user count.
     *
     * @return array<string, array{name: string, capabilities: array<int, string>, users: int, builtin: bool, protected: bool, deletable: bool}>
     */
    public static function listRoles(): array
    {
        $counts = self::countUsersByRole();
        $roles = [];
        foreach (self::allRolesCaps() as $slug => $caps) {
            $roles[$slug] = [
                'name' => self::roleDisplayName($slug),
                'capabilities' => $caps,
                'users' => $counts[$slug] ?? 0,
                'builtin' => in_array($slug, self::BUILTIN_ROLES, true),
                'protected' => $slug === self::ADMIN_ROLE,
                'deletable' => !in_array($slug, self::BUILTIN_ROLES, true),
            ];
        }
        return $roles;
    }

    /**
     * Whether the proposed capability map would leave zero users
     * with `manage_options`.
     *
     * Pure logic over explicit inputs, so it is unit-testable
     * without WordPress: `$proposedCaps` maps each role slug to
     * its capability list AFTER the proposed change, and
     * `$userCounts` maps each role slug to its user count. A
     * role only counts when it grants `manage_options` AND has
     * at least one user — a user holding several roles is
     * counted under each of them by `count_users()`, so a
     * multi-role user still counts through the role that keeps
     * the capability. On multisite, an existing super admin also
     * counts, because super admins hold `manage_options`
     * regardless of roles.
     *
     * @param array<string, array<int, string>> $proposedCaps Role slug => capabilities after the change.
     * @param array<string, int> $userCounts Role slug => user count.
     * @return bool True when NO user would retain `manage_options`.
     */
    public static function wouldLeaveZeroAdmins(array $proposedCaps, array $userCounts): bool
    {
        foreach ($proposedCaps as $slug => $caps) {
            if (!is_array($caps)) {
                continue;
            }
            if (in_array(self::ADMIN_CAPABILITY, $caps, true) && ($userCounts[$slug] ?? 0) > 0) {
                return false;
            }
        }
        if (function_exists('is_multisite') && is_multisite()
            && function_exists('get_super_admins') && get_super_admins() !== []
        ) {
            return false;
        }
        return true;
    }

    /**
     * Edit one role's capabilities.
     *
     * Expected fields: `role` (slug), `caps[]` (the complete
     * desired capability set — unchecked boxes are absent, so
     * the list is the new set, not a delta). Refuses, writing
     * nothing, when the role is `administrator`, the role does
     * not exist, any capability is not allowlisted, or the
     * change would leave zero `manage_options` holders. Applies
     * only the diff (`add_cap` for added, `remove_cap` for
     * removed) and logs what changed.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    private static function editRole(array $data): array
    {
        $role = isset($data['role']) && is_string($data['role']) ? sanitize_key($data['role']) : '';
        if ($role === '') {
            return self::failure(__('Select a role to edit.', 'traydigita-core'), ['role' => __('Select a role to edit.', 'traydigita-core')]);
        }
        if ($role === self::ADMIN_ROLE) {
            return self::failure(
                __('The administrator role can never be edited.', 'traydigita-core'),
                ['role' => __('The administrator role can never be edited.', 'traydigita-core')]
            );
        }
        $wpRole = get_role($role);
        if ($wpRole === null) {
            return self::failure(__('Unknown role.', 'traydigita-core'), ['role' => __('Unknown role.', 'traydigita-core')]);
        }
        $requested = self::readCapabilityList($data['caps'] ?? []);
        $unknown = [];
        foreach ($requested as $cap) {
            if (!CapabilityAllowlist::isAllowed($cap)) {
                $unknown[] = $cap;
            }
        }
        if ($unknown !== []) {
            return self::failure(
                sprintf(
                    /* translators: %s is a comma-separated list of unknown capability names. */
                    __('Unknown capabilities: %s. Nothing was saved.', 'traydigita-core'),
                    implode(', ', $unknown)
                ),
                ['caps' => __('One or more capabilities are not recognised.', 'traydigita-core')]
            );
        }
        $proposed = self::allRolesCaps();
        $proposed[$role] = $requested;
        if (self::wouldLeaveZeroAdmins($proposed, self::countUsersByRole())) {
            return self::failure(
                __('This change would leave no user able to manage the site. Nothing was saved.', 'traydigita-core'),
                ['caps' => __('At least one user must keep the manage_options capability.', 'traydigita-core')]
            );
        }
        $current = self::roleCapabilities($role);
        $added = array_diff($requested, $current);
        $removed = array_diff($current, $requested);
        if ($added === [] && $removed === []) {
            return [
                'success' => true,
                'message' => __('No capability changes for this role.', 'traydigita-core'),
                'data' => ['role' => $role, 'operation' => 'edit'],
            ];
        }
        foreach ($added as $cap) {
            $wpRole->add_cap($cap);
        }
        foreach ($removed as $cap) {
            $wpRole->remove_cap($cap);
        }
        $details = self::describeDiff($added, $removed);
        RoleChangeLog::record('updated', $role, $details);
        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s is the role display name. */
                __('Capabilities saved for %s.', 'traydigita-core'),
                self::roleDisplayName($role)
            ),
            'data' => ['role' => $role, 'operation' => 'edit'],
        ];
    }

    /**
     * Clone an existing role into a new role.
     *
     * Expected fields: `source_role` (slug), `new_slug`,
     * `new_name`, and the optional `grant_manage_options`
     * checkbox. The new role copies the source role's
     * capabilities, except `manage_options`: it is stripped
     * unless explicitly granted, and when granted it is added
     * even when the source role lacks it. Granting it still
     * passes the zero-admin check (adding a role can only add
     * `manage_options` holders, never remove them). The slug
     * must be a fresh, valid role slug; a collision or an
     * invalid slug is refused. The source role may be any
     * existing role, including `administrator`: cloning it does
     * not edit it, and its `manage_options` is still stripped
     * unless granted.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    private static function cloneRole(array $data): array
    {
        $source = isset($data['source_role']) && is_string($data['source_role'])
            ? sanitize_key($data['source_role'])
            : '';
        if ($source === '' || get_role($source) === null) {
            return self::failure(__('Unknown source role.', 'traydigita-core'), ['source_role' => __('Unknown source role.', 'traydigita-core')]);
        }
        $slug = isset($data['new_slug']) && is_string($data['new_slug'])
            ? sanitize_key($data['new_slug'])
            : '';
        if ($slug === '' || preg_match('/^[a-z0-9_]{2,60}$/', $slug) !== 1) {
            return self::failure(
                __('The new role slug must be 2–60 lowercase letters, digits, or underscores.', 'traydigita-core'),
                ['new_slug' => __('Use 2–60 lowercase letters, digits, or underscores.', 'traydigita-core')]
            );
        }
        if (get_role($slug) !== null) {
            return self::failure(
                __('A role with that slug already exists.', 'traydigita-core'),
                ['new_slug' => __('A role with that slug already exists.', 'traydigita-core')]
            );
        }
        $name = isset($data['new_name']) && is_string($data['new_name'])
            ? sanitize_text_field($data['new_name'])
            : '';
        if ($name === '') {
            return self::failure(
                __('Give the new role a display name.', 'traydigita-core'),
                ['new_name' => __('Give the new role a display name.', 'traydigita-core')]
            );
        }
        $grantAdmin = !empty($data['grant_manage_options']);
        $caps = self::roleCapabilities($source);
        if ($grantAdmin) {
            if (!in_array(self::ADMIN_CAPABILITY, $caps, true)) {
                $caps[] = self::ADMIN_CAPABILITY;
            }
        } else {
            $caps = array_values(array_diff($caps, [self::ADMIN_CAPABILITY]));
        }
        $proposed = self::allRolesCaps();
        $proposed[$slug] = $caps;
        if (self::wouldLeaveZeroAdmins($proposed, self::countUsersByRole())) {
            return self::failure(
                __('This change would leave no user able to manage the site. Nothing was saved.', 'traydigita-core'),
                ['new_slug' => __('At least one user must keep the manage_options capability.', 'traydigita-core')]
            );
        }
        $created = add_role($slug, $name, array_fill_keys($caps, true));
        if ($created === null) {
            return self::failure(__('Could not create the role.', 'traydigita-core'));
        }
        RoleChangeLog::record(
            'created',
            $slug,
            sprintf(
                /* translators: %1$s is the source role slug, %2$s is "granted" or "not granted" for manage_options. */
                __('Cloned from %1$s; manage_options %2$s.', 'traydigita-core'),
                $source,
                $grantAdmin ? __('granted', 'traydigita-core') : __('not granted', 'traydigita-core')
            )
        );
        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s is the new role display name. */
                __('Role %s created.', 'traydigita-core'),
                $name
            ),
            'data' => ['role' => $slug, 'operation' => 'clone'],
        ];
    }

    /**
     * Delete a custom role.
     *
     * Expected fields: `role` (slug) and `confirm` (the role slug
     * typed as confirmation). Built-in roles are refused outright;
     * a missing or mismatched confirmation is refused; and the
     * zero-admin check runs against the role map without the
     * deleted role, so deleting the last `manage_options` role
     * is refused and nothing is written. Users who held only
     * the deleted role keep their accounts but lose that role —
     * the page shows the affected user count before confirming.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    private static function deleteRole(array $data): array
    {
        $role = isset($data['role']) && is_string($data['role']) ? sanitize_key($data['role']) : '';
        if ($role === '') {
            return self::failure(__('Select a role to delete.', 'traydigita-core'), ['role' => __('Select a role to delete.', 'traydigita-core')]);
        }
        if ($role === self::ADMIN_ROLE) {
            return self::failure(
                __('The administrator role can never be deleted.', 'traydigita-core'),
                ['role' => __('The administrator role can never be deleted.', 'traydigita-core')]
            );
        }
        if (in_array($role, self::BUILTIN_ROLES, true)) {
            return self::failure(
                __('Built-in roles can never be deleted.', 'traydigita-core'),
                ['role' => __('Built-in roles can never be deleted.', 'traydigita-core')]
            );
        }
        if (get_role($role) === null) {
            return self::failure(__('Unknown role.', 'traydigita-core'), ['role' => __('Unknown role.', 'traydigita-core')]);
        }
        $confirm = isset($data['confirm']) && is_string($data['confirm'])
            ? sanitize_text_field($data['confirm'])
            : '';
        if ($confirm === '' || $confirm !== $role) {
            return self::failure(
                __('Type the role slug to confirm the delete.', 'traydigita-core'),
                ['confirm' => __('Type the role slug exactly to confirm.', 'traydigita-core')]
            );
        }
        $counts = self::countUsersByRole();
        $userCount = $counts[$role] ?? 0;
        $proposed = self::allRolesCaps();
        unset($proposed[$role]);
        if (self::wouldLeaveZeroAdmins($proposed, $counts)) {
            return self::failure(
                __('Deleting this role would leave no user able to manage the '
                    . 'site. Nothing was deleted.', 'traydigita-core'),
                ['role' => __('At least one user must keep the manage_options capability.', 'traydigita-core')]
            );
        }
        remove_role($role);
        RoleChangeLog::record(
            'deleted',
            $role,
            sprintf(
                /* translators: %d is the number of users who held the deleted role. */
                __('Role deleted; %d user(s) held this role.', 'traydigita-core'),
                $userCount
            )
        );
        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %s is the deleted role slug. */
                __('Role %s deleted.', 'traydigita-core'),
                $role
            ),
            'data' => ['role' => $role, 'operation' => 'delete'],
        ];
    }

    /**
     * Build a failure result, optionally with field errors.
     *
     * @param string $message Human-readable error.
     * @param array<string, string> $errors Field errors.
     * @return array{success: bool, message: string, errors?: array<string, string>}
     */
    private static function failure(string $message, array $errors = []): array
    {
        $result = [
            'success' => false,
            'message' => $message,
        ];
        if ($errors !== []) {
            $result['errors'] = $errors;
        }
        return $result;
    }

    /**
     * Read a posted capability list into clean, unique slugs.
     *
     * Accepts the `caps[]` checkbox array (or a single string).
     * Names are passed through `sanitize_key()`; allowlist
     * validation happens in the caller, so unknown names are
     * reported there rather than silently dropped here.
     *
     * @param mixed $raw Raw posted value.
     * @return array<int, string>
     */
    private static function readCapabilityList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $caps = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $cap = sanitize_key($item);
            if ($cap !== '' && !in_array($cap, $caps, true)) {
                $caps[] = $cap;
            }
        }
        return $caps;
    }

    /**
     * Plain-language summary of a capability diff for the log.
     *
     * @param array<int, string> $added Capabilities added.
     * @param array<int, string> $removed Capabilities removed.
     * @return string
     */
    private static function describeDiff(array $added, array $removed): string
    {
        $parts = [];
        if ($added !== []) {
            $parts[] = sprintf(
                /* translators: %s is a comma-separated list of capability names. */
                __('Added: %s', 'traydigita-core'),
                implode(', ', $added)
            );
        }
        if ($removed !== []) {
            $parts[] = sprintf(
                /* translators: %s is a comma-separated list of capability names. */
                __('Removed: %s', 'traydigita-core'),
                implode(', ', $removed)
            );
        }
        return implode(' ', $parts);
    }

    /**
     * All roles mapped to their granted capability names.
     *
     * Reads `wp_roles()->roles` (the roles API's own store) and
     * keeps only capabilities stored as truthy — WordPress
     * stores capabilities as `capability => bool`.
     *
     * @return array<string, array<int, string>> Role slug => capability names.
     */
    private static function allRolesCaps(): array
    {
        $map = [];
        if (!function_exists('wp_roles')) {
            return $map;
        }
        $wpRoles = wp_roles();
        if (!is_object($wpRoles) || !is_array($wpRoles->roles)) {
            return $map;
        }
        foreach ($wpRoles->roles as $slug => $details) {
            if (!is_string($slug) || !is_array($details)) {
                continue;
            }
            $caps = $details['capabilities'] ?? [];
            $map[$slug] = is_array($caps) ? array_keys(array_filter($caps)) : [];
        }
        return $map;
    }

    /**
     * Granted capability names for one role.
     *
     * @param string $slug Role slug.
     * @return array<int, string>
     */
    private static function roleCapabilities(string $slug): array
    {
        $map = self::allRolesCaps();
        return $map[$slug] ?? [];
    }

    /**
     * Display name for a role slug.
     *
     * @param string $slug Role slug.
     * @return string
     */
    private static function roleDisplayName(string $slug): string
    {
        if (!function_exists('wp_roles')) {
            return $slug;
        }
        $wpRoles = wp_roles();
        if (!is_object($wpRoles) || !is_array($wpRoles->roles)) {
            return $slug;
        }
        $details = $wpRoles->roles[$slug] ?? null;
        if (!is_array($details) || !isset($details['name']) || !is_string($details['name'])) {
            return $slug;
        }
        return $details['name'];
    }

    /**
     * User count per role slug.
     *
     * Uses `count_users()` (which counts a multi-role user
     * under each of their roles — exactly what the zero-admin
     * check needs). Falls back to a `get_users()` scan when
     * `count_users()` is unavailable.
     *
     * @return array<string, int> Role slug => user count.
     */
    private static function countUsersByRole(): array
    {
        if (function_exists('count_users')) {
            $counts = count_users();
            if (is_array($counts) && isset($counts['avail_roles']) && is_array($counts['avail_roles'])) {
                $out = [];
                foreach ($counts['avail_roles'] as $slug => $count) {
                    if (is_string($slug) && is_int($count)) {
                        $out[$slug] = $count;
                    }
                }
                return $out;
            }
        }
        $out = [];
        if (!function_exists('get_users')) {
            return $out;
        }
        $users = get_users();
        if (!is_array($users)) {
            return $out;
        }
        foreach ($users as $user) {
            if (!is_object($user) || !isset($user->roles) || !is_array($user->roles)) {
                continue;
            }
            foreach ($user->roles as $slug) {
                if (!is_string($slug)) {
                    continue;
                }
                $out[$slug] = ($out[$slug] ?? 0) + 1;
            }
        }
        return $out;
    }
}
