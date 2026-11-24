<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ReadingProgress;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function _n;
use function absint;
use function add_action;
use function ceil;
use function checked;
use function count;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function get_option;
use function get_post;
use function get_post_field;
use function get_the_ID;
use function is_array;
use function is_int;
use function is_singular;
use function is_string;
use function max;
use function preg_split;
use function sanitize_hex_color;
use function sprintf;
use function trim;
use function update_option;
use function wp_nonce_field;
use function wp_strip_all_tags;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Reading Progress module.
 *
 * A thin progress bar fixed at the top of single articles, filled
 * in proportion to how far the reader has scrolled through the
 * article content element (`.entry-content`), plus an estimated
 * reading time ("3 minutes read") shown near the headline.
 *
 * Reading time is computed server-side from the post word count
 * at the configured words-per-minute rate (default 200), rounded
 * up, with a minimum of one minute — so even an empty post reads
 * as one minute. The bar itself is pure progressive enhancement:
 * with JavaScript disabled the bar simply stays at zero width
 * and the reading time still renders.
 *
 * Bar colour: when the `bar_color` setting is empty (the
 * default) the bar uses the theme accent variable `var(--brand)`,
 * which follows the Customizer `brand_primary_color` setting
 * (`--traydigita-color-primary`, default #166534) and is
 * lightened automatically in dark mode. A stored hex colour
 * overrides it through the `--reading-progress-color` custom
 * property set inline on the bar element.
 *
 * Placement (coordinator wiring — this module edits no entry
 * template itself):
 * - Bar: `boot()` hooks `wp_footer` (priority 20) and prints
 *   `render()` there, gated on `is_singular('post')`. No
 *   template edit is needed for the bar. The partial
 *   `templates/sections/reading-progress.php` calls the same
 *   `render()` for manual placement — use one or the other,
 *   never both, or two bars render.
 * - Reading time: call `ReadingProgressModule::renderReadingTime()`
 *   in the entry-header meta area (for example inside
 *   `templates/sections/entry-header.php`, next to the date /
 *   author meta), or from a `ThemeHooks` `entry_header` callback.
 *   There is no WordPress hook that reaches the entry-header
 *   meta area, so this one needs the explicit template call.
 *
 * Settings live in the single option `traydigita_reading_progress`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 *
 * The frontend JavaScript (`resources/js/modules/reading-progress.ts`,
 * `initReadingProgress()`) and CSS
 * (`resources/css/components/reading-progress.css`) are bundled
 * into the theme's app entry by the coordinator; this module
 * registers no separate assets.
 */
final class ReadingProgressModule implements ModuleInterface
{
    /**
     * Option name for all reading-progress settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_reading_progress';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_reading_progress_save';

    /**
     * Default words-per-minute rate for the reading-time estimate.
     */
    public const int DEFAULT_WORDS_PER_MINUTE = 200;

    /**
     * Minimum words-per-minute rate an administrator may set.
     */
    public const int MIN_WORDS_PER_MINUTE = 50;

    /**
     * Maximum words-per-minute rate an administrator may set.
     */
    public const int MAX_WORDS_PER_MINUTE = 1000;

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
        return 'reading-progress';
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
        return __('Reading Progress', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A thin progress bar at the top of single articles showing how far the reader has scrolled through the article, plus an estimated reading time near the headline.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'chart-line';
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
            __('Thin progress bar at the top of single articles.', 'traydigita-core'),
            __('Estimated reading time near the headline.', 'traydigita-core'),
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
        return 'traydigita-reading-progress';
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
     * No setup is needed: the defaults produce a working bar and
     * reading time, so this is always true.
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
     * One form: show-bar toggle, show-reading-time toggle, bar
     * colour (empty = theme accent), and the words-per-minute
     * rate. The form is submitted by the generic admin JavaScript
     * via the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings reading-progress-settings">
            <?php
            SettingsControls::intro(
                __('A thin bar fixed at the top of single articles fills in as the reader scrolls through the article '
                    . 'content, and an estimated reading time is shown near the headline.', 'traydigita-core'),
                __('The bar is printed automatically from wp_footer once the module is enabled; the reading time is placed by the theme in the entry-header meta area (see the module documentation for the exact call).', 'traydigita-core')
            );
            ?>

            <form class="reading-progress-settings-form" data-traydigita-module-form data-traydigita-module="reading-progress" data-module="reading-progress" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="reading-progress-field">
                    <label class="traydigita-ui-toggle" for="reading-progress-show-bar"><input class="traydigita-ui-toggle-input" type="checkbox" id="reading-progress-show-bar" name="show_bar" value="1" <?php checked($settings['show_bar']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the progress bar', 'traydigita-core'); ?></span></label>
                    <span class="reading-progress-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Thin bar fixed at the top of single articles.', 'traydigita-core'),
                            __('It measures scroll progress through the article content element, not the whole page.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="reading-progress-field">
                    <label class="traydigita-ui-toggle" for="reading-progress-show-reading-time"><input class="traydigita-ui-toggle-input" type="checkbox" id="reading-progress-show-reading-time" name="show_reading_time" value="1" <?php checked($settings['show_reading_time']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the estimated reading time', 'traydigita-core'); ?></span></label>
                    <span class="reading-progress-field-help">
                        <?php esc_html_e('Shown near the headline in the entry-header meta area. Computed server-side from the post word count.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="reading-progress-field">
                    <label for="reading-progress-bar-color"><?php esc_html_e('Bar colour', 'traydigita-core'); ?></label>
                    <input type="text" id="reading-progress-bar-color" name="bar_color" value="<?php echo esc_attr($settings['bar_color']); ?>" placeholder="#166534" inputmode="text" autocomplete="off">
                    <span class="reading-progress-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Hex colour for the bar, for example #166534.', 'traydigita-core'),
                            __('Leave empty to use the theme accent colour (the CSS variable var(--brand), which follows the Customizer brand colour and is lightened in dark mode).', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="reading-progress-field">
                    <label for="reading-progress-words-per-minute"><?php esc_html_e('Words per minute', 'traydigita-core'); ?></label>
                    <input type="number" id="reading-progress-words-per-minute" name="words_per_minute" value="<?php echo esc_attr((string) $settings['words_per_minute']); ?>" min="50" max="1000" step="1">
                    <span class="reading-progress-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Reading speed used for the estimate.', 'traydigita-core'),
                            __('The time is the word count divided by this rate, rounded up, with a minimum of one minute.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys: `show_bar` and `show_reading_time`
     * (checkboxes: `1` when checked, absent when unchecked),
     * `bar_color` (hex colour or an empty string for the theme
     * accent — anything else is discarded to empty), and
     * `words_per_minute` (integer clamped to 50–1000). Unknown
     * keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Reading Progress settings.', 'traydigita-core'),
            ];
        }
        $barColor = '';
        if (isset($data['bar_color']) && is_string($data['bar_color']) && trim($data['bar_color']) !== '') {
            $sanitized = sanitize_hex_color(trim($data['bar_color']));
            $barColor = is_string($sanitized) ? $sanitized : '';
        }
        $wordsPerMinute = isset($data['words_per_minute']) ? absint($data['words_per_minute']) : self::DEFAULT_WORDS_PER_MINUTE;
        if ($wordsPerMinute < self::MIN_WORDS_PER_MINUTE) {
            $wordsPerMinute = self::MIN_WORDS_PER_MINUTE;
        } elseif ($wordsPerMinute > self::MAX_WORDS_PER_MINUTE) {
            $wordsPerMinute = self::MAX_WORDS_PER_MINUTE;
        }
        update_option(self::OPTION, [
            'show_bar' => !empty($data['show_bar']),
            'show_reading_time' => !empty($data['show_reading_time']),
            'bar_color' => $barColor,
            'words_per_minute' => $wordsPerMinute,
        ], false);
        return [
            'success' => true,
            'message' => __('Reading Progress settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Hooks the bar render into `wp_footer` (priority 20), gated
     * on single posts inside renderBar(). The reading time has no
     * suitable WordPress hook — it is placed by an explicit
     * `renderReadingTime()` call in the entry-header meta area
     * (see the class docblock). A disabled module registers
     * nothing; the isEnabled() guard is belt and braces for a
     * direct call.
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
        add_action('wp_footer', [self::class, 'renderBar'], 20);
    }

    /**
     * Print the progress bar from `wp_footer`.
     *
     * Only on single posts and only when the bar toggle is on.
     * The markup comes from render(), so a coordinator that
     * prefers manual placement can call render() (or the
     * `templates/sections/reading-progress.php` partial) instead
     * — but never both, or two bars render.
     *
     * @return void
     */
    public static function renderBar(): void
    {
        if (!is_singular('post')) {
            return;
        }
        $html = self::render();
        if ($html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns fully escaped markup.
        }
    }

    /**
     * Render the progress bar markup for a post.
     *
     * Returns an empty string when the bar toggle is off, the
     * view is not a single post, or the post does not exist. The
     * bar starts at zero width; the frontend JavaScript fills it
     * in as the reader scrolls. A stored bar colour is applied
     * through the `--reading-progress-color` custom property;
     * with no stored colour the CSS falls back to
     * `var(--brand)`, the theme accent.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Bar markup, or '' when the bar is not shown.
     */
    public static function render(?int $postId = null): string
    {
        $settings = self::getSettings();
        if (!$settings['show_bar']) {
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
        $style = '';
        if ($settings['bar_color'] !== '') {
            $style = ' style="--reading-progress-color: ' . esc_attr($settings['bar_color']) . ';"';
        }
        return '<div class="reading-progress" data-reading-progress data-reading-progress-target=".entry-content"'
            . ' role="progressbar" aria-label="' . esc_attr__('Reading progress', 'traydigita-core') . '"'
            . ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"' . $style . '></div>';
    }

    /**
     * Render the estimated reading time for a post.
     *
     * Returns an empty string when the reading-time toggle is off
     * or the post does not exist. Place the returned span in the
     * entry-header meta area, next to the date / author meta.
     * The text is "1 minute read" / "N minutes read" (translated
     * to "N menit baca" in the Indonesian translation).
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Reading-time span markup, or '' when it is not shown.
     */
    public static function renderReadingTime(?int $postId = null): string
    {
        $settings = self::getSettings();
        if (!$settings['show_reading_time']) {
            return '';
        }
        $minutes = self::getReadingTime($postId);
        if ($minutes === null) {
            return '';
        }
        $text = sprintf(
            /* translators: %d is the estimated reading time in minutes. */
            _n('%d minute read', '%d minutes read', $minutes, 'traydigita-core'),
            $minutes
        );
        return '<span class="reading-progress-time" data-reading-time>' . esc_html($text) . '</span>';
    }

    /**
     * Estimated reading time in minutes for a post.
     *
     * Word count of the raw post content divided by the configured
     * words-per-minute rate, rounded up, minimum one minute.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return int|null Minutes (at least 1), or null when the post does not exist.
     */
    public static function getReadingTime(?int $postId = null): ?int
    {
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return null;
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'post') {
            return null;
        }
        $content = get_post_field('post_content', $postId);
        if (!is_string($content)) {
            return null;
        }
        $settings = self::getSettings();
        return self::calculateReadingTime(self::countWords($content), $settings['words_per_minute']);
    }

    /**
     * Reading time in minutes for a word count at a given rate.
     *
     * Ceiling division, minimum one minute: 0 words is still one
     * minute, 200 words at 200 wpm is one minute, 450 words at
     * 200 wpm is three minutes.
     *
     * @param int $wordCount Number of words.
     * @param int $wordsPerMinute Reading speed in words per minute.
     * @return int Minutes, at least 1.
     */
    public static function calculateReadingTime(int $wordCount, int $wordsPerMinute): int
    {
        if ($wordsPerMinute < 1) {
            $wordsPerMinute = 1;
        }
        if ($wordCount < 0) {
            $wordCount = 0;
        }
        return (int) max(1, (int) ceil($wordCount / $wordsPerMinute));
    }

    /**
     * Count the words in post content HTML.
     *
     * Tags, shortcodes brackets excluded: all markup is stripped
     * first, then the remaining text is split on whitespace. HTML
     * entities are left as-is — they contain no whitespace, so
     * they cannot inflate the count.
     *
     * @param string $content Post content HTML.
     * @return int Word count, 0 for empty content.
     */
    public static function countWords(string $content): int
    {
        $text = trim(wp_strip_all_tags($content));
        if ($text === '') {
            return 0;
        }
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return 0;
        }
        return count($words);
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_bar: bool, show_reading_time: bool, bar_color: string, words_per_minute: int}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $barColor = '';
        if (isset($settings['bar_color']) && is_string($settings['bar_color'])) {
            $sanitized = sanitize_hex_color(trim($settings['bar_color']));
            $barColor = is_string($sanitized) ? $sanitized : '';
        }
        $wordsPerMinute = isset($settings['words_per_minute']) ? absint($settings['words_per_minute']) : self::DEFAULT_WORDS_PER_MINUTE;
        if ($wordsPerMinute < self::MIN_WORDS_PER_MINUTE) {
            $wordsPerMinute = self::MIN_WORDS_PER_MINUTE;
        } elseif ($wordsPerMinute > self::MAX_WORDS_PER_MINUTE) {
            $wordsPerMinute = self::MAX_WORDS_PER_MINUTE;
        }
        return [
            'show_bar' => (bool) ($settings['show_bar'] ?? false),
            'show_reading_time' => (bool) ($settings['show_reading_time'] ?? false),
            'bar_color' => $barColor,
            'words_per_minute' => $wordsPerMinute,
        ];
    }

    /**
     * Default settings.
     *
     * Bar and reading time both on, no custom bar colour (theme
     * accent via `var(--brand)`), 200 words per minute.
     *
     * @return array{show_bar: bool, show_reading_time: bool, bar_color: string, words_per_minute: int}
     */
    public static function getDefaults(): array
    {
        return [
            'show_bar' => true,
            'show_reading_time' => true,
            'bar_color' => '',
            'words_per_minute' => self::DEFAULT_WORDS_PER_MINUTE,
        ];
    }
}
