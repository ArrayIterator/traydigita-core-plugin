<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Comments\Providers;

use TrayDigita\WP\Plugin\Core\Modules\Comments\CommentProviderInterface;
use function __;

/**
 * Totally-disabled comments provider.
 *
 * Renders nothing anywhere. When this provider is selected, the
 * module also filters `comments_open` and `pings_open` to false
 * (see CommentsModule::boot()), so WordPress itself stops
 * accepting comments and pingbacks too; switching back to another
 * provider restores normal behaviour. This is the "hide all
 * comments" switch.
 */
final class DisabledProvider implements CommentProviderInterface
{
    /**
     * Provider id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'disabled';
    }

    /**
     * Provider label.
     *
     * @return string
     */
    public static function label(): string
    {
        return __('Totally disabled', 'traydigita-core');
    }

    /**
     * Provider description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Hide the comment area everywhere and stop WordPress from accepting new comments and pingbacks. Switching back to another provider restores normal behaviour.', 'traydigita-core');
    }

    /**
     * Provider-specific settings fields.
     *
     * None - disabling needs no setup.
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
     * Never: the disabled provider renders nothing by design.
     *
     * @param array<string, mixed> $settings Merged module settings.
     * @return bool Always false.
     */
    public static function isConfigured(array $settings): bool
    {
        return false;
    }

    /**
     * Echo the comment area for a post.
     *
     * No-op: nothing renders while comments are totally disabled.
     *
     * @param \WP_Post $post Post to render comments for.
     * @return void
     */
    public static function render(\WP_Post $post): void
    {
    }

    /**
     * Enqueue whatever scripts this provider needs.
     *
     * None.
     *
     * @return void
     */
    public static function enqueue(): void
    {
    }
}
