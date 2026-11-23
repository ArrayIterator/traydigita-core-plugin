<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FrontendEditor;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\ContributorSplitMigration;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function add_shortcode;
use function array_merge;
use function current_user_can;
use function esc_html__;
use function esc_html_e;
use function get_option;
use function in_array;
use function is_array;
use function is_string;
use function sanitize_hex_color;
use function sanitize_key;
use function update_option;
use function wp_die;
use function wp_nonce_field;

/**
 * Frontend Editor module.
 *
 * Lets contributors write and submit articles from the frontend
 * instead of wp-admin: a simple block editor (paragraph,
 * heading, list and quote only — enforced server-side on save),
 * a scoped featured-image upload (one image per post, no
 * `upload_files` capability, contributors can never open the
 * Media Library), and the Write section of the member
 * dashboard, registered through the
 * `traydigita:core:member/sections` filter.
 *
 * Split out of the Contributor Frontend Editor module
 * (MUST-4, 2026-11-24): disabling this module hides the Write
 * section and stops the editor and its submission endpoints,
 * while the member area, profile edit and Bookmarks keep
 * working. Disabling the Member Area module instead leaves
 * this editor working on its own (see
 * FrontendEditor::shortcode()).
 *
 * Settings live in the single option
 * `traydigita_frontend_editor` (autoload `no`) and are saved
 * through the phase-2 admin AJAX dispatcher, which verifies
 * the nonce and the `manage_options` capability;
 * saveSettings() still checks the capability itself and
 * re-sanitizes every field for its own type. Sites upgraded
 * from the old combined module get their settings from the
 * one-time split migration (see ContributorSplitMigration in
 * the Member Area module).
 */
final class FrontendEditorModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'frontend-editor';

    /**
     * Option name for all module settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_frontend_editor';

    /**
     * admin-post.php action and nonce action for editor submissions.
     */
    public const string SAVE_ACTION = 'traydigita_contributor_save';

    /**
     * admin-post.php action for trashing an own post.
     */
    public const string DELETE_ACTION = 'traydigita_contributor_delete';

    /**
     * admin-post.php action for restoring an own trashed post.
     */
    public const string RESTORE_ACTION = 'traydigita_contributor_restore';

    /**
     * admin-post.php action for permanently deleting an own
     * trashed post.
     */
    public const string PERMANENT_DELETE_ACTION = 'traydigita_contributor_delete_permanent';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_frontend_editor_save';

    /**
     * Nonce action for the scoped featured-image endpoints.
     */
    public const string FEATURED_NONCE_ACTION = 'traydigita_contributor_featured';

    /**
     * AJAX action for the scoped featured-image upload.
     */
    public const string FEATURED_UPLOAD_ACTION = 'traydigita_contributor_featured_upload';

    /**
     * AJAX action for removing the scoped featured image.
     */
    public const string FEATURED_REMOVE_ACTION = 'traydigita_contributor_featured_remove';

    /**
     * Attachment meta flag marking an image uploaded through the
     * scoped featured-image endpoint. Only flagged attachments
     * are deleted when replaced or removed; an editor-set
     * featured image is detached, never deleted.
     */
    public const string FEATURED_META = '_traydigita_contributor_featured';

    /**
     * Block types the frontend editor may produce, as full
     * `core/*` names. The same list is enforced server-side on
     * save (see FrontendEditor::sanitizeBlocks()).
     *
     * @var array<int, string>
     */
    public const array ALLOWED_BLOCKS = [
        'core/paragraph',
        'core/heading',
        'core/list',
        'core/quote',
    ];

    /**
     * Post statuses an author may edit in the frontend editor.
     *
     * Shared with the dashboard (MemberDashboard::editUrl()):
     * a frontend-only user may only open their own draft or
     * needs-revision post in the editor.
     *
     * @var array<int, string>
     */
    public const array EDITABLE_STATUSES = ['draft', 'needs-revision'];

    /**
     * Post statuses the dashboard may offer to trash.
     *
     * Shared with the dashboard (MemberDashboard::rowActions()):
     * only the author's own draft or rejected post gets a Trash
     * link. Mirrors the private copy in FrontendEditor.
     *
     * @var array<int, string>
     */
    public const array TRASHABLE_STATUSES = ['draft', 'rejected'];

    /**
     * Image extensions the scoped upload may accept, keyed by
     * extension to MIME type. AVIF is only honoured when the
     * server image editor supports it (see avifSupported()).
     *
     * @var array<string, string>
     */
    public const array ALLOWED_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

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
        return __('Frontend Editor', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Simple block editor for contributors on the frontend: paragraph, heading, list and quote blocks only, enforced server-side on save, with a scoped featured-image upload that never opens the Media Library.',
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
        return 'edit';
    }

    /**
     * Module version.
     *
     * 1.0.0 splits the block editor out of the Contributor
     * Frontend Editor module (MUST-4).
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
            __('Simple block editor for writing articles.', 'traydigita-core'),
            __('Scoped featured-image upload.', 'traydigita-core'),
            __('Write section in the member dashboard.', 'traydigita-core'),
            __('Works on its own when the member area is disabled.', 'traydigita-core'),
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
                    __('Split out of the Contributor Frontend Editor module.', 'traydigita-core'),
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
        return 'traydigita-frontend-editor';
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
     * The editor works with no configuration at all, so the
     * module is always considered configured.
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
     * Read the stored settings merged over the defaults.
     *
     * Editor colours are hex colours (or the default when the
     * stored value is not one); upload limits are clamped to
     * their ranges; block and type allowlists are intersected
     * with the known lists.
     *
     * @return array{allow_links: bool, editor_accent: string, editor_paper: string, editor_text: string, allowed_blocks: array<int, string>, upload_max_mb: int, upload_max_px: int, allowed_types: array<int, string>}
     */
    public static function getSettings(): array
    {
        ContributorSplitMigration::migrate();
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $defaults = self::getDefaults();
        $blocks = [];
        $rawBlocks = $settings['allowed_blocks'] ?? [];
        if (is_array($rawBlocks)) {
            foreach ($rawBlocks as $block) {
                if (!is_string($block)) {
                    continue;
                }
                if (in_array($block, self::ALLOWED_BLOCKS, true) && !in_array($block, $blocks, true)) {
                    $blocks[] = $block;
                }
            }
        }
        if ($blocks === []) {
            $blocks = ['core/paragraph'];
        }
        $types = [];
        $rawTypes = $settings['allowed_types'] ?? [];
        if (is_array($rawTypes)) {
            foreach ($rawTypes as $type) {
                if (!is_string($type)) {
                    continue;
                }
                $type = sanitize_key($type);
                if (in_array($type, ['jpg', 'png', 'webp', 'avif'], true) && !in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }
        if ($types === []) {
            $types = ['jpg', 'png', 'webp'];
        }
        return [
            'allow_links' => !empty($settings['allow_links']),
            'editor_accent' => self::sanitizeColor(
                $settings['editor_accent'] ?? '',
                (string) $defaults['editor_accent']
            ),
            'editor_paper' => self::sanitizeColor(
                $settings['editor_paper'] ?? '',
                (string) $defaults['editor_paper']
            ),
            'editor_text' => self::sanitizeColor(
                $settings['editor_text'] ?? '',
                (string) $defaults['editor_text']
            ),
            'allowed_blocks' => $blocks,
            'upload_max_mb' => self::clampInt((int) ($settings['upload_max_mb'] ?? 0), 1, 10, 2),
            'upload_max_px' => self::clampInt((int) ($settings['upload_max_px'] ?? 0), 600, 4000, 2400),
            'allowed_types' => $types,
        ];
    }

    /**
     * Default settings: links are allowed in the editor, the
     * editor uses the Elegant Blue accent, all four blocks are
     * allowed, and uploads are JPG/PNG/WebP up to 2 MB and
     * 2400 px (AVIF off until the admin enables it).
     *
     * @return array{allow_links: bool, editor_accent: string, editor_paper: string, editor_text: string, allowed_blocks: array<int, string>, upload_max_mb: int, upload_max_px: int, allowed_types: array<int, string>}
     */
    public static function getDefaults(): array
    {
        return [
            'allow_links' => true,
            'editor_accent' => '#2563eb',
            'editor_paper' => '#ffffff',
            'editor_text' => '#1e293b',
            'allowed_blocks' => self::ALLOWED_BLOCKS,
            'upload_max_mb' => 2,
            'upload_max_px' => 2400,
            'allowed_types' => ['jpg', 'png', 'webp'],
        ];
    }

    /**
     * Clamp an integer to a range, falling back to the default
     * when the value is not a positive number.
     *
     * @param int $value Submitted value.
     * @param int $min Minimum allowed value.
     * @param int $max Maximum allowed value.
     * @param int $default Fallback when the value is out of range.
     * @return int
     */
    private static function clampInt(int $value, int $min, int $max, int $default): int
    {
        if ($value < $min || $value > $max) {
            return $default;
        }
        return $value;
    }

    /**
     * Sanitize a hex colour, falling back to the default when
     * the value is not a `#rrggbb` colour.
     *
     * @param mixed $value Submitted value.
     * @param string $default Fallback colour.
     * @return string
     */
    private static function sanitizeColor(mixed $value, string $default): string
    {
        if (!is_string($value)) {
            return $default;
        }
        $color = sanitize_hex_color($value);
        return is_string($color) && $color !== '' ? $color : $default;
    }

    /**
     * Whether the server image editor can handle AVIF.
     *
     * Checks both editor classes (Imagick and GD) at runtime:
     * AVIF is only offered when at least one of them reports
     * support for the AVIF MIME type. The scoped upload refuses
     * AVIF with a clear error when this is false, even if the
     * admin enabled the AVIF toggle.
     *
     * @return bool
     */
    public static function avifSupported(): bool
    {
        if (function_exists('_wp_image_editor_choose')) {
            _wp_image_editor_choose();
        }
        foreach (['WP_Image_Editor_Imagick', 'WP_Image_Editor_GD'] as $class) {
            if (class_exists($class) && $class::supports_mime_type('image/avif')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * The form uses the shell's generic AJAX save endpoint (see
     * data-traydigita-module-form).
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        $settings = self::getSettings();
        $avifSupported = self::avifSupported();
        ?>
        <div class="traydigita-module-settings frontend-editor-settings">
            <?php
            SettingsControls::intro(
                __('Simple block editor for contributors on the frontend.', 'traydigita-core'),
                __('The editor only offers paragraph, heading, list and quote blocks. Anything else a contributor pastes in is stripped when the article is saved, so the allowlist is enforced on the server, never trusted from the browser.', 'traydigita-core')
            );
            ?>

            <form class="frontend-editor-settings-form" data-traydigita-module-form data-traydigita-module="frontend-editor" data-module="frontend-editor" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <?php
                SettingsControls::sectionOpen(
                    'editor',
                    __('Editor', 'traydigita-core'),
                    __('Block editor behaviour for the frontend dashboard.', 'traydigita-core'),
                    __('The editor only offers paragraph, heading, list and quote blocks. Anything else a contributor pastes in is stripped when the article is saved, so the allowlist is enforced on the server, never trusted from the browser.', 'traydigita-core')
                );
                SettingsControls::toggle([
                    'id' => 'frontend-editor-allow-links',
                    'name' => 'allow_links',
                    'label' => __('Allow links in article text', 'traydigita-core'),
                    'checked' => $settings['allow_links'],
                    'help' => __('Contributors can add links to their article text.', 'traydigita-core'),
                    'help_long' => __('When off, the link button disappears from the editor and any link in a submitted article is stripped on save, keeping only the link text.', 'traydigita-core'),
                ]);
                SettingsControls::color([
                    'id' => 'frontend-editor-editor-accent',
                    'name' => 'editor_accent',
                    'label' => __('Editor accent colour', 'traydigita-core'),
                    'value' => $settings['editor_accent'],
                    'help' => __('Buttons and the active state in the frontend editor.', 'traydigita-core'),
                    'help_long' => __('Defaults to the Elegant Blue accent #2563eb. The colour only affects the contributor dashboard editor, never the site theme.', 'traydigita-core'),
                ]);
                SettingsControls::color([
                    'id' => 'frontend-editor-editor-paper',
                    'name' => 'editor_paper',
                    'label' => __('Editor paper colour', 'traydigita-core'),
                    'value' => $settings['editor_paper'],
                    'help' => __('Background of the editor canvas.', 'traydigita-core'),
                    'help_long' => __('Defaults to white. Dark mode on the reader’s device switches the dashboard to its dark palette instead; this colour is the light-mode canvas.', 'traydigita-core'),
                ]);
                SettingsControls::color([
                    'id' => 'frontend-editor-editor-text',
                    'name' => 'editor_text',
                    'label' => __('Editor text colour', 'traydigita-core'),
                    'value' => $settings['editor_text'],
                    'help' => __('Text colour inside the editor canvas.', 'traydigita-core'),
                    'help_long' => __('Defaults to slate #1e293b. It only affects the contributor dashboard editor, never the site theme.', 'traydigita-core'),
                ]);
                SettingsControls::checkboxGroup([
                    'id' => 'frontend-editor-allowed-blocks',
                    'name' => 'allowed_blocks[]',
                    'label' => __('Allowed blocks', 'traydigita-core'),
                    'options' => [
                        'core/paragraph' => __('Paragraph', 'traydigita-core'),
                        'core/heading' => __('Heading', 'traydigita-core'),
                        'core/list' => __('List', 'traydigita-core'),
                        'core/quote' => __('Quote', 'traydigita-core'),
                    ],
                    'checked' => $settings['allowed_blocks'],
                    'help' => __('Blocks the frontend editor offers.', 'traydigita-core'),
                    'help_long' => __('Unticked blocks disappear from the editor, and any such block in a submitted article is stripped on save. At least the paragraph block is always kept, so the editor never ends up empty.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose();

                SettingsControls::sectionOpen(
                    'featured-image',
                    __('Featured image', 'traydigita-core'),
                    __('Scoped upload limits for the one featured image per article.', 'traydigita-core'),
                    __('Contributors upload through a dedicated endpoint, not the Media Library: they never gain the upload_files capability and cannot open the Media Library. Replacing an image deletes the previous one only when it was uploaded through this endpoint; an image an editor set is detached, never deleted.', 'traydigita-core')
                );
                SettingsControls::number([
                    'id' => 'frontend-editor-upload-max-mb',
                    'name' => 'upload_max_mb',
                    'label' => __('Maximum file size (MB)', 'traydigita-core'),
                    'value' => $settings['upload_max_mb'],
                    'help' => __('Largest featured image a contributor may upload.', 'traydigita-core'),
                    'help_long' => __('Between 1 and 10 MB. Larger files are refused with a clear error before anything is stored.', 'traydigita-core'),
                    'attrs' => ['min' => '1', 'max' => '10', 'step' => '1'],
                ]);
                SettingsControls::number([
                    'id' => 'frontend-editor-upload-max-px',
                    'name' => 'upload_max_px',
                    'label' => __('Maximum dimension (px)', 'traydigita-core'),
                    'value' => $settings['upload_max_px'],
                    'help' => __('Longest side, in pixels, of an uploaded featured image.', 'traydigita-core'),
                    'help_long' => __('Between 600 and 4000 px. Images wider or taller than this are refused with a clear error; they are never silently downscaled.', 'traydigita-core'),
                    'attrs' => ['min' => '600', 'max' => '4000', 'step' => '100'],
                ]);
                SettingsControls::checkboxGroup([
                    'id' => 'frontend-editor-allowed-types',
                    'name' => 'allowed_types[]',
                    'label' => __('Allowed image types', 'traydigita-core'),
                    'options' => [
                        'jpg' => __('JPEG', 'traydigita-core'),
                        'png' => __('PNG', 'traydigita-core'),
                        'webp' => __('WebP', 'traydigita-core'),
                        'avif' => __('AVIF', 'traydigita-core'),
                    ],
                    'checked' => $settings['allowed_types'],
                    'help' => $avifSupported
                        ? __('Your server supports AVIF images.', 'traydigita-core')
                        : __('Your server does not support AVIF images.', 'traydigita-core'),
                    'help_long' => $avifSupported
                        ? __('AVIF uploads are accepted when ticked, because this server’s image editor can handle AVIF.', 'traydigita-core')
                        : __('AVIF uploads are refused even when ticked, because this server’s image editor cannot handle AVIF. Enable AVIF support in GD or Imagick to offer it.', 'traydigita-core'),
                ]);
                SettingsControls::sectionClose();
                ?>

                <p class="frontend-editor-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary">
                        <?php esc_html_e('Save changes', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * Re-sanitizes every field for its own type: colours must
     * be `#rrggbb`; numbers are clamped to their ranges; block
     * and type lists are intersected with the known lists.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change frontend editor settings.', 'traydigita-core'),
            ];
        }
        $defaults = self::getDefaults();
        $blocks = [];
        $rawBlocks = $data['allowed_blocks'] ?? [];
        if (is_array($rawBlocks)) {
            foreach ($rawBlocks as $block) {
                if (!is_string($block)) {
                    continue;
                }
                if (in_array($block, self::ALLOWED_BLOCKS, true) && !in_array($block, $blocks, true)) {
                    $blocks[] = $block;
                }
            }
        }
        if ($blocks === []) {
            $blocks = ['core/paragraph'];
        }
        $types = [];
        $rawTypes = $data['allowed_types'] ?? [];
        if (is_array($rawTypes)) {
            foreach ($rawTypes as $type) {
                if (!is_string($type)) {
                    continue;
                }
                $type = sanitize_key($type);
                if (in_array($type, ['jpg', 'png', 'webp', 'avif'], true) && !in_array($type, $types, true)) {
                    $types[] = $type;
                }
            }
        }
        if ($types === []) {
            $types = ['jpg', 'png', 'webp'];
        }
        update_option(self::OPTION, [
            'allow_links' => !empty($data['allow_links']),
            'editor_accent' => self::sanitizeColor(
                $data['editor_accent'] ?? '',
                (string) $defaults['editor_accent']
            ),
            'editor_paper' => self::sanitizeColor(
                $data['editor_paper'] ?? '',
                (string) $defaults['editor_paper']
            ),
            'editor_text' => self::sanitizeColor(
                $data['editor_text'] ?? '',
                (string) $defaults['editor_text']
            ),
            'allowed_blocks' => $blocks,
            'upload_max_mb' => self::clampInt(absint($data['upload_max_mb'] ?? 0), 1, 10, 2),
            'upload_max_px' => self::clampInt(absint($data['upload_max_px'] ?? 0), 600, 4000, 2400),
            'allowed_types' => $types,
        ], false);
        return [
            'success' => true,
            'message' => __('Frontend editor settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The shortcode and the admin-post handlers are
     * registered here, together with the Write section (through
     * the member sections filter) and the scoped featured-image
     * endpoints. This module boots after the Member Area module,
     * so its shortcode registration wins and delegates to the
     * dashboard shell when the member area is enabled.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_filter(MemberAreaModule::SECTIONS_FILTER, [self::class, 'registerWriteSection']);
        add_shortcode(MemberAreaModule::SHORTCODE, [FrontendEditor::class, 'shortcode']);
        add_action('admin_post_' . self::SAVE_ACTION, [FrontendEditor::class, 'handleSave']);
        add_action('admin_post_nopriv_' . self::SAVE_ACTION, [FrontendEditor::class, 'handleSave']);
        add_action('admin_post_' . self::DELETE_ACTION, [FrontendEditor::class, 'handleDelete']);
        add_action('admin_post_nopriv_' . self::DELETE_ACTION, [FrontendEditor::class, 'handleDelete']);
        add_action('admin_post_' . self::RESTORE_ACTION, [FrontendEditor::class, 'handleRestore']);
        add_action('admin_post_nopriv_' . self::RESTORE_ACTION, [FrontendEditor::class, 'handleRestore']);
        add_action(
            'admin_post_' . self::PERMANENT_DELETE_ACTION,
            [FrontendEditor::class, 'handlePermanentDelete']
        );
        add_action(
            'admin_post_nopriv_' . self::PERMANENT_DELETE_ACTION,
            [FrontendEditor::class, 'handlePermanentDelete']
        );
        add_action('wp_ajax_' . self::FEATURED_UPLOAD_ACTION, [FeaturedUpload::class, 'handleUpload']);
        add_action('wp_ajax_' . self::FEATURED_REMOVE_ACTION, [FeaturedUpload::class, 'handleRemove']);
    }

    /**
     * Register the Write section in the member area.
     *
     * Appended through the `traydigita:core:member/sections`
     * filter, after the member area's built-in sections: the
     * section only exists while this module is enabled. The
     * contributor-only rule applies here: only dashboard users
     * (contributors without `edit_others_posts`) may use the
     * editor section; everyone else is sent to wp-admin by the
     * dashboard resolver.
     *
     * @param array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}> $sections Sections registered so far.
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function registerWriteSection(array $sections): array
    {
        $sections[] = [
            'id' => 'write',
            'label' => __('Write', 'traydigita-core'),
            'capability' => static fn (): bool => RoleAccessModule::isDashboardUser(),
            'available' => null,
            'render' => [FrontendEditor::class, 'renderWriteSection'],
        ];
        return $sections;
    }
}
