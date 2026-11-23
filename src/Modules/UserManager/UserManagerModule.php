<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\UserManager;

use TrayDigita\WP\Plugin\Core\Admin\AdminNotice;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use WP_User;
use function __;
use function add_action;
use function add_filter;
use function admin_url;
use function array_values;
use function checked;
use function class_exists;
use function current_user_can;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function get_current_screen;
use function get_editable_roles;
use function get_option;
use function get_role;
use function get_user_by;
use function get_user_meta;
use function get_users;
use function is_array;
use function is_numeric;
use function sanitize_key;
use function sprintf;
use function time;
use function update_option;
use function update_user_meta;
use function user_can;
use function wp_create_nonce;
use function wp_date;
use function wp_get_current_user;
use function wp_nonce_field;
use function get_avatar;

/**
 * User Manager module.
 *
 * Enhances the core Users list screen instead of building a
 * parallel user table: it adds an avatar column and a last-login
 * column to `WP_Users_List_Table` (via `manage_users_columns` /
 * `manage_users_custom_column`), makes the last-login column
 * sortable, and adds a quick role-change select per row. Role
 * filtering and searching by name or email are the core list
 * table's own features (role views and dropdown, search box);
 * this module relies on them rather than duplicating them, and
 * the module page says so.
 *
 * Last login is tracked on `wp_login` into user meta
 * `traydigita_last_login` as a Unix timestamp, and displayed with
 * `wp_date()` in the site timezone (WordPress core has no
 * per-user timezone setting; the site timezone is what core
 * date functions use everywhere else).
 *
 * Avatars are never rendered by this module's own logic: the
 * column calls core `get_avatar()`, which the Custom Avatar
 * module overrides through its `get_avatar` filter whenever it
 * is enabled, and which falls back to Gravatar (or the site
 * default) otherwise. Changing a user's avatar is likewise
 * delegated: the module page links to the Custom Avatar module
 * page when that module is enabled, and to the user's profile
 * page otherwise (the Avatar module renders its fields there,
 * and core renders its own avatar UI when the module is off).
 *
 * Safety rules (enforced server-side in changeUserRole(), never
 * only in JavaScript):
 * - A role change targeting a user who has the `manage_options`
 *   capability is refused when the actor is a different user.
 *   Only the user themself may change their own role. This
 *   protects administrator (and any other manage_options) accounts
 *   from being demoted by another administrator. The rule is
 *   stated on the module page and on the Users screen.
 * - The target user must exist (`get_user_by()`); unknown user
 *   ids are refused, never acted on.
 * - The new role must be one of the editable roles
 *   (`get_editable_roles()`); anything else is refused.
 * - Password hashes are never exposed: every AJAX response and
 *   every list output goes through safeUserSummary(), an explicit
 *   allowlist of fields (id, login, display name, email, roles,
 *   last login). `user_pass` never appears in any output, log,
 *   or AJAX response.
 *
 * Settings live in the single option `traydigita_user_manager`
 * (autoload `no`): which columns are shown and whether last
 * login is tracked. The settings page is rendered inline here
 * (no template partial), following SeoModule and AvatarModule.
 */
final class UserManagerModule implements ModuleInterface
{
    /**
     * Option name for all User Manager settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_user_manager';

    /**
     * User meta key holding the last-login Unix timestamp.
     */
    public const string LAST_LOGIN_META = 'traydigita_last_login';

    /**
     * Fully qualified class name of the Custom Avatar module.
     * Guarded with class_exists() everywhere it is used, because
     * the Avatar module is built by a parallel track and may not
     * exist (or may be disabled) on this install.
     */
    public const string AVATAR_MODULE_CLASS = 'TrayDigita\\WP\\Plugin\\Core\\Modules\\Avatar\\AvatarModule';

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'user-manager';
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
        return __('User Manager', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Enhance the Users screen with avatar and last-login columns and quick role changes, with administrators protected from being demoted by anyone except themselves.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
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
            __('Avatar and last-login columns on the Users screen.', 'traydigita-core'),
            __('Quick role changes.', 'traydigita-core'),
            __('Administrators are protected from being demoted by anyone '
                . 'except another administrator.', 'traydigita-core'),
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
    public static function settingsPageSlug(): string
    {
        return 'traydigita-user-manager';
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
     * The module works with its defaults, so it is always
     * configured.
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
     * @return bool False: the module is optional and toggleable.
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Three parts: the settings form (which columns to show,
     * whether last login is tracked), the safety-rule notice
     * (administrators can only be re-roled by themselves), and
     * the bulk role-change panel (multi-select of users plus a
     * target role, handled by admin-user-manager.ts through the
     * module's own AJAX endpoint). A link to the core Users
     * screen and a link to the avatar manager (Custom Avatar
     * module page when enabled, profile-page hint otherwise)
     * complete the page.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $roles = get_editable_roles();
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email'],
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 200,
        ]);
        $avatarEnabled = self::isAvatarModuleEnabled();
        $avatarUrl = $avatarEnabled
            ? admin_url('admin.php?page=traydigita-avatar')
            : admin_url('users.php');
        ?>
        <div
            class="traydigita-module-settings user-manager-settings"
            data-user-manager-admin
            data-msg-saved="<?php esc_attr_e('Role updated.', 'traydigita-core'); ?>"
            data-msg-error="<?php esc_attr_e('Something went wrong. Try again.', 'traydigita-core'); ?>"
            data-msg-no-user="<?php esc_attr_e('Choose at least one user first.', 'traydigita-core'); ?>"
            data-msg-no-role="<?php esc_attr_e('Choose a role first.', 'traydigita-core'); ?>"
            data-msg-working="<?php esc_attr_e('Working…', 'traydigita-core'); ?>"
            data-msg-changed="<?php esc_attr_e('Changed', 'traydigita-core'); ?>"
            data-msg-refused="<?php esc_attr_e('Refused', 'traydigita-core'); ?>"
            data-msg-skipped="<?php esc_attr_e('Skipped', 'traydigita-core'); ?>"
        >
            <?php
            SettingsControls::intro(
                __('User Manager enhances the core Users screen — it does not replace it.', 'traydigita-core'),
                __('Filter by role and search by name or email from the Users screen itself; this page holds the module settings, the safety rules, and bulk role changes.', 'traydigita-core')
            );
            ?>
            <p class="user-manager-link">
                <a class="traydigita-admin-button" href="<?php echo esc_url(admin_url('users.php')); ?>">
                    <?php esc_html_e('Open the Users screen', 'traydigita-core'); ?>
                </a>
                <a class="traydigita-admin-button" href="<?php echo esc_url($avatarUrl); ?>">
                    <?php esc_html_e('Manage avatars', 'traydigita-core'); ?>
                </a>
            </p>

            <?php
            AdminNotice::renderHtml(
                'warning',
                '<p class="traydigita-ui-notice-message">'
                    . SettingsControls::helpBlock(
                        __('A role change targeting a user who can manage_options is refused when the '
                            . 'change comes from a different administrator.', 'traydigita-core'),
                        __('Only the user themself may change their own role. This stops one administrator from demoting another administrator (or any other manage_options account). The rule is enforced on the server for every role change, including bulk changes.', 'traydigita-core')
                    )
                    . '</p>',
                __('Safety rule: administrators are protected.', 'traydigita-core')
            );
            ?>

            <form class="user-manager-settings-form" data-traydigita-module-form data-traydigita-module="user-manager" data-module="user-manager" method="post" action="">
                <?php wp_nonce_field('traydigita_user_manager_save'); ?>
                <p class="user-manager-field">
                    <label class="traydigita-ui-toggle" for="user-manager-show-avatar"><input class="traydigita-ui-toggle-input" type="checkbox" id="user-manager-show-avatar" name="show_avatar_column" value="1" <?php checked($settings['show_avatar_column']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the avatar column on the Users screen', 'traydigita-core'); ?></span></label>
                    <span class="user-manager-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Avatars come from the Custom Avatar module when it is enabled, otherwise '
                                . 'from Gravatar or the site default.', 'traydigita-core'),
                            __('The Custom Avatar module supplies the avatar shown in the Users list through its get_avatar filter. Avatars are changed from the user’s profile page.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>
                <p class="user-manager-field">
                    <label class="traydigita-ui-toggle" for="user-manager-show-last-login"><input class="traydigita-ui-toggle-input" type="checkbox" id="user-manager-show-last-login" name="show_last_login_column" value="1" <?php checked($settings['show_last_login_column']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the last-login column on the Users screen', 'traydigita-core'); ?></span></label>
                </p>
                <p class="user-manager-field">
                    <label class="traydigita-ui-toggle" for="user-manager-show-quick-role"><input class="traydigita-ui-toggle-input" type="checkbox" id="user-manager-show-quick-role" name="show_quick_role" value="1" <?php checked($settings['show_quick_role']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the quick role-change control on the Users screen', 'traydigita-core'); ?></span></label>
                    <span class="user-manager-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Requires the promote_users capability.', 'traydigita-core'),
                            __('The control is disabled for protected users (see the safety rule above).', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>
                <p class="user-manager-field">
                    <label class="traydigita-ui-toggle" for="user-manager-track-last-login"><input class="traydigita-ui-toggle-input" type="checkbox" id="user-manager-track-last-login" name="track_last_login" value="1" <?php checked($settings['track_last_login']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Record the last-login time when a user signs in', 'traydigita-core'); ?></span></label>
                    <span class="user-manager-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Stored as a timestamp in user meta and shown in the site timezone.', 'traydigita-core'),
                            __('Users who have never signed in since this was switched on show “Never”.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>
                <p class="user-manager-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>

            <div class="user-manager-bulk">
                <h2><?php esc_html_e('Bulk role change', 'traydigita-core'); ?></h2>
                <p class="user-manager-bulk-help">
                    <?php
                    SettingsControls::helpText(
                        __('Change the role of several users at once.', 'traydigita-core'),
                        __('Protected users (anyone who can manage_options, other than yourself) are skipped with an explanation — the rest still change.', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="user-manager-status" data-user-manager-status role="status" aria-live="polite"></p>
                <form class="user-manager-bulk-form" data-user-manager-bulk-form method="post" action="">
                    <p class="user-manager-field">
                        <label for="user-manager-bulk-users"><?php esc_html_e('Users', 'traydigita-core'); ?></label>
                        <select id="user-manager-bulk-users" name="user_ids[]" multiple size="8">
                            <?php foreach ($users as $user) : ?>
                                <option value="<?php echo esc_attr((string) $user->ID); ?>">
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="user-manager-field-help">
                            <?php
                            SettingsControls::helpText(
                                __('Hold Ctrl (or Cmd on macOS) to select several users.', 'traydigita-core'),
                                __('At most 200 users are listed here; use the Users screen for larger selections.', 'traydigita-core')
                            );
                            ?>
                        </span>
                    </p>
                    <p class="user-manager-field">
                        <label for="user-manager-bulk-role"><?php esc_html_e('Change role to', 'traydigita-core'); ?></label>
                        <select id="user-manager-bulk-role" name="role">
                            <option value=""><?php esc_html_e('Choose a role…', 'traydigita-core'); ?></option>
                            <?php foreach ($roles as $roleKey => $roleData) : ?>
                                <option value="<?php echo esc_attr($roleKey); ?>">
                                    <?php echo esc_html($roleData['name'] ?? $roleKey); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="user-manager-form-actions">
                        <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                            <?php esc_html_e('Change Role for Selected Users', 'traydigita-core'); ?>
                        </button>
                    </p>
                </form>
                <div class="user-manager-bulk-results" data-user-manager-bulk-results></div>
            </div>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `show_avatar_column`,
     * `show_last_login_column`, `show_quick_role`,
     * `track_last_login` (each `1` when checked; an absent key
     * means the checkbox was unchecked). Every value is cast to
     * boolean explicitly. Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change User Manager settings.', 'traydigita-core'),
            ];
        }
        $settings = [
            'show_avatar_column' => !empty($data['show_avatar_column']),
            'show_last_login_column' => !empty($data['show_last_login_column']),
            'show_quick_role' => !empty($data['show_quick_role']),
            'track_last_login' => !empty($data['track_last_login']),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('User Manager settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Registers the last-login tracker, the Users list
     * columns (avatar, last login, quick role), the sortable
     * last-login column, the AJAX endpoints, and the admin-entry
     * enqueue on the Users screen (the admin entry is otherwise
     * only loaded on TrayDigita pages, and the quick-role control
     * needs its JavaScript on users.php).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted || !ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        UserManagerAjax::register();
        add_action('wp_login', [self::class, 'recordLastLogin'], 10, 2);
        add_filter('manage_users_columns', [self::class, 'addColumns']);
        add_filter('manage_users_custom_column', [self::class, 'renderColumn'], 10, 3);
        add_filter('manage_users_sortable_columns', [self::class, 'addSortableColumns']);
        add_action('pre_get_users', [self::class, 'sortByLastLogin']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueUsersScreenAssets']);
        // Close the gap the module's own endpoints do not cover:
        // core's bulk "Change role to..." dropdown and the role
        // field on the profile screen both check the
        // `promote_user` meta capability per user. Deny it when
        // the target can manage options and the actor is someone
        // else, so no other admin can demote or re-role a site
        // administrator through core.
        add_filter('map_meta_cap', [self::class, 'guardPromoteUser'], 10, 4);
    }

    /**
     * Deny promoting a manage_options user by anyone else.
     *
     * `map_meta_cap` filter for `promote_user`: core checks this
     * capability per user for its bulk role change and for the
     * role field on the profile screen. When the target user can
     * manage options and the actor is a different user, the check
     * fails closed with `do_not_allow` — which also binds a
     * super admin. An administrator changing their own role is
     * untouched, and users without `manage_options` are
     * unaffected. Only the capability map is filtered here; no
     * role or user record is ever written.
     *
     * @param array<int, string> $caps Capabilities required (mapped).
     * @param string $cap Capability being checked.
     * @param int $userId Id of the user the check runs for (the actor).
     * @param array<int, mixed> $args Extra arguments; $args[0] is the target user id.
     * @return array<int, string> The (possibly denied) capability list.
     */
    public static function guardPromoteUser(array $caps, string $cap, int $userId, array $args): array
    {
        if ($cap !== 'promote_user' || !isset($args[0])) {
            return $caps;
        }
        $targetId = (int) $args[0];
        if ($targetId <= 0 || $targetId === $userId) {
            return $caps;
        }
        if (!user_can($targetId, 'manage_options')) {
            return $caps;
        }
        return ['do_not_allow'];
    }

    /**
     * Record a user's sign-in time in user meta.
     *
     * Stores a Unix timestamp so display can use the site
     * timezone at render time. Runs only when tracking is
     * switched on in the settings.
     *
     * @param string $userLogin Username used to sign in.
     * @param WP_User $user User who signed in.
     * @return void
     */
    public static function recordLastLogin(string $userLogin, WP_User $user): void
    {
        if (!self::getSettings()['track_last_login']) {
            return;
        }
        update_user_meta((int) $user->ID, self::LAST_LOGIN_META, time());
    }

    /**
     * Add the module columns to the Users list table.
     *
     * Columns are appended after the core columns, in this
     * order: avatar, last login, quick role. A column is only
     * added when its setting is switched on.
     *
     * @param array<string, string> $columns Core list-table columns.
     * @return array<string, string> Columns with the module columns added.
     */
    public static function addColumns(array $columns): array
    {
        $settings = self::getSettings();
        if ($settings['show_avatar_column']) {
            $columns['traydigita_avatar'] = __('Avatar', 'traydigita-core');
        }
        if ($settings['show_last_login_column']) {
            $columns['traydigita_last_login'] = __('Last Login', 'traydigita-core');
        }
        if ($settings['show_quick_role'] && current_user_can('promote_users')) {
            $columns['traydigita_quick_role'] = __('Quick Role', 'traydigita-core');
        }
        return $columns;
    }

    /**
     * Render one module column cell for one user.
     *
     * Only the module's own columns are handled; every other
     * column returns the passed value untouched. No password
     * hash or other sensitive field is ever printed here — the
     * cells contain only the avatar image, a formatted date,
     * or the role select.
     *
     * @param string $value Default cell content from core.
     * @param string $columnName Column slug.
     * @param int $userId Row user id.
     * @return string Cell HTML.
     */
    public static function renderColumn(string $value, string $columnName, int $userId): string
    {
        if ($columnName === 'traydigita_avatar') {
            return self::avatarHtml($userId, 32);
        }
        if ($columnName === 'traydigita_last_login') {
            return esc_html(self::formatLastLogin($userId));
        }
        if ($columnName === 'traydigita_quick_role') {
            return self::quickRoleHtml($userId);
        }
        return $value;
    }

    /**
     * Register the last-login column as sortable.
     *
     * @param array<string, string> $columns Sortable columns.
     * @return array<string, string> Columns with last login added.
     */
    public static function addSortableColumns(array $columns): array
    {
        $columns['traydigita_last_login'] = 'traydigita_last_login';
        return $columns;
    }

    /**
     * Sort the Users query by last login when requested.
     *
     * Only touches the query when the list table asked to order
     * by the last-login column; the meta key is fixed (never
     * user input) and the sort is numeric.
     *
     * @param \WP_User_Query $query User query about to run.
     * @return void
     */
    public static function sortByLastLogin(\WP_User_Query $query): void
    {
        if ($query->get('orderby') !== 'traydigita_last_login') {
            return;
        }
        $query->set('meta_key', self::LAST_LOGIN_META);
        $query->set('orderby', 'meta_value_num');
    }

    /**
     * Enqueue the admin entry on the Users screen.
     *
     * The admin entry (which carries admin-user-manager.ts once
     * the coordinator wires the import) is otherwise only
     * loaded on TrayDigita pages. The quick-role control on
     * users.php needs it, so this loads the entry there too —
     * only for users who may change roles.
     *
     * @param string $hookSuffix Current admin page hook suffix.
     * @return void
     */
    public static function enqueueUsersScreenAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'users.php') {
            return;
        }
        if (!current_user_can('promote_users')) {
            return;
        }
        $screen = get_current_screen();
        if ($screen !== null && $screen->id !== 'users') {
            return;
        }
        Core::assets()->enqueueAdminEntry();
    }

    /**
     * Whether the Custom Avatar module exists and is enabled.
     *
     * Used only to pick the avatar-management link on the
     * module page; avatar rendering itself always goes through
     * get_avatar(), so this never gates the column output.
     *
     * @return bool
     */
    public static function isAvatarModuleEnabled(): bool
    {
        $class = self::AVATAR_MODULE_CLASS;
        if (!class_exists($class)) {
            return false;
        }
        return ModuleRegistry::isEnabled($class::id());
    }

    /**
     * Avatar `<img>` markup for a user.
     *
     * Always core `get_avatar()`: when the Custom Avatar module
     * is enabled its `get_avatar` filter returns the custom
     * avatar, and when it is not, core returns Gravatar (or the
     * site default). No avatar logic is duplicated here.
     *
     * @param int $userId User id.
     * @param int $size Avatar size in pixels.
     * @return string Avatar `<img>` markup (already escaped by its source).
     */
    public static function avatarHtml(int $userId, int $size): string
    {
        if ($userId <= 0 || $size <= 0) {
            return '';
        }
        $avatar = get_avatar($userId, $size);
        return is_string($avatar) ? $avatar : '';
    }

    /**
     * Formatted last-login time for a user, in the site timezone.
     *
     * WordPress core has no per-user timezone setting, so the
     * site timezone (what `wp_date()` uses) is the display
     * timezone, matching every other date in the admin. Users
     * who never signed in while tracking was on show "Never".
     *
     * @param int $userId User id.
     * @return string Formatted date and time, or "Never".
     */
    public static function formatLastLogin(int $userId): string
    {
        $stored = get_user_meta($userId, self::LAST_LOGIN_META, true);
        if (!is_numeric($stored) || (int) $stored <= 0) {
            return __('Never', 'traydigita-core');
        }
        $format = (string) get_option('date_format', 'Y-m-d')
            . ' ' . (string) get_option('time_format', 'H:i');
        $formatted = wp_date($format, (int) $stored);
        return is_string($formatted) ? $formatted : __('Never', 'traydigita-core');
    }

    /**
     * Quick role-change control for one user row.
     *
     * A select of the editable roles with the user's current
     * (first) role selected, carrying the AJAX action data the
     * JavaScript needs (nonce, ajax URL, user id) plus the
     * translatable status strings. For a protected user — one
     * who can `manage_options` and is not the current user —
     * the select is rendered disabled with an explanation, so
     * the protection is visible before any request is made
     * (the server refuses it too).
     *
     * @param int $userId Row user id.
     * @return string Control HTML (empty when the viewer may not change roles).
     */
    public static function quickRoleHtml(int $userId): string
    {
        if (!current_user_can('promote_users')) {
            return '';
        }
        $user = get_user_by('id', $userId);
        if ($user === false) {
            return '';
        }
        $roles = get_editable_roles();
        if ($roles === []) {
            return '';
        }
        $currentRole = is_array($user->roles) && $user->roles !== []
            ? sanitize_key((string) $user->roles[0])
            : '';
        $actor = wp_get_current_user();
        $protected = !self::canChangeRole($user, $actor);
        $nonce = wp_create_nonce(UserManagerAjax::NONCE_ACTION);
        $ajaxUrl = admin_url('admin-ajax.php');
        $html = '<span class="user-manager-quick-role" data-user-manager-quick-role-wrap data-user-id="'
            . esc_attr((string) $userId) . '">';
        $html .= '<select class="user-manager-quick-role-select" data-user-manager-quick-role'
            . ' data-user-id="' . esc_attr((string) $userId) . '"'
            . ' data-nonce="' . esc_attr($nonce) . '"'
            . ' data-ajax-url="' . esc_attr($ajaxUrl) . '"'
            . ' data-action="' . esc_attr(UserManagerAjax::ACTION_CHANGE_ROLE) . '"'
            . ' data-current-role="' . esc_attr($currentRole) . '"'
            . ' data-msg-saved="' . esc_attr__('Role updated.', 'traydigita-core') . '"'
            . ' data-msg-error="' . esc_attr__('Something went wrong. Try again.', 'traydigita-core') . '"'
            . ' aria-label="' . esc_attr__('Change role', 'traydigita-core') . '"'
            . ($protected ? ' disabled' : '') . '>';
        foreach ($roles as $roleKey => $roleData) {
            $label = is_array($roleData) && isset($roleData['name']) ? (string) $roleData['name'] : (string) $roleKey;
            $html .= '<option value="' . esc_attr($roleKey) . '"'
                . ($roleKey === $currentRole ? ' selected' : '') . '>'
                . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        if ($protected) {
            $html .= ' <span class="user-manager-protected">' . esc_html__('Protected — only this user can change their own role.', 'traydigita-core') . '</span>';
        }
        $html .= ' <span class="user-manager-quick-status" data-user-manager-quick-status role="status" aria-live="polite"></span>';
        $html .= '</span>';
        return $html;
    }

    /**
     * Whether the actor may change the target user's role.
     *
     * The protection rule: a user who has the `manage_options`
     * capability may only have their role changed by themself.
     * A role change targeting such a user by a different admin
     * is refused. Everyone else may be re-roled by anyone with
     * the `promote_users` capability (checked by the caller).
     *
     * Pure logic (no output, no writes) so it can be tested in
     * isolation.
     *
     * @param WP_User $target User whose role would change.
     * @param WP_User|null $actor User requesting the change (null when nobody is logged in).
     * @return bool True when the change is allowed by this rule.
     */
    public static function canChangeRole(WP_User $target, ?WP_User $actor): bool
    {
        if ($actor === null || (int) $actor->ID === 0) {
            return false;
        }
        if ($target->has_cap('manage_options') && (int) $target->ID !== (int) $actor->ID) {
            return false;
        }
        return true;
    }

    /**
     * Change one user's role, enforcing every safety rule.
     *
     * Pure logic (no JSON output) so the AJAX handlers and the
     * test harness share it: the target must exist, the role
     * must be an editable role, the actor must have
     * `promote_users`, and the manage_options protection rule
     * must pass. A caller without `manage_options` can never
     * assign a role that grants it, so `promote_users` alone
     * is not a ladder to administrator.
     *
     * @param int $userId Target user id.
     * @param string $role New role slug.
     * @param WP_User|null $actor Acting user (defaults to the current user).
     * @return array{success: true, message: string, user: array{id: int, user_login: string, display_name: string, user_email: string, roles: array<int, string>, last_login: string|null}}|array{success: false, code: string, message: string}
     */
    public static function changeUserRole(int $userId, string $role, ?WP_User $actor = null): array
    {
        $actor = $actor ?? wp_get_current_user();
        if ($actor === null || (int) $actor->ID === 0) {
            return [
                'success' => false,
                'code' => 'not_logged_in',
                'message' => __('You must be logged in to change a role.', 'traydigita-core'),
            ];
        }
        if (!current_user_can('promote_users')) {
            return [
                'success' => false,
                'code' => 'forbidden',
                'message' => __('You are not allowed to change user roles.', 'traydigita-core'),
            ];
        }
        $user = get_user_by('id', $userId);
        if ($user === false) {
            return [
                'success' => false,
                'code' => 'unknown_user',
                'message' => __('Unknown user.', 'traydigita-core'),
            ];
        }
        $role = sanitize_key($role);
        $editable = get_editable_roles();
        if ($role === '' || !isset($editable[$role])) {
            return [
                'success' => false,
                'code' => 'unknown_role',
                'message' => __('Unknown role.', 'traydigita-core'),
            ];
        }
        // Admin-grant guard: `promote_users` alone must never be
        // a ladder to administrator. A caller without
        // `manage_options` (for example an editor handed
        // `promote_users` through the Role Editor) cannot assign
        // a role that itself grants `manage_options` — including
        // promoting their own account.
        if (!$actor->has_cap('manage_options')) {
            $roleObject = get_role($role);
            if ($roleObject !== null && !empty($roleObject->capabilities['manage_options'])) {
                return [
                    'success' => false,
                    'code' => 'forbidden',
                    'message' => __('You are not allowed to change user roles.', 'traydigita-core'),
                ];
            }
        }
        if (!self::canChangeRole($user, $actor)) {
            return [
                'success' => false,
                'code' => 'protected',
                'message' => sprintf(
                    /* translators: %s is the display name of the protected user. */
                    __('%s can manage options, so only they can change their own role.', 'traydigita-core'),
                    $user->display_name
                ),
            ];
        }
        $user->set_role($role);
        return [
            'success' => true,
            'message' => __('Role updated.', 'traydigita-core'),
            'user' => self::safeUserSummary($user),
        ];
    }

    /**
     * Safe public summary of a user for AJAX responses.
     *
     * Explicit allowlist only: id, login, display name, email,
     * roles, and the formatted last login. The password hash
     * (`user_pass`) and every other user field are never
     * included, so no response built from this can leak a hash.
     *
     * @param WP_User $user User to summarize.
     * @return array{id: int, user_login: string, display_name: string, user_email: string, roles: array<int, string>, last_login: string|null}
     */
    public static function safeUserSummary(WP_User $user): array
    {
        $roles = is_array($user->roles) ? array_values($user->roles) : [];
        $stored = get_user_meta((int) $user->ID, self::LAST_LOGIN_META, true);
        $lastLogin = is_numeric($stored) && (int) $stored > 0
            ? self::formatLastLogin((int) $user->ID)
            : null;
        return [
            'id' => (int) $user->ID,
            'user_login' => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'user_email' => (string) $user->user_email,
            'roles' => $roles,
            'last_login' => $lastLogin,
        ];
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_avatar_column: bool, show_last_login_column: bool, show_quick_role: bool, track_last_login: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'show_avatar_column' => (bool) ($settings['show_avatar_column'] ?? true),
            'show_last_login_column' => (bool) ($settings['show_last_login_column'] ?? true),
            'show_quick_role' => (bool) ($settings['show_quick_role'] ?? true),
            'track_last_login' => (bool) ($settings['track_last_login'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * Everything is on: both columns, the quick-role control,
     * and last-login tracking.
     *
     * @return array{show_avatar_column: bool, show_last_login_column: bool, show_quick_role: bool, track_last_login: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'show_avatar_column' => true,
            'show_last_login_column' => true,
            'show_quick_role' => true,
            'track_last_login' => true,
        ];
    }
}
