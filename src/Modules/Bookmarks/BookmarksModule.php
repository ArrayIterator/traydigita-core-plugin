<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Bookmarks;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Bookmarks;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use function __;
use function add_filter;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function wp_die;

/**
 * Bookmarks module.
 *
 * Reader bookmarks, stored in the browser only (IndexedDB).
 * There is NO database storage for bookmarks — no user meta, no
 * REST sync endpoints, nothing on the server. The single source
 * of truth is one IndexedDB database in the reader's browser
 * (see Bookmarks): database `traydigita-bookmarks`, version 1,
 * object store `bookmarks` keyed by `postId`, shared by the
 * article toggle, the bookmark.php page and the member-area
 * Bookmarks section. Accepted trade-off: bookmarks do not sync
 * across devices and are lost when the browser data is cleared.
 * Guests can bookmark exactly like logged-in users.
 *
 * The module plugs into the member area through the
 * `traydigita:core:member/sections` filter — the member-area
 * module never calls this module directly. Disabling this
 * module removes the Bookmarks section from the member area
 * and hides the toggle on posts (Bookmarks::renderToggle()
 * and renderListShell() return an empty string while the
 * module is disabled). The theme's bookmark.php page reads
 * the same IndexedDB store directly and keeps working
 * against it.
 *
 * This module has no settings of its own: the settings page
 * only explains the browser-only storage.
 */
final class BookmarksModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'bookmarks';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return self::MODULE_ID;
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
        return __('Bookmarks', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Reader bookmarks stored in the browser only (IndexedDB): a save-for-later toggle on articles, a bookmark list page, and a Bookmarks section in the member area. Nothing is stored on the server.',
            'traydigita-core'
        );
    }

    /**
     * Dashicon slug for the module grid card.
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'bookmark';
    }

    /**
     * Module version.
     *
     * 1.0.0 makes bookmarks a real module (MUST-3).
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
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Save-for-later toggle on articles.', 'traydigita-core'),
            __('Bookmarks section in the member area.', 'traydigita-core'),
            __('Stored in the browser only — nothing on the server.', 'traydigita-core'),
            __('Guests can bookmark exactly like members.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Bookmarks is now a module of its own, with an enable/disable toggle.', 'traydigita-core'),
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
     * Admin page slug for this module's settings page.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-bookmarks';
    }

    /**
     * Whether the module can run (it has no hard dependencies).
     *
     * @return bool
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Bookmarks need no configuration, so the module is always
     * considered configured.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
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
     * Bookmarks have no settings: the page is a visible info
     * page in two sections — what the module does and what
     * disabling removes — with nothing hidden behind a Help
     * toggle. There is no form to save.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        ?>
        <div class="traydigita-module-settings bookmarks-settings">
            <div class="traydigita-ui-section td-settings-section">
                <h2 class="traydigita-ui-section-title"><?php esc_html_e('What this module does', 'traydigita-core'); ?></h2>
                <p>
                    <?php esc_html_e('Reader bookmarks, stored in the browser only.', 'traydigita-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('Bookmarks live in the reader’s browser (IndexedDB database traydigita-bookmarks, version 1) — never in the WordPress database, never in user meta, never synced through REST. The article toggle, the bookmark list page and the member-area Bookmarks section all read the same store, so a bookmark saved on an article shows up in the member area on the same browser.', 'traydigita-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('Guests can bookmark exactly like logged-in users — no account is needed; bookmarks are tied to the browser, not the user.', 'traydigita-core'); ?>
                </p>
                <p>
                    <?php esc_html_e('This module has no settings. Bookmarks do not sync across devices and are lost when the browser data is cleared — that trade-off is accepted.', 'traydigita-core'); ?>
                </p>
            </div>
            <div class="traydigita-ui-section td-settings-section">
                <h2 class="traydigita-ui-section-title"><?php esc_html_e('What disabling does', 'traydigita-core'); ?></h2>
                <p>
                    <?php esc_html_e('Disabling this module removes the Bookmarks section from the member area and hides the save-for-later toggle on articles. Bookmarks already saved in a reader’s browser stay there until the reader clears them; they are not deleted by disabling the module.', 'traydigita-core'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * Bookmarks have no settings, so there is nothing to save;
     * the call still succeeds so the admin shell can confirm
     * the page.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change bookmark settings.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('Bookmark settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the member-area section. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The Bookmarks section is registered through the
     * member sections filter — the member-area module never
     * calls this module directly.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter(MemberAreaModule::SECTIONS_FILTER, [self::class, 'registerBookmarkSection']);
    }

    /**
     * Register the Bookmarks section in the member area.
     *
     * Appended through the `traydigita:core:member/sections`
     * filter, after the Write section: the section only exists
     * while this module is enabled.
     *
     * @param array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}> $sections Sections registered so far.
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function registerBookmarkSection(array $sections): array
    {
        $sections[] = [
            'id' => 'bookmark',
            'label' => __('Bookmarks', 'traydigita-core'),
            'capability' => null,
            'available' => null,
            'render' => [self::class, 'renderSection'],
        ];
        return $sections;
    }

    /**
     * Render the Bookmarks section: the reader's saved articles.
     *
     * The list shell is the same one the bookmark.php page
     * renders — the entries themselves live in the browser's
     * IndexedDB store (see Bookmarks), so the list is filled
     * in by JavaScript on both surfaces.
     *
     * @return string
     */
    public static function renderSection(): string
    {
        $html = '<h2 class="traydigita-contributor-heading">' . esc_html__('Bookmarks', 'traydigita-core') . '</h2>';
        $html .= '<p class="traydigita-contributor-empty">'
            . esc_html__('Articles you saved for later. Bookmarks live in this browser only — they do not sync to other devices.', 'traydigita-core')
            . '</p>';
        $html .= Bookmarks::renderListShell();
        return $html;
    }
}
