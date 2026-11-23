<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\MemberProfile;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\ContributorSplitMigration;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use function __;
use function add_action;
use function add_filter;
use function array_merge;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function get_option;
use function is_array;
use function update_option;
use function wp_die;
use function wp_nonce_field;

/**
 * Member Profile module.
 *
 * The Profile and Change Email sections of the member
 * dashboard, registered through the
 * `traydigita:core:member/sections` filter: members edit
 * their own display name, website, bio and avatar, and may
 * change their own email address when the admin allows it
 * (through WordPress core's own confirmation flow — the new
 * address only takes effect after the member clicks the
 * confirmation link).
 *
 * Split out of the Contributor Frontend Editor module
 * (MUST-4, 2026-11-24): disabling this module removes the
 * Profile and Change Email sections and stops their form
 * handlers, while the member area, the block editor and
 * Bookmarks keep working.
 *
 * Settings live in the single option
 * `traydigita_member_profile` (autoload `no`) and are saved
 * through the phase-2 admin AJAX dispatcher, which verifies
 * the nonce and the `manage_options` capability;
 * saveSettings() still checks the capability itself and
 * re-sanitizes every field for its own type. Sites upgraded
 * from the old combined module get their settings from the
 * one-time split migration (see ContributorSplitMigration in
 * the Member Area module).
 */
final class MemberProfileModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'member-profile';

    /**
     * Option name for all module settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_member_profile';

    /**
     * admin-post.php action and nonce action for profile saves.
     */
    public const string PROFILE_ACTION = 'traydigita_contributor_profile';

    /**
     * admin-post.php action and nonce action for email changes.
     */
    public const string EMAIL_ACTION = 'traydigita_contributor_email';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_member_profile_save';

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
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Member Profile', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Profile and email change for members, as sections of the member dashboard: display name, website, bio and avatar, plus an optional email change confirmed through WordPress core.',
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
        return 'admin-users';
    }

    /**
     * Module version.
     *
     * 1.0.0 splits profile and email out of the Contributor
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
            __('Profile section in the member dashboard.', 'traydigita-core'),
            __('Change Email section, confirmed through WordPress core.', 'traydigita-core'),
            __('Avatar upload when the Avatar module is active.', 'traydigita-core'),
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
        return 'traydigita-member-profile';
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
     * The profile works with no configuration at all, so the
     * module is always considered configured.
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
     * @return array{allow_email_change: bool}
     */
    public static function getSettings(): array
    {
        ContributorSplitMigration::migrate();
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'allow_email_change' => !empty($settings['allow_email_change']),
        ];
    }

    /**
     * Default settings: members may not change their email
     * from the dashboard (they can always do it from
     * profile.php when they have wp-admin access).
     *
     * @return array{allow_email_change: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'allow_email_change' => false,
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * The form uses the shell's generic AJAX save endpoint (see
     * data-traydigita-module-form).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings member-profile-settings">
            <?php
            SettingsControls::intro(
                __('Profile and email change for members in the dashboard.', 'traydigita-core'),
                __('Members edit their own display name, website, bio and avatar from the Profile section. Password changes stay on the wp-admin profile page.', 'traydigita-core')
            );
            ?>

            <form class="member-profile-settings-form" data-traydigita-module-form data-traydigita-module="member-profile" data-module="member-profile" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php
                SettingsControls::sectionOpen(
                    'member-profile',
                    __('Member profile', 'traydigita-core'),
                    __('Dashboard sections and email changes.', 'traydigita-core'),
                    __('Change Email only appears when the toggle below is on; users who can access wp-admin can always change their email from profile.php instead.', 'traydigita-core')
                );
                SettingsControls::toggle([
                    'id' => 'member-profile-allow-email-change',
                    'name' => 'allow_email_change',
                    'label' => __('Allow email change in the dashboard', 'traydigita-core'),
                    'checked' => $settings['allow_email_change'],
                    'help' => __('Members may change their own email from the dashboard.', 'traydigita-core'),
                    'help_long' => __('When on, the Change Email section appears in the dashboard and the new address is confirmed through the email WordPress sends. When off, the section is hidden entirely; users who can access wp-admin can still change their email from profile.php.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose();
                ?>

                <p class="member-profile-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change member profile settings.', 'traydigita-core'),
            ];
        }
        update_option(self::OPTION, [
            'allow_email_change' => !empty($data['allow_email_change']),
        ], false);
        return [
            'success' => true,
            'message' => __('Member profile settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The Profile and Change Email sections are
     * registered through the member sections filter, and their
     * admin-post handlers here.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter(MemberAreaModule::SECTIONS_FILTER, [self::class, 'registerProfileSections']);
        add_action('admin_post_' . self::PROFILE_ACTION, [MemberProfile::class, 'handleProfile']);
        add_action('admin_post_nopriv_' . self::PROFILE_ACTION, [MemberProfile::class, 'handleProfile']);
        add_action('admin_post_' . self::EMAIL_ACTION, [MemberProfile::class, 'handleEmail']);
        add_action('admin_post_nopriv_' . self::EMAIL_ACTION, [MemberProfile::class, 'handleEmail']);
    }

    /**
     * Register the Profile and Change Email sections in the member area.
     *
     * Appended through the `traydigita:core:member/sections`
     * filter, after the Bookmarks section: the sections only
     * exist while this module is enabled. Change Email only
     * appears when the allow-email-change setting is on.
     *
     * @param array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}> $sections Sections registered so far.
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function registerProfileSections(array $sections): array
    {
        $sections[] = [
            'id' => 'profile',
            'label' => __('Profile', 'traydigita-core'),
            'capability' => null,
            'available' => null,
            'render' => [MemberProfile::class, 'renderProfileSection'],
        ];
        $sections[] = [
            'id' => 'change-email',
            'label' => __('Change Email', 'traydigita-core'),
            'capability' => null,
            'available' => static fn (): bool => (bool) self::getSettings()['allow_email_change'],
            'render' => [MemberProfile::class, 'renderChangeEmailSection'],
        ];
        return $sections;
    }
}
