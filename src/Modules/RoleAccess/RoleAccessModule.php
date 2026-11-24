<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\RoleAccess;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\ContributorSplitMigration;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use function __;
use function add_action;
use function add_filter;
use function add_query_arg;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_values;
use function current_user_can;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function get_current_user_id;
use function get_option;
use function get_userdata;
use function in_array;
use function is_array;
use function is_string;
use function is_user_logged_in;
use function sanitize_key;
use function selected;
use function update_option;
use function user_can;
use function wp_die;
use function wp_nonce_field;
use function wp_roles;
use function wp_safe_redirect;

/**
 * Role Access module.
 *
 * Per-role access modes for the frontend: the admin picks
 * "Allow dashboard access" or "Frontend only" per role. A
 * frontend-only user gets no admin bar, and any wp-admin page
 * they load redirects them to the frontend member area. The
 * redirect never fires for admin-ajax.php, admin-post.php
 * (the editor's own form handler), the user's own profile
 * page, REST requests, WP-CLI, cron, or the login/logout
 * flows, and a redirect loop is impossible (the target is
 * always a frontend page).
 *
 * HARD RULE — administrators are never affected. The
 * `administrator` role is never listed on the settings page, is
 * never stored in the settings, and every enforcement point
 * checks `manage_options` first (see isFrontendOnlyUser()).
 * An administrator can therefore never hide their own admin
 * bar or be redirected away from wp-admin, even by mistake.
 *
 * Split out of the Contributor Frontend Editor module
 * (MUST-4, 2026-11-24): access modes are their own
 * responsibility now, and they keep working when the member
 * area or the frontend editor is disabled.
 *
 * The module ships DISABLED by default: access control changes
 * behaviour for real users, so it is strictly opt-in.
 *
 * Settings live in the single option `traydigita_role_access`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability
 * itself and re-sanitizes every field for its own type. Sites
 * upgraded from the old combined module get their settings
 * from the one-time split migration (see
 * ContributorSplitMigration in the Member Area module).
 */
final class RoleAccessModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'role-access';

    /**
     * Option name for all module settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_role_access';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_role_access_save';

    /**
     * Access mode: the role may use wp-admin as usual.
     */
    public const string MODE_DASHBOARD = 'dashboard';

    /**
     * Access mode: the role works frontend-only (no admin bar,
     * wp-admin redirects to the member area).
     */
    public const string MODE_FRONTEND = 'frontend';

    /**
     * Allowed access modes.
     *
     * @var array<int, string>
     */
    public const array MODES = [self::MODE_DASHBOARD, self::MODE_FRONTEND];

    /**
     * The role that is never listed, never stored, and never
     * restricted by this module.
     */
    public const string ADMIN_ROLE = 'administrator';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * Default category slug for the module list.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'security';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Role Access', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Per-role access modes: dashboard access or frontend-only. Frontend-only users get no admin bar and any wp-admin page redirects them to the member area. Administrators are never affected.',
            'traydigita-core'
        );
    }

    /**
     * Dashicon slug for the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'lock';
    }

    /**
     * Module version.
     *
     * 1.0.0 splits per-role access modes out of the Contributor
     * Frontend Editor module (MUST-4).
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
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Per-role access modes: dashboard or frontend-only.', 'traydigita-core'),
            __('Frontend-only users lose the admin bar.', 'traydigita-core'),
            __('Frontend-only users are redirected from wp-admin to the member area.', 'traydigita-core'),
            __('Administrators are never affected.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Split out of the Contributor Frontend Editor module.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin page slug for this module's settings page.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-role-access';
    }

    /**
     * Whether the module can run (it has no hard dependencies).
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * The access modes are optional, so the module is always
     * considered configured.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * Role modes are keyed by role slug and hold 'dashboard' or
     * 'frontend'; the administrator slug is dropped whenever it
     * appears, so it can never be stored.
     *
     * @return array{role_modes: array<string, string>}
     */
    public static function getSettings(): array
    {
        ContributorSplitMigration::migrate();
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $modes = [];
        $validRoles = self::configurableRoles();
        $rawModes = $settings['role_modes'] ?? [];
        if (is_array($rawModes)) {
            foreach ($rawModes as $role => $mode) {
                if (!is_string($role) || !is_string($mode)) {
                    continue;
                }
                $slug = sanitize_key($role);
                if (!in_array($slug, $validRoles, true)) {
                    continue;
                }
                if (!in_array($mode, self::MODES, true)) {
                    continue;
                }
                $modes[$slug] = $mode;
            }
        }
        return [
            'role_modes' => $modes,
        ];
    }

    /**
     * Default settings: every role keeps dashboard access.
     *
     * @return array{role_modes: array<string, string>}
     */
    public static function getDefaults(): array
    {
        return [
            'role_modes' => [],
        ];
    }

    /**
     * Role slugs the admin may configure on the settings page.
     *
     * Every role on the site except `administrator`. Uses
     * wp_roles() (available everywhere) rather than
     * get_editable_roles() (wp-admin only).
     *
     * @return array<int, string>
     */
    public static function configurableRoles(): array
    {
        $roles = array_keys(wp_roles()->get_names());
        return array_values(array_filter(
            $roles,
            static fn (string $slug): bool => $slug !== self::ADMIN_ROLE
        ));
    }

    /**
     * Whether a user is frontend-only under the current settings.
     *
     * HARD RULE: anyone who can `manage_options` (administrators
     * and anyone else explicitly granted it) is never
     * frontend-only. A user is frontend-only only when at least
     * one of their roles is set to 'frontend' and none of their
     * roles is set to 'dashboard' (dashboard wins when a user
     * holds both kinds of role). Roles with no configured mode
     * default to dashboard access.
     *
     * @param int|null $userId User id, or null for the current user.
     * @return bool
     */
    public static function isFrontendOnlyUser(?int $userId = null): bool
    {
        $userId = $userId ?? get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        if (user_can($userId, 'manage_options')) {
            return false;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return false;
        }
        $roles = is_array($user->roles) ? $user->roles : [];
        if ($roles === []) {
            return false;
        }
        $modes = self::getSettings()['role_modes'];
        $frontend = false;
        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }
            $mode = $modes[sanitize_key($role)] ?? self::MODE_DASHBOARD;
            if ($mode === self::MODE_DASHBOARD) {
                return false;
            }
            if ($mode === self::MODE_FRONTEND) {
                $frontend = true;
            }
        }
        return $frontend;
    }

    /**
     * Whether a user may use the contributor dashboard.
     *
     * The dashboard is contributor-only: the user must hold the
     * `contributor` role and must NOT be able to edit other
     * people's posts. A user holding contributor AND a higher
     * role (editor, administrator) belongs in wp-admin, so they
     * are not a dashboard user.
     *
     * @param int|null $userId User id, or null for the current user.
     * @return bool
     */
    public static function isDashboardUser(?int $userId = null): bool
    {
        $userId = $userId ?? get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        if (user_can($userId, 'edit_others_posts')) {
            return false;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return false;
        }
        $roles = is_array($user->roles) ? $user->roles : [];
        return in_array('contributor', $roles, true);
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * The form uses the shell's generic AJAX save endpoint (see
     * data-traydigita-module-form). The administrator role is
     * never rendered as a row: it cannot be configured at all.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        $settings = self::getSettings();
        $roles = wp_roles()->get_names();
        $modes = $settings['role_modes'];
        ?>
        <div class="traydigita-module-settings role-access-settings">
            <?php
            SettingsControls::intro(
                __('Per-role access modes for the frontend.', 'traydigita-core'),
                __('Pick an access mode per role below. Frontend-only users get no admin bar, and any wp-admin page they open redirects them to the member area.', 'traydigita-core')
            );
            ?>
            <p class="role-access-settings-intro">
                <strong><?php esc_html_e('Administrators are never affected.', 'traydigita-core'); ?></strong>
                <?php
                SettingsControls::helpText(
                    '',
                    __('The administrator role is not listed here and cannot be restricted: administrators always keep the admin bar and full wp-admin access, no matter what is configured below.', 'traydigita-core')
                );
                ?>
            </p>

            <form class="role-access-settings-form" data-traydigita-module-form data-traydigita-module="role-access" data-module="role-access" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <div class="traydigita-admin-table-wrap">
                <table class="role-access-roles widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Role', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Access', 'traydigita-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $slug => $label) : ?>
                            <?php if ($slug === self::ADMIN_ROLE) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php $mode = $modes[$slug] ?? self::MODE_DASHBOARD; ?>
                            <tr>
                                <td><?php echo esc_html(is_string($label) ? $label : $slug); ?></td>
                                <td>
                                    <select name="role_modes[<?php echo esc_attr($slug); ?>]">
                                        <option value="dashboard" <?php selected($mode, self::MODE_DASHBOARD); ?>><?php esc_html_e('Allow dashboard access', 'traydigita-core'); ?></option>
                                        <option value="frontend" <?php selected($mode, self::MODE_FRONTEND); ?>><?php esc_html_e('Frontend only', 'traydigita-core'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="role-access-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('Frontend only hides the admin bar and redirects wp-admin '
                            . 'to the member area.', 'traydigita-core'),
                        __('A user holding both a dashboard role and a frontend-only role keeps dashboard access.', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="role-access-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('The redirect skips system and editor requests.', 'traydigita-core'),
                        __('The redirect never fires for admin-ajax.php, admin-post.php (the editor form handler), the user’s own profile page, REST requests, WP-CLI, cron, or the login and logout flows.', 'traydigita-core')
                    );
                    ?>
                </p>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * Re-sanitizes every field for its own type: role modes are
     * kept only for roles that exist (never `administrator`),
     * with values restricted to the mode allowlist.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change role access settings.', 'traydigita-core'),
            ];
        }
        $modes = [];
        $validRoles = self::configurableRoles();
        $rawModes = $data['role_modes'] ?? [];
        if (is_array($rawModes)) {
            foreach ($rawModes as $role => $mode) {
                if (!is_string($role) || !is_string($mode)) {
                    continue;
                }
                $slug = sanitize_key($role);
                if (!in_array($slug, $validRoles, true)) {
                    continue;
                }
                if (!in_array($mode, self::MODES, true)) {
                    continue;
                }
                $modes[$slug] = $mode;
            }
        }
        update_option(self::OPTION, [
            'role_modes' => $modes,
        ], false);
        return [
            'success' => true,
            'message' => __('Role access settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The access hooks only bite for users the
     * settings mark frontend-only, and never for administrators.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter('show_admin_bar', [self::class, 'filterShowAdminBar']);
        add_action('admin_init', [self::class, 'maybeRedirectAdmin']);
    }

    /**
     * Hide the admin bar for frontend-only users.
     *
     * Administrators are never affected (see
     * isFrontendOnlyUser()): the check runs first inside it.
     * The member page hides the bar for every dashboard user
     * through MemberAreaModule::filterShowAdminBar(), hooked
     * on the same filter.
     *
     * @param bool $show Whether the admin bar would show.
     * @return bool
     */
    public static function filterShowAdminBar(bool $show): bool
    {
        if (self::isFrontendOnlyUser()) {
            return false;
        }
        return $show;
    }

    /**
     * Redirect frontend-only users away from wp-admin.
     *
     * Runs on `admin_init`, which only fires inside wp-admin,
     * so the frontend can never trigger it. Never fires for
     * administrators (hard rule in isFrontendOnlyUser()),
     * admin-ajax.php, admin-post.php (the editor's own form
     * handler — blocking it would break every submission),
     * the user's own profile page (password and 2FA management
     * live there), REST requests, WP-CLI, or cron. The target
     * is always a frontend page, so a redirect loop is
     * impossible; the login and logout flows never reach
     * `admin_init` at all.
     *
     * @return void
     */
    public static function maybeRedirectAdmin(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        if (!self::isFrontendOnlyUser()) {
            return;
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        global $pagenow;
        if (is_string($pagenow) && in_array($pagenow, ['admin-post.php', 'admin-ajax.php', 'profile.php'], true)) {
            return;
        }
        $url = add_query_arg(MemberAreaModule::NO_DASHBOARD_FLAG, '1', MemberAreaModule::memberAreaUrl());
        wp_safe_redirect($url);
        exit;
    }
}
