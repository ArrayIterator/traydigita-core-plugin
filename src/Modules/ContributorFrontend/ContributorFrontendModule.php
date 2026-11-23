<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ContributorFrontend;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use Throwable;
use function __;
use function _n_noop;
use function _x;
use function absint;
use function add_action;
use function add_filter;
use function add_meta_box;
use function add_query_arg;
use function add_shortcode;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_values;
use function current_user_can;
use function delete_post_meta;
use function esc_attr;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function file_exists;
use function get_current_user_id;
use function get_option;
use function get_permalink;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function get_userdata;
use function home_url;
use function implode;
use function in_array;
use function is_array;
use function is_page;
use function is_string;
use function is_user_logged_in;
use function register_post_status;
use function sanitize_hex_color;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function selected;
use function sprintf;
use function str_starts_with;
use function update_option;
use function update_post_meta;
use function user_can;
use function wp_dequeue_style;
use function wp_die;
use function wp_dropdown_pages;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_login_url;
use function wp_nonce_field;
use function wp_roles;
use function wp_safe_redirect;
use function wp_verify_nonce;
use function wp_unslash;

/**
 * Contributor Frontend Editor module (v2).
 *
 * Lets contributors write and submit articles from a frontend
 * dashboard instead of wp-admin. The module has three halves:
 *
 * 1. Access control. Per role, the admin picks "Allow dashboard
 *    access" or "Frontend only". A frontend-only user gets no
 *    admin bar, and any wp-admin page they load redirects them to
 *    the frontend member area. The redirect never fires for
 *    admin-ajax.php, admin-post.php (the editor's own form
 *    handler), the user's own profile page, REST requests,
 *    WP-CLI, cron, or the login/logout flows, and a redirect
 *    loop is impossible (the target is always a frontend page).
 *
 * 2. Contributor dashboard. The configured member page is the
 *    dashboard: one unified shell (sidebar + top bar) for
 *    every logged-in user, with sections from the member
 *    registry (Overview, Posts, My Contributions, Write,
 *    Profile, Change Email). Sections are registerable through
 *    the `traydigita:core:member/sections` filter and
 *    manageable from the module settings (enable/disable,
 *    reorder). A section renders only when the user has the
 *    capability for it AND its feature is available. The
 *    contributor-only rule applies to the EDITOR section only:
 *    non-contributors hitting Write are redirected to
 *    wp-admin. Posts lists published posts plus the viewer's
 *    own non-published ones (editors additionally see the
 *    pending review queue), and My Contributions always lists
 *    the user's own posts, even after a demotion to
 *    subscriber. The dashboard template applies only when a
 *    member page is configured; without one the shortcode
 *    still renders inline wherever it is placed.
 *
 * 3. Frontend editor. The `[traydigita_contributor_editor]`
 *    shortcode (see ContributorFrontendEditor) renders the
 *    dashboard. Writing uses a small block editor (paragraph,
 *    heading, list and quote only — enforced server-side on
 *    save), a scoped featured-image upload (one image per post,
 *    no `upload_files` capability, contributors can never open
 *    the Media Library), and custom post statuses
 *    (`needs-revision`, `rejected`) that only editors and
 *    administrators may set. Authorship is always the logged-in
 *    user; no capability is ever granted by this module.
 *
 * HARD RULE — administrators are never affected. The
 * `administrator` role is never listed on the settings page, is
 * never stored in the settings, and every enforcement point
 * checks `manage_options` first (see isFrontendOnlyUser()).
 * An administrator can therefore never hide their own admin
 * bar or be redirected away from wp-admin, even by mistake.
 *
 * The module ships DISABLED by default: access control changes
 * behaviour for real users, so it is strictly opt-in.
 *
 * Settings live in the single option
 * `traydigita_contributor_frontend` (autoload `no`) and are
 * saved through the phase-2 admin AJAX dispatcher, which
 * verifies the nonce and the `manage_options` capability;
 * saveSettings() still checks the capability itself and
 * re-sanitizes every field for its own type.
 */
final class ContributorFrontendModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'contributor-frontend';

    /**
     * Option name for all module settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_contributor_frontend';

    /**
     * Shortcode rendering the frontend editor.
     */
    public const string SHORTCODE = 'traydigita_contributor_editor';

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
     * admin-post.php action and nonce action for profile saves.
     */
    public const string PROFILE_ACTION = 'traydigita_contributor_profile';

    /**
     * admin-post.php action and nonce action for email changes.
     */
    public const string EMAIL_ACTION = 'traydigita_contributor_email';

    /**
     * Filter registering member-area sections.
     *
     * Receives the section list (see memberSections()) and
     * returns it, optionally with extra sections appended. A
     * section is `['id' => string, 'label' => string,
     * 'capability' => callable|null, 'available' => callable|null,
     * 'render' => callable|null]`: capability and availability
     * default to always true, render defaults to the built-in
     * renderer for the id (custom sections must provide one).
     */
    public const string SECTIONS_FILTER = 'traydigita:core:member/sections';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_contributor_frontend_save';

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
     * Access mode: the role may use wp-admin as usual.
     */
    public const string MODE_DASHBOARD = 'dashboard';

    /**
     * Access mode: the role works frontend-only (no admin bar,
     * wp-admin redirects to the member area).
     */
    public const string MODE_FRONTEND = 'frontend';

    /**
     * Allowed access modes.
     *
     * @var array<int, string>
     */
    public const array MODES = [self::MODE_DASHBOARD, self::MODE_FRONTEND];

    /**
     * The role that is never listed, never stored, and never
     * restricted by this module.
     */
    public const string ADMIN_ROLE = 'administrator';

    /**
     * Custom status: an editor sent the post back to the author
     * with a revision note. The author may edit and resubmit it.
     */
    public const string STATUS_NEEDS_REVISION = 'needs-revision';

    /**
     * Custom status: an editor rejected the post. The author may
     * read it and delete it, but may not edit or resubmit it.
     */
    public const string STATUS_REJECTED = 'rejected';

    /**
     * Statuses only editors and administrators may set.
     *
     * @var array<int, string>
     */
    public const array EDITORIAL_STATUSES = [
        self::STATUS_NEEDS_REVISION,
        self::STATUS_REJECTED,
    ];

    /**
     * Capability that marks an editor or administrator for the
     * editorial statuses: anyone who can edit other people's
     * posts may set them; contributors may not.
     */
    public const string EDITORIAL_CAPABILITY = 'edit_others_posts';

    /**
     * Post meta holding the editor's revision note for a
     * needs-revision post.
     */
    public const string REVISION_NOTE_META = '_traydigita_revision_note';

    /**
     * Attachment meta flag marking an image uploaded through the
     * scoped featured-image endpoint. Only flagged attachments
     * are deleted when replaced or removed; an editor-set
     * featured image is detached, never deleted.
     */
    public const string FEATURED_META = '_traydigita_contributor_featured';

    /**
     * Nonce field name for the revision-note meta box.
     */
    public const string REVISION_NOTE_NONCE_ACTION = 'traydigita_revision_note_save';

    /**
     * Block types the frontend editor may produce, as full
     * `core/*` names. The same list is enforced server-side on
     * save (see ContributorFrontendEditor::sanitizeBlocks()).
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
     * Cached member sections (filter result) for this request.
     *
     * @var array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>|null
     */
    private static ?array $sectionsCache = null;

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
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
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
        return __('Contributor Frontend Editor', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * States the administrator exclusion plainly: it is the one
     * rule a site owner must understand before enabling the
     * module.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Let contributors write and submit articles from a frontend dashboard instead of wp-admin: a simple block editor, a scoped featured-image upload, and custom needs-revision / rejected statuses editors set from wp-admin. Per-role access modes (dashboard or frontend-only), with the admin bar hidden and wp-admin redirected to the member area for frontend-only users. Administrators are never affected.',
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
     * 2.0.0 rebuilds the editor: block editor, scoped
     * featured-image upload, custom statuses, and the unified
     * contributor dashboard template.
     *
     * @return string
     */
    public static function version(): string
    {
        return '2.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Frontend dashboard for contributors — no wp-admin needed.', 'traydigita-core'),
            __('Simple block editor for writing articles.', 'traydigita-core'),
            __('Scoped featured-image upload.', 'traydigita-core'),
            __('Needs-revision and rejected statuses set by editors.', 'traydigita-core'),
            __('Per-role access modes; administrators are never affected.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here — never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '2.0.0',
                'changes' => [
                    __('Current version in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
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
        return 'traydigita-contributor-frontend';
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
     * The editor shortcode works with no configuration at all,
     * so the module is always considered configured; the access
     * modes are optional on top of it.
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
     * Always false for the modules shipped today: every module is
     * optional and can be disabled from the grid.
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
     * Role modes are keyed by role slug and hold 'dashboard' or
     * 'frontend'; the administrator slug is dropped whenever it
     * appears, so it can never be stored. The member page id is
     * a published page id, or 0 for the homepage. Editor colours
     * are hex colours (or the default when the stored value is
     * not one); upload limits are clamped to their ranges; block
     * and type allowlists are intersected with the known lists.
     *
     * @return array{role_modes: array<string, string>, member_page_id: int, allow_links: bool, editor_accent: string, editor_paper: string, editor_text: string, allowed_blocks: array<int, string>, upload_max_mb: int, upload_max_px: int, allowed_types: array<int, string>, allow_email_change: bool, sections_disabled: array<int, string>, section_order: array<int, string>}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $modes = [];
        $validRoles = self::configurableRoles();
        $rawModes = $settings['role_modes'] ?? [];
        if (is_array($rawModes)) {
            foreach ($rawModes as $role => $mode) {
                if (!is_string($role) || !is_string($mode)) {
                    continue;
                }
                $slug = sanitize_key($role);
                if (!in_array($slug, $validRoles, true)) {
                    continue;
                }
                if (!in_array($mode, self::MODES, true)) {
                    continue;
                }
                $modes[$slug] = $mode;
            }
        }
        $pageId = absint($settings['member_page_id'] ?? 0);
        if ($pageId > 0) {
            $page = get_post($pageId);
            if ($page === null || $page->post_type !== 'page' || $page->post_status !== 'publish') {
                $pageId = 0;
            }
        }
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
        $sectionIds = array_map(
            static fn (array $section): string => (string) $section['id'],
            self::memberSections()
        );
        $disabled = [];
        $rawDisabled = $settings['sections_disabled'] ?? [];
        if (is_array($rawDisabled)) {
            foreach ($rawDisabled as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = sanitize_key($id);
                if (in_array($id, $sectionIds, true) && !in_array($id, $disabled, true)) {
                    $disabled[] = $id;
                }
            }
        }
        $order = [];
        $rawOrder = $settings['section_order'] ?? [];
        if (is_array($rawOrder)) {
            foreach ($rawOrder as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = sanitize_key($id);
                if (in_array($id, $sectionIds, true) && !in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        return [
            'role_modes' => $modes,
            'member_page_id' => $pageId,
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
            'allow_email_change' => !empty($settings['allow_email_change']),
            'sections_disabled' => $disabled,
            'section_order' => $order,
        ];
    }

    /**
     * Default settings: every role keeps dashboard access, the
     * member area is the homepage, links are allowed in the
     * editor, the editor uses the Elegant Blue accent, all four
     * blocks are allowed, and uploads are JPG/PNG/WebP up to
     * 2 MB and 2400 px (AVIF off until the admin enables it).
     *
     * @return array{role_modes: array<string, string>, member_page_id: int, allow_links: bool, editor_accent: string, editor_paper: string, editor_text: string, allowed_blocks: array<int, string>, upload_max_mb: int, upload_max_px: int, allowed_types: array<int, string>, allow_email_change: bool, sections_disabled: array<int, string>, section_order: array<int, string>}
     */
    public static function getDefaults(): array
    {
        return [
            'role_modes' => [],
            'member_page_id' => 0,
            'allow_links' => true,
            'editor_accent' => '#2563eb',
            'editor_paper' => '#ffffff',
            'editor_text' => '#1e293b',
            'allowed_blocks' => self::ALLOWED_BLOCKS,
            'upload_max_mb' => 2,
            'upload_max_px' => 2400,
            'allowed_types' => ['jpg', 'png', 'webp'],
            'allow_email_change' => false,
            'sections_disabled' => [],
            'section_order' => [],
        ];
    }

    /**
     * Member-area sections, filterable by other code.
     *
     * Each section is `['id' => string, 'label' => string,
     * 'capability' => callable|null, 'available' =>
     * callable|null, 'render' => callable|null]`. A null
     * capability or availability means always true; a null
     * render means the built-in renderer for the id (custom
     * sections must provide one). The list is cached per
     * request; the filter runs once.
     *
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function memberSections(): array
    {
        if (self::$sectionsCache !== null) {
            return self::$sectionsCache;
        }
        $sections = [
            [
                'id' => 'overview',
                'label' => __('Overview', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'posts',
                'label' => __('Posts', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'contributions',
                'label' => __('My Contributions', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'write',
                'label' => __('Write', 'traydigita-core'),
                'capability' => static fn (): bool => self::isDashboardUser(),
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'bookmark',
                'label' => __('Bookmarks', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'profile',
                'label' => __('Profile', 'traydigita-core'),
                'capability' => null,
                'available' => null,
                'render' => null,
            ],
            [
                'id' => 'change-email',
                'label' => __('Change Email', 'traydigita-core'),
                'capability' => null,
                'available' => static fn (): bool => (bool) self::getSettings()['allow_email_change'],
                'render' => null,
            ],
        ];
        $filtered = apply_filters(self::SECTIONS_FILTER, $sections);
        if (!is_array($filtered)) {
            return self::$sectionsCache = $sections;
        }
        $clean = [];
        $seen = [];
        foreach ($filtered as $section) {
            if (!is_array($section) || !isset($section['id']) || !is_string($section['id'])) {
                continue;
            }
            $id = sanitize_key($section['id']);
            if ($id === '' || in_array($id, $seen, true)) {
                continue;
            }
            $seen[] = $id;
            $clean[] = [
                'id' => $id,
                'label' => isset($section['label']) && is_string($section['label']) && $section['label'] !== ''
                    ? $section['label']
                    : $id,
                'capability' => isset($section['capability']) && is_callable($section['capability'])
                    ? $section['capability']
                    : null,
                'available' => isset($section['available']) && is_callable($section['available'])
                    ? $section['available']
                    : null,
                'render' => isset($section['render']) && is_callable($section['render'])
                    ? $section['render']
                    : null,
            ];
        }
        return self::$sectionsCache = $clean === [] ? $sections : $clean;
    }

    /**
     * Enabled sections in display order.
     *
     * The stored order wins; sections missing from it (new
     * sections, or a cleared setting) append in registration
     * order. Disabled sections are dropped.
     *
     * @return array<int, array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null}>
     */
    public static function enabledSections(): array
    {
        $settings = self::getSettings();
        $disabled = $settings['sections_disabled'];
        $order = $settings['section_order'];
        $byId = [];
        foreach (self::memberSections() as $section) {
            $byId[(string) $section['id']] = $section;
        }
        $ordered = [];
        foreach ($order as $id) {
            if (isset($byId[$id]) && !in_array($id, $disabled, true)) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        foreach ($byId as $id => $section) {
            if (!in_array($id, $disabled, true)) {
                $ordered[] = $section;
            }
        }
        return $ordered;
    }

    /**
     * Whether a section may render for the current user.
     *
     * BOTH conditions must hold: the capability callback (when
     * set) must pass, AND the availability callback (when set)
     * must pass. A section failing either is hidden entirely
     * — never shown greyed-out — and its URL redirects away
     * (see ContributorFrontendEditor::resolveView()).
     *
     * @param array{id: string, label: string, capability: callable|null, available: callable|null, render: callable|null} $section Section definition.
     * @return bool
     */
    public static function sectionVisible(array $section): bool
    {
        $capability = $section['capability'] ?? null;
        if ($capability !== null) {
            try {
                if (!$capability()) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }
        $available = $section['available'] ?? null;
        if ($available !== null) {
            try {
                if (!$available()) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }
        return true;
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
     * Role slugs the admin may configure on the settings page.
     *
     * Every role on the site except `administrator`. Uses
     * wp_roles() (available everywhere) rather than
     * get_editable_roles() (wp-admin only).
     *
     * @return array<int, string>
     */
    public static function configurableRoles(): array
    {
        $roles = array_keys(wp_roles()->get_names());
        return array_values(array_filter(
            $roles,
            static fn (string $slug): bool => $slug !== self::ADMIN_ROLE
        ));
    }

    /**
     * Whether a user is frontend-only under the current settings.
     *
     * HARD RULE: anyone who can `manage_options` (administrators
     * and anyone else explicitly granted it) is never
     * frontend-only. A user is frontend-only only when at least
     * one of their roles is set to 'frontend' and none of their
     * roles is set to 'dashboard' (dashboard wins when a user
     * holds both kinds of role). Roles with no configured mode
     * default to dashboard access.
     *
     * @param int|null $userId User id, or null for the current user.
     * @return bool
     */
    public static function isFrontendOnlyUser(?int $userId = null): bool
    {
        $userId = $userId ?? get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        if (user_can($userId, 'manage_options')) {
            return false;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return false;
        }
        $roles = is_array($user->roles) ? $user->roles : [];
        if ($roles === []) {
            return false;
        }
        $modes = self::getSettings()['role_modes'];
        $frontend = false;
        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }
            $mode = $modes[sanitize_key($role)] ?? self::MODE_DASHBOARD;
            if ($mode === self::MODE_DASHBOARD) {
                return false;
            }
            if ($mode === self::MODE_FRONTEND) {
                $frontend = true;
            }
        }
        return $frontend;
    }

    /**
     * Whether a user may use the contributor dashboard.
     *
     * The dashboard is contributor-only: the user must hold the
     * `contributor` role and must NOT be able to edit other
     * people's posts. A user holding contributor AND a higher
     * role (editor, administrator) belongs in wp-admin, so they
     * are not a dashboard user.
     *
     * @param int|null $userId User id, or null for the current user.
     * @return bool
     */
    public static function isDashboardUser(?int $userId = null): bool
    {
        $userId = $userId ?? get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        if (user_can($userId, self::EDITORIAL_CAPABILITY)) {
            return false;
        }
        $user = get_userdata($userId);
        if ($user === false) {
            return false;
        }
        $roles = is_array($user->roles) ? $user->roles : [];
        return in_array('contributor', $roles, true);
    }

    /**
     * Whether the current request is the configured member page.
     *
     * The dashboard template and its access gate only apply when
     * a member page is configured (a real published page). With
     * no member page the shortcode still renders inline wherever
     * it is placed, and the homepage is never gated — gating it
     * would lock the whole front page for guests.
     *
     * @return bool
     */
    public static function isMemberPage(): bool
    {
        $pageId = self::getSettings()['member_page_id'];
        if ($pageId <= 0) {
            return false;
        }
        return is_page($pageId);
    }

    /**
     * URL of the frontend member area.
     *
     * The configured member page, or the homepage when none is
     * configured (or the configured page is gone).
     *
     * @return string
     */
    public static function memberAreaUrl(): string
    {
        $pageId = self::getSettings()['member_page_id'];
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        $home = home_url('/');
        return is_string($home) ? $home : '/';
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * The form uses the shell's generic AJAX save endpoint (see
     * data-traydigita-module-form). The administrator role is
     * never rendered as a row: it cannot be configured at all.
     * New settings use the SettingsControls framework (title +
     * short description visible, long description behind a Help
     * toggle) like every other module.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        $settings = self::getSettings();
        $roles = wp_roles()->get_names();
        $modes = $settings['role_modes'];
        $shortcode = '[' . self::SHORTCODE . ']';
        $avifSupported = self::avifSupported();
        ?>
        <div class="traydigita-module-settings contributor-frontend-settings">
            <?php
            SettingsControls::intro(
                __('Contributors write and submit articles from a frontend '
                    . 'dashboard instead of wp-admin.', 'traydigita-core'),
                __('Pick an access mode per role below. Frontend-only users get no admin bar, and any wp-admin page they open redirects them to the member area. The member page is the dashboard: Overview, Posts, My Contributions, Write, Bookmarks, Profile and Change Email in one shell.', 'traydigita-core')
            );
            ?>
            <p class="contributor-frontend-settings-intro">
                <strong><?php esc_html_e('Administrators are never affected.', 'traydigita-core'); ?></strong>
                <?php
                SettingsControls::helpText(
                    '',
                    __('The administrator role is not listed here and cannot be restricted: administrators always keep the admin bar and full wp-admin access, no matter what is configured below.', 'traydigita-core')
                );
                ?>
            </p>
            <p class="contributor-frontend-settings-intro">
                <?php esc_html_e('Place this shortcode on the member page to show the dashboard:', 'traydigita-core'); ?>
                <code><?php echo esc_html($shortcode); ?></code>
            </p>

            <form class="contributor-frontend-settings-form" data-traydigita-module-form data-traydigita-module="contributor-frontend" data-module="contributor-frontend" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <div class="traydigita-admin-table-wrap">
                <table class="contributor-frontend-roles widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Role', 'traydigita-core'); ?></th>
                            <th><?php esc_html_e('Access', 'traydigita-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $slug => $label) : ?>
                            <?php if ($slug === self::ADMIN_ROLE) : ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <?php $mode = $modes[$slug] ?? self::MODE_DASHBOARD; ?>
                            <tr>
                                <td><?php echo esc_html(is_string($label) ? $label : $slug); ?></td>
                                <td>
                                    <select name="role_modes[<?php echo esc_attr($slug); ?>]">
                                        <option value="dashboard" <?php selected($mode, self::MODE_DASHBOARD); ?>><?php esc_html_e('Allow dashboard access', 'traydigita-core'); ?></option>
                                        <option value="frontend" <?php selected($mode, self::MODE_FRONTEND); ?>><?php esc_html_e('Frontend only', 'traydigita-core'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="contributor-frontend-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('Frontend only hides the admin bar and redirects wp-admin '
                            . 'to the member area.', 'traydigita-core'),
                        __('A user holding both a dashboard role and a frontend-only role keeps dashboard access.', 'traydigita-core')
                    );
                    ?>
                </p>

                <p class="contributor-frontend-field">
                    <label for="contributor-frontend-member-page"><?php esc_html_e('Member area page', 'traydigita-core'); ?></label><br>
                    <?php
                    wp_dropdown_pages([
                        'name' => 'member_page_id',
                        'id' => 'contributor-frontend-member-page',
                        'selected' => $settings['member_page_id'],
                        'show_option_none' => __('Homepage', 'traydigita-core'),
                        'option_none_value' => '0',
                    ]);
                    ?>
                    <span class="contributor-frontend-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Frontend-only users are redirected here when they open wp-admin.', 'traydigita-core'),
                            __('Put the editor shortcode on this page. The page becomes the member dashboard (Overview, Posts, My Contributions, Write, Bookmarks, Profile, Change Email) for every logged-in user: guests go to the login page, and sections inside are shown only when the user has the capability and the feature is available. When no page is picked, the homepage is used and no dashboard template is applied.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>
                <p class="contributor-frontend-field-help">
                    <?php
                    SettingsControls::helpText(
                        __('The redirect skips system and editor requests.', 'traydigita-core'),
                        __('The redirect never fires for admin-ajax.php, admin-post.php (the editor form handler), the user’s own profile page, REST requests, WP-CLI, cron, or the login and logout flows.', 'traydigita-core')
                    );
                    ?>
                </p>

                <?php
                SettingsControls::sectionOpen(
                    'editor',
                    __('Editor', 'traydigita-core'),
                    __('Block editor behaviour for the frontend dashboard.', 'traydigita-core'),
                    __('The editor only offers paragraph, heading, list and quote blocks. Anything else a contributor pastes in is stripped when the article is saved, so the allowlist is enforced on the server, never trusted from the browser.', 'traydigita-core')
                );
                SettingsControls::toggle([
                    'id' => 'contributor-frontend-allow-links',
                    'name' => 'allow_links',
                    'label' => __('Allow links in article text', 'traydigita-core'),
                    'checked' => $settings['allow_links'],
                    'help' => __('Contributors can add links to their article text.', 'traydigita-core'),
                    'help_long' => __('When off, the link button disappears from the editor and any link in a submitted article is stripped on save, keeping only the link text.', 'traydigita-core'),
                ]);
                SettingsControls::color([
                    'id' => 'contributor-frontend-editor-accent',
                    'name' => 'editor_accent',
                    'label' => __('Editor accent colour', 'traydigita-core'),
                    'value' => $settings['editor_accent'],
                    'help' => __('Buttons and the active state in the frontend editor.', 'traydigita-core'),
                    'help_long' => __('Defaults to the Elegant Blue accent #2563eb. The colour only affects the contributor dashboard editor, never the site theme.', 'traydigita-core'),
                ]);
                SettingsControls::color([
                    'id' => 'contributor-frontend-editor-paper',
                    'name' => 'editor_paper',
                    'label' => __('Editor paper colour', 'traydigita-core'),
                    'value' => $settings['editor_paper'],
                    'help' => __('Background of the editor canvas.', 'traydigita-core'),
                    'help_long' => __('Defaults to white. Dark mode on the reader’s device switches the dashboard to its dark palette instead; this colour is the light-mode canvas.', 'traydigita-core'),
                ]);
                SettingsControls::color([
                    'id' => 'contributor-frontend-editor-text',
                    'name' => 'editor_text',
                    'label' => __('Editor text colour', 'traydigita-core'),
                    'value' => $settings['editor_text'],
                    'help' => __('Text colour inside the editor canvas.', 'traydigita-core'),
                    'help_long' => __('Defaults to slate #1e293b. It only affects the contributor dashboard editor, never the site theme.', 'traydigita-core'),
                ]);
                SettingsControls::checkboxGroup([
                    'id' => 'contributor-frontend-allowed-blocks',
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
                    'id' => 'contributor-frontend-upload-max-mb',
                    'name' => 'upload_max_mb',
                    'label' => __('Maximum file size (MB)', 'traydigita-core'),
                    'value' => $settings['upload_max_mb'],
                    'help' => __('Largest featured image a contributor may upload.', 'traydigita-core'),
                    'help_long' => __('Between 1 and 10 MB. Larger files are refused with a clear error before anything is stored.', 'traydigita-core'),
                    'attrs' => ['min' => '1', 'max' => '10', 'step' => '1'],
                ]);
                SettingsControls::number([
                    'id' => 'contributor-frontend-upload-max-px',
                    'name' => 'upload_max_px',
                    'label' => __('Maximum dimension (px)', 'traydigita-core'),
                    'value' => $settings['upload_max_px'],
                    'help' => __('Longest side, in pixels, of an uploaded featured image.', 'traydigita-core'),
                    'help_long' => __('Between 600 and 4000 px. Images wider or taller than this are refused with a clear error; they are never silently downscaled.', 'traydigita-core'),
                    'attrs' => ['min' => '600', 'max' => '4000', 'step' => '100'],
                ]);
                SettingsControls::checkboxGroup([
                    'id' => 'contributor-frontend-allowed-types',
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

                $sectionOptions = [];
                foreach (self::memberSections() as $section) {
                    $sectionOptions[(string) $section['id']] = (string) $section['label'];
                }
                SettingsControls::sectionOpen(
                    'member-area',
                    __('Member area', 'traydigita-core'),
                    __('Dashboard sections and email changes.', 'traydigita-core'),
                    __('Sections are registered through the traydigita:core:member/sections filter and managed here: tick a section to hide it from the dashboard, and list section ids in order to reorder them. A hidden section is never reachable by URL either — the dashboard redirects to Overview. Change Email only appears when the toggle below is on; users who can access wp-admin can always change their email from profile.php instead.', 'traydigita-core')
                );
                SettingsControls::toggle([
                    'id' => 'contributor-frontend-allow-email-change',
                    'name' => 'allow_email_change',
                    'label' => __('Allow email change in the dashboard', 'traydigita-core'),
                    'checked' => $settings['allow_email_change'],
                    'help' => __('Members may change their own email from the dashboard.', 'traydigita-core'),
                    'help_long' => __('When on, the Change Email section appears in the dashboard and the new address is confirmed through the email WordPress sends. When off, the section is hidden entirely; users who can access wp-admin can still change their email from profile.php.', 'traydigita-core'),
                ]);
                SettingsControls::checkboxGroup([
                    'id' => 'contributor-frontend-sections-disabled',
                    'name' => 'sections_disabled[]',
                    'label' => __('Hidden sections', 'traydigita-core'),
                    'options' => $sectionOptions,
                    'checked' => $settings['sections_disabled'],
                    'help' => __('Tick a section to hide it from the dashboard.', 'traydigita-core'),
                    'help_long' => __('A hidden section disappears from the sidebar and its URL redirects to Overview. Sections a user may not use (for example Write for non-contributors) are already hidden by capability and need no ticking here.', 'traydigita-core'),
                ]);
                SettingsControls::input([
                    'id' => 'contributor-frontend-section-order',
                    'name' => 'section_order',
                    'label' => __('Section order', 'traydigita-core'),
                    'value' => implode(',', $settings['section_order']),
                    'help' => __('Comma-separated section ids, in display order.', 'traydigita-core'),
                    'help_long' => __('For example: overview,posts,contributions,write,bookmark,profile,change-email. Sections left out keep their registered order after the listed ones. Unknown ids are ignored.', 'traydigita-core'),
                    'mono' => true,
                ]);
                SettingsControls::sectionClose();
                ?>

                <p class="contributor-frontend-form-actions">
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
     * Re-sanitizes every field for its own type: role modes are
     * kept only for roles that exist (never `administrator`),
     * with values restricted to the mode allowlist; the member
     * page must be a published page or it falls back to 0;
     * colours must be `#rrggbb`; numbers are clamped to their
     * ranges; block and type lists are intersected with the
     * known lists.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change contributor settings.', 'traydigita-core'),
            ];
        }
        $modes = [];
        $validRoles = self::configurableRoles();
        $rawModes = $data['role_modes'] ?? [];
        if (is_array($rawModes)) {
            foreach ($rawModes as $role => $mode) {
                if (!is_string($role) || !is_string($mode)) {
                    continue;
                }
                $slug = sanitize_key($role);
                if (!in_array($slug, $validRoles, true)) {
                    continue;
                }
                if (!in_array($mode, self::MODES, true)) {
                    continue;
                }
                $modes[$slug] = $mode;
            }
        }
        $pageId = absint($data['member_page_id'] ?? 0);
        if ($pageId > 0) {
            $page = get_post($pageId);
            if ($page === null || $page->post_type !== 'page' || $page->post_status !== 'publish') {
                $pageId = 0;
            }
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
        $sectionIds = array_map(
            static fn (array $section): string => (string) $section['id'],
            self::memberSections()
        );
        $disabled = [];
        $rawDisabled = $data['sections_disabled'] ?? [];
        if (is_array($rawDisabled)) {
            foreach ($rawDisabled as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $id = sanitize_key($id);
                if (in_array($id, $sectionIds, true) && !in_array($id, $disabled, true)) {
                    $disabled[] = $id;
                }
            }
        }
        $order = [];
        $rawOrder = $data['section_order'] ?? '';
        if (is_string($rawOrder) && $rawOrder !== '') {
            foreach (explode(',', $rawOrder) as $id) {
                $id = sanitize_key(trim($id));
                if ($id !== '' && in_array($id, $sectionIds, true) && !in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        update_option(self::OPTION, [
            'role_modes' => $modes,
            'member_page_id' => $pageId,
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
            'allow_email_change' => !empty($data['allow_email_change']),
            'sections_disabled' => $disabled,
            'section_order' => $order,
        ], false);
        return [
            'success' => true,
            'message' => __('Contributor settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. The shortcode and the admin-post handlers are
     * registered here; the access hooks only bite for users the
     * settings mark frontend-only, and never for administrators.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_action('init', [self::class, 'registerPostStatuses']);
        add_filter('show_admin_bar', [self::class, 'filterShowAdminBar']);
        add_action('admin_init', [self::class, 'maybeRedirectAdmin']);
        add_action('wp_enqueue_scripts', [self::class, 'maybeStripVariantStyles'], 100);
        add_shortcode(self::SHORTCODE, [ContributorFrontendEditor::class, 'shortcode']);
        add_action('admin_post_' . self::SAVE_ACTION, [ContributorFrontendEditor::class, 'handleSave']);
        add_action('admin_post_nopriv_' . self::SAVE_ACTION, [ContributorFrontendEditor::class, 'handleSave']);
        add_action('admin_post_' . self::DELETE_ACTION, [ContributorFrontendEditor::class, 'handleDelete']);
        add_action('admin_post_nopriv_' . self::DELETE_ACTION, [ContributorFrontendEditor::class, 'handleDelete']);
        add_action('admin_post_' . self::RESTORE_ACTION, [ContributorFrontendEditor::class, 'handleRestore']);
        add_action('admin_post_nopriv_' . self::RESTORE_ACTION, [ContributorFrontendEditor::class, 'handleRestore']);
        add_action(
            'admin_post_' . self::PERMANENT_DELETE_ACTION,
            [ContributorFrontendEditor::class, 'handlePermanentDelete']
        );
        add_action(
            'admin_post_nopriv_' . self::PERMANENT_DELETE_ACTION,
            [ContributorFrontendEditor::class, 'handlePermanentDelete']
        );
        add_action('admin_post_' . self::PROFILE_ACTION, [ContributorFrontendEditor::class, 'handleProfile']);
        add_action('admin_post_nopriv_' . self::PROFILE_ACTION, [ContributorFrontendEditor::class, 'handleProfile']);
        add_action('admin_post_' . self::EMAIL_ACTION, [ContributorFrontendEditor::class, 'handleEmail']);
        add_action('admin_post_nopriv_' . self::EMAIL_ACTION, [ContributorFrontendEditor::class, 'handleEmail']);
        add_action('transition_post_status', [self::class, 'recordStatusChange'], 10, 3);
        add_filter('quick_edit_statuses', [self::class, 'quickEditStatuses'], 10, 4);
        add_action('wp_ajax_' . self::FEATURED_UPLOAD_ACTION, [ContributorFrontendUpload::class, 'handleUpload']);
        add_action('wp_ajax_' . self::FEATURED_REMOVE_ACTION, [ContributorFrontendUpload::class, 'handleRemove']);
        add_filter('template_include', [self::class, 'templateInclude']);
        add_filter('wp_insert_post_data', [self::class, 'enforceEditorialStatus'], 10, 2);
        add_action('add_meta_boxes', [self::class, 'addRevisionNoteBox']);
        add_action('save_post', [self::class, 'saveRevisionNote'], 10, 2);
    }

    /**
     * Register the custom post statuses.
     *
     * Both are non-public and excluded from search, but visible
     * in wp-admin: they appear in the post-list status filters
     * and in quick edit, so editors can set them there. They are
     * also exposed in the REST API so the block editor can read
     * them. Only editors and administrators may SET them — see
     * enforceEditorialStatus(), which strips them from any save
     * by a user without `edit_others_posts`.
     *
     * @return void
     */
    public static function registerPostStatuses(): void
    {
        register_post_status(self::STATUS_NEEDS_REVISION, [
            'label' => _x('Needs Revision', 'post status', 'traydigita-core'),
            'label_count' => _n_noop(
                'Needs Revision <span class="count">(%s)</span>',
                'Needs Revision <span class="count">(%s)</span>',
                'traydigita-core'
            ),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'show_in_rest' => true,
        ]);
        register_post_status(self::STATUS_REJECTED, [
            'label' => _x('Rejected', 'post status', 'traydigita-core'),
            'label_count' => _n_noop(
                'Rejected <span class="count">(%s)</span>',
                'Rejected <span class="count">(%s)</span>',
                'traydigita-core'
            ),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'show_in_rest' => true,
        ]);
    }

    /**
     * Keep the editorial statuses out of contributors' hands.
     *
     * Runs on every post save (admin, quick edit, REST,
     * Gutenberg): when a user without `edit_others_posts`
     * tries to set `needs-revision` or `rejected`, the status
     * is replaced with the post's current status (on update) or
     * `draft` (on create). Editors and administrators pass
     * through untouched.
     *
     * @param array<string, mixed> $data Sanitized post data about to be saved.
     * @param array<string, mixed> $postarr Raw post data, including the id on update.
     * @return array<string, mixed>
     */
    public static function enforceEditorialStatus(array $data, array $postarr): array
    {
        if (($data['post_type'] ?? '') !== 'post') {
            return $data;
        }
        $status = $data['post_status'] ?? '';
        if (!is_string($status) || !in_array($status, self::EDITORIAL_STATUSES, true)) {
            return $data;
        }
        if (current_user_can(self::EDITORIAL_CAPABILITY)) {
            return $data;
        }
        if (!empty($postarr['ID'])) {
            $existing = get_post((int) $postarr['ID']);
            if ($existing !== null && !in_array($existing->post_status, self::EDITORIAL_STATUSES, true)) {
                $data['post_status'] = $existing->post_status;
                return $data;
            }
        }
        $data['post_status'] = 'draft';
        return $data;
    }

    /**
     * Offer the editorial statuses in Quick Edit and Bulk Edit.
     *
     * WordPress core's Quick Edit status dropdown only lists
     * the built-in statuses; custom statuses need the
     * `quick_edit_statuses` filter (since WP 6.9) to appear.
     * Only editors and administrators are offered them —
     * contributors never see these options, and
     * enforceEditorialStatus() still strips them from any save
     * by a user without `edit_others_posts`, so the dropdown
     * can never be used to smuggle a status past the server.
     *
     * @param array<string, string> $statuses Statuses core offers.
     * @param string $postType Post type the list is for.
     * @param bool $bulk Whether this is the Bulk Edit dropdown.
     * @param bool $canPublish Whether the user can publish posts.
     * @return array<string, string>
     */
    public static function quickEditStatuses(array $statuses, string $postType, bool $bulk, bool $canPublish): array
    {
        if ($postType !== 'post' || !current_user_can(self::EDITORIAL_CAPABILITY)) {
            return $statuses;
        }
        $statuses[self::STATUS_NEEDS_REVISION] = _x('Needs Revision', 'post status', 'traydigita-core');
        $statuses[self::STATUS_REJECTED] = _x('Rejected', 'post status', 'traydigita-core');
        return $statuses;
    }

    /**
     * Log editorial status changes for the post author.
     *
     * Hooked on `transition_post_status`. Only transitions
     * into the statuses the dashboard shows are recorded
     * (pending, needs-revision, rejected, publish), and only
     * for posts — the Activity Log module is the notification
     * source for the dashboard Overview (see
     * ContributorFrontendEditor::fetchNotifications()). The
     * call is a no-op when the Activity Log module is disabled
     * (ActivityLogModule::record() checks that itself).
     *
     * @param string $newStatus Status the post moved to.
     * @param string $oldStatus Status the post moved from.
     * @param \WP_Post $post Post whose status changed.
     * @return void
     */
    public static function recordStatusChange(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($post->post_type !== 'post') {
            return;
        }
        if ($newStatus === $oldStatus) {
            return;
        }
        $watched = ['pending', self::STATUS_NEEDS_REVISION, self::STATUS_REJECTED, 'publish'];
        if (!in_array($newStatus, $watched, true)) {
            return;
        }
        $title = get_the_title($post);
        if ($title === '') {
            $title = sprintf(
                /* translators: %d: post id. */
                __('Post #%d', 'traydigita-core'),
                $post->ID
            );
        }
        $message = match ($newStatus) {
            'pending' => sprintf(
                /* translators: %s: post title. */
                __('Your post "%s" was sent for review.', 'traydigita-core'),
                $title
            ),
            self::STATUS_NEEDS_REVISION => sprintf(
                /* translators: %s: post title. */
                __('Your post "%s" needs revision.', 'traydigita-core'),
                $title
            ),
            self::STATUS_REJECTED => sprintf(
                /* translators: %s: post title. */
                __('Your post "%s" was rejected.', 'traydigita-core'),
                $title
            ),
            default => sprintf(
                /* translators: %s: post title. */
                __('Your post "%s" was published.', 'traydigita-core'),
                $title
            ),
        };
        ActivityLogModule::record(
            self::MODULE_ID,
            'status-changed',
            $message,
            (int) $post->post_author,
            ['post_id' => $post->ID, 'status' => $newStatus]
        );
    }

    /**
     * Add the revision-note meta box to the post edit screen.
     *
     * Only shown to users who may set the editorial statuses
     * (editors and administrators): the note is what the author
     * reads when a post comes back as needs-revision.
     *
     * @return void
     */
    public static function addRevisionNoteBox(): void
    {
        if (!current_user_can(self::EDITORIAL_CAPABILITY)) {
            return;
        }
        add_meta_box(
            'traydigita-revision-note',
            __('Revision note for the author', 'traydigita-core'),
            [self::class, 'renderRevisionNoteBox'],
            'post',
            'side',
            'default'
        );
    }

    /**
     * Render the revision-note meta box.
     *
     * @param \WP_Post $post Post being edited.
     * @return void
     */
    public static function renderRevisionNoteBox(\WP_Post $post): void
    {
        // Named nonce field, NOT the default `_wpnonce`: the edit
        // form already carries its own `_wpnonce` (the
        // `update-post_{id}` nonce), and a second field with the
        // same name would overwrite it in $_POST and make core
        // reject the whole save with a 403.
        wp_nonce_field(self::REVISION_NOTE_NONCE_ACTION, 'traydigita_revision_note_nonce');
        $note = get_post_meta($post->ID, self::REVISION_NOTE_META, true);
        ?>
        <p>
            <label for="traydigita-revision-note"><?php esc_html_e('Note shown to the author when the post needs revision.', 'traydigita-core'); ?></label>
        </p>
        <textarea id="traydigita-revision-note" name="traydigita_revision_note" rows="4" class="widefat"><?php echo esc_textarea(is_string($note) ? $note : ''); ?></textarea>
        <?php
    }

    /**
     * Save the revision note from the post edit screen.
     *
     * Only editors and administrators may save it; the nonce,
     * autosaves and revisions are all rejected first.
     *
     * @param int $postId Post id being saved.
     * @param \WP_Post $post Post object being saved.
     * @return void
     */
    public static function saveRevisionNote(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'post') {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        if (!current_user_can(self::EDITORIAL_CAPABILITY, $postId)) {
            return;
        }
        $nonce = isset($_POST['traydigita_revision_note_nonce'])
            && is_string($_POST['traydigita_revision_note_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_revision_note_nonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::REVISION_NOTE_NONCE_ACTION)) {
            return;
        }
        if (!isset($_POST['traydigita_revision_note'])) {
            return;
        }
        $note = sanitize_textarea_field(wp_unslash((string) $_POST['traydigita_revision_note']));
        if ($note === '') {
            delete_post_meta($postId, self::REVISION_NOTE_META);
            return;
        }
        update_post_meta($postId, self::REVISION_NOTE_META, $note);
    }

    /**
     * Serve the contributor dashboard template for the member page.
     *
     * Hooked on `template_include`. The gate runs first: guests
     * go to wp-login (see dashboardGateRedirect()). Every
     * logged-in user may use the dashboard shell — sections
     * inside it are gated by capability and feature
     * availability, and only the Write section is
     * contributor-only (non-contributors hitting it are
     * redirected to wp-admin by the shortcode). The template
     * itself is a standalone shell: no theme masthead,
     * sidebars or bottom tab bar, and no theme style variant.
     *
     * @param string $template Template WordPress selected.
     * @return string Template path to load.
     */
    public static function templateInclude(string $template): string
    {
        if (!self::isMemberPage()) {
            return $template;
        }
        $redirect = self::dashboardGateRedirect();
        if ($redirect !== '') {
            wp_safe_redirect($redirect);
            exit;
        }
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/contributor/dashboard.php';
        return file_exists($file) ? $file : $template;
    }

    /**
     * Where a member-page visitor who may not use the dashboard
     * is sent, or an empty string when they may stay.
     *
     * Guests go to wp-login (back to the member area after
     * login). Every logged-in user may use the dashboard shell:
     * sections inside it are gated by capability and feature
     * availability, so there is nothing to redirect a logged-in
     * user away from at the gate. The contributor-only rule
     * lives on the Write section (see
     * ContributorFrontendEditor::resolveView()).
     *
     * @return string Redirect URL, or '' when the visitor may use the dashboard.
     */
    public static function dashboardGateRedirect(): string
    {
        if (!is_user_logged_in()) {
            return wp_login_url(self::memberAreaUrl());
        }
        return '';
    }

    /**
     * Hide the admin bar on the member page and for
     * frontend-only users.
     *
     * Administrators are never affected (see
     * isFrontendOnlyUser()): the check runs first inside it.
     * The member page hides the bar for every dashboard user —
     * the dashboard has its own top bar.
     *
     * @param bool $show Whether the admin bar would show.
     * @return bool
     */
    public static function filterShowAdminBar(bool $show): bool
    {
        if (self::isFrontendOnlyUser()) {
            return false;
        }
        if (self::isMemberPage()) {
            return false;
        }
        return $show;
    }

    /**
     * Drop the theme style variant on the member page.
     *
     * The dashboard is theme-independent: it uses its own
     * visual language and ignores the active style variant.
     * The base app stylesheet stays (it carries the dashboard
     * styles and the Tailwind preflight), only the variant
     * stylesheet is dequeued.
     *
     * @return void
     */
    public static function maybeStripVariantStyles(): void
    {
        if (!self::isMemberPage()) {
            return;
        }
        global $wp_styles;
        if (!isset($wp_styles) || !is_object($wp_styles)) {
            return;
        }
        $registered = $wp_styles->registered ?? [];
        if (!is_array($registered)) {
            return;
        }
        foreach (array_keys($registered) as $handle) {
            if (!is_string($handle)) {
                continue;
            }
            if (str_starts_with($handle, 'traydigita-theme-style-')) {
                wp_dequeue_style($handle);
            }
        }
    }

    /**
     * Redirect frontend-only users away from wp-admin.
     *
     * Runs on `admin_init`, which only fires inside wp-admin,
     * so the frontend can never trigger it. Never fires for
     * administrators (hard rule in isFrontendOnlyUser()),
     * admin-ajax.php, admin-post.php (the editor's own form
     * handler — blocking it would break every submission),
     * the user's own profile page (password and 2FA management
     * live there), REST requests, WP-CLI, or cron. The target
     * is always a frontend page, so a redirect loop is
     * impossible; the login and logout flows never reach
     * `admin_init` at all.
     *
     * @return void
     */
    public static function maybeRedirectAdmin(): void
    {
        if (!is_user_logged_in()) {
            return;
        }
        if (!self::isFrontendOnlyUser()) {
            return;
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        global $pagenow;
        if (is_string($pagenow) && in_array($pagenow, ['admin-post.php', 'admin-ajax.php', 'profile.php'], true)) {
            return;
        }
        $url = add_query_arg('traydigita_no_dashboard', '1', self::memberAreaUrl());
        wp_safe_redirect($url);
        exit;
    }
}
