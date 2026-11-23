<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Avatar;

use TrayDigita\WP\Plugin\Core\Admin\AdminNotice;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use WP_Comment;
use WP_Post;
use WP_User;
use function __;
use function add_action;
use function add_filter;
use function checked;
use function ctype_digit;
use function current_user_can;
use function delete_transient;
use function esc_attr;
use function esc_attr_e;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_url;
use function esc_url_raw;
use function get_current_screen;
use function get_current_user_id;
use function get_option;
use function get_transient;
use function get_users;
use function implode;
use function is_array;
use function is_string;
use function sanitize_text_field;
use function set_transient;
use function sprintf;
use function update_option;
use function wp_get_current_user;
use function wp_nonce_field;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Custom Avatar module.
 *
 * Lets a user have a custom avatar in one of two ways: a remote
 * image URL, verified as a real image before it is saved
 * (`wp_remote_head()` pre-check plus a `getimagesize()` MIME
 * check on the downloaded file — only JPEG, PNG, GIF, and WebP
 * are allowed), or an uploaded image, which is centre-cropped to
 * a square and resized to the recommended 256x256 with
 * `wp_get_image_editor()`. Uploaded files live under
 * `wp-content/traydigita-avatar/<user-id>.<ext>` (never in the
 * uploads directory); the directory is created with
 * `wp_mkdir_p()` plus an `index.php` silence file, and the old
 * avatar file for a user is deleted when it is replaced.
 *
 * Administrators manage any user's avatar from the module page
 * (the user must exist — unknown user ids are refused). Users can
 * also set their own avatar from their profile page when
 * self-service is enabled (or when they are an administrator).
 * The `get_avatar` filter returns the custom avatar (sized to the
 * requested size via the image attributes) and leaves Gravatar
 * and the default avatar untouched when no custom avatar is set.
 *
 * Settings live in the single option `traydigita_avatar_settings`
 * (autoload `no`): `allow_self_service` and `allow_remote_url`,
 * both on by default. Avatar records themselves live in user
 * meta (see AvatarStorage). The settings page is rendered inline
 * here (no template partial), following SeoModule.
 */
final class AvatarModule implements ModuleInterface
{
    /**
     * Option name for all avatar settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_avatar_settings';

    /**
     * Nonce action for the profile-page avatar fields.
     */
    public const string PROFILE_NONCE_ACTION = 'traydigita_avatar_profile';

    /**
     * Transient (per user) holding the result notice of a classic
     * profile-page save, shown once on the next profile load.
     */
    public const string PROFILE_NOTICE_TRANSIENT = 'traydigita_avatar_notice';

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
        return 'avatar';
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
        return 'design';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Custom Avatar', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Give users a custom avatar by verified image URL or upload: uploads are cropped to a 256×256 square and stored outside the media library, and the avatar replaces Gravatar wherever WordPress shows one.', 'traydigita-core');
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
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-avatar';
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
     * The module works with its defaults (self-service and remote
     * URLs both on), so it is always configured.
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
     * Two parts: the settings form (self-service and remote-URL
     * toggles, saved through the shell's generic save endpoint)
     * and the avatar manager (set by URL, upload a file, and a
     * table of every user who currently has a custom avatar, with
     * a delete button per row). The manager forms are handled by
     * the module's own AJAX endpoints via admin-avatar.ts; the
     * root element carries the translatable UI strings as
     * `data-msg-*` attributes.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        $users = get_users([
            'fields' => ['ID', 'display_name', 'user_email'],
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 500,
        ]);
        $withAvatars = get_users([
            'meta_key' => AvatarStorage::META_KEY,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 200,
        ]);
        ?>
        <div class="traydigita-module-settings avatar-settings">
            <form class="avatar-settings-form" data-traydigita-module-form data-traydigita-module="avatar" data-module="avatar" method="post" action="">
                <?php wp_nonce_field('traydigita_avatar_save'); ?>
                <p class="avatar-field">
                    <label class="traydigita-ui-toggle" for="avatar-allow-self-service"><input class="traydigita-ui-toggle-input" type="checkbox" id="avatar-allow-self-service" name="allow_self_service" value="1" <?php checked($settings['allow_self_service']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Let users set their own avatar from their profile page', 'traydigita-core'); ?></span></label>
                    <span class="avatar-field-help">
                        <?php esc_html_e('When off, only administrators can change avatars (from this page or from a user’s profile page).', 'traydigita-core'); ?>
                    </span>
                </p>
                <p class="avatar-field">
                    <label class="traydigita-ui-toggle" for="avatar-allow-remote-url"><input class="traydigita-ui-toggle-input" type="checkbox" id="avatar-allow-remote-url" name="allow_remote_url" value="1" <?php checked($settings['allow_remote_url']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Allow avatars by remote image URL', 'traydigita-core'); ?></span></label>
                    <span class="avatar-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('When off, avatars can only be uploaded as files.', 'traydigita-core'),
                            __('Remote URLs are always verified as real images before they are saved.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>
                <p class="avatar-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>

            <div
                class="avatar-manager"
                data-avatar-admin
                data-msg-saved="<?php esc_attr_e('Avatar saved.', 'traydigita-core'); ?>"
                data-msg-deleted="<?php esc_attr_e('Avatar deleted.', 'traydigita-core'); ?>"
                data-msg-error="<?php esc_attr_e('Something went wrong. Try again.', 'traydigita-core'); ?>"
                data-msg-uploading="<?php esc_attr_e('Uploading…', 'traydigita-core'); ?>"
                data-msg-saving="<?php esc_attr_e('Saving…', 'traydigita-core'); ?>"
                data-msg-no-file="<?php esc_attr_e('Choose an image file first.', 'traydigita-core'); ?>"
                data-msg-no-user="<?php esc_attr_e('Choose a user first.', 'traydigita-core'); ?>"
            >
                <h2><?php esc_html_e('Manage avatars', 'traydigita-core'); ?></h2>
                <p class="avatar-manager-help">
                    <?php
                    SettingsControls::helpText(
                        __('Uploads are centre-cropped to a square and resized to the recommended size of 256×256 pixels.', 'traydigita-core'),
                        __('Files are stored under wp-content/traydigita-avatar/ and never in the media library.', 'traydigita-core')
                    );
                    ?>
                </p>
                <p class="avatar-status" data-avatar-status role="status" aria-live="polite"></p>

                <h3><?php esc_html_e('Set avatar by image URL', 'traydigita-core'); ?></h3>
                <form class="avatar-url-form" data-avatar-url-form method="post" action="">
                    <p class="avatar-field">
                        <label for="avatar-url-user"><?php esc_html_e('User', 'traydigita-core'); ?></label>
                        <select id="avatar-url-user" name="user_id">
                            <option value=""><?php esc_html_e('Choose a user…', 'traydigita-core'); ?></option>
                            <?php foreach ($users as $user) : ?>
                                <option value="<?php echo esc_attr((string) $user->ID); ?>">
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="avatar-field">
                        <label for="avatar-url-input"><?php esc_html_e('Image URL', 'traydigita-core'); ?></label>
                        <input type="url" id="avatar-url-input" name="avatar_url" placeholder="https://example.com/avatar.jpg" inputmode="url">
                        <span class="avatar-field-help">
                            <?php
                            SettingsControls::helpText(
                                __('The URL is checked before saving: the server must return an image (JPEG, PNG, GIF, or WebP).', 'traydigita-core'),
                                __('Anything else is rejected.', 'traydigita-core')
                            );
                            ?>
                        </span>
                    </p>
                    <p class="avatar-form-actions">
                        <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                            <?php esc_html_e('Set Avatar from URL', 'traydigita-core'); ?>
                        </button>
                    </p>
                </form>

                <h3><?php esc_html_e('Upload an avatar', 'traydigita-core'); ?></h3>
                <form class="avatar-upload-form" data-avatar-upload-form method="post" action="" enctype="multipart/form-data">
                    <p class="avatar-field">
                        <label for="avatar-upload-user"><?php esc_html_e('User', 'traydigita-core'); ?></label>
                        <select id="avatar-upload-user" name="user_id">
                            <option value=""><?php esc_html_e('Choose a user…', 'traydigita-core'); ?></option>
                            <?php foreach ($users as $user) : ?>
                                <option value="<?php echo esc_attr((string) $user->ID); ?>">
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="avatar-field">
                        <label for="avatar-upload-file"><?php esc_html_e('Image file', 'traydigita-core'); ?></label>
                        <input type="file" id="avatar-upload-file" name="avatar_file" accept="image/jpeg,image/png,image/gif,image/webp">
                        <span class="avatar-field-help">
                            <?php esc_html_e('JPEG, PNG, GIF, or WebP, up to 2 MB. Non-square images are centre-cropped to a square automatically.', 'traydigita-core'); ?>
                        </span>
                    </p>
                    <p class="avatar-preview" data-avatar-upload-preview hidden>
                        <img data-avatar-upload-preview-img src="" alt="">
                    </p>
                    <p class="avatar-form-actions">
                        <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                            <?php esc_html_e('Upload Avatar', 'traydigita-core'); ?>
                        </button>
                    </p>
                </form>

                <h3><?php esc_html_e('Users with a custom avatar', 'traydigita-core'); ?></h3>
                <?php if ($withAvatars === []) : ?>
                    <p class="avatar-empty">
                        <?php esc_html_e('No user has a custom avatar yet.', 'traydigita-core'); ?>
                    </p>
                <?php else : ?>
                    <table class="widefat striped avatar-list">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Avatar', 'traydigita-core'); ?></th>
                                <th><?php esc_html_e('User', 'traydigita-core'); ?></th>
                                <th><?php esc_html_e('Type', 'traydigita-core'); ?></th>
                                <th><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($withAvatars as $user) : ?>
                                <?php $avatar = AvatarStorage::getAvatar((int) $user->ID); ?>
                                <?php
                                if ($avatar === null) {
                                    continue;
                                }
                                ?>
                                <tr data-avatar-row data-user-id="<?php echo esc_attr((string) $user->ID); ?>">
                                    <td>
                                        <img class="avatar avatar-48 photo" src="<?php echo esc_url($avatar['url']); ?>" alt="" width="48" height="48" data-avatar-img>
                                    </td>
                                    <td>
                                        <?php echo esc_html($user->display_name); ?><br>
                                        <span class="avatar-user-email"><?php echo esc_html($user->user_email); ?></span>
                                    </td>
                                    <td>
                                        <?php echo $avatar['type'] === 'file' ? esc_html__('Uploaded file', 'traydigita-core') : esc_html__('Remote URL', 'traydigita-core'); ?>
                                    </td>
                                    <td>
                                        <button type="button" class="traydigita-admin-button" data-avatar-delete data-user-id="<?php echo esc_attr((string) $user->ID); ?>" data-confirm="<?php esc_attr_e('Delete this user’s custom avatar? They will fall back to Gravatar.', 'traydigita-core'); ?>">
                                            <?php esc_html_e('Delete', 'traydigita-core'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `allow_self_service`,
     * `allow_remote_url` (each `1` when checked; an absent key
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
                'message' => __('You are not allowed to change avatar settings.', 'traydigita-core'),
            ];
        }
        $settings = [
            'allow_self_service' => !empty($data['allow_self_service']),
            'allow_remote_url' => !empty($data['allow_remote_url']),
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Avatar settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Registers the module AJAX endpoints, the
     * `get_avatar` filter override, and the profile-page fields
     * (plus their save handler and the one-time result notice).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted || !ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        AvatarAjax::register();
        add_filter('get_avatar', [self::class, 'filterGetAvatar'], 10, 6);
        add_action('show_user_profile', [self::class, 'renderProfileFields']);
        add_action('edit_user_profile', [self::class, 'renderProfileFields']);
        add_action('personal_options_update', [self::class, 'saveProfileFields']);
        add_action('edit_user_profile_update', [self::class, 'saveProfileFields']);
        add_action('admin_notices', [self::class, 'showProfileNotice']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueProfileNoticeAssets']);
    }

    /**
     * Return the custom avatar for a user, or the default avatar untouched.
     *
     * The `$id_or_email` argument may be a user id, an email
     * address, a WP_User, a WP_Post (its author), or a WP_Comment.
     * When the resolved user has a custom avatar, an `<img>` tag
     * for it is returned with the requested size as its width and
     * height (the stored file is 256x256; the browser scales it).
     * Otherwise the original `$avatar` HTML (Gravatar or the site
     * default) is returned unchanged.
     *
     * @param string $avatar Default avatar HTML.
     * @param mixed $idOrEmail User id, email, WP_User, WP_Post, or WP_Comment.
     * @param int $size Requested avatar size in pixels.
     * @param string $default Default avatar URL.
     * @param string $alt Alt text for the image.
     * @param array<string, mixed> $args Extra get_avatar() arguments.
     * @return string Avatar HTML.
     */
    public static function filterGetAvatar(string $avatar, mixed $idOrEmail, int $size, string $default, string $alt, array $args): string
    {
        $userId = self::resolveUserId($idOrEmail);
        if ($userId === null) {
            return $avatar;
        }
        $custom = AvatarStorage::getAvatar($userId);
        if ($custom === null) {
            return $avatar;
        }
        $class = 'avatar avatar-' . $size . ' photo';
        $extraClass = $args['class'] ?? '';
        if (is_array($extraClass)) {
            $extraClass = implode(' ', $extraClass);
        }
        if (is_string($extraClass) && $extraClass !== '') {
            $class .= ' ' . $extraClass;
        }
        return sprintf(
            '<img alt="%s" src="%s" class="%s" height="%d" width="%d" decoding="async" />',
            esc_attr($alt),
            esc_url($custom['url']),
            esc_attr($class),
            $size,
            $size
        );
    }

    /**
     * Resolve a `get_avatar` id/email argument to a user id.
     *
     * Accepts a user id, a numeric string, an email address, a
     * WP_User, a WP_Post (its author), or a WP_Comment (its author
     * user, or the commenter email). Returns null when no user can
     * be resolved.
     *
     * @param mixed $idOrEmail Value passed to get_avatar().
     * @return int|null User id, or null when unresolvable.
     */
    public static function resolveUserId(mixed $idOrEmail): ?int
    {
        if ($idOrEmail instanceof WP_User) {
            return $idOrEmail->ID > 0 ? (int) $idOrEmail->ID : null;
        }
        if (is_int($idOrEmail) || (is_string($idOrEmail) && ctype_digit($idOrEmail))) {
            $id = (int) $idOrEmail;
            return $id > 0 ? $id : null;
        }
        if ($idOrEmail instanceof WP_Post) {
            return $idOrEmail->post_author > 0 ? (int) $idOrEmail->post_author : null;
        }
        if ($idOrEmail instanceof WP_Comment) {
            if ($idOrEmail->user_id > 0) {
                return (int) $idOrEmail->user_id;
            }
            $idOrEmail = $idOrEmail->comment_author_email;
        }
        if (is_string($idOrEmail) && $idOrEmail !== '') {
            $user = get_user_by('email', $idOrEmail);
            return $user !== false ? (int) $user->ID : null;
        }
        return null;
    }

    /**
     * Whether a non-administrator may set their own avatar.
     *
     * @return bool
     */
    public static function isSelfServiceAllowed(): bool
    {
        return self::getSettings()['allow_self_service'];
    }

    /**
     * Render the avatar fields on the user profile page.
     *
     * Shown when self-service is enabled, or to administrators
     * editing any profile. The fields live inside WordPress's own
     * profile form (which already uses multipart encoding), so the
     * file input works with a classic POST; the save handler below
     * verifies the nonce and the `edit_user` capability.
     *
     * @param WP_User $user User whose profile is shown.
     * @return void
     */
    public static function renderProfileFields(WP_User $user): void
    {
        if (!self::isSelfServiceAllowed() && !current_user_can('manage_options')) {
            return;
        }
        $avatar = AvatarStorage::getAvatar((int) $user->ID);
        $isOwnProfile = get_current_user_id() === (int) $user->ID;
        if (!$isOwnProfile && !current_user_can('edit_user', (int) $user->ID)) {
            return;
        }
        ?>
        <div data-avatar-profile>
            <h2><?php esc_html_e('Custom Avatar', 'traydigita-core'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="traydigita-avatar-url"><?php esc_html_e('Current avatar', 'traydigita-core'); ?></label></th>
                    <td>
                        <?php if ($avatar !== null) : ?>
                            <img class="avatar avatar-96 photo" src="<?php echo esc_url($avatar['url']); ?>" alt="" width="96" height="96" data-avatar-profile-current>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('No custom avatar set — Gravatar (or the site default) is used.', 'traydigita-core'); ?></p>
                        <?php endif; ?>
                        <p class="avatar-profile-preview" data-avatar-profile-preview hidden>
                            <img data-avatar-profile-preview-img src="" alt="">
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="traydigita-avatar-url"><?php esc_html_e('Avatar image URL', 'traydigita-core'); ?></label></th>
                    <td>
                        <input type="url" name="traydigita_avatar_url" id="traydigita-avatar-url" value="" class="traydigita-ui-input" placeholder="https://example.com/avatar.jpg" inputmode="url">
                        <p class="description">
                            <?php esc_html_e('The URL is verified as a real image (JPEG, PNG, GIF, or WebP) before it is saved.', 'traydigita-core'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="traydigita-avatar-file"><?php esc_html_e('Upload an avatar', 'traydigita-core'); ?></label></th>
                    <td>
                        <input type="file" name="traydigita_avatar_file" id="traydigita-avatar-file" accept="image/jpeg,image/png,image/gif,image/webp" data-avatar-profile-file>
                        <p class="description">
                            <?php esc_html_e('JPEG, PNG, GIF, or WebP, up to 2 MB. The image is centre-cropped to a square and resized to the recommended size of 256×256 pixels.', 'traydigita-core'); ?>
                        </p>
                    </td>
                </tr>
                <?php if ($avatar !== null) : ?>
                    <tr>
                        <th><?php esc_html_e('Remove avatar', 'traydigita-core'); ?></th>
                        <td>
                            <label class="traydigita-ui-toggle" for="traydigita-avatar-delete"><input class="traydigita-ui-toggle-input" type="checkbox" name="traydigita_avatar_delete" id="traydigita-avatar-delete" value="1"><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Delete the custom avatar (fall back to Gravatar)', 'traydigita-core'); ?></span></label>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            <?php wp_nonce_field(self::PROFILE_NONCE_ACTION, 'traydigita_avatar_nonce'); ?>
        </div>
        <?php
    }

    /**
     * Save the avatar fields submitted from the profile page.
     *
     * A file upload wins over a URL, and a URL wins over the
     * delete checkbox. The nonce and the `edit_user` capability
     * for the edited user are both required. Because the profile
     * form is a classic POST, the result is stored in a transient
     * and shown once as an admin notice on the next profile load
     * (see showProfileNotice()).
     *
     * @param int $userId Id of the user being edited.
     * @return void
     */
    public static function saveProfileFields(int $userId): void
    {
        $nonce = isset($_POST['traydigita_avatar_nonce']) && is_string($_POST['traydigita_avatar_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_avatar_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::PROFILE_NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_user', $userId)) {
            return;
        }
        if (!self::isSelfServiceAllowed() && !current_user_can('manage_options')) {
            return;
        }
        $hasFile = isset($_FILES['traydigita_avatar_file'])
            && is_array($_FILES['traydigita_avatar_file'])
            && !empty($_FILES['traydigita_avatar_file']['tmp_name']);
        $url = isset($_POST['traydigita_avatar_url']) && is_string($_POST['traydigita_avatar_url'])
            ? esc_url_raw(wp_unslash($_POST['traydigita_avatar_url']))
            : '';
        $delete = !empty($_POST['traydigita_avatar_delete']);
        if ($hasFile) {
            /** @var array<string, mixed> $file */
            $file = $_FILES['traydigita_avatar_file'];
            $result = AvatarStorage::storeUploadedFile($userId, $file);
        } elseif ($url !== '') {
            if (!self::getSettings()['allow_remote_url']) {
                $result = [
                    'success' => false,
                    'message' => __('Setting an avatar by URL is disabled by the site administrator.', 'traydigita-core'),
                ];
            } else {
                $result = AvatarStorage::setUrlAvatar($userId, $url);
            }
        } elseif ($delete) {
            AvatarStorage::deleteAvatar($userId);
            $result = [
                'success' => true,
                'message' => __('Avatar deleted.', 'traydigita-core'),
            ];
        } else {
            return;
        }
        set_transient(
            self::PROFILE_NOTICE_TRANSIENT . '_' . get_current_user_id(),
            ['success' => (bool) $result['success'], 'message' => (string) $result['message']],
            60
        );
    }

    /**
     * Show the one-time result notice of a profile-page avatar save.
     *
     * Only on the profile screens, for the administrator or user
     * who submitted the save. The transient is deleted after it
     * is read, so the notice shows exactly once.
     *
     * @return void
     */
    public static function showProfileNotice(): void
    {
        $screen = get_current_screen();
        if ($screen === null || ($screen->id !== 'profile' && $screen->id !== 'user-edit')) {
            return;
        }
        $current = wp_get_current_user();
        if ($current === null || $current->ID === 0) {
            return;
        }
        $userId = (int) $current->ID;
        $notice = get_transient(self::PROFILE_NOTICE_TRANSIENT . '_' . $userId);
        if (!is_array($notice) || !isset($notice['message'])) {
            return;
        }
        delete_transient(self::PROFILE_NOTICE_TRANSIENT . '_' . $userId);
        AdminNotice::render(
            !empty($notice['success']) ? 'success' : 'error',
            (string) $notice['message']
        );
    }

    /**
     * Enqueue the admin UI library on the profile screen when the
     * one-time avatar notice will show.
     *
     * profile.php is not a TrayDigita page, so the `admin` entry
     * does not load there by itself — without it the library
     * notice in showProfileNotice() would render unstyled. The
     * entry's JavaScript guards itself when the admin shell is
     * absent, so only its styles take effect here.
     *
     * @return void
     */
    public static function enqueueProfileNoticeAssets(): void
    {
        $current = wp_get_current_user();
        if ($current === null || (int) $current->ID === 0) {
            return;
        }
        if (get_transient(self::PROFILE_NOTICE_TRANSIENT . '_' . (int) $current->ID) === false) {
            return;
        }
        Core::assets()->enqueueAdminEntry();
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{allow_self_service: bool, allow_remote_url: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        return [
            'allow_self_service' => (bool) ($settings['allow_self_service'] ?? true),
            'allow_remote_url' => (bool) ($settings['allow_remote_url'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * Self-service and remote URLs are both on: a user may set
     * their own avatar from their profile, by URL or by upload.
     *
     * @return array{allow_self_service: bool, allow_remote_url: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'allow_self_service' => true,
            'allow_remote_url' => true,
        ];
    }
}
