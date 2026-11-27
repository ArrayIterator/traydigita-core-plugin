<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\MemberProfile;

use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Avatar\AvatarStorage;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberDashboard;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use function add_query_arg;
use function admin_url;
use function email_exists;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function esc_url_raw;
use function get_current_user_id;
use function get_user_meta;
use function get_userdata;
use function is_array;
use function is_email;
use function is_string;
use function is_user_logged_in;
use function is_wp_error;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function send_confirmation_on_profile_email;
use function sprintf;
use function wp_login_url;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_unslash;
use function wp_update_user;
use function wp_verify_nonce;

/**
 * Member profile and email change for the dashboard.
 *
 * The Profile and Change Email views of the member dashboard,
 * plugged in through the `traydigita:core:member/sections`
 * filter. Profile saves the member's own display name, website,
 * bio and (when the Avatar module is active) avatar; password
 * changes stay on the wp-admin profile page. Email changes go
 * through WordPress core's own confirmation flow: the new
 * address is stored pending and only takes effect after the
 * member clicks the confirmation link.
 */
final class MemberProfile
{
    /**
     * Render the Profile section of the dashboard shell.
     *
     * The section render callable the Member Profile module
     * registers through the `traydigita:core:member/sections`
     * filter, so the shell renders the profile through the
     * hook, never by calling this class directly.
     *
     * @return string
     */
    public static function renderProfileSection(): string
    {
        return self::renderProfile();
    }

    /**
     * Render the Change Email section of the dashboard shell.
     *
     * The section render callable the Member Profile module
     * registers through the `traydigita:core:member/sections`
     * filter, so the shell renders the form through the hook,
     * never by calling this class directly.
     *
     * @return string
     */
    public static function renderChangeEmailSection(): string
    {
        return self::renderChangeEmail();
    }

    /**
     * Handle a profile save via admin-post.php.
     *
     * Saves the contributor's own display name, website and
     * bio, and - when the Avatar module is active - an uploaded
     * avatar through AvatarStorage. No capability beyond being
     * logged in is needed: wp_update_user() only allows users
     * to edit themselves, and the posted user id is never read
     * - the id is always the current user. Password changes
     * stay on the wp-admin profile page (which frontend-only
     * users may reach).
     *
     * @return void
     */
    public static function handleProfile(): void
    {
        $fallback = MemberAreaModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $profileUrl = add_query_arg([MemberAreaModule::VIEW_FLAG => 'profile'], MemberAreaModule::currentPageUrl());
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, MemberProfileModule::PROFILE_ACTION)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'nonce', $profileUrl));
            exit;
        }
        $userId = get_current_user_id();
        $displayName = isset($_POST['traydigita_display_name']) && is_string($_POST['traydigita_display_name'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_display_name']))
            : '';
        $userUrl = isset($_POST['traydigita_user_url']) && is_string($_POST['traydigita_user_url'])
            ? esc_url_raw(wp_unslash($_POST['traydigita_user_url']))
            : '';
        $description = isset($_POST['traydigita_description']) && is_string($_POST['traydigita_description'])
            ? sanitize_textarea_field(wp_unslash($_POST['traydigita_description']))
            : '';
        $result = wp_update_user([
            'ID' => $userId,
            'display_name' => $displayName,
            'user_url' => $userUrl,
            'description' => $description,
        ]);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'save', $profileUrl));
            exit;
        }
        if (isset($_FILES['traydigita_avatar']) && is_array($_FILES['traydigita_avatar'])) {
            $avatarError = self::saveAvatar($userId);
            if ($avatarError !== '') {
                wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'avatar', $profileUrl));
                exit;
            }
        }
        wp_safe_redirect(add_query_arg(MemberAreaModule::SAVED_FLAG, 'profile', $profileUrl));
        exit;
    }

    /**
     * Store an uploaded avatar for the current user.
     *
     * Returns an empty string on success (or when no file was
     * uploaded), and an error code when the Avatar module is
     * unavailable or the upload failed. The file itself is
     * validated and cropped by AvatarStorage.
     *
     * @param int $userId User id.
     * @return string Error code, or '' on success.
     */
    private static function saveAvatar(int $userId): string
    {
        $file = $_FILES['traydigita_avatar'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if (!ModuleRegistry::isEnabled('avatar') || !class_exists(AvatarStorage::class)) {
            return 'avatar';
        }
        $result = AvatarStorage::storeUploadedFile($userId, $file);
        if (empty($result['success'])) {
            return 'avatar';
        }
        return '';
    }

    /**
     * Handle an email change via admin-post.php.
     *
     * Only when the allow-email-change setting is on. The new
     * address is validated (real email, not used by another
     * user) and then sent through WordPress core's own
     * confirmation flow: the address is stored pending in
     * `_new_email` and a confirmation link is mailed to the new
     * address. The link points at profile.php, which
     * frontend-only users may reach. The address only changes
     * after the user clicks the link.
     *
     * @return void
     */
    public static function handleEmail(): void
    {
        $fallback = MemberAreaModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $emailUrl = add_query_arg([MemberAreaModule::VIEW_FLAG => 'change-email'], MemberAreaModule::currentPageUrl());
        $overviewUrl = add_query_arg(
            [MemberAreaModule::VIEW_FLAG => 'overview', MemberAreaModule::NOTICE_FLAG => 'section-disabled'],
            MemberAreaModule::currentPageUrl()
        );
        if (!MemberProfileModule::getSettings()['allow_email_change']) {
            wp_safe_redirect($overviewUrl);
            exit;
        }
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, MemberProfileModule::EMAIL_ACTION)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'nonce', $emailUrl));
            exit;
        }
        $userId = get_current_user_id();
        $user = get_userdata($userId);
        if ($user === false) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'capability', $fallback));
            exit;
        }
        $newEmail = isset($_POST['traydigita_new_email']) && is_string($_POST['traydigita_new_email'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_new_email']))
            : '';
        if ($newEmail === '' || !is_email($newEmail)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'email-invalid', $emailUrl));
            exit;
        }
        if ($newEmail === $user->user_email) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'email-same', $emailUrl));
            exit;
        }
        if (email_exists($newEmail)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'email-taken', $emailUrl));
            exit;
        }
        // Core's confirmation flow reads the new address from
        // $_POST['email'] and mails a confirmation link to it.
        $_POST['email'] = $newEmail;
        send_confirmation_on_profile_email($userId);
        wp_safe_redirect(add_query_arg(MemberAreaModule::SAVED_FLAG, 'email-sent', $emailUrl));
        exit;
    }

    /**
     * Render the Profile view: the contributor's own display
     * name, website, bio and avatar.
     *
     * The avatar upload only renders when the Avatar module is
     * active. Password and two-factor changes stay on the
     * wp-admin profile page (linked from here): frontend-only
     * users may reach profile.php, and building password
     * changes into the dashboard would widen what the
     * dashboard may do.
     *
     * @return string
     */
    private static function renderProfile(): string
    {
        $user = get_userdata(get_current_user_id());
        if ($user === false) {
            return MemberDashboard::renderNotice('error', esc_html__('You do not have permission to use the member area.', 'traydigita-core'));
        }
        $avatarEnabled = ModuleRegistry::isEnabled('avatar') && class_exists(AvatarStorage::class);
        $html = '<h2 class="traydigita-contributor-heading">' . esc_html__('Profile', 'traydigita-core') . '</h2>';
        $html .= '<form class="traydigita-contributor-form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        $html .= wp_nonce_field(MemberProfileModule::PROFILE_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="'
            . esc_attr(MemberProfileModule::PROFILE_ACTION) . '">';
        if ($avatarEnabled) {
            $avatar = AvatarStorage::getAvatar((int) $user->ID);
            $html .= '<p class="traydigita-contributor-field">';
            if (is_array($avatar) && isset($avatar['url']) && is_string($avatar['url']) && $avatar['url'] !== '') {
                $html .= '<img class="traydigita-contributor-avatar" src="' . esc_url($avatar['url']) . '" alt="">';
            }
            $html .= '<label for="traydigita-contributor-avatar">' . esc_html__('Avatar', 'traydigita-core') . '</label>'
                . '<input type="file" id="traydigita-contributor-avatar" name="traydigita_avatar" accept="image/jpeg,image/png,image/gif,image/webp">'
                . '</p>';
        }
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-display-name">' . esc_html__('Display name', 'traydigita-core') . '</label>'
            . '<input type="text" id="traydigita-contributor-display-name" name="traydigita_display_name" maxlength="100" value="'
            . esc_attr($user->display_name) . '">'
            . '</p>';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-user-url">' . esc_html__('Website', 'traydigita-core') . '</label>'
            . '<input type="url" id="traydigita-contributor-user-url" name="traydigita_user_url" maxlength="200" value="'
            . esc_attr($user->user_url) . '">'
            . '</p>';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-description">' . esc_html__('Biography', 'traydigita-core') . '</label>'
            . '<textarea id="traydigita-contributor-description" name="traydigita_description" rows="4">'
            . esc_textarea($user->description) . '</textarea>'
            . '</p>';
        $html .= '<p class="traydigita-contributor-actions">'
            . '<button type="submit" class="traydigita-contributor-button traydigita-contributor-button-primary">'
            . esc_html__('Save profile', 'traydigita-core') . '</button>'
            . '</p>';
        $html .= '</form>';
        $html .= '<p class="traydigita-contributor-empty">'
            . '<a href="' . esc_url(admin_url('profile.php')) . '">'
            . esc_html__('Change your password and security settings on your WordPress profile page.', 'traydigita-core')
            . '</a></p>';
        return $html;
    }

    /**
     * Render the Change Email view.
     *
     * Only reachable when the allow-email-change setting is on
     * (the resolver redirects otherwise). Users who can access
     * wp-admin are reminded they can always change their email
     * on profile.php, setting or not.
     *
     * @return string
     */
    private static function renderChangeEmail(): string
    {
        $user = get_userdata(get_current_user_id());
        if ($user === false) {
            return MemberDashboard::renderNotice('error', esc_html__('You do not have permission to use the member area.', 'traydigita-core'));
        }
        $pending = get_user_meta((int) $user->ID, '_new_email', true);
        $html = '<h2 class="traydigita-contributor-heading">' . esc_html__('Change email', 'traydigita-core') . '</h2>';
        $html .= '<p class="traydigita-contributor-empty">'
            . esc_html__('Your current email address is:', 'traydigita-core') . ' <strong>'
            . esc_html($user->user_email) . '</strong></p>';
        if (is_array($pending) && isset($pending['newemail']) && is_string($pending['newemail'])) {
            $html .= '<p class="traydigita-contributor-notice traydigita-contributor-notice-info">'
                . sprintf(
                    /* translators: %s: pending new email address. */
                    esc_html__('A change to %s is waiting for confirmation. Check your inbox for the confirmation email.', 'traydigita-core'),
                    esc_html($pending['newemail'])
                )
                . '</p>';
        }
        $html .= '<form class="traydigita-contributor-form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '">';
        $html .= wp_nonce_field(MemberProfileModule::EMAIL_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="'
            . esc_attr(MemberProfileModule::EMAIL_ACTION) . '">';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-new-email">' . esc_html__('New email address', 'traydigita-core') . '</label>'
            . '<input type="email" id="traydigita-contributor-new-email" name="traydigita_new_email" required maxlength="100" value="">'
            . '</p>';
        $html .= '<p class="traydigita-contributor-actions">'
            . '<button type="submit" class="traydigita-contributor-button traydigita-contributor-button-primary">'
            . esc_html__('Send confirmation email', 'traydigita-core') . '</button>'
            . '</p>';
        $html .= '</form>';
        $html .= '<p class="traydigita-contributor-empty">'
            . esc_html__('The new address only takes effect after you click the confirmation link sent to it.', 'traydigita-core')
            . '</p>';
        if (!RoleAccessModule::isFrontendOnlyUser()) {
            $html .= '<p class="traydigita-contributor-empty">'
                . '<a href="' . esc_url(admin_url('profile.php')) . '">'
                . esc_html__('You can also change your email on your WordPress profile page.', 'traydigita-core')
                . '</a></p>';
        }
        return $html;
    }
}
