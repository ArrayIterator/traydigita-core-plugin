<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

/**
 * Contract every TrayDigita admin module must implement.
 *
 * Modules are static-only classes: no constructor, no instance state.
 * All methods are static so the registry and the admin shell can call
 * them without instantiating anything, and so a module can be skipped
 * entirely (never booted, never enqueued) when it is disabled.
 *
 * Security contract for saveSettings(): the module itself is
 * responsible for sanitizing every field it reads from the $data
 * array (sanitize_text_field(), absint(), esc_url_raw(), allowlists,
 * and so on). The AJAX dispatcher (AdminAjax) only applies a generic
 * recursive text sanitization before handing the data over, and it
 * never returns option values that may contain secrets (for example
 * an SMTP password) in any response.
 */
interface ModuleInterface
{
    /**
     * Stable machine id of the module, e.g. 'login', 'maintenance',
     * 'smtp', 'email', 'cookie-consent', 'security-headers', 'pwa'.
     *
     * The id is stored in the `traydigita_modules` option and used in
     * admin URLs, so it must be lowercase letters, digits and dashes.
     *
     * @return string
     */
    public static function id(): string;

    /**
     * Human-readable module name, translatable via __().
     *
     * @return string
     */
    public static function name(): string;

    /**
     * Short human-readable description, translatable via __().
     *
     * @return string
     */
    public static function description(): string;

    /**
     * Dashicons slug without the 'dashicons-' prefix, e.g.
     * 'admin-users'. Used for the module card icon.
     *
     * @return string
     */
    public static function icon(): string;

    /**
     * Module version, e.g. '1.0.0'.
     *
     * @return string
     */
    public static function version(): string;

    /**
     * Admin menu slug of the module settings page, e.g.
     * 'traydigita-login'. The slug is used as the submenu slug
     * under the TrayDigita parent menu.
     *
     * @return string
     */
    public static function settingsPageSlug(): string;

    /**
     * Whether the module can run at all right now.
     *
     * Return false when a hard dependency is missing (for example a
     * required plugin is inactive). An unavailable module is never
     * booted and its card shows an unavailable state.
     *
     * @return bool
     */
    public static function isAvailable(): bool;

    /**
     * Whether the module is configured enough to do its job.
     *
     * Return false when the module still needs setup (for example
     * SMTP credentials are unset). A configured-but-disabled module
     * is fine; this only drives the "needs setup" badge on the card.
     *
     * @return bool
     */
    public static function isConfigured(): bool;

    /**
     * Whether this is a core module.
     *
     * Core modules are always enabled and cannot be deactivated:
     * the registry always reports them as enabled (even when the
     * stored `traydigita_modules` option does not list them),
     * `bootEnabled()` boots them unconditionally, the grid toggle
     * is disabled for them with a "Core module" note, and the
     * toggle endpoint rejects disabling them. A core module that
     * is unavailable (see isAvailable()) is still never booted.
     *
     * Every module shipped today is optional and returns false
     * here. Phase 3 marks modules as core by flipping this to
     * true; no other change is needed.
     *
     * @return bool
     */
    public static function isCore(): bool;

    /**
     * Optional author name shown on the module card.
     *
     * Deliberately NOT part of this interface: requiring it would
     * break every child-theme module that implements the interface
     * without it. A module may declare a static author() method;
     * ModuleRegistry::author() calls it when it exists and falls
     * back to 'TrayDigita' otherwise, so a card author is never
     * empty. Every module shipped with the theme declares it.
     */

    /**
     * Optional submenu opt-in.
     *
     * Deliberately NOT part of this interface, for the same reason
     * as author() above: requiring it would break every child-theme
     * module that implements the interface without it. A module may
     * declare a static showInSubmenu() method returning true to
     * appear as an entry in the TrayDigita admin submenu;
     * ModuleRegistry::showInSubmenu() calls it when it exists and
     * falls back to false otherwise, so the submenu stays clean
     * unless a module opts in explicitly.
     *
     * Optional recommendation.
     *
     * A module may also declare a static isRecommended() method
     * returning true to mark itself as recommended;
     * ModuleRegistry::isRecommended() calls it when it exists and
     * falls back to false otherwise. Recommended modules ship
     * inactive but carry a "Recommended" note on their module
     * card, so the admin sees the suggestion without the module
     * turning itself on.
     *
     * Optional category.
     *
     * A module may also declare a static category() method
     * returning its default category slug (for example
     * 'content', 'security'); ModuleRegistry::categoryOf()
     * calls it when it exists and falls back to the
     * 'administration' category otherwise, so a card is never
     * uncategorised. The full default set lives in
     * ModuleRegistry::defaultCategories().
     *
     * Optional open link.
     *
     * A module may also declare a static openLink() method
     * returning an admin-relative target (for example
     * 'customize.php', 'tools.php', or
     * 'admin.php?page=traydigita-login') that its submenu entry
     * and its module-card "Open" button both link to; null (or no
     * method) means the module's own settings page
     * ('admin.php?page=' . settingsPageSlug()). The target must
     * name a WordPress admin file — ModuleRegistry::openLink()
     * validates it and falls back to the own settings page for
     * anything else, so an absolute or external URL can never
     * leak into the menu or the module list JSON.
     *
     * Optional features and changelog.
     *
     * A module may also declare static features() and
     * changelog() methods for the module info popup:
     * features() returns a list of short strings, one per
     * thing the module actually does, and changelog() returns
     * a list of ['version' => string, 'changes' => list of
     * strings] entries, newest first. Both are resolved by
     * ModuleRegistry::features() / ::changelog() with
     * method_exists, and both degrade gracefully: a module
     * without features() shows its short description in the
     * popup's Features section, and a module without
     * changelog() shows a single entry built from version().
     * Only recorded history belongs in changelog() — never
     * invent earlier versions.
     */

    /**
     * Echo the settings UI for this module.
     *
     * The output is rendered inside the admin shell content area, so
     * it must not print its own admin page wrapper. Settings forms
     * must be marked with data-traydigita-module-form (or a
     * data-action naming their AJAX action) and name their fields
     * flat, matching the option keys; the module id is read from the
     * form's data-module attribute or the nearest ancestor carrying
     * data-traydigita-module="<id>". The admin JavaScript submits
     * such forms via AJAX without a reload, using the shell's
     * data-ajax-url and data-nonce attributes when the form does not
     * carry its own. In-page tabs use [data-traydigita-tab] buttons
     * with matching [data-traydigita-panel] panels; a module may
     * declare its tabs through an optional static tabs() method
     * returning a list of ['id' => string, 'label' => string] pairs.
     *
     * @return void
     */
    public static function renderSettingsPage(): void;

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * The module must sanitize every field again for its own type
     * before storing it (the dispatcher cannot know the field types).
     * Secrets (passwords, tokens) must never be included in the
     * returned array.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string, data?: array<string, mixed>, errors?: array<string, string>}
     */
    public static function saveSettings(array $data): array;

    /**
     * Register the module's frontend/login hooks.
     *
     * Called ONLY when the module is enabled (and available) — or
     * unconditionally when it is a core module — by
     * ModuleRegistry::bootEnabled(). A disabled module must register
     * no hooks and enqueue nothing.
     *
     * @return void
     */
    public static function boot(): void;
}
