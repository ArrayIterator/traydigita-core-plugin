<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Share;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use function __;
use function add_action;
use function add_filter;
use function add_query_arg;
use function checked;
use function current_user_can;
use function esc_attr;
use function esc_html_e;
use function get_option;
use function get_permalink;
use function get_post;
use function get_the_title;
use function in_array;
use function in_the_loop;
use function is_admin;
use function is_feed;
use function is_main_query;
use function is_singular;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function rawurlencode;
use function sanitize_text_field;
use function selected;
use function setup_postdata;
use function update_option;
use function wp_nonce_field;
use function wp_reset_postdata;

/**
 * Share Buttons module.
 *
 * Renders one share block modelled on the verified padangkita.com
 * reference (single article, inspected 2026-11-21): a single row
 * at the bottom of the article body, after the topic tags and
 * immediately above the related posts, headed "Share this news"
 * in small grey text, with six controls in one left-aligned
 * row of small white rounded-rectangle buttons with a thin
 * light-grey border and dark navy icons/text, and no brand
 * colours: Facebook, X, WhatsApp, Email (all icon-only), Copy
 * link ("Salin tautan") and Print ("Cetak"). No share counts are
 * fetched anywhere (no external count APIs — privacy and
 * performance), no share row is shown at the top, and no
 * floating rail is shown by default.
 *
 * Per-network toggles (Facebook, X, WhatsApp, Telegram, Email)
 * and the position (below content / above content / floating
 * side) live on the module settings page; Telegram is an extra
 * network and is off by default. Copy link and Print are local
 * actions, not networks, so they have no toggle and are always
 * shown. By default the block appears on single posts only; a
 * toggle extends it to pages.
 *
 * Rendering: `render()` returns the block HTML for the current
 * post by capturing `templates/sections/share-buttons.php`
 * through the theme templating loader. The default 'below'
 * position renders explicitly right after the tags block in
 * `layouts/entry/footer.php`, matching the reference order
 * (tags, share, related); `boot()` therefore registers the
 * `the_content` filter at priority 20 only for the 'above'
 * position, and `wp_footer` for the floating rail. A
 * coordinator that moves the block must not register the
 * filter for 'below' as well, or the block will appear twice.
 *
 * Settings live in the single option `traydigita_share`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 */
final class ShareModule implements ModuleInterface
{
    /**
     * Option name for all share settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_share';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_share_save';

    /**
     * Allowed positions for the share block.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['below', 'above', 'floating'];

    /**
     * Default heading, matching the padangkita.com reference.
     */
    public const string DEFAULT_HEADING = 'Share this news';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'share';
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
        return __('Share Buttons', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A single share row at the bottom of the article — Facebook, X, WhatsApp, Telegram, email, copy link, and print — styled after padangkita.com, with no share counts and no external requests.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'share-alt';
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
            __('Share row at the bottom of the article.', 'traydigita-core'),
            __('Facebook, X, WhatsApp, Telegram, email, copy link, and print.', 'traydigita-core'),
            __('No third-party scripts.', 'traydigita-core'),
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
        return 'traydigita-share';
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
     * Configured means at least one network is switched on. Copy
     * link and Print are always available, so a module with every
     * network off still renders a (much smaller) block — the
     * badge only reflects the network setup.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $settings = self::getSettings();
        return $settings['facebook']
            || $settings['x']
            || $settings['whatsapp']
            || $settings['telegram']
            || $settings['email'];
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
     * One toggle per network (Telegram off by default), a position
     * select, a pages toggle, and the heading text. The form is
     * submitted through the generic module-settings AJAX
     * dispatcher (see `data-traydigita-module-form`).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings share-settings">
            <?php
            SettingsControls::intro(
                __('One share row on single posts, styled after padangkita.com: small white buttons with dark '
                    . 'navy icons, no brand colours, and no share counts.', 'traydigita-core'),
                __('No external service is contacted.', 'traydigita-core')
            );
            ?>

            <form class="share-settings-form" data-traydigita-module-form data-traydigita-module="share" data-module="share" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <fieldset class="share-field-group">
                    <legend><?php esc_html_e('Networks', 'traydigita-core'); ?></legend>

                    <p class="share-field">
                        <label class="traydigita-ui-toggle" for="share-facebook"><input class="traydigita-ui-toggle-input" type="checkbox" id="share-facebook" name="facebook" value="1" <?php checked($settings['facebook']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Facebook', 'traydigita-core'); ?></span></label>
                    </p>

                    <p class="share-field">
                        <label class="traydigita-ui-toggle" for="share-x"><input class="traydigita-ui-toggle-input" type="checkbox" id="share-x" name="x" value="1" <?php checked($settings['x']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('X', 'traydigita-core'); ?></span></label>
                    </p>

                    <p class="share-field">
                        <label class="traydigita-ui-toggle" for="share-whatsapp"><input class="traydigita-ui-toggle-input" type="checkbox" id="share-whatsapp" name="whatsapp" value="1" <?php checked($settings['whatsapp']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('WhatsApp', 'traydigita-core'); ?></span></label>
                    </p>

                    <p class="share-field">
                        <label class="traydigita-ui-toggle" for="share-telegram"><input class="traydigita-ui-toggle-input" type="checkbox" id="share-telegram" name="telegram" value="1" <?php checked($settings['telegram']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Telegram', 'traydigita-core'); ?></span></label>
                        <span class="share-field-help">
                            <?php esc_html_e('Extra network, off by default — it is not part of the padangkita.com reference row.', 'traydigita-core'); ?>
                        </span>
                    </p>

                    <p class="share-field">
                        <label class="traydigita-ui-toggle" for="share-email"><input class="traydigita-ui-toggle-input" type="checkbox" id="share-email" name="email" value="1" <?php checked($settings['email']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Email', 'traydigita-core'); ?></span></label>
                        <span class="share-field-help">
                            <?php esc_html_e('Opens the visitor’s mail client with the post title as the subject.', 'traydigita-core'); ?>
                        </span>
                    </p>

                    <p class="share-field-help">
                        <?php esc_html_e('Copy link and Print are always shown — they are local actions, not networks.', 'traydigita-core'); ?>
                    </p>
                </fieldset>

                <p class="share-field">
                    <label for="share-position"><?php esc_html_e('Position', 'traydigita-core'); ?></label>
                    <select id="share-position" name="position">
                        <option value="below" <?php selected($settings['position'], 'below'); ?>><?php esc_html_e('Below the content (default)', 'traydigita-core'); ?></option>
                        <option value="above" <?php selected($settings['position'], 'above'); ?>><?php esc_html_e('Above the content', 'traydigita-core'); ?></option>
                        <option value="floating" <?php selected($settings['position'], 'floating'); ?>><?php esc_html_e('Floating side rail (desktop only)', 'traydigita-core'); ?></option>
                    </select>
                    <span class="share-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Below the content matches the reference: after the topic tags, '
                                . 'immediately above the related posts.', 'traydigita-core'),
                            __('The floating rail is a vertical icon-only rail on the left edge, hidden on narrow screens.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="share-field">
                    <label class="traydigita-ui-toggle" for="share-show-on-pages"><input class="traydigita-ui-toggle-input" type="checkbox" id="share-show-on-pages" name="show_on_pages" value="1" <?php checked($settings['show_on_pages']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Also show on pages', 'traydigita-core'); ?></span></label>
                    <span class="share-field-help">
                        <?php esc_html_e('Off by default: the block appears on single posts only.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="share-field">
                    <label for="share-heading"><?php esc_html_e('Heading', 'traydigita-core'); ?></label>
                    <input type="text" id="share-heading" name="heading" value="<?php echo esc_attr($settings['heading']); ?>" maxlength="120">
                    <span class="share-field-help">
                        <?php esc_html_e('Small grey heading above the buttons. Empty resets to the default.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="share-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys in `$data`: `facebook`, `x`,
     * `whatsapp`, `telegram`, `email`, `show_on_pages` (each `1`
     * when checked; an absent key means the checkbox was
     * unchecked), `position` (allowlisted), and `heading` (plain
     * text). Every value is re-sanitized for its own type;
     * unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change share settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position']) ? $data['position'] : '';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'below';
        }
        $heading = isset($data['heading']) && is_string($data['heading'])
            ? sanitize_text_field($data['heading'])
            : '';
        if ($heading === '') {
            $heading = self::DEFAULT_HEADING;
        }
        $settings = [
            'facebook' => !empty($data['facebook']),
            'x' => !empty($data['x']),
            'whatsapp' => !empty($data['whatsapp']),
            'telegram' => !empty($data['telegram']),
            'email' => !empty($data['email']),
            'position' => $position,
            'show_on_pages' => !empty($data['show_on_pages']),
            'heading' => $heading,
        ];
        update_option(self::OPTION, $settings, false);
        return [
            'success' => true,
            'message' => __('Share settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The content filter (priority 20, after wpautop)
     * appends or prepends the block for the below/above
     * positions; the floating rail is rendered from `wp_footer`
     * instead, so it never ends up inside the post content.
     *
     * The frontend JavaScript (`resources/js/modules/share.ts`,
     * `initShare()`) and CSS (`resources/css/components/share.css`)
     * are bundled into the theme's app entry by the coordinator;
     * this module registers no separate assets.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        // The default 'below' position renders explicitly after
        // the tags in layouts/entry/footer.php (padangkita.com
        // order: tags, share, related), so the content filter
        // only runs for the 'above' position; the floating rail
        // renders from wp_footer instead.
        if (self::getSettings()['position'] !== 'below') {
            add_filter('the_content', [self::class, 'filterContent'], 20);
        }
        add_action('wp_footer', [self::class, 'renderFloating'], 20);
    }

    /**
     * Append or prepend the share block to the post content.
     *
     * Only for the main query, inside the loop, on a singular
     * view, and only for the below/above positions — the
     * floating rail is rendered by `renderFloating()` instead.
     * Feeds and admin screens never get a share block. A
     * coordinator that renders the block explicitly (see the
     * class docblock) should remove this filter for the posts
     * it places by hand, or the block will appear twice.
     *
     * @param string $content The post content.
     * @return string The content with the share block added, or unchanged.
     */
    public static function filterContent(string $content): string
    {
        if (is_admin() || is_feed() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $settings = self::getSettings();
        if ($settings['position'] === 'floating') {
            return $content;
        }
        $html = self::render();
        if ($html === '') {
            return $content;
        }
        return $settings['position'] === 'above' ? $html . $content : $content . $html;
    }

    /**
     * Render the floating side rail from `wp_footer`.
     *
     * Only when the position setting is `floating` and the
     * current view is a singular post (or page, when enabled).
     * The rail is hidden on narrow screens by CSS.
     *
     * @return void
     */
    public static function renderFloating(): void
    {
        if (is_admin() || is_feed()) {
            return;
        }
        $settings = self::getSettings();
        if ($settings['position'] !== 'floating') {
            return;
        }
        $html = self::render();
        if ($html !== '') {
            // The block is already fully escaped by the partial.
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Render the share block for a post, or an empty string when it must not show.
     *
     * The block is rendered for the current post in the loop
     * (or the post passed in, which is set up with
     * `setup_postdata()` so the partial sees it). It is only
     * rendered on singular views: single posts always, pages
     * only when the `show_on_pages` setting is on. The markup
     * comes from `templates/sections/share-buttons.php`,
     * captured through the theme templating loader so a child
     * theme can override the partial.
     *
     * @param \WP_Post|null $post Post to render for; the current post when null.
     * @return string
     */
    public static function render(?\WP_Post $post = null): string
    {
        $post ??= get_post();
        if (!$post instanceof \WP_Post) {
            return '';
        }
        if (!self::isVisibleForPost($post)) {
            return '';
        }
        $resetPostData = false;
        if (get_post() !== $post) {
            setup_postdata($post);
            $resetPostData = true;
        }
        ob_start();
        Core::template()->loadSection('share-buttons', null, Core::template()->getArguments());
        $html = ob_get_clean();
        if ($resetPostData) {
            wp_reset_postdata();
        }
        return is_string($html) ? $html : '';
    }

    /**
     * Whether the share block may appear for this post.
     *
     * Single posts always qualify; pages only when the
     * `show_on_pages` setting is on; any other post type never
     * does. The check also requires a singular view, so archive
     * and home loops never get a block (the content filter can
     * run there for full-content cards).
     *
     * @param \WP_Post $post Post to check.
     * @return bool
     */
    public static function isVisibleForPost(\WP_Post $post): bool
    {
        if (!is_singular()) {
            return false;
        }
        if ($post->post_type === 'post') {
            return is_singular('post');
        }
        if ($post->post_type === 'page') {
            return self::getSettings()['show_on_pages'] && is_singular('page');
        }
        return false;
    }

    /**
     * Share controls for a post, in display order.
     *
     * Networks follow the module toggles; the order matches the
     * reference row (Facebook, X, WhatsApp, Telegram, Email),
     * followed by the two local actions, Copy link and Print.
     * Every share URL is built with `add_query_arg()` (or
     * `rawurlencode()` for the mailto link) and escaped with
     * `esc_url()` at output in the partial. No share counts are
     * fetched and no external request is made.
     *
     * @param \WP_Post $post Post to share.
     * @return array<int, array{key: string, label: string, url: string|null, action: string}>
     */
    public static function links(\WP_Post $post): array
    {
        $settings = self::getSettings();
        $url = get_permalink($post);
        if ($url === false) {
            $url = '';
        }
        $title = get_the_title($post);
        $links = [];
        if ($settings['facebook']) {
            $links[] = [
                'key' => 'facebook',
                'label' => __('Share on Facebook', 'traydigita-core'),
                'url' => add_query_arg(['u' => $url], 'https://www.facebook.com/sharer/sharer.php'),
                'action' => 'link',
            ];
        }
        if ($settings['x']) {
            $links[] = [
                'key' => 'x',
                'label' => __('Share on X', 'traydigita-core'),
                'url' => add_query_arg(
                    ['url' => $url, 'text' => $title],
                    'https://twitter.com/intent/tweet'
                ),
                'action' => 'link',
            ];
        }
        if ($settings['whatsapp']) {
            $links[] = [
                'key' => 'whatsapp',
                'label' => __('Share on WhatsApp', 'traydigita-core'),
                'url' => add_query_arg(
                    ['text' => $title . ' ' . $url],
                    'https://api.whatsapp.com/send'
                ),
                'action' => 'link',
            ];
        }
        if ($settings['telegram']) {
            $links[] = [
                'key' => 'telegram',
                'label' => __('Share on Telegram', 'traydigita-core'),
                'url' => add_query_arg(
                    ['url' => $url, 'text' => $title],
                    'https://t.me/share/url'
                ),
                'action' => 'link',
            ];
        }
        if ($settings['email']) {
            $links[] = [
                'key' => 'email',
                'label' => __('Share by email', 'traydigita-core'),
                'url' => 'mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($url),
                'action' => 'link',
            ];
        }
        $links[] = [
            'key' => 'copy',
            'label' => __('Copy link', 'traydigita-core'),
            'url' => $url,
            'action' => 'copy',
        ];
        $links[] = [
            'key' => 'print',
            'label' => __('Print', 'traydigita-core'),
            'url' => null,
            'action' => 'print',
        ];
        return $links;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{facebook: bool, x: bool, whatsapp: bool, telegram: bool, email: bool,
     *     position: string, show_on_pages: bool, heading: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $position = isset($settings['position']) && is_string($settings['position']) ? $settings['position'] : '';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'below';
        }
        $heading = isset($settings['heading']) && is_string($settings['heading']) ? $settings['heading'] : '';
        if ($heading === 'Bagikan berita ini') {
            // Legacy stored value (pre-i18n default): migrate to the English default.
            $heading = self::DEFAULT_HEADING;
        }
        if ($heading === '') {
            $heading = self::DEFAULT_HEADING;
        }
        return [
            'facebook' => (bool) ($settings['facebook'] ?? false),
            'x' => (bool) ($settings['x'] ?? false),
            'whatsapp' => (bool) ($settings['whatsapp'] ?? false),
            'telegram' => (bool) ($settings['telegram'] ?? false),
            'email' => (bool) ($settings['email'] ?? false),
            'position' => $position,
            'show_on_pages' => (bool) ($settings['show_on_pages'] ?? false),
            'heading' => $heading,
        ];
    }

    /**
     * Default settings.
     *
     * Matches the padangkita.com reference: Facebook, X,
     * WhatsApp, and Email on; Telegram off; below the content;
     * posts only; heading "Share this news".
     *
     * @return array{facebook: bool, x: bool, whatsapp: bool, telegram: bool, email: bool,
     *     position: string, show_on_pages: bool, heading: string}
     */
    public static function getDefaults(): array
    {
        return [
            'facebook' => true,
            'x' => true,
            'whatsapp' => true,
            'telegram' => false,
            'email' => true,
            'position' => 'below',
            'show_on_pages' => false,
            'heading' => self::DEFAULT_HEADING,
        ];
    }
}
