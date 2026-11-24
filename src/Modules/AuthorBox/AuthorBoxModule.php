<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\AuthorBox;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use WP_Post;
use WP_User;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function array_merge;
use function checked;
use function count_user_posts;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_author_posts_url;
use function get_avatar;
use function get_option;
use function get_post;
use function get_the_author_meta;
use function get_the_ID;
use function get_theme_mod;
use function get_user_meta;
use function get_userdata;
use function in_array;
use function in_the_loop;
use function is_admin;
use function is_array;
use function is_feed;
use function is_int;
use function is_main_query;
use function is_page;
use function is_singular;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function selected;
use function set_theme_mod;
use function sprintf;
use function str_starts_with;
use function substr;
use function trim;
use function update_option;
use function wp_nonce_field;
use function _n;

/**
 * Author Box module.
 *
 * Renders an author box below (or above) the article content on
 * single posts: avatar, display name, bio, social links, a link to
 * the author archive, and the author's published article count.
 * Pages are excluded by default and can be opted in.
 *
 * Avatar: the Custom Avatar module (phase 4) overrides the core
 * `get_avatar` filter, so calling get_avatar() already renders a
 * custom avatar when that module is enabled. This module therefore
 * never duplicates avatar storage or rendering logic: it calls
 * core get_avatar() and lets the filter do the work.
 *
 * Social links come only from profile fields that already exist:
 * the core `user_url` (Website) plus four read-only user-meta keys
 * that other plugins and themes commonly fill — `twitter`,
 * `facebook`, `instagram`, `linkedin`. These keys are READ, never
 * written, by this module: an empty or absent key simply renders
 * no link, and no new user meta is ever created. A stored value
 * that is a full URL is used as-is; a bare Twitter handle is
 * turned into an x.com profile URL.
 *
 * Settings live in the single option `traydigita_author_box`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and casts every field to its own type.
 */
final class AuthorBoxModule implements ModuleInterface
{
    /**
     * Option name for all author-box settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_author_box';

    /**
     * Customizer setting id for the position (theme mod).
     */
    public const string CUSTOMIZER_POSITION_ID = 'author_box_position';

    /**
     * Customizer setting id for showing the box on pages (theme mod).
     */
    public const string CUSTOMIZER_SHOW_ON_PAGES_ID = 'author_box_show_on_pages';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_author_box_save';

    /**
     * Default avatar size in pixels.
     */
    public const int DEFAULT_AVATAR_SIZE = 96;

    /**
     * Allowed placement values.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['below', 'above', 'manual'];

    /**
     * Read-only social profile fields.
     *
     * Keyed by user-meta key; each entry holds the network label
     * and the URL prefix used when the stored value is a bare
     * handle instead of a full URL. The `user_url` Website field
     * is handled separately (see socialLinks()). These keys are
     * only ever read — this module never writes user meta.
     *
     * @var array<string, array{label: string, prefix: string}>
     */
    public const array SOCIAL_FIELDS = [
        'twitter' => ['label' => 'X (Twitter)', 'prefix' => 'https://x.com/'],
        'facebook' => ['label' => 'Facebook', 'prefix' => 'https://www.facebook.com/'],
        'instagram' => ['label' => 'Instagram', 'prefix' => 'https://www.instagram.com/'],
        'linkedin' => ['label' => 'LinkedIn', 'prefix' => 'https://www.linkedin.com/in/'],
    ];

    /**
     * Re-entrancy guard for the `the_content` filter.
     */
    private static bool $filtering = false;

    /**
     * Whether boot() already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'author-box';
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
        return 'content';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Author Box', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Shows an author box below the article with avatar, bio, social links, and article count. Uses the Custom Avatar module when it is enabled, otherwise the standard avatar.', 'traydigita-core');
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
            __('Author box below every article.', 'traydigita-core'),
            __('Avatar, bio, social links, and article count.', 'traydigita-core'),
            __('Uses the Custom Avatar module when enabled, otherwise the standard avatar.', 'traydigita-core'),
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
        return 'traydigita-author-box';
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
     * No setup is needed: the defaults render a working box, so
     * this is always true.
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
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * One form: placement select, show-on-pages checkbox, social
     * links checkbox, article-count checkbox, and avatar size.
     * The form is submitted by the generic admin JavaScript via
     * the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings author-box-settings">
            <?php
            SettingsControls::intro(
                __('The author box appears on single posts with the author avatar, name, '
                    . 'bio, social links, and article count.', 'traydigita-core'),
                __('The avatar comes from the Custom Avatar module when it is enabled, otherwise from the standard avatar.', 'traydigita-core')
            );
            ?>

            <form class="author-box-settings-form" data-traydigita-module-form data-traydigita-module="author-box" data-module="author-box" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="author-box-field">
                    <label for="author-box-position"><?php esc_html_e('Position', 'traydigita-core'); ?></label>
                    <select id="author-box-position" name="position">
                        <option value="below" <?php selected($settings['position'], 'below'); ?>><?php esc_html_e('Below the content', 'traydigita-core'); ?></option>
                        <option value="above" <?php selected($settings['position'], 'above'); ?>><?php esc_html_e('Above the content', 'traydigita-core'); ?></option>
                        <option value="manual" <?php selected($settings['position'], 'manual'); ?>><?php esc_html_e('Manual placement (template partial only)', 'traydigita-core'); ?></option>
                    </select>
                    <span class="author-box-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('With manual placement the content filter does nothing.', 'traydigita-core'),
                            __('Place the author-box partial (or call AuthorBoxModule::render()) where the box belongs.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="author-box-field">
                    <label class="traydigita-ui-toggle" for="author-box-show-pages"><input class="traydigita-ui-toggle-input" type="checkbox" id="author-box-show-pages" name="show_on_pages" value="1" <?php checked($settings['show_on_pages']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show on pages too', 'traydigita-core'); ?></span></label>
                    <span class="author-box-field-help">
                        <?php esc_html_e('Off by default: the box shows on single posts only.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="author-box-field">
                    <label class="traydigita-ui-toggle" for="author-box-show-social"><input class="traydigita-ui-toggle-input" type="checkbox" id="author-box-show-social" name="show_social" value="1" <?php checked($settings['show_social']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show social links', 'traydigita-core'); ?></span></label>
                    <span class="author-box-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Links from the author Website field and the social profile fields (X, Facebook, '
                                . 'Instagram, LinkedIn) when they are filled in.', 'traydigita-core'),
                            __('Empty fields render no link.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="author-box-field">
                    <label class="traydigita-ui-toggle" for="author-box-show-count"><input class="traydigita-ui-toggle-input" type="checkbox" id="author-box-show-count" name="show_post_count" value="1" <?php checked($settings['show_post_count']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show article count', 'traydigita-core'); ?></span></label>
                    <span class="author-box-field-help">
                        <?php esc_html_e('Links to the author archive with the number of published articles.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="author-box-field">
                    <label for="author-box-avatar-size"><?php esc_html_e('Avatar size (pixels)', 'traydigita-core'); ?></label>
                    <input type="number" id="author-box-avatar-size" name="avatar_size" value="<?php echo esc_attr((string) $settings['avatar_size']); ?>" min="48" max="256" step="1">
                </p>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys: `position` (allowlisted), `show_on_pages`,
     * `show_social`, `show_post_count` (checkboxes: `1` when
     * checked, absent when unchecked), `avatar_size` (integer
     * clamped to 48–256). Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Author Box settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position'])
            ? sanitize_key($data['position'])
            : 'below';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'below';
        }
        $avatarSize = isset($data['avatar_size']) ? absint($data['avatar_size']) : self::DEFAULT_AVATAR_SIZE;
        if ($avatarSize < 48) {
            $avatarSize = 48;
        } elseif ($avatarSize > 256) {
            $avatarSize = 256;
        }
        $showOnPages = !empty($data['show_on_pages']);
        update_option(self::OPTION, [
            'position' => $position,
            'show_on_pages' => $showOnPages,
            'show_social' => !empty($data['show_social']),
            'show_post_count' => !empty($data['show_post_count']),
            'avatar_size' => $avatarSize,
        ], false);
        // Keep the Customizer copies in sync (see
        // syncFromCustomizer() for the other direction).
        set_theme_mod(self::CUSTOMIZER_POSITION_ID, $position);
        set_theme_mod(self::CUSTOMIZER_SHOW_ON_PAGES_ID, $showOnPages);
        return [
            'success' => true,
            'message' => __('Author Box settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Registers the `the_content` filter at priority 13 — after the
     * Table of Contents filter at 12, so a box placed above the
     * content lands outside the TOC. With the `manual` placement
     * the filter does nothing and the box renders only from the
     * entry footer template (layouts/entry/footer.php). Also
     * registers the Customizer definitions for position and
     * show-on-pages and the Customizer-save sync, so the two
     * settings never drift between the module page and the
     * Customizer. A disabled module registers nothing; the
     * isEnabled() guard is belt and braces for a direct call.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        add_filter('traydigita:core:customizer/definitions', [self::class, 'registerCustomizerDefinitions']);
        add_action('customize_save_after', [self::class, 'syncFromCustomizer']);
        add_filter('the_content', [self::class, 'filterContent'], 13);
    }

    /**
     * Render the author box for a post.
     *
     * Returns an empty string when the post does not exist, has no
     * author, or is not a single post (or a page with
     * `show_on_pages` enabled). The box always shows the author
     * name linked to the archive; the bio, social links, and
     * article count render only when the data and their toggles
     * allow it.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Author box markup, or '' when there is nothing to show.
     */
    public static function render(?int $postId = null): string
    {
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return '';
        }
        $post = get_post($postId);
        if ($post === null || !($post instanceof WP_Post)) {
            return '';
        }
        $settings = self::getSettings();
        if ($post->post_type === 'page') {
            // Pages opt in through the setting; filterContent() also
            // enforces the single-post/single-page rule on the
            // automatic path, while render() stays callable from a
            // template for an explicit placement.
            if (!$settings['show_on_pages']) {
                return '';
            }
        } elseif ($post->post_type !== 'post') {
            return '';
        }
        $authorId = (int) $post->post_author;
        if ($authorId <= 0) {
            return '';
        }
        $author = get_userdata($authorId);
        if (!$author instanceof WP_User) {
            return '';
        }
        $name = trim((string) get_the_author_meta('display_name', $authorId));
        if ($name === '') {
            $name = trim((string) $author->user_nicename);
        }
        if ($name === '') {
            $name = trim((string) $author->user_login);
        }
        if ($name === '') {
            return '';
        }
        $bio = trim((string) get_the_author_meta('description', $authorId));
        $archiveUrl = get_author_posts_url($authorId);
        $html = '<aside class="author-box" aria-label="' . esc_attr__('About the author', 'traydigita-core') . '">';
        $html .= '<div class="author-box-avatar">' . self::avatarHtml($authorId, $settings['avatar_size']) . '</div>';
        $html .= '<div class="author-box-content">';
        $html .= '<span class="author-box-label">' . esc_html__('Written by', 'traydigita-core') . '</span>';
        $html .= '<p class="author-box-name"><a href="' . esc_url($archiveUrl) . '">' . esc_html($name) . '</a></p>';
        if ($bio !== '') {
            $html .= '<p class="author-box-bio">' . esc_html($bio) . '</p>';
        }
        if ($settings['show_social']) {
            $social = self::socialLinks($authorId);
            if ($social !== []) {
                $html .= '<ul class="author-box-social">';
                foreach ($social as $link) {
                    $html .= '<li class="author-box-social-item"><a class="author-box-social-link author-box-social-'
                        . esc_attr($link['key']) . '" href="' . esc_url($link['url']) . '" target="_blank" rel="noopener" aria-label="'
                        . esc_attr($link['label']) . '">' . esc_html($link['label']) . '</a></li>';
                }
                $html .= '</ul>';
            }
        }
        if ($settings['show_post_count']) {
            $count = count_user_posts($authorId, 'post', true);
            $html .= '<a class="author-box-archive" href="' . esc_url($archiveUrl) . '">';
            $html .= esc_html(
                sprintf(
                    /* translators: %1$s is the article count, %2$s is the author display name. */
                    _n('%1$s article by %2$s', '%1$s articles by %2$s', (int) $count, 'traydigita-core'),
                    (string) $count,
                    $name
                )
            );
            $html .= '</a>';
        }
        $html .= '</div></aside>';
        return $html;
    }

    /**
     * Filter `the_content`: append or prepend the author box.
     *
     * Runs only in the main loop on a single post (or a page when
     * `show_on_pages` is enabled); never in feeds, excerpts,
     * widgets, or the admin. With the `manual` placement the
     * content is returned unchanged.
     *
     * @param string $content Filtered post content.
     * @return string Content with the author box added, or unchanged.
     */
    public static function filterContent(string $content): string
    {
        if (self::$filtering || is_admin() || is_feed() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $settings = self::getSettings();
        if ($settings['position'] === 'manual') {
            return $content;
        }
        $isPost = is_singular('post');
        $isPage = is_page() && $settings['show_on_pages'];
        if (!$isPost && !$isPage) {
            return $content;
        }
        self::$filtering = true;
        try {
            $box = self::render(get_the_ID() ?: null);
            if ($box === '') {
                return $content;
            }
            return $settings['position'] === 'above' ? $box . $content : $content . $box;
        } finally {
            self::$filtering = false;
        }
    }

    /**
     * Render the avatar image for a user.
     *
     * Prefers the Custom Avatar module's public helper when that
     * module exists (its class is built by the parallel Avatar
     * track); otherwise falls back to core get_avatar(), which the
     * Avatar module also overrides through the `get_avatar` filter
     * when it is enabled — so a custom avatar renders either way
     * and no avatar logic is duplicated here. Any failure in the
     * helper falls back to get_avatar() as well.
     *
     * @param int $userId User id.
     * @param int $size Avatar size in pixels.
     * @return string Avatar `<img>` markup (already escaped by its source).
     */
    public static function avatarHtml(int $userId, int $size): string
    {
        $avatar = get_avatar($userId, $size, '', '', ['class' => 'author-box-avatar-img']);
        return is_string($avatar) ? $avatar : '';
    }

    /**
     * Collect the author's social links.
     *
     * Reads only fields that already exist: the core Website
     * (`user_url`) plus the read-only keys in SOCIAL_FIELDS. A
     * stored value that is already a URL is used as-is; otherwise
     * the network prefix turns a bare handle into a profile URL.
     * Values are sanitized as text; empty values produce no link.
     *
     * @param int $userId User id.
     * @return array<int, array{key: string, label: string, url: string}>
     */
    public static function socialLinks(int $userId): array
    {
        $links = [];
        $website = trim((string) get_the_author_meta('user_url', $userId));
        if ($website !== '') {
            $links[] = [
                'key' => 'website',
                'label' => __('Website', 'traydigita-core'),
                'url' => $website,
            ];
        }
        foreach (self::SOCIAL_FIELDS as $key => $field) {
            $value = trim(sanitize_text_field((string) get_user_meta($userId, $key, true)));
            if ($value === '') {
                continue;
            }
            $value = substr($value, 0, 1) === '@' ? substr($value, 1) : $value;
            $url = str_starts_with($value, 'http://') || str_starts_with($value, 'https://')
                ? $value
                : $field['prefix'] . $value;
            $links[] = [
                'key' => $key,
                'label' => $field['label'],
                'url' => $url,
            ];
        }
        return $links;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{position: string, show_on_pages: bool, show_social: bool, show_post_count: bool, avatar_size: int}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $position = isset($settings['position']) && is_string($settings['position'])
            ? sanitize_key($settings['position'])
            : 'below';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'below';
        }
        $avatarSize = isset($settings['avatar_size']) ? absint($settings['avatar_size']) : self::DEFAULT_AVATAR_SIZE;
        if ($avatarSize < 48) {
            $avatarSize = 48;
        } elseif ($avatarSize > 256) {
            $avatarSize = 256;
        }
        return [
            'position' => $position,
            'show_on_pages' => (bool) ($settings['show_on_pages'] ?? false),
            'show_social' => (bool) ($settings['show_social'] ?? false),
            'show_post_count' => (bool) ($settings['show_post_count'] ?? false),
            'avatar_size' => $avatarSize,
        ];
    }

    /**
     * Default settings.
     *
     * Below the content, posts only, social links and article
     * count shown, 96 px avatar.
     *
     * @return array{position: string, show_on_pages: bool, show_social: bool, show_post_count: bool, avatar_size: int}
     */
    public static function getDefaults(): array
    {
        return [
            'position' => 'below',
            'show_on_pages' => false,
            'show_social' => true,
            'show_post_count' => true,
            'avatar_size' => self::DEFAULT_AVATAR_SIZE,
        ];
    }

    /**
     * Customizer definitions for this module.
     *
     * Registered by boot() through the
     * `traydigita:core:customizer/definitions` filter (see
     * SettingsCustomizer). Only the two settings readers most often
     * reach for are exposed here — the full set stays on the module
     * page. The module option stays the source of truth; the theme
     * mods are kept in sync by saveSettings() and
     * syncFromCustomizer().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function customizerDefinitions(): array
    {
        return [
            [
                'id' => 'author_box_position',
                'type' => 'select',
                'section' => 'traydigita_article',
                'default' => 'below',
                'label' => __('Author box position', 'traydigita-core'),
                'choices' => [
                    'below' => __('Below the content', 'traydigita-core'),
                    'above' => __('Above the content', 'traydigita-core'),
                    'manual' => __('Manual placement (template partial only)', 'traydigita-core'),
                ],
            ],
            [
                'id' => 'author_box_show_on_pages',
                'type' => 'checkbox',
                'section' => 'traydigita_article',
                'default' => false,
                'label' => __('Show the author box on pages too', 'traydigita-core'),
            ],
        ];
    }

    /**
     * Append this module's definitions to the Customizer list.
     *
     * Filter callback for `traydigita:core:customizer/definitions`,
     * registered by boot().
     *
     * @param array<int, mixed> $definitions Existing definitions.
     * @return array<int, mixed> Definitions with the author-box settings appended.
     */
    public static function registerCustomizerDefinitions(array $definitions): array
    {
        return array_merge($definitions, self::customizerDefinitions());
    }

    /**
     * Copy the Customizer values into the module option.
     *
     * Runs on `customize_save_after`, so merely previewing a value
     * in the Customizer never writes to the database. Only valid
     * values are copied; an invalid theme mod is ignored and the
     * stored option keeps its value. The other direction
     * (module page to Customizer) is handled by saveSettings().
     *
     * @return void
     */
    public static function syncFromCustomizer(): void
    {
        if (!current_user_can('edit_theme_options')) {
            return;
        }
        $settings = self::getSettings();
        $changed = false;
        $position = get_theme_mod(self::CUSTOMIZER_POSITION_ID, $settings['position']);
        if (is_string($position)
            && in_array($position, self::POSITIONS, true)
            && $position !== $settings['position']
        ) {
            $settings['position'] = $position;
            $changed = true;
        }
        $showOnPages = (bool) get_theme_mod(self::CUSTOMIZER_SHOW_ON_PAGES_ID, $settings['show_on_pages']);
        if ($showOnPages !== (bool) $settings['show_on_pages']) {
            $settings['show_on_pages'] = $showOnPages;
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $settings, false);
        }
    }
}
