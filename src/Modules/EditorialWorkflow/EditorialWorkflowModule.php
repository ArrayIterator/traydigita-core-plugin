<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\EditorialWorkflow;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use function __;
use function _n_noop;
use function _x;
use function add_action;
use function add_filter;
use function add_meta_box;
use function current_user_can;
use function delete_post_meta;
use function esc_html__;
use function esc_textarea;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function in_array;
use function is_string;
use function register_post_status;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sprintf;
use function update_post_meta;
use function wp_die;
use function wp_is_post_autosave;
use function wp_is_post_revision;
use function wp_nonce_field;
use function wp_unslash;
use function wp_verify_nonce;

/**
 * Editorial Workflow module.
 *
 * The admin-side editorial workflow for posts: the custom
 * `needs-revision` and `rejected` statuses, the capability
 * guard that keeps them out of contributors' hands, the Quick
 * Edit / Bulk Edit status offers, and the revision-note meta
 * box editors use to tell the author what to fix.
 *
 * Split out of the Contributor Frontend Editor module
 * (SHOULD-4, 2026-11-24): the workflow lives in wp-admin and
 * works with the frontend editor disabled — editors keep
 * sending posts back for revision and rejecting them either
 * way. Disabling this module removes the statuses (posts in
 * them keep their stored status value; the dashboard lists
 * them through its own status filter) and the revision-note
 * box.
 *
 * OUT OF SCOPE (later security+membership round): restoring a
 * trashed rejected / needs-revision post returns it to draft
 * instead of its previous status (O-1), and the revision note
 * cannot be entered from Quick Edit (O-2).
 *
 * This module has no settings of its own: the settings page
 * only explains the workflow.
 */
final class EditorialWorkflowModule implements ModuleInterface
{
    /**
     * Module id used in the `traydigita_modules` option and AJAX calls.
     */
    public const string MODULE_ID = 'editorial-workflow';

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
     * Nonce field name for the revision-note meta box.
     */
    public const string REVISION_NOTE_NONCE_ACTION = 'traydigita_revision_note_save';

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
        return __('Editorial Workflow', 'traydigita-core');
    }

    /**
     * Module description for the module grid card.
     *
     * @return string
     */
    public static function description(): string
    {
        return __(
            'Editorial workflow in wp-admin: needs-revision and rejected post statuses only editors may set, with a revision-note box telling the author what to fix.',
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
        return 'edit-page';
    }

    /**
     * Module version.
     *
     * 1.0.0 splits the editorial workflow out of the
     * Contributor Frontend Editor module (SHOULD-4).
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
            __('Needs-revision and rejected post statuses.', 'traydigita-core'),
            __('Contributors cannot set editorial statuses.', 'traydigita-core'),
            __('Revision note shown to the author.', 'traydigita-core'),
            __('Works without the frontend editor.', 'traydigita-core'),
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
        return 'traydigita-editorial-workflow';
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
     * The workflow has no settings, so the module is always
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
     * The workflow has no settings: the page only explains what
     * the module does. There is no form to save.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to access this page.', 'traydigita-core'));
        }
        ?>
        <div class="traydigita-module-settings editorial-workflow-settings">
            <?php
            SettingsControls::intro(
                __('Editorial workflow for posts, in wp-admin.', 'traydigita-core'),
                __('Editors send a post back with the needs-revision status and a note telling the author what to fix, or reject it outright. Contributors can never set these statuses themselves: any save that tries is forced back to the post’s current status (or draft for a new post). The statuses appear in the post list, in Quick Edit and Bulk Edit, and in the block editor.', 'traydigita-core')
            );
            ?>
            <p class="editorial-workflow-settings-note">
                <?php esc_html_e('This module has no settings. Disable it to remove the statuses and the revision-note box from wp-admin.', 'traydigita-core'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Save the module settings from already deep-sanitized data.
     *
     * The workflow has no settings, so there is nothing to
     * save; the call still succeeds so the admin shell can
     * confirm the page.
     *
     * @param array<string, mixed> $data Submitted data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change editorial workflow settings.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('Editorial workflow settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the admin hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        add_action('init', [self::class, 'registerPostStatuses']);
        add_action('transition_post_status', [self::class, 'recordStatusChange'], 10, 3);
        add_filter('quick_edit_statuses', [self::class, 'quickEditStatuses'], 10, 4);
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
     * MemberDashboard::fetchNotifications()). The
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
}
