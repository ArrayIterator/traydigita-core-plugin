<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\MemberArea;

use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\Bookmarks\BookmarksModule;
use TrayDigita\WP\Plugin\Core\Modules\EditorialWorkflow\EditorialWorkflowModule;
use TrayDigita\WP\Plugin\Core\Modules\FrontendEditor\FrontendEditorModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberProfile\MemberProfileModule;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use function array_filter;
use function array_key_exists;
use function array_values;
use function get_option;
use function in_array;
use function is_array;
use function update_option;

/**
 * One-time migration for the MUST-4 split.
 *
 * The Contributor Frontend Editor module
 * (`contributor-frontend`, option
 * `traydigita_contributor_frontend`) split into six modules
 * (MUST-4 + MUST-3 + SHOULD-4, 2026-11-24). This migration
 * runs once, on the first request after the upgrade, and
 * never again:
 *
 * 1. Enabled state. When the old module id is in the stored
 *    `traydigita_modules` list, it is replaced by the six new
 *    ids (member-area, frontend-editor, bookmarks,
 *    member-profile, role-access, editorial-workflow), so a
 *    site that had the old module enabled keeps everything
 *    working. When the old id is NOT in the list, nothing is
 *    enabled - the replacement modules must not silently turn
 *    on for a site that had the old module disabled. (On a
 *    fresh install there is no stored list at all, and the
 *    registry's DEFAULT_ENABLED applies instead: bookmarks is
 *    on by default there, the other five are off, matching
 *    the old module shipping disabled.)
 * 2. Settings. The keys of the old option are distributed to
 *    the new options (member page + section order to the
 *    Member Area, editor + upload keys to the Frontend
 *    Editor, allow-email-change to Member Profile, role modes
 *    to Role Access). A key is only copied when the new
 *    option does not already hold it, so an admin's newer
 *    choice is never overwritten and the migration is safe to
 *    run twice.
 *
 * The old option is KEPT as the rollback source and never
 * deleted. The flag option
 * `traydigita_contributor_frontend_migrated` ('1') marks the
 * migration as done; it is set even when there was nothing to
 * migrate, so the old option is read at most once per site.
 * Each new option is sanitized on read by its own
 * getSettings(), so the copied values are safe even before
 * they are re-saved.
 *
 * Runs from ModuleRegistry::bootEnabled() before the enabled
 * list is read (so the newly enabled modules boot in the same
 * request), and lazily from each new module's getSettings()
 * for any read that happens before boot.
 */
final class ContributorSplitMigration
{
    /**
     * Old combined-module option name (kept as rollback source, never deleted).
     */
    public const string LEGACY_OPTION = 'traydigita_contributor_frontend';

    /**
     * Old combined-module id in the `traydigita_modules` option.
     */
    public const string LEGACY_MODULE_ID = 'contributor-frontend';

    /**
     * Option flag marking the split migration as done ('1').
     */
    public const string MIGRATION_FLAG_OPTION = 'traydigita_contributor_frontend_migrated';

    /**
     * Whether the migration has already run in this request.
     */
    private static bool $migrationAttempted = false;

    /**
     * New module ids replacing the old combined module.
     *
     * @var array<int, string>
     */
    public const array NEW_MODULE_IDS = [
        MemberAreaModule::MODULE_ID,
        FrontendEditorModule::MODULE_ID,
        BookmarksModule::MODULE_ID,
        MemberProfileModule::MODULE_ID,
        RoleAccessModule::MODULE_ID,
        EditorialWorkflowModule::MODULE_ID,
    ];

    /**
     * Run the one-time split migration (if needed).
     *
     * Idempotent: the flag option stops any second run, and
     * the per-key copy never overwrites a value the new option
     * already holds.
     *
     * @return void
     */
    public static function migrate(): void
    {
        if (self::$migrationAttempted) {
            return;
        }
        self::$migrationAttempted = true;
        if (get_option(self::MIGRATION_FLAG_OPTION, null) !== null) {
            return;
        }
        $legacy = get_option(self::LEGACY_OPTION, null);
        $stored = get_option(ModuleRegistry::OPTION, null);
        if (is_array($stored) && in_array(self::LEGACY_MODULE_ID, $stored, true)) {
            $stored = array_values(array_filter(
                $stored,
                static fn (mixed $id): bool => $id !== self::LEGACY_MODULE_ID
            ));
            foreach (self::NEW_MODULE_IDS as $id) {
                if (!in_array($id, $stored, true)) {
                    $stored[] = $id;
                }
            }
            update_option(ModuleRegistry::OPTION, $stored, false);
        }
        if (is_array($legacy)) {
            self::migrateSettings($legacy);
        }
        update_option(self::MIGRATION_FLAG_OPTION, '1', false);
    }

    /**
     * Distribute the legacy option's keys to the new options.
     *
     * Only keys the new option does not already hold are
     * copied; each module's getSettings() sanitizes on read.
     *
     * @param array<string, mixed> $legacy Legacy option values.
     * @return void
     */
    private static function migrateSettings(array $legacy): void
    {
        $copies = [
            MemberAreaModule::OPTION => ['member_page_id', 'sections_disabled', 'section_order'],
            FrontendEditorModule::OPTION => [
                'allow_links',
                'editor_accent',
                'editor_paper',
                'editor_text',
                'allowed_blocks',
                'upload_max_mb',
                'upload_max_px',
                'allowed_types',
            ],
            MemberProfileModule::OPTION => ['allow_email_change'],
            RoleAccessModule::OPTION => ['role_modes'],
        ];
        foreach ($copies as $option => $keys) {
            $target = get_option($option, []);
            if (!is_array($target)) {
                $target = [];
            }
            $changed = false;
            foreach ($keys as $key) {
                if (!array_key_exists($key, $legacy) || array_key_exists($key, $target)) {
                    continue;
                }
                $target[$key] = $legacy[$key];
                $changed = true;
            }
            if ($changed) {
                update_option($option, $target, false);
            }
        }
    }
}
