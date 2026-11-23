<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\PrintArticle;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function add_filter;
use function checked;
use function current_user_can;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function get_bloginfo;
use function get_option;
use function get_permalink;
use function get_post;
use function get_the_date;
use function get_the_ID;
use function get_the_title;
use function in_array;
use function is_admin;
use function is_array;
use function is_feed;
use function is_int;
use function is_main_query;
use function is_singular;
use function is_string;
use function in_the_loop;
use function selected;
use function update_option;
use function wp_nonce_field;

/**
 * Print Article module.
 *
 * A "Print this article" button on single posts plus a print
 * stylesheet: when the reader prints (or saves as PDF), the
 * header, footer, sidebars, navigation, share row, comments,
 * cookie banner, paywall overlay and other screen chrome are
 * hidden, the article expands to the full page width, and a
 * source line (site name, article title, URL, date) is printed
 * under the article. On screen the button looks like the
 * Read Aloud button and the source line is hidden.
 *
 * The button is inserted by a `the_content` filter (priority
 * 22, after the Read Aloud filter at 21), so it needs no
 * template edit and follows the same gating as those filters:
 * main query, inside the loop, singular post, never in feeds
 * or admin screens. The stylesheet is bundled into the
 * theme's app entry; this module registers no separate assets.
 * The print itself is the browser's `window.print()`, bound
 * by `resources/js/modules/print-article.ts` — no server
 * cost, no PDF generation, no network request.
 *
 * Settings live in the single option `traydigita_print_article`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 */
final class PrintArticleModule implements ModuleInterface
{
    /**
     * Option name for all print-article settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_print_article';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_print_article_save';

    /**
     * Allowed button positions.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['above', 'below'];

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
        return 'print-article';
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
        return __('Print Article', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A "Print this article" button on single posts and a print stylesheet that hides the header, footer, sidebars and other screen chrome, so the printed page holds the article and its source line only.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'printer';
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
            __('Print this article button on single posts.', 'traydigita-core'),
            __('Print stylesheet hides header, footer, sidebars, and screen chrome.', 'traydigita-core'),
            __('The printed page holds the article and its source line only.', 'traydigita-core'),
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
     * Author shown on the module card in the admin module list.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-print-article';
    }

    /**
     * Whether the module can run at all.
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
     * No setup is needed: the defaults produce a working button
     * and stylesheet, so this is always true.
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
     * @return bool Always false.
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * One form: show-button toggle, button position, hide-images
     * toggle, and source-line toggle. The form is submitted by
     * the generic admin JavaScript via the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings print-article-settings">
            <?php
            SettingsControls::intro(
                __('A print button on single posts, and a print stylesheet that hides the site chrome '
                    . 'when the article is printed or saved as PDF.', 'traydigita-core'),
                __('Printing is the browser’s own print dialog — there is no server-side PDF generation and no extra request. The source line under the article shows where the print came from.', 'traydigita-core')
            );
            ?>

            <form class="print-article-settings-form" data-traydigita-module-form data-traydigita-module="print-article" data-module="print-article" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="print-article-field">
                    <label class="traydigita-ui-toggle" for="print-article-show-button"><input class="traydigita-ui-toggle-input" type="checkbox" id="print-article-show-button" name="show_button" value="1" <?php checked($settings['show_button']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the print button', 'traydigita-core'); ?></span></label>
                    <span class="print-article-field-help">
                        <?php esc_html_e('Shows the button on single posts. Turn it off to keep the print stylesheet without the button.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="print-article-field">
                    <label for="print-article-position"><?php esc_html_e('Button position', 'traydigita-core'); ?></label>
                    <select id="print-article-position" name="position">
                        <option value="above" <?php selected($settings['position'], 'above'); ?>><?php esc_html_e('Above the article content', 'traydigita-core'); ?></option>
                        <option value="below" <?php selected($settings['position'], 'below'); ?>><?php esc_html_e('Below the article content', 'traydigita-core'); ?></option>
                    </select>
                    <span class="print-article-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Where the button sits relative to the article text.', 'traydigita-core'),
                            __('The source line always prints under the article, whatever the button position is.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="print-article-field">
                    <label class="traydigita-ui-toggle" for="print-article-hide-images"><input class="traydigita-ui-toggle-input" type="checkbox" id="print-article-hide-images" name="hide_images" value="1" <?php checked($settings['hide_images']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Hide images when printing', 'traydigita-core'); ?></span></label>
                    <span class="print-article-field-help">
                        <?php esc_html_e('Prints text only: article images are hidden on the printed page. Off by default.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="print-article-field">
                    <label class="traydigita-ui-toggle" for="print-article-show-source"><input class="traydigita-ui-toggle-input" type="checkbox" id="print-article-show-source" name="show_source" value="1" <?php checked($settings['show_source']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Print the source line', 'traydigita-core'); ?></span></label>
                    <span class="print-article-field-help">
                        <?php esc_html_e('A line under the article with the site name, article title, URL, and date. It only appears on the printed page, never on screen.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="print-article-form-actions">
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
     * Expected flat keys: `show_button`, `hide_images` and
     * `show_source` (checkboxes: `1` when checked, absent when
     * unchecked), `position` (`above` or `below` — anything
     * else falls back to `below`). Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Print Article settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position'])
            && in_array($data['position'], self::POSITIONS, true)
            ? $data['position']
            : 'below';
        update_option(self::OPTION, [
            'show_button' => !empty($data['show_button']),
            'position' => $position,
            'hide_images' => !empty($data['hide_images']),
            'show_source' => !empty($data['show_source']),
        ], false);
        return [
            'success' => true,
            'message' => __('Print Article settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Hooks the button + source line into `the_content`
     * (priority 22) and the hide-images body class into
     * `body_class`. A disabled module registers nothing.
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
        add_filter('the_content', [self::class, 'filterContent'], 22);
        add_filter('body_class', [self::class, 'filterBodyClass']);
    }

    /**
     * Insert the print button and the source line into the post content.
     *
     * Only for the main query, inside the loop, on a singular post
     * view, and never in feeds or admin screens. The button sits
     * above or below the content per the setting; the source line
     * always follows the content (it is print-only, so it is
     * invisible on screen either way).
     *
     * @param string $content Post content HTML.
     * @return string Content with the button and source line inserted, or unchanged.
     */
    public static function filterContent(string $content): string
    {
        if (is_admin() || is_feed() || !in_the_loop() || !is_main_query() || !is_singular('post')) {
            return $content;
        }
        $settings = self::getSettings();
        $button = self::renderButton();
        $source = self::renderSourceLine();
        if ($button === '' && $source === '') {
            return $content;
        }
        if ($settings['position'] === 'above') {
            return $button . $content . $source;
        }
        return $content . $button . $source;
    }

    /**
     * Add the hide-images body class on single posts.
     *
     * The print stylesheet hides article images only when this
     * class is present, so the setting needs no content rewrite.
     *
     * @param array<int, string> $classes Body classes.
     * @return array<int, string>
     */
    public static function filterBodyClass(array $classes): array
    {
        if (is_singular('post') && self::getSettings()['hide_images']) {
            $classes[] = 'print-article-no-images';
        }
        return $classes;
    }

    /**
     * Render the print button markup for the current post.
     *
     * Returns an empty string when the button toggle is off, the
     * view is not a single post, or the post does not exist.
     * The button carries no post id: the JavaScript simply calls
     * `window.print()` for the page it is on.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Button markup, or '' when the button is not shown.
     */
    public static function renderButton(?int $postId = null): string
    {
        $settings = self::getSettings();
        if (!$settings['show_button']) {
            return '';
        }
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return '';
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'post') {
            return '';
        }
        return '<div class="print-article" data-print-article>'
            . '<button type="button" class="print-article-button" data-print-article-button>'
            . '<span class="print-article-icon" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>'
            . '</span>'
            . '<span class="print-article-label">' . esc_html__('Print this article', 'traydigita-core') . '</span>'
            . '</button>'
            . '</div>';
    }

    /**
     * Render the print-only source line for the current post.
     *
     * Returns an empty string when the source toggle is off, the
     * view is not a single post, or the post does not exist. The
     * line is hidden on screen by CSS and only appears on the
     * printed page.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Source-line markup, or '' when it is not shown.
     */
    public static function renderSourceLine(?int $postId = null): string
    {
        $settings = self::getSettings();
        if (!$settings['show_source']) {
            return '';
        }
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return '';
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'post') {
            return '';
        }
        $title = get_the_title($postId);
        $url = get_permalink($postId);
        $date = get_the_date('', $postId);
        return '<p class="print-article-source" data-print-article-source>'
            . esc_html__('Source:', 'traydigita-core') . ' '
            . esc_html((string) get_bloginfo('name')) . ' — '
            . esc_html($title) . ' — '
            . esc_html((string) $url) . ' — '
            . esc_html((string) $date)
            . '</p>';
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_button: bool, position: string, hide_images: bool, show_source: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $position = isset($settings['position']) && is_string($settings['position']) && in_array($settings['position'], self::POSITIONS, true)
            ? $settings['position']
            : 'below';
        return [
            'show_button' => (bool) ($settings['show_button'] ?? false),
            'position' => $position,
            'hide_images' => (bool) ($settings['hide_images'] ?? false),
            'show_source' => (bool) ($settings['show_source'] ?? false),
        ];
    }

    /**
     * Default settings.
     *
     * Button on, below the content, images printed, source line on.
     *
     * @return array{show_button: bool, position: string, hide_images: bool, show_source: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'show_button' => true,
            'position' => 'below',
            'hide_images' => false,
            'show_source' => true,
        ];
    }
}
