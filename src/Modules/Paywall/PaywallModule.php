<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Paywall;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function absint;
use function add_action;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function esc_url_raw;
use function get_option;
use function get_post;
use function get_the_ID;
use function is_admin;
use function is_feed;
use function is_singular;
use function max;
use function min;
use function sanitize_textarea_field;
use function trim;
use function update_option;
use function wp_nonce_field;

/**
 * Metered Paywall module.
 *
 * A soft, honest metered paywall: each visitor may read a
 * configured number of free articles per period (default 3 per
 * 30 days); after that, a subscribe prompt covers the article.
 * There is no payment processing here — the prompt links to the
 * site's own subscription page (configured URL), and the site
 * owner wires that page to whatever payment provider they use.
 * Nothing is faked: no fake prices, no fake checkout.
 *
 * How the meter works: the frontend JavaScript
 * (`resources/js/modules/paywall.ts`, `initPaywall()`) keeps the
 * list of article ids read in the current period in localStorage
 * (`traydigita_paywall_v1`). When the distinct count passes the
 * free limit, the script shows the overlay. The article itself
 * is always rendered in full by the server — the overlay is a
 * client-side layer only — so search engines, feeds and readers
 * without JavaScript always see the complete article, and the
 * meter can never break the site for crawlers.
 *
 * Who is never metered: anyone who can edit posts (admins and
 * editors previewing their own work), admin screens, feeds, and
 * every view that is not a single post. The configuration is
 * printed from `wp_footer` as data attributes on one hidden
 * element, only on single posts, only when the module is
 * enabled — there is no extra query and no network request.
 *
 * Settings live in the single option `traydigita_paywall`
 * (autoload `no`) and are saved through the phase-2 admin AJAX
 * dispatcher, which verifies the nonce and the `manage_options`
 * capability; saveSettings() still checks the capability itself
 * and re-sanitizes every field for its own type.
 */
final class PaywallModule implements ModuleInterface
{
    /**
     * Option name for all paywall settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_paywall';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_paywall_save';

    /**
     * Default number of free articles per period.
     */
    public const int DEFAULT_FREE_ARTICLES = 3;

    /**
     * Minimum free articles an administrator may set.
     */
    public const int MIN_FREE_ARTICLES = 1;

    /**
     * Maximum free articles an administrator may set.
     */
    public const int MAX_FREE_ARTICLES = 20;

    /**
     * Default meter period in days.
     */
    public const int DEFAULT_PERIOD_DAYS = 30;

    /**
     * Minimum meter period in days.
     */
    public const int MIN_PERIOD_DAYS = 1;

    /**
     * Maximum meter period in days.
     */
    public const int MAX_PERIOD_DAYS = 90;

    /**
     * Default meter message shown in the subscribe prompt.
     *
     * Stored as an empty string (see getDefaults()): the
     * translated default is applied by getSettings(), so the
     * string below lives in a __() call and is extracted for
     * translation like every other user-facing string.
     */
    public const string DEFAULT_MESSAGE = '';

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
        return 'paywall';
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
        return __('Metered Paywall', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('A soft metered paywall: a set number of free articles per period, then a subscribe prompt linking to your own subscription page. No payment processing, no fake checkout — the article is always fully rendered for search engines.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
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
            __('Soft metered paywall: free articles per period, then a subscribe prompt.', 'traydigita-core'),
            __('Links to your own subscription page.', 'traydigita-core'),
            __('No payment processing, no fake checkout.', 'traydigita-core'),
            __('Articles are always fully rendered for search engines.', 'traydigita-core'),
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
        return 'traydigita-paywall';
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
     * The meter works with the defaults alone; the subscribe URL
     * is optional (without it the prompt shows the message with
     * no button). A module that needs no setup is always
     * configured, so this is always true.
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
     * One form: free articles per period, period length in days,
     * subscribe URL, and the prompt message. The form is submitted
     * by the generic admin JavaScript via the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings paywall-settings">
            <?php
            SettingsControls::intro(
                __('A soft metered paywall. Each visitor reads a set number of free articles per period; after that a '
                    . 'subscribe prompt covers the article and links to your subscription page.', 'traydigita-core'),
                __('This module takes no payments itself — connect the subscribe link to your own subscription or payment page. Search engines, feeds and anyone who can edit posts are never metered, and the full article is always rendered for them.', 'traydigita-core')
            );
            ?>

            <form class="paywall-settings-form" data-traydigita-module-form data-traydigita-module="paywall" data-module="paywall" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="paywall-field">
                    <label for="paywall-free-articles"><?php esc_html_e('Free articles per period', 'traydigita-core'); ?></label>
                    <input type="number" id="paywall-free-articles" name="free_articles" value="<?php echo esc_attr((string) $settings['free_articles']); ?>" min="1" max="20" step="1">
                    <span class="paywall-field-help">
                        <?php esc_html_e('How many different articles a visitor may read for free before the subscribe prompt appears. 1 to 20.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="paywall-field">
                    <label for="paywall-period-days"><?php esc_html_e('Period length (days)', 'traydigita-core'); ?></label>
                    <input type="number" id="paywall-period-days" name="period_days" value="<?php echo esc_attr((string) $settings['period_days']); ?>" min="1" max="90" step="1">
                    <span class="paywall-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('How long the free-article count lasts.', 'traydigita-core'),
                            __('After this many days the count resets and the visitor gets free articles again.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="paywall-field">
                    <label for="paywall-subscribe-url"><?php esc_html_e('Subscribe page URL', 'traydigita-core'); ?></label>
                    <input type="url" id="paywall-subscribe-url" name="subscribe_url" value="<?php echo esc_attr($settings['subscribe_url']); ?>" placeholder="https://example.com/subscribe" inputmode="url" autocomplete="off">
                    <span class="paywall-field-help">
                        <?php esc_html_e('Your own subscription or payment page. The prompt links here. Leave empty to show the message with no button.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="paywall-field">
                    <label for="paywall-message"><?php esc_html_e('Prompt message', 'traydigita-core'); ?></label>
                    <textarea id="paywall-message" name="message" rows="3"><?php echo esc_html($settings['message']); ?></textarea>
                    <span class="paywall-field-help">
                        <?php esc_html_e('Shown in the subscribe prompt when the free articles run out.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="paywall-form-actions">
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
     * Expected flat keys: `free_articles` (integer clamped to
     * 1–20), `period_days` (integer clamped to 1–90),
     * `subscribe_url` (URL or an empty string), and `message`
     * (plain text, empty falls back to the default message).
     * Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Metered Paywall settings.', 'traydigita-core'),
            ];
        }
        $freeArticles = isset($data['free_articles']) ? absint($data['free_articles']) : self::DEFAULT_FREE_ARTICLES;
        $freeArticles = (int) min(self::MAX_FREE_ARTICLES, max(self::MIN_FREE_ARTICLES, $freeArticles));
        $periodDays = isset($data['period_days']) ? absint($data['period_days']) : self::DEFAULT_PERIOD_DAYS;
        $periodDays = (int) min(self::MAX_PERIOD_DAYS, max(self::MIN_PERIOD_DAYS, $periodDays));
        $subscribeUrl = isset($data['subscribe_url']) && is_string($data['subscribe_url'])
            ? esc_url_raw(trim($data['subscribe_url']))
            : '';
        $message = isset($data['message']) && is_string($data['message'])
            ? trim(sanitize_textarea_field($data['message']))
            : '';
        // Empty means "use the translated default message":
        // storing the empty string keeps the default translatable
        // instead of freezing one language into the option.
        update_option(self::OPTION, [
            'free_articles' => $freeArticles,
            'period_days' => $periodDays,
            'subscribe_url' => $subscribeUrl,
            'message' => $message,
        ], false);
        return [
            'success' => true,
            'message' => __('Metered Paywall settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Hooks the meter configuration render into `wp_footer`
     * (priority 20). A disabled module registers nothing.
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
        add_action('wp_footer', [self::class, 'renderMeter'], 20);
    }

    /**
     * Print the meter configuration from `wp_footer`.
     *
     * Only on single posts, never in admin screens or feeds, and
     * never for users who can edit posts. The markup comes from
     * render(), so a coordinator that prefers manual placement
     * can call render() instead — but never both, or two meter
     * elements render and the count is recorded twice.
     *
     * @return void
     */
    public static function renderMeter(): void
    {
        if (is_admin() || is_feed() || !is_singular('post')) {
            return;
        }
        if (current_user_can('edit_posts')) {
            return;
        }
        $html = self::render();
        if ($html !== '') {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns fully escaped markup.
        }
    }

    /**
     * Render the meter configuration element for the current post.
     *
     * Returns an empty string when the view is not a single post
     * or the post does not exist. The element carries the free
     * limit, the period, the current post id, the subscribe URL
     * and the prompt message as data attributes; the JavaScript
     * reads them, counts this article in localStorage, and shows
     * the overlay only when the free articles are used up. The
     * overlay itself is built by the script from these values —
     * no prompt markup is printed for readers still inside
     * their free allowance.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string Meter element markup, or '' when not metered.
     */
    public static function render(?int $postId = null): string
    {
        $settings = self::getSettings();
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return '';
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'post') {
            return '';
        }
        $subscribeLabel = $settings['subscribe_url'] !== ''
            ? __('Subscribe', 'traydigita-core')
            : '';
        // The count line is a format string, not a finished
        // sentence: the JavaScript knows how many free articles
        // this visitor has actually used and substitutes the two
        // numbers into the %1$d / %2$d placeholders itself.
        /* translators: %1$d is the number of free articles used, %2$d is the free article limit. */
        $countText = __('You have read %1$d of %2$d free articles.', 'traydigita-core');
        return '<div class="paywall-meter" data-paywall-meter hidden'
            . ' data-paywall-post-id="' . esc_attr((string) $postId) . '"'
            . ' data-paywall-free="' . esc_attr((string) $settings['free_articles']) . '"'
            . ' data-paywall-period-days="' . esc_attr((string) $settings['period_days']) . '"'
            . ' data-paywall-subscribe-url="' . esc_attr($settings['subscribe_url']) . '"'
            . ' data-paywall-subscribe-label="' . esc_attr($subscribeLabel) . '"'
            . ' data-paywall-message="' . esc_attr($settings['message']) . '"'
            . ' data-paywall-count-template="' . esc_attr($countText) . '"'
            . '></div>';
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{free_articles: int, period_days: int, subscribe_url: string, message: string}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $freeArticles = isset($settings['free_articles']) ? absint($settings['free_articles']) : self::DEFAULT_FREE_ARTICLES;
        $freeArticles = (int) min(self::MAX_FREE_ARTICLES, max(self::MIN_FREE_ARTICLES, $freeArticles));
        $periodDays = isset($settings['period_days']) ? absint($settings['period_days']) : self::DEFAULT_PERIOD_DAYS;
        $periodDays = (int) min(self::MAX_PERIOD_DAYS, max(self::MIN_PERIOD_DAYS, $periodDays));
        $subscribeUrl = isset($settings['subscribe_url']) && is_string($settings['subscribe_url'])
            ? esc_url_raw(trim($settings['subscribe_url']))
            : '';
        $message = isset($settings['message']) && is_string($settings['message'])
            ? trim(sanitize_textarea_field($settings['message']))
            : '';
        if ($message === '') {
            // No custom message stored: the translated default.
            $message = __('You have read your free articles for this period. Subscribe to keep reading.', 'traydigita-core');
        }
        return [
            'free_articles' => $freeArticles,
            'period_days' => $periodDays,
            'subscribe_url' => $subscribeUrl,
            'message' => $message,
        ];
    }

    /**
     * Default settings.
     *
     * 3 free articles per 30 days, no subscribe URL yet (the
     * prompt shows the message with no button until the owner
     * sets one), default message.
     *
     * @return array{free_articles: int, period_days: int, subscribe_url: string, message: string}
     */
    public static function getDefaults(): array
    {
        return [
            'free_articles' => self::DEFAULT_FREE_ARTICLES,
            'period_days' => self::DEFAULT_PERIOD_DAYS,
            'subscribe_url' => '',
            'message' => self::DEFAULT_MESSAGE,
        ];
    }
}
