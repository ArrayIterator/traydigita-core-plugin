<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Comments\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentProviderInterface;
use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentsModule;
use function __;
use function add_filter;
use function esc_attr;
use function esc_attr_e;
use function esc_html_e;
use function esc_url;
use function get_locale;
use function get_permalink;
use function get_post;
use function is_singular;
use function is_string;
use function sprintf;
use function str_contains;
use function str_replace;
use function wp_enqueue_script;

/**
 * Facebook Comments Plugin provider.
 *
 * Renders the Facebook Comments Plugin (`<div class="fb-comments">`)
 * for the post permalink and enqueues the Facebook SDK once, async.
 * Comments are stored and moderated by Facebook, in Facebook's own
 * tools — never in this site's database. The SDK loads only after
 * the visitor accepted cookies (see
 * CommentsModule::hasConsentForFacebook()); without an App ID the
 * provider renders nothing at all.
 *
 * The colourscheme is always light: the theme's dark mode lives in
 * localStorage, which the server cannot read, so the server cannot
 * know the visitor's current mode when the widget is parsed.
 */
final class FacebookProvider implements CommentProviderInterface
{
    /**
     * Script handle for the Facebook SDK.
     */
    public const string SCRIPT_HANDLE = 'traydigita-facebook-sdk';

    /**
     * Pinned Facebook SDK version.
     */
    public const string SDK_VERSION = 'v21.0';

    /**
     * Whether the async/defer tag filter is already registered.
     */
    private static bool $tagFilterRegistered = false;

    /**
     * Provider id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'facebook';
    }

    /**
     * Provider label.
     *
     * @return string
     */
    public static function label(): string
    {
        return __('Facebook', 'traydigita-core');
    }

    /**
     * Provider description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Facebook Comments Plugin. Comments are stored and moderated by Facebook, in Facebook’s own tools — never in this site’s database. Needs a Facebook App ID; the widget only loads after the visitor accepts cookies when Cookie Consent is active.', 'traydigita-core');
    }

    /**
     * Provider-specific settings fields.
     *
     * @return array<string, array{type: string, label: string, help?: string, default?: mixed, options?: array<string, string>, min?: int, max?: int, placeholder?: string, sanitize?: string}>
     */
    public static function getSettings(): array
    {
        return [
            'facebook_app_id' => [
                'type' => 'password',
                'label' => __('Facebook App ID', 'traydigita-core'),
                'help' => __('The App ID of your Facebook app. It is stored like a secret and never printed. Leave empty to keep the saved App ID.', 'traydigita-core'),
                'default' => '',
                'placeholder' => __('Enter App ID', 'traydigita-core'),
            ],
            'facebook_numposts' => [
                'type' => 'number',
                'label' => __('Number of posts', 'traydigita-core'),
                'help' => __('How many comments the plugin shows at first.', 'traydigita-core'),
                'default' => 5,
                'min' => 1,
                'max' => 100,
            ],
            'facebook_order' => [
                'type' => 'select',
                'label' => __('Sort order', 'traydigita-core'),
                'help' => __('The order Facebook shows comments in.', 'traydigita-core'),
                'default' => 'social',
                'options' => [
                    'social' => __('Social', 'traydigita-core'),
                    'newest' => __('Newest', 'traydigita-core'),
                    'oldest' => __('Oldest', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Whether this provider can render.
     *
     * Needs a saved App ID and, when Cookie Consent is active,
     * the visitor's cookie consent.
     *
     * @param array<string, mixed> $settings Merged module settings.
     * @return bool
     */
    public static function isConfigured(array $settings): bool
    {
        $appId = isset($settings['facebook_app_id']) && is_string($settings['facebook_app_id'])
            ? $settings['facebook_app_id']
            : '';
        return $appId !== '' && CommentsModule::hasConsentForFacebook();
    }

    /**
     * Echo the Facebook comments widget for a post.
     *
     * Outputs nothing without an App ID or before cookie consent.
     *
     * @param \WP_Post $post Post to render comments for.
     * @return void
     */
    public static function render(\WP_Post $post): void
    {
        $settings = CommentsModule::getSettings();
        if (!self::isConfigured($settings)) {
            return;
        }
        $url = get_permalink($post);
        if ($url === false) {
            return;
        }
        $numposts = isset($settings['facebook_numposts']) ? (int) $settings['facebook_numposts'] : 5;
        if ($numposts < 1) {
            $numposts = 5;
        }
        $order = isset($settings['facebook_order']) && is_string($settings['facebook_order'])
            ? $settings['facebook_order']
            : 'social';
        $orderBy = match ($order) {
            'newest' => 'reverse_time',
            'oldest' => 'time',
            default => 'social',
        };
        ?>
        <section class="td-comments td-comments-facebook" aria-label="<?php esc_attr_e('Comments', 'traydigita-core'); ?>">
            <h2 class="td-comments-title"><?php esc_html_e('Comments', 'traydigita-core'); ?></h2>
            <div
                class="fb-comments"
                data-href="<?php echo esc_url($url); ?>"
                data-width="100%"
                data-numposts="<?php echo esc_attr((string) $numposts); ?>"
                data-order-by="<?php echo esc_attr($orderBy); ?>"
                data-colorscheme="light"
            ></div>
        </section>
        <?php
    }

    /**
     * Enqueue the Facebook SDK, async.
     *
     * Only on singular views where the comment area will render
     * and the provider is configured. The async/defer attributes
     * are added through the `script_loader_tag` filter, registered
     * once.
     *
     * @return void
     */
    public static function enqueue(): void
    {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post || !CommentsModule::isVisibleForPost($post)) {
            return;
        }
        if (!self::isConfigured(CommentsModule::getSettings())) {
            return;
        }
        $appId = CommentsModule::getSettings()['facebook_app_id'];
        $locale = get_locale();
        $src = sprintf(
            'https://connect.facebook.net/%s/sdk.js#xfbml=1&version=%s&appId=%s',
            $locale,
            self::SDK_VERSION,
            $appId
        );
        if (!self::$tagFilterRegistered) {
            self::$tagFilterRegistered = true;
            add_filter('script_loader_tag', [self::class, 'filterScriptTag'], 10, 3);
        }
        wp_enqueue_script(self::SCRIPT_HANDLE, $src, [], null, true);
    }

    /**
     * Add async + defer to the Facebook SDK script tag.
     *
     * Other scripts pass through untouched.
     *
     * @param string $tag Script tag HTML.
     * @param string $handle Script handle.
     * @param string $src Script source URL.
     * @return string
     */
    public static function filterScriptTag(string $tag, string $handle, string $src): string
    {
        if ($handle !== self::SCRIPT_HANDLE) {
            return $tag;
        }
        if (str_contains($tag, ' async')) {
            return $tag;
        }
        return str_replace('<script ', '<script async defer ', $tag);
    }
}
