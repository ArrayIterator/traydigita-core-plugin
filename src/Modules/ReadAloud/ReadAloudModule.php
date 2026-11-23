<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ReadAloud;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function add_filter;
use function checked;
use function current_user_can;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function get_bloginfo;
use function get_option;
use function get_post;
use function get_the_ID;
use function in_array;
use function is_admin;
use function is_feed;
use function is_main_query;
use function is_singular;
use function in_the_loop;
use function update_option;
use function wp_nonce_field;

/**
 * Read Aloud module.
 *
 * A "Listen to this article" button on single posts, powered by
 * the browser's built-in SpeechSynthesis API — zero server cost,
 * no audio files, no network request, no account. The button is
 * inserted by a `the_content` filter (priority 21, after the
 * Table of Contents filter at 12 and the Share filter at 20), so
 * it needs no template edit and follows the same gating as those
 * filters: main query, inside the loop, singular post, never in
 * feeds or admin screens.
 *
 * The frontend JavaScript (`resources/js/modules/read-aloud.ts`,
 * `initReadAloud()`) reads the article text from the
 * `.entry-content` element, splits it into sentences and speaks
 * them as a queue of short utterances — one long utterance is
 * unreliable in Chrome (it stalls or stops mid-article), so
 * chunking is what makes long articles actually finish. The
 * button toggles Listen / Stop, shows a Listening state while
 * speaking, and hides itself entirely when the browser has no
 * SpeechSynthesis support, so unsupported browsers see nothing
 * at all. Speech rate is the admin-configured rate (0.5–2.0,
 * default 1.0); the voice is picked by the browser from the
 * article language (the `lang` attribute on the button, taken
 * from the site locale), falling back to the browser default.
 *
 * Settings live in the single option `traydigita_read_aloud`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 */
final class ReadAloudModule implements ModuleInterface
{
    /**
     * Option name for all read-aloud settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_read_aloud';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_read_aloud_save';

    /**
     * Allowed button positions.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['above', 'below'];

    /**
     * Default speech rate.
     */
    public const float DEFAULT_RATE = 1.0;

    /**
     * Minimum speech rate an administrator may set.
     */
    public const float MIN_RATE = 0.5;

    /**
     * Maximum speech rate an administrator may set.
     */
    public const float MAX_RATE = 2.0;

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
        return 'read-aloud';
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
        return __('Read Aloud', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A "Listen to this article" button on single posts, read aloud by the browser\'s built-in speech synthesis — no audio files, no server cost, no network requests.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'controls-volumeon';
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
        return 'traydigita-read-aloud';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * Speech synthesis itself is a browser capability, checked in
     * JavaScript; a browser without it simply hides the button.
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
     * No setup is needed: the defaults produce a working button.
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
     * One form: show-button toggle, button position (above or
     * below the article content), and the speech rate. The form is
     * submitted by the generic admin JavaScript via the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings read-aloud-settings">
            <?php
            SettingsControls::intro(
                __('A "Listen to this article" button on single posts, read aloud by the browser\'s built-in speech synthesis.', 'traydigita-core'),
                __('There are no audio files to generate and no network requests: the visitor\'s own browser does the reading. Browsers without speech synthesis never see the button.', 'traydigita-core')
            );
            ?>

            <form class="read-aloud-settings-form" data-traydigita-module-form data-traydigita-module="read-aloud" data-module="read-aloud" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="read-aloud-field">
                    <label class="traydigita-ui-toggle" for="read-aloud-show-button"><input class="traydigita-ui-toggle-input" type="checkbox" id="read-aloud-show-button" name="show_button" value="1" <?php checked($settings['show_button']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show the listen button', 'traydigita-core'); ?></span></label>
                    <span class="read-aloud-field-help">
                        <?php esc_html_e('Shows the button on single posts. Turn it off to hide the button without disabling the whole module.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="read-aloud-field">
                    <label for="read-aloud-position"><?php esc_html_e('Button position', 'traydigita-core'); ?></label>
                    <select id="read-aloud-position" name="position">
                        <option value="above" <?php echo $settings['position'] === 'above' ? 'selected="selected"' : ''; ?>><?php esc_html_e('Above the article content', 'traydigita-core'); ?></option>
                        <option value="below" <?php echo $settings['position'] === 'below' ? 'selected="selected"' : ''; ?>><?php esc_html_e('Below the article content', 'traydigita-core'); ?></option>
                    </select>
                    <span class="read-aloud-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Where the button sits relative to the article text.', 'traydigita-core'),
                            __('Above the content matches where readers look first; below sits next to the share row.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="read-aloud-field">
                    <label for="read-aloud-rate"><?php esc_html_e('Speech rate', 'traydigita-core'); ?></label>
                    <input type="number" id="read-aloud-rate" name="rate" value="<?php echo esc_attr((string) $settings['rate']); ?>" min="0.5" max="2" step="0.1">
                    <span class="read-aloud-field-help">
                        <?php esc_html_e('How fast the article is read, from 0.5 (half speed) to 2 (double speed). 1 is normal speed.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="read-aloud-form-actions">
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
     * Expected flat keys: `show_button` (checkbox: `1` when
     * checked, absent when unchecked), `position` (`above` or
     * `below` — anything else falls back to `above`), and `rate`
     * (float clamped to 0.5–2.0). Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Read Aloud settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position']) && in_array($data['position'], self::POSITIONS, true)
            ? $data['position']
            : 'above';
        $rate = isset($data['rate']) && is_numeric($data['rate']) ? (float) $data['rate'] : self::DEFAULT_RATE;
        if ($rate < self::MIN_RATE) {
            $rate = self::MIN_RATE;
        } elseif ($rate > self::MAX_RATE) {
            $rate = self::MAX_RATE;
        }
        update_option(self::OPTION, [
            'show_button' => !empty($data['show_button']),
            'position' => $position,
            'rate' => $rate,
        ], false);
        return [
            'success' => true,
            'message' => __('Read Aloud settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Hooks the button render into `the_content` (priority 21).
     * A disabled module registers nothing.
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
        add_filter('the_content', [self::class, 'filterContent'], 21);
    }

    /**
     * Insert the listen button into the post content.
     *
     * Only for the main query, inside the loop, on a singular post
     * view, and only when the button toggle is on. Feeds and admin
     * screens never get a button. The button carries the speech
     * rate and the site language; the JavaScript hides it when the
     * browser has no SpeechSynthesis support.
     *
     * @param string $content Post content HTML.
     * @return string Content with the button inserted, or unchanged.
     */
    public static function filterContent(string $content): string
    {
        if (is_admin() || is_feed() || !in_the_loop() || !is_main_query() || !is_singular('post')) {
            return $content;
        }
        $button = self::render();
        if ($button === '') {
            return $content;
        }
        if (self::getSettings()['position'] === 'below') {
            return $content . $button;
        }
        return $button . $content;
    }

    /**
     * Render the listen button markup for the current post.
     *
     * Returns an empty string when the button toggle is off, the
     * view is not a single post, or the post does not exist. The
     * button is hidden by default (`hidden` attribute) and shown
     * by the JavaScript only when SpeechSynthesis is available,
     * so unsupported browsers never see a dead button.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Button markup, or '' when the button is not shown.
     */
    public static function render(?int $postId = null): string
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
        return '<div class="read-aloud" data-read-aloud>'
            . '<button type="button" class="read-aloud-button" data-read-aloud-button'
            . ' data-read-aloud-rate="' . esc_attr((string) $settings['rate']) . '"'
            . ' data-listening-text="' . esc_attr__('Listening…', 'traydigita-core') . '"'
            . ' lang="' . esc_attr(get_bloginfo('language')) . '"'
            . ' aria-pressed="false" hidden>'
            . '<span class="read-aloud-icon" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>'
            . '</span>'
            . '<span class="read-aloud-label">' . esc_html__('Listen to this article', 'traydigita-core') . '</span>'
            . '<span class="read-aloud-label-stop">' . esc_html__('Stop listening', 'traydigita-core') . '</span>'
            . '</button>'
            . '<span class="read-aloud-status" data-read-aloud-status role="status" hidden></span>'
            . '</div>';
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{show_button: bool, position: string, rate: float}
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
            : 'above';
        $rate = isset($settings['rate']) && is_numeric($settings['rate']) ? (float) $settings['rate'] : self::DEFAULT_RATE;
        if ($rate < self::MIN_RATE) {
            $rate = self::MIN_RATE;
        } elseif ($rate > self::MAX_RATE) {
            $rate = self::MAX_RATE;
        }
        return [
            'show_button' => (bool) ($settings['show_button'] ?? false),
            'position' => $position,
            'rate' => $rate,
        ];
    }

    /**
     * Default settings.
     *
     * Button on, above the content, normal speech rate.
     *
     * @return array{show_button: bool, position: string, rate: float}
     */
    public static function getDefaults(): array
    {
        return [
            'show_button' => true,
            'position' => 'above',
            'rate' => self::DEFAULT_RATE,
        ];
    }
}
