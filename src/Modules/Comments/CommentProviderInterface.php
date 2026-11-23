<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Comments;

/**
 * Contract for a comment provider.
 *
 * Providers are static-only classes, like modules: no constructor,
 * no instance state. The Comments module owns the single option
 * (`traydigita_comments`) and the render gate; a provider only
 * describes its own settings fields, decides whether it can
 * render, renders its markup, and enqueues whatever scripts it
 * needs. Built-in providers (WordPress, Facebook, Disqus, the
 * totally-disabled provider) register through the same registry
 * as third-party ones — nothing in the module special-cases a
 * built-in provider id.
 *
 * Registration: the module collects provider classes through the
 * `traydigita:core:comments/providers` filter (see
 * CommentsModule::providers()). A child theme or plugin adds a
 * provider by appending its class name to that list:
 *
 *     add_filter('traydigita:core:comments/providers', function (array $providers): array {
 *         $providers[] = MyCommentProvider::class;
 *         return $providers;
 *     });
 *
 * Settings values live in the `traydigita_comments` option and are
 * read through CommentsModule::getSettings() — providers must not
 * read the option directly, and must never print a stored secret.
 */
interface CommentProviderInterface
{
    /**
     * Stable machine id, e.g. 'wordpress', 'facebook', 'disqus'.
     *
     * @return string
     */
    public static function id(): string;

    /**
     * Human-readable provider name, translatable via __().
     *
     * Shown in the provider select on the module settings page.
     *
     * @return string
     */
    public static function label(): string;

    /**
     * Short human-readable description, translatable via __().
     *
     * Shown under the provider select on the settings page.
     *
     * @return string
     */
    public static function description(): string;

    /**
     * Provider-specific settings field definitions.
     *
     * Each entry is keyed by its option key (stored flat inside
     * the `traydigita_comments` option) and describes one field:
     *
     *     [
     *         'type' => 'text|password|number|select|checkbox',
     *         'label' => __('Field label', 'traydigita-core'),
     *         'help' => __('Help text', 'traydigita-core'), // optional
     *         'default' => '',                          // optional
     *         'options' => ['a' => __('A', 'traydigita-core')], // select only
     *         'min' => 1, 'max' => 100,                 // number only
     *         'placeholder' => __('…', 'traydigita-core'),   // optional
     *         'sanitize' => 'key',                      // optional: 'key' for slug-like values
     *     ]
     *
     * The module renders these fields (only for the selected
     * provider), sanitizes them by type on save, and merges the
     * defaults into CommentsModule::getSettings(). A provider with
     * no settings returns an empty array.
     *
     * @return array<string, array{type: string, label: string, help?: string, default?: mixed, options?: array<string, string>, min?: int, max?: int, placeholder?: string, sanitize?: string}>
     */
    public static function getSettings(): array;

    /**
     * Whether this provider can render for the current request.
     *
     * Receives the merged module settings (see
     * CommentsModule::getSettings()). A provider returns false
     * when it is missing what it needs — for example the Facebook
     * provider without an App ID, or before cookie consent. The
     * module renders nothing for a provider that reports false;
     * render() must not be called for it, but must also be safe
     * to call (it re-checks and outputs nothing).
     *
     * @param array<string, mixed> $settings Merged module settings.
     * @return bool
     */
    public static function isConfigured(array $settings): bool;

    /**
     * Echo the comment area markup for a post.
     *
     * Called only when the module's render gate passed and
     * isConfigured() returned true, inside an output buffer
     * captured by CommentsModule::render(). All output must be
     * fully escaped; nothing may be printed when the provider
     * cannot render.
     *
     * @param \WP_Post $post Post to render comments for.
     * @return void
     */
    public static function render(\WP_Post $post): void;

    /**
     * Enqueue whatever scripts this provider needs.
     *
     * Called from the `wp_enqueue_scripts` action (through
     * CommentsModule::boot()) for the active provider only. A
     * provider that needs no scripts implements this as a no-op.
     * Implementations must check visibility themselves (for
     * example is_singular() and the module's
     * CommentsModule::isVisibleForPost()) so nothing is enqueued
     * on pages where the comment area never renders.
     *
     * @return void
     */
    public static function enqueue(): void;
}
