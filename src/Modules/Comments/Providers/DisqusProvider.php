<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Comments\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentProviderInterface;
use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentsModule;
use function __;
use function esc_attr_e;
use function esc_html_e;
use function esc_js;
use function esc_url;
use function get_permalink;
use function is_string;
use function sprintf;

/**
 * Disqus comments provider.
 *
 * Renders the Disqus embed (`#disqus_thread` plus the Disqus
 * `disqus_config` and embed script) for the post permalink, with
 * the page identifier `traydigita-{post ID}`. Comments are stored
 * and moderated by Disqus, in Disqus's own tools - never in this
 * site's database. Without a shortname the provider renders
 * nothing at all. The embed script loads from the render output
 * itself (the official Disqus pattern), so enqueue() is a no-op.
 */
final class DisqusProvider implements CommentProviderInterface
{
    /**
     * Provider id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'disqus';
    }

    /**
     * Provider label.
     *
     * @return string
     */
    public static function label(): string
    {
        return __('Disqus', 'traydigita-core');
    }

    /**
     * Provider description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Disqus comments. Comments are stored and moderated by Disqus, in Disqus’s own tools — never in this site’s database. Needs a Disqus shortname from your Disqus site settings.', 'traydigita-core');
    }

    /**
     * Provider-specific settings fields.
     *
     * @return array<string, array{type: string, label: string, help?: string, default?: mixed, options?: array<string, string>, min?: int, max?: int, placeholder?: string, sanitize?: string}>
     */
    public static function getSettings(): array
    {
        return [
            'disqus_shortname' => [
                'type' => 'text',
                'label' => __('Disqus shortname', 'traydigita-core'),
                'help' => __('The shortname of your Disqus site (from Disqus Admin > Settings). Comments load from {shortname}.disqus.com.', 'traydigita-core'),
                'default' => '',
                'placeholder' => __('Enter shortname', 'traydigita-core'),
                'sanitize' => 'key',
            ],
        ];
    }

    /**
     * Whether this provider can render.
     *
     * Needs a saved shortname.
     *
     * @param array<string, mixed> $settings Merged module settings.
     * @return bool
     */
    public static function isConfigured(array $settings): bool
    {
        $shortname = isset($settings['disqus_shortname']) && is_string($settings['disqus_shortname'])
            ? $settings['disqus_shortname']
            : '';
        return $shortname !== '';
    }

    /**
     * Echo the Disqus thread for a post.
     *
     * Outputs nothing without a shortname.
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
        $shortname = $settings['disqus_shortname'];
        $url = get_permalink($post);
        if ($url === false) {
            return;
        }
        $identifier = 'traydigita-' . $post->ID;
        $embedUrl = sprintf('https://%s.disqus.com/embed.js', $shortname);
        ?>
        <section class="td-comments td-comments-disqus" aria-label="<?php esc_attr_e('Comments', 'traydigita-core'); ?>">
            <h2 class="td-comments-title"><?php esc_html_e('Comments', 'traydigita-core'); ?></h2>
            <div id="disqus_thread"></div>
            <script>
                var disqus_config = function () {
                    this.page.url = '<?php echo esc_js($url); ?>';
                    this.page.identifier = '<?php echo esc_js($identifier); ?>';
                };
                (function () {
                    var d = document, s = d.createElement('script');
                    s.src = '<?php echo esc_js(esc_url($embedUrl)); ?>';
                    s.setAttribute('data-timestamp', +new Date());
                    (d.head || d.body).appendChild(s);
                })();
            </script>
            <noscript><?php esc_html_e('Please enable JavaScript to view the comments powered by Disqus.', 'traydigita-core'); ?></noscript>
        </section>
        <?php
    }

    /**
     * Enqueue whatever scripts this provider needs.
     *
     * None: the Disqus embed script is loaded by the render
     * output itself (the official Disqus pattern).
     *
     * @return void
     */
    public static function enqueue(): void
    {
    }
}
