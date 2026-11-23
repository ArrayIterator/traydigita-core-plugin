<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ContributorFrontend;

use TrayDigita\WP\Plugin\Core\Modules\EditorialWorkflow\EditorialWorkflowModule;
use TrayDigita\WP\Plugin\Core\Modules\FrontendEditor\FrontendEditorModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberProfile\MemberProfileModule;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use function array_merge;

/**
 * Backwards-compatibility shim for the retired Contributor Frontend Editor module.
 *
 * @deprecated Since the MUST-4 split (2026-11-24). The module id
 * `contributor-frontend` no longer exists in the registry: the
 * dashboard shell is MemberAreaModule, the block editor is
 * FrontendEditorModule, profile and email are
 * MemberProfileModule, per-role access modes are
 * RoleAccessModule, the editorial workflow is
 * EditorialWorkflowModule, and bookmarks are BookmarksModule.
 * Use those classes directly. This class only keeps the old
 * constants and the most-used static helpers working for
 * child themes or plugins that referenced them, and it is NOT
 * a module: it implements no ModuleInterface and is never
 * booted or listed. The old settings option
 * `traydigita_contributor_frontend` is kept as the migration
 * rollback source and is no longer written.
 */
final class ContributorFrontendModule
{
    /**
     * Retired module id. No longer registered; kept so code
     * comparing against the old id does not fatal.
     */
    public const string MODULE_ID = 'contributor-frontend';

    /**
     * Old combined-module option name (rollback source only).
     */
    public const string OPTION = 'traydigita_contributor_frontend';

    /**
     * Shortcode rendering the frontend dashboard.
     */
    public const string SHORTCODE = MemberAreaModule::SHORTCODE;

    /**
     * admin-post.php action and nonce action for editor submissions.
     */
    public const string SAVE_ACTION = FrontendEditorModule::SAVE_ACTION;

    /**
     * admin-post.php action for trashing an own post.
     */
    public const string DELETE_ACTION = FrontendEditorModule::DELETE_ACTION;

    /**
     * admin-post.php action for restoring an own trashed post.
     */
    public const string RESTORE_ACTION = FrontendEditorModule::RESTORE_ACTION;

    /**
     * admin-post.php action for permanently deleting an own
     * trashed post.
     */
    public const string PERMANENT_DELETE_ACTION = FrontendEditorModule::PERMANENT_DELETE_ACTION;

    /**
     * admin-post.php action and nonce action for profile saves.
     */
    public const string PROFILE_ACTION = MemberProfileModule::PROFILE_ACTION;

    /**
     * admin-post.php action and nonce action for email changes.
     */
    public const string EMAIL_ACTION = MemberProfileModule::EMAIL_ACTION;

    /**
     * Filter registering member-area sections.
     */
    public const string SECTIONS_FILTER = MemberAreaModule::SECTIONS_FILTER;

    /**
     * Nonce action for the old settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_contributor_frontend_save';

    /**
     * Nonce action for the scoped featured-image endpoints.
     */
    public const string FEATURED_NONCE_ACTION = FrontendEditorModule::FEATURED_NONCE_ACTION;

    /**
     * AJAX action for the scoped featured-image upload.
     */
    public const string FEATURED_UPLOAD_ACTION = FrontendEditorModule::FEATURED_UPLOAD_ACTION;

    /**
     * AJAX action for removing the scoped featured image.
     */
    public const string FEATURED_REMOVE_ACTION = FrontendEditorModule::FEATURED_REMOVE_ACTION;

    /**
     * Access mode: the role may use wp-admin as usual.
     */
    public const string MODE_DASHBOARD = RoleAccessModule::MODE_DASHBOARD;

    /**
     * Access mode: the role works frontend-only (no admin bar,
     * wp-admin redirects to the member area).
     */
    public const string MODE_FRONTEND = RoleAccessModule::MODE_FRONTEND;

    /**
     * Allowed access modes.
     *
     * @var array<int, string>
     */
    public const array MODES = RoleAccessModule::MODES;

    /**
     * The role that is never listed, never stored, and never
     * restricted by this module.
     */
    public const string ADMIN_ROLE = RoleAccessModule::ADMIN_ROLE;

    /**
     * Custom status: an editor sent the post back to the author
     * with a revision note.
     */
    public const string STATUS_NEEDS_REVISION = EditorialWorkflowModule::STATUS_NEEDS_REVISION;

    /**
     * Custom status: an editor rejected the post.
     */
    public const string STATUS_REJECTED = EditorialWorkflowModule::STATUS_REJECTED;

    /**
     * Statuses only editors and administrators may set.
     *
     * @var array<int, string>
     */
    public const array EDITORIAL_STATUSES = EditorialWorkflowModule::EDITORIAL_STATUSES;

    /**
     * Capability that marks an editor or administrator for the
     * editorial statuses.
     */
    public const string EDITORIAL_CAPABILITY = EditorialWorkflowModule::EDITORIAL_CAPABILITY;

    /**
     * Post meta holding the editor's revision note for a
     * needs-revision post.
     */
    public const string REVISION_NOTE_META = EditorialWorkflowModule::REVISION_NOTE_META;

    /**
     * Attachment meta flag marking an image uploaded through the
     * scoped featured-image endpoint.
     */
    public const string FEATURED_META = FrontendEditorModule::FEATURED_META;

    /**
     * Nonce field name for the revision-note meta box.
     */
    public const string REVISION_NOTE_NONCE_ACTION = EditorialWorkflowModule::REVISION_NOTE_NONCE_ACTION;

    /**
     * Block types the frontend editor may produce, as full
     * `core/*` names.
     *
     * @var array<int, string>
     */
    public const array ALLOWED_BLOCKS = FrontendEditorModule::ALLOWED_BLOCKS;

    /**
     * Image extensions the scoped upload may accept, keyed by
     * extension to MIME type.
     *
     * @var array<string, string>
     */
    public const array ALLOWED_MIMES = FrontendEditorModule::ALLOWED_MIMES;

    /**
     * Retired module id.
     *
     * @return string
     * @deprecated Use the new module ids.
     */
    public static function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * Merged settings in the old combined shape.
     *
     * Built from the new modules' settings, so code that read
     * the old option keys keeps working. Each new module
     * sanitizes its own keys on read.
     *
     * @return array{role_modes: array<string, string>, member_page_id: int, allow_links: bool, editor_accent: string, editor_paper: string, editor_text: string, allowed_blocks: array<int, string>, upload_max_mb: int, upload_max_px: int, allowed_types: array<int, string>, allow_email_change: bool, sections_disabled: array<int, string>, section_order: array<int, string>}
     * @deprecated Read the new modules' getSettings() instead.
     */
    public static function getSettings(): array
    {
        $settings = array_merge(
            MemberAreaModule::getSettings(),
            FrontendEditorModule::getSettings(),
            MemberProfileModule::getSettings(),
            RoleAccessModule::getSettings()
        );
        return [
            'role_modes' => $settings['role_modes'],
            'member_page_id' => $settings['member_page_id'],
            'allow_links' => $settings['allow_links'],
            'editor_accent' => $settings['editor_accent'],
            'editor_paper' => $settings['editor_paper'],
            'editor_text' => $settings['editor_text'],
            'allowed_blocks' => $settings['allowed_blocks'],
            'upload_max_mb' => $settings['upload_max_mb'],
            'upload_max_px' => $settings['upload_max_px'],
            'allowed_types' => $settings['allowed_types'],
            'allow_email_change' => $settings['allow_email_change'],
            'sections_disabled' => $settings['sections_disabled'],
            'section_order' => $settings['section_order'],
        ];
    }

    /**
     * Member-area sections, filterable by other code.
     *
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     * @deprecated Use MemberAreaModule::memberSections().
     */
    public static function memberSections(): array
    {
        return MemberAreaModule::memberSections();
    }

    /**
     * Enabled sections in display order.
     *
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     * @deprecated Use MemberAreaModule::enabledSections().
     */
    public static function enabledSections(): array
    {
        return MemberAreaModule::enabledSections();
    }

    /**
     * Whether a section may render for the current user.
     *
     * @param array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null} $section Section definition.
     * @return bool
     * @deprecated Use MemberAreaModule::sectionVisible().
     */
    public static function sectionVisible(array $section): bool
    {
        return MemberAreaModule::sectionVisible($section);
    }

    /**
     * Whether the server image editor can handle AVIF.
     *
     * @return bool
     * @deprecated Use FrontendEditorModule::avifSupported().
     */
    public static function avifSupported(): bool
    {
        return FrontendEditorModule::avifSupported();
    }

    /**
     * Whether a user is frontend-only under the current settings.
     *
     * @param int|null $userId User id, or null for the current user.
     * @return bool
     * @deprecated Use RoleAccessModule::isFrontendOnlyUser().
     */
    public static function isFrontendOnlyUser(?int $userId = null): bool
    {
        return RoleAccessModule::isFrontendOnlyUser($userId);
    }

    /**
     * Whether a user may use the contributor dashboard.
     *
     * @param int|null $userId User id, or null for the current user.
     * @return bool
     * @deprecated Use RoleAccessModule::isDashboardUser().
     */
    public static function isDashboardUser(?int $userId = null): bool
    {
        return RoleAccessModule::isDashboardUser($userId);
    }

    /**
     * Whether the current request is the configured member page.
     *
     * @return bool
     * @deprecated Use MemberAreaModule::isMemberPage().
     */
    public static function isMemberPage(): bool
    {
        return MemberAreaModule::isMemberPage();
    }

    /**
     * URL of the frontend member area.
     *
     * @return string
     * @deprecated Use MemberAreaModule::memberAreaUrl().
     */
    public static function memberAreaUrl(): string
    {
        return MemberAreaModule::memberAreaUrl();
    }
}
