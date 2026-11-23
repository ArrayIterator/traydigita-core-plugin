<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Comments\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentProviderInterface;
use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentsModule;
use function __;
use function comments_open;
use function comments_template;
use function get_option;
use function get_post;
use function is_singular;
use function wp_enqueue_script;

/**
 * Native WordPress comments provider.
 *
 * Renders the theme's root `comments.php` template (comment list
 * via wp_list_comments() and the comment form via comment_form())
 * and enqueues the `comment-reply` script for threaded replies.
 * Comment avatars come from get_avatar(), so the Custom Avatar
 * module's filter applies automatically — there is no second
 * avatar path here.
 */
final class WordpressProvider implements CommentProviderInterface
{
    /**
     * Provider id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'wordpress';
    }

    /**
     * Provider label.
     *
     * @return string
     */
    public static function label(): string
    {
        return __('WordPress', 'traydigita-core');
    }

    /**
     * Provider description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Native WordPress comments, stored in this site’s database. Threaded replies, moderation, and the comment form all work exactly as WordPress provides them.', 'traydigita-core');
    }

    /**
     * Provider-specific settings fields.
     *
     * None — native comments need no extra setup.
     *
     * @return array<string, array{type: string, label: string, help?: string, default?: mixed, options?: array<string, string>, min?: int, max?: int, placeholder?: string, sanitize?: string}>
     */
    public static function getSettings(): array
    {
        return [];
    }

    /**
     * Whether this provider can render.
     *
     * Native comments are always ready; the module's render gate
     * already decided visibility for the post.
     *
     * @param array<string, mixed> $settings Merged module settings.
     * @return bool Always true.
     */
    public static function isConfigured(array $settings): bool
    {
        return true;
    }

    /**
     * Echo the WordPress comment area for a post.
     *
     * Loads the theme's root `comments.php` template, which
     * renders the comment list, the comment navigation, and the
     * comment form.
     *
     * @param \WP_Post $post Post to render comments for.
     * @return void
     */
    public static function render(\WP_Post $post): void
    {
        comments_template();
    }

    /**
     * Enqueue the `comment-reply` script for threaded replies.
     *
     * Only on singular views where the comment area is visible
     * and comments are open, and only when threaded comments are
     * enabled in Settings > Discussion.
     *
     * @return void
     */
    public static function enqueue(): void
    {
        if (!is_singular() || !comments_open() || !get_option('thread_comments')) {
            return;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post || !CommentsModule::isVisibleForPost($post)) {
            return;
        }
        wp_enqueue_script('comment-reply');
    }
}
