<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\MemberArea;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Customizer\Settings;
use TrayDigita\WP\Plugin\Core\Modules\EditorialWorkflow\EditorialWorkflowModule;
use TrayDigita\WP\Plugin\Core\Modules\FrontendEditor\FrontendEditor;
use TrayDigita\WP\Plugin\Core\Modules\FrontendEditor\FrontendEditorModule;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use function __;
use function absint;
use function add_query_arg;
use function admin_url;
use function array_map;
use function array_merge;
use function array_slice;
use function ceil;
use function count;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_current_user_id;
use function get_permalink;
use function get_post;
use function get_post_status_object;
use function get_post_thumbnail_id;
use function get_posts;
use function get_preview_post_link;
use function get_the_category;
use function get_the_date;
use function get_theme_mod;
use function get_userdata;
use function home_url;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_string;
use function is_user_logged_in;
use function json_decode;
use function number_format_i18n;
use function sanitize_text_field;
use function sprintf;
use function trim;
use function usort;
use function wp_count_posts;
use function wp_get_attachment_image_src;
use function wp_login_url;
use function wp_logout_url;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_unslash;

/**
 * Frontend member dashboard shell.
 *
 * Renders the `[traydigita_contributor_editor]` shortcode (see
 * MemberAreaModule::SHORTCODE): one unified dashboard shell
 * (sidebar + top bar) for every logged-in user, with sections from
 * the member registry (see MemberAreaModule::memberSections()):
 * Overview, Posts, My Contributions, plus sections other modules
 * register through the `traydigita:core:member/sections` filter
 * (Write from the Frontend Editor, Bookmarks, Profile and Change
 * Email). Guests see a login prompt. Every section renders only
 * when the user has the capability for it AND the underlying
 * feature is available; a hidden menu never means an accessible
 * URL - the view resolver redirects server-side.
 *
 * This class owns the shell and the built-in list views only.
 * The Write view lives in FrontendEditor, Profile and Change
 * Email in MemberProfile, and Bookmarks in BookmarksModule; each
 * plugs its section in through the sections filter with its own
 * render callable, so this shell never calls another module's
 * renderer directly.
 *
 * The status matrix, enforced here and in the editor module:
 *
 * - draft: edit + trash.
 * - pending (in review): read-only + preview.
 * - needs-revision: edit + resubmit (the editor's note shows
 *   when one exists).
 * - published (and scheduled): read-only + view live.
 * - rejected: read-only + preview + trash.
 * - trash: restore + delete permanently.
 */
final class MemberDashboard
{
    /**
     * Posts per list page.
     */
    private const int PER_PAGE = 20;

    /**
     * Non-trash statuses of the author's own posts.
     *
     * @var array<int, string>
     */
    private const array OWN_STATUSES = [
        'draft',
        'pending',
        'needs-revision',
        'publish',
        'future',
        'rejected',
    ];

    /**
     * Cached statistics rows for the current request, so the
     * menu check and the Overview share one collector call.
     * Null means not fetched yet.
     *
     * @var array{total: int, rows: array<int, array{post: \WP_Post, views: int}>}|null
     */
    private static ?array $statsCache = null;

    /**
     * Shortcode `[traydigita_contributor_editor]`.
     *
     * Guests see a login prompt; every logged-in user sees the
     * dashboard shell (sections gate themselves by capability
     * and feature availability).
     *
     * @param array<string, mixed> $atts Shortcode attributes (unused).
     * @return string
     */
    public static function shortcode(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return self::renderLoginPrompt();
        }
        return self::renderDashboard();
    }

    /**
     * Login prompt for guests, wrapped in the editor container.
     *
     * Shared with the Frontend Editor's standalone shortcode
     * (member area disabled), so both surfaces show the same
     * prompt.
     *
     * @return string
     */
    public static function renderLoginPrompt(): string
    {
        return self::renderNotice(
            'info',
            sprintf(
                /* translators: %s: login URL. */
                __(
                    'Please <a href="%s">log in</a> to use the member area.',
                    'traydigita-core'
                ),
                esc_url(wp_login_url(MemberAreaModule::currentPageUrl()))
            ),
            true
        );
    }

    /**
     * Saved / error / info notices from the query string.
     *
     * Shared with the Frontend Editor's standalone shortcode
     * (member area disabled), so a save that redirects back to
     * an editor-only page still shows its result. Unknown codes
     * render nothing.
     *
     * @return string
     */
    public static function renderNotices(): string
    {
        $html = '';
        if (isset($_GET[MemberAreaModule::SAVED_FLAG]) && is_string($_GET[MemberAreaModule::SAVED_FLAG])) {
            $message = self::savedMessage(sanitize_text_field(wp_unslash($_GET[MemberAreaModule::SAVED_FLAG])));
            if ($message !== '') {
                $html .= self::renderNotice('success', $message);
            }
        }
        if (isset($_GET[MemberAreaModule::ERROR_FLAG]) && is_string($_GET[MemberAreaModule::ERROR_FLAG])) {
            $message = self::errorMessage(sanitize_text_field(wp_unslash($_GET[MemberAreaModule::ERROR_FLAG])));
            if ($message !== '') {
                $html .= self::renderNotice('error', $message);
            }
        }
        if (isset($_GET[MemberAreaModule::NOTICE_FLAG]) && is_string($_GET[MemberAreaModule::NOTICE_FLAG])) {
            $message = self::noticeMessage(sanitize_text_field(wp_unslash($_GET[MemberAreaModule::NOTICE_FLAG])));
            if ($message !== '') {
                $html .= self::renderNotice('info', $message);
            }
        }
        return $html;
    }

    /**
     * URL of the "Write new" destination for the current user.
     *
     * Dashboard-mode users (the default for unconfigured roles,
     * and always for administrators) go to wp-admin
     * post-new.php - the full Gutenberg editor. Frontend-only
     * users open the frontend block editor: sending them to
     * wp-admin would only bounce them straight back to the
     * member area.
     *
     * @return string
     */
    public static function writeUrl(): string
    {
        if (RoleAccessModule::isFrontendOnlyUser()) {
            return MemberAreaModule::viewUrl('write');
        }
        return admin_url('post-new.php');
    }

    /**
     * URL for editing one post, or '' when there is no edit
     * destination for the current user.
     *
     * Dashboard-mode users edit in wp-admin (post.php); the
     * frontend editor is only a destination for frontend-only
     * users editing their own draft or needs-revision post -
     * there is no frontend way to edit anyone else's post, so
     * no Edit link renders for those.
     *
     * @param \WP_Post $post Post to edit.
     * @return string Edit URL, or ''.
     */
    private static function editUrl(\WP_Post $post): string
    {
        if (!current_user_can('edit_posts')) {
            return '';
        }
        $userId = get_current_user_id();
        $own = (int) $post->post_author === $userId;
        if (!$own && !current_user_can(EditorialWorkflowModule::EDITORIAL_CAPABILITY)) {
            return '';
        }
        if (RoleAccessModule::isFrontendOnlyUser()) {
            if (self::canUseFrontendEditor() && $own && in_array($post->post_status, FrontendEditorModule::EDITABLE_STATUSES, true)) {
                return MemberAreaModule::viewUrl('write', [MemberAreaModule::EDIT_FLAG => $post->ID]);
            }
            return '';
        }
        return admin_url('post.php?post=' . $post->ID . '&action=edit');
    }

    /**
     * Whether the frontend block editor is available to the
     * current user as a write destination.
     *
     * Dashboard-mode users write in wp-admin regardless of
     * the frontend-editor module; frontend-only users need
     * the module enabled, otherwise their Write/Edit links
     * would point at a section that no longer exists.
     *
     * @return bool
     */
    private static function canUseFrontendEditor(): bool
    {
        return !RoleAccessModule::isFrontendOnlyUser()
            || ModuleRegistry::isEnabled(FrontendEditorModule::MODULE_ID);
    }

    /**
     * Resolve the requested dashboard section, enforcing the
     * menu-availability rule server-side.
     *
     * Unknown sections fall back to Overview. A disabled
     * section redirects to Overview with a notice. The Write
     * section is frontend-only: a dashboard-mode user (or an
     * administrator or editor) asking for it goes to wp-admin
     * post-new.php instead of the frontend editor - the menu
     * already links them there, and a direct
     * `?traydigita_view=write` URL must not serve the
     * frontend editor to them. A logged-in non-contributor
     * who is frontend-only is redirected to wp-admin (their
     * profile page, since the dashboard would bounce them
     * straight back). A hidden menu never means an accessible
     * URL.
     *
     * @return string Section id.
     */
    private static function resolveView(): string
    {
        $sections = MemberAreaModule::enabledSections();
        $ids = array_map(static fn (array $section): string => (string) $section['id'], $sections);
        $view = isset($_GET[MemberAreaModule::VIEW_FLAG]) && is_string($_GET[MemberAreaModule::VIEW_FLAG])
            ? sanitize_text_field(wp_unslash($_GET[MemberAreaModule::VIEW_FLAG]))
            : 'overview';
        if (isset($_GET[MemberAreaModule::EDIT_FLAG])) {
            $view = 'write';
        }
        if ($view === 'write' && !RoleAccessModule::isFrontendOnlyUser()) {
            wp_safe_redirect(admin_url('post-new.php'));
            exit;
        }
        if (!in_array($view, $ids, true)) {
            if ($view === 'stats') {
                // The old Statistics section merged into Overview.
                return 'overview';
            }
            if ($view === 'write' && !RoleAccessModule::isDashboardUser()) {
                self::redirectToAdmin();
            }
            return 'overview';
        }
        $section = null;
        foreach ($sections as $candidate) {
            if ((string) $candidate['id'] === $view) {
                $section = $candidate;
                break;
            }
        }
        if ($section === null) {
            return 'overview';
        }
        if (!MemberAreaModule::sectionVisible($section)) {
            if ($view === 'write') {
                self::redirectToAdmin();
            }
            wp_safe_redirect(add_query_arg(
                [MemberAreaModule::VIEW_FLAG => 'overview', MemberAreaModule::NOTICE_FLAG => 'section-disabled'],
                MemberAreaModule::currentPageUrl()
            ));
            exit;
        }
        return $view;
    }

    /**
     * Redirect a user who may not use the editor to wp-admin.
     *
     * Frontend-only users go to their profile page instead of
     * the dashboard: maybeRedirectAdmin() would bounce them
     * straight back from it, and profile.php is the one
     * wp-admin page the redirect exempts.
     *
     * @return void
     */
    private static function redirectToAdmin(): void
    {
        if (RoleAccessModule::isFrontendOnlyUser()) {
            wp_safe_redirect(admin_url('profile.php'));
            exit;
        }
        wp_safe_redirect(admin_url());
        exit;
    }

    /**
     * Menu items for the dashboard sidebar.
     *
     * Built from the enabled sections, each kept only when the
     * user has the capability for it AND its feature is
     * available. The Write item follows the Tulis Baru
     * destination rule (wp-admin post-new.php for
     * dashboard-mode users, the frontend editor for
     * frontend-only users).
     *
     * @param string $active Active section id.
     * @return array<int, array{id: string, label: string, url: string, active: bool}>
     */
    private static function menuItems(string $active): array
    {
        $items = [];
        foreach (MemberAreaModule::enabledSections() as $section) {
            if (!MemberAreaModule::sectionVisible($section)) {
                continue;
            }
            $id = (string) $section['id'];
            $url = $id === 'write' ? self::writeUrl() : MemberAreaModule::viewUrl($id);
            $items[] = [
                'id' => $id,
                'label' => (string) $section['label'],
                'url' => $url,
                'active' => $active === $id,
            ];
        }
        return $items;
    }

    /**
     * Whether the views statistics block is available for the
     * current user.
     *
     * True only when the traydigita-stack analytics collector
     * exists AND the user has at least one recorded view across
     * their published posts in the last 30 days. No data means
     * no statistics block - numbers are never invented.
     *
     * @return bool
     */
    public static function statsAvailable(): bool
    {
        return self::fetchStats()['total'] > 0;
    }

    /**
     * Fetch the current user's published-post view counts.
     *
     * Reads the last 30 days from the traydigita-stack
     * collector when it is available, and keeps only the
     * current user's own published posts. Returns zero rows
     * (never an error) when the collector is missing.
     *
     * @return array{total: int, rows: array<int, array{post: \WP_Post, views: int}>}
     */
    private static function fetchStats(): array
    {
        if (self::$statsCache !== null) {
            return self::$statsCache;
        }
        $empty = ['total' => 0, 'rows' => []];
        try {
            if (!class_exists(\TrayDigita\WP\Plugin\Core\Stack\Stack::class)) {
                return self::$statsCache = $empty;
            }
            $stack = Core::stack();
            $collector = $stack->getPopularPosts();
            $analytics = $collector->getSiteAnalytics();
            $popular = $analytics->getPopularPostsFromDaysInterval(30, 100);
            $userId = get_current_user_id();
            $total = 0;
            $rows = [];
            foreach ($popular as $entry) {
                if (!is_array($entry) || !isset($entry['post']) || !isset($entry['count'])) {
                    continue;
                }
                $post = $entry['post'];
                if (!$post instanceof \WP_Post || (int) $post->post_author !== $userId) {
                    continue;
                }
                $views = (int) $entry['count'];
                if ($views <= 0) {
                    continue;
                }
                $total += $views;
                $rows[] = ['post' => $post, 'views' => $views];
            }
            usort($rows, static fn (array $a, array $b): int => $b['views'] <=> $a['views']);
            return self::$statsCache = ['total' => $total, 'rows' => $rows];
        } catch (Throwable) {
            return self::$statsCache = $empty;
        }
    }

    /**
     * Recent status-change notifications for the current user.
     *
     * Reads the Activity Log module when it is enabled: the
     * editorial workflow records one row per status change on
     * the user's own posts (see
     * EditorialWorkflowModule::recordStatusChange()). Rows
     * recorded under the retired 'contributor-frontend' module
     * id are read too, so notifications recorded before the
     * MUST-4 split keep showing. Returns an empty list when
     * the module is off or nothing was recorded - the Overview
     * then shows an honest empty state.
     *
     * @return array<int, array{message: string, date: string, url: string}>
     */
    private static function fetchNotifications(): array
    {
        if (!ModuleRegistry::isEnabled('activity-log') || !class_exists(ActivityLogModule::class)) {
            return [];
        }
        $rows = [];
        foreach (['editorial-workflow', 'contributor-frontend'] as $moduleId) {
            try {
                $result = ActivityLogModule::query([
                    'module' => $moduleId,
                    'event_type' => 'status-changed',
                    'user_id' => get_current_user_id(),
                    'per_page' => 5,
                ]);
            } catch (Throwable) {
                continue;
            }
            $found = is_array($result['rows'] ?? null) ? $result['rows'] : [];
            foreach ($found as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, static function (mixed $a, mixed $b): int {
            $dateA = is_array($a) && isset($a['created_at']) && is_string($a['created_at']) ? $a['created_at'] : '';
            $dateB = is_array($b) && isset($b['created_at']) && is_string($b['created_at']) ? $b['created_at'] : '';
            return strcmp($dateB, $dateA);
        });
        $rows = array_slice($rows, 0, 5);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['message']) || !is_string($row['message'])) {
                continue;
            }
            $meta = [];
            if (isset($row['meta']) && is_string($row['meta']) && $row['meta'] !== '') {
                $decoded = json_decode($row['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $out[] = [
                'message' => $row['message'],
                'date' => isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : '',
                'url' => self::notificationUrl($meta),
            ];
        }
        return $out;
    }

    /**
     * Link target for a notification row.
     *
     * Published posts link to the live article; the author's
     * own non-published posts link to their preview; anything
     * else links to My Contributions.
     *
     * @param array<string, mixed> $meta Notification meta.
     * @return string
     */
    private static function notificationUrl(array $meta): string
    {
        $postId = isset($meta['post_id']) ? (int) $meta['post_id'] : 0;
        if ($postId > 0) {
            $post = get_post($postId);
            if ($post !== null && $post->post_type === 'post') {
                if ($post->post_status === 'publish') {
                    $url = get_permalink($post);
                    if (is_string($url) && $url !== '') {
                        return $url;
                    }
                }
                if ((int) $post->post_author === get_current_user_id()) {
                    $preview = get_preview_post_link($post);
                    if (is_string($preview) && $preview !== '') {
                        return $preview;
                    }
                }
            }
        }
        return MemberAreaModule::viewUrl('contributions');
    }

    /**
     * The site announcement, when the theme has one.
     *
     * The real source is the Customizer announcement bar text
     * (Settings::get('announcement_text')). An empty
     * setting means no announcements - the Overview then shows
     * an honest empty state instead of inventing one.
     *
     * @return string Announcement text, or ''.
     */
    private static function announcementText(): string
    {
        try {
            if (class_exists(Settings::class)) {
                $text = Settings::get('announcement_text');
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
        } catch (Throwable) {
            // Fall through to the theme_mod fallback below.
        }
        $text = get_theme_mod('announcement_text', '');
        return is_string($text) ? trim($text) : '';
    }

    /**
     * Count the current user's own posts in one status.
     *
     * @param string $status Post status (or 'all' for every non-trash status).
     * @return int
     */
    private static function countOwnPosts(string $status): int
    {
        $statuses = $status === 'all' ? self::OWN_STATUSES : [$status];
        $posts = get_posts([
            'author' => get_current_user_id(),
            'post_type' => 'post',
            'post_status' => $statuses,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        return count($posts);
    }

    /**
     * Human-readable message for a save error code.
     *
     * Unknown codes return an empty string (nothing renders).
     *
     * @param string $code Error code from the query string.
     * @return string
     */
    private static function errorMessage(string $code): string
    {
        return match ($code) {
            'nonce' => esc_html__('Security check failed. Please try again.', 'traydigita-core'),
            'capability' => esc_html__('You do not have permission to submit articles.', 'traydigita-core'),
            'title' => esc_html__('Please enter a title.', 'traydigita-core'),
            'save' => esc_html__('The article could not be saved. Please try again.', 'traydigita-core'),
            'not-editable' => esc_html__('This article cannot be edited.', 'traydigita-core'),
            'not-found' => esc_html__('The article could not be found.', 'traydigita-core'),
            'avatar' => esc_html__('The avatar could not be uploaded. Please try again.', 'traydigita-core'),
            'email-invalid' => esc_html__('Please enter a valid email address.', 'traydigita-core'),
            'email-same' => esc_html__('This is already your email address.', 'traydigita-core'),
            'email-taken' => esc_html__('This email address is already used by another account.', 'traydigita-core'),
            default => '',
        };
    }

    /**
     * Human-readable message for a saved-action flag.
     *
     * @param string $flag Saved flag from the query string.
     * @return string
     */
    private static function savedMessage(string $flag): string
    {
        return match ($flag) {
            '1' => esc_html__('Your article was saved.', 'traydigita-core'),
            'trashed' => esc_html__('The article was moved to Trash.', 'traydigita-core'),
            'restored' => esc_html__('The article was restored.', 'traydigita-core'),
            'deleted-permanent' => esc_html__('The article was permanently deleted.', 'traydigita-core'),
            'profile' => esc_html__('Your profile was saved.', 'traydigita-core'),
            'email-sent' => esc_html__('A confirmation email was sent to your new address. Click the link in it to confirm the change.', 'traydigita-core'),
            default => '',
        };
    }

    /**
     * Human-readable message for an info notice code.
     *
     * @param string $code Notice code from the query string.
     * @return string
     */
    private static function noticeMessage(string $code): string
    {
        return match ($code) {
            'section-disabled' => esc_html__('That section is not available.', 'traydigita-core'),
            'stats-unavailable' => esc_html__(
                'Statistics are not available yet: no views have been recorded for your published articles.',
                'traydigita-core'
            ),
            default => '',
        };
    }

    /**
     * Render one notice paragraph.
     *
     * @param string $kind Notice kind: info, success, or error.
     * @param string $message Already-escaped message HTML.
     * @param bool $wrap Whether to wrap in the editor container.
     * @return string
     */
    public static function renderNotice(string $kind, string $message, bool $wrap = false): string
    {
        $html = '<p class="traydigita-contributor-notice traydigita-contributor-notice-'
            . esc_attr($kind) . '">' . $message . '</p>';
        if ($wrap) {
            return '<div class="traydigita-contributor-editor">' . $html . '</div>';
        }
        return $html;
    }

    /**
     * Render the whole dashboard shell with the active section.
     *
     * Sidebar + top bar + section content. The top bar holds
     * the back-to-member-area link (the Overview, since the
     * dashboard IS the member area), the section title, a save
     * state the editor updates, and the View site / Log out
     * links. The Write section's top-bar actions submit the
     * editor form through its id, so they work from outside
     * the form.
     *
     * @return string
     */
    private static function renderDashboard(): string
    {
        $view = self::resolveView();
        $html = '';
        $noDashboard = isset($_GET[MemberAreaModule::NO_DASHBOARD_FLAG]) && $_GET[MemberAreaModule::NO_DASHBOARD_FLAG] === '1';
        if ($noDashboard) {
            $html .= self::renderNotice(
                'info',
                esc_html__(
                    'You do not have access to the WordPress dashboard. Use this page to write and submit articles.',
                    'traydigita-core'
                )
            );
        }
        $html .= self::renderNotices();
        $title = '';
        $section = null;
        foreach (MemberAreaModule::enabledSections() as $candidate) {
            if ((string) $candidate['id'] === $view) {
                $section = $candidate;
                $title = (string) $candidate['label'];
                break;
            }
        }
        if ($section !== null && is_callable($section['render'] ?? null)) {
            // A section registered through the filter with its own
            // renderer owns its output (see memberSections()).
            $content = (string) $section['render']();
        } else {
            $content = match ($view) {
                'posts' => self::renderPostsList(),
                'contributions' => self::renderContributions(),
                default => self::renderOverview(),
            };
        }
        $menu = '';
        foreach (self::menuItems($view) as $item) {
            $menu .= '<li class="traydigita-contributor-menu-item">'
                . '<a class="traydigita-contributor-menu-link'
                . ($item['active'] ? ' traydigita-contributor-menu-link-active' : '')
                . '" href="' . esc_url($item['url']) . '">'
                . esc_html($item['label']) . '</a></li>';
        }
        $topActions = '';
        if ($view === 'write' && !FrontendEditor::isNotEditable()) {
            $topActions = '<button type="submit" name="traydigita_submit" value="draft" form="traydigita-contributor-form" class="traydigita-contributor-button traydigita-contributor-button-secondary">'
                . esc_html__('Save draft', 'traydigita-core') . '</button> '
                . '<button type="submit" name="traydigita_submit" value="review" form="traydigita-contributor-form" class="traydigita-contributor-button traydigita-contributor-button-primary">'
                . esc_html__('Submit for review', 'traydigita-core') . '</button>';
        }
        return '<div class="traydigita-contributor-dashboard">'
            . '<aside class="traydigita-contributor-sidebar">'
            . '<p class="traydigita-contributor-brand">' . esc_html__('Member area', 'traydigita-core') . '</p>'
            . '<nav class="traydigita-contributor-menu" aria-label="' . esc_attr(__('Dashboard menu', 'traydigita-core')) . '">'
            . '<ul class="traydigita-contributor-menu-list">' . $menu . '</ul>'
            . '</nav>'
            . '</aside>'
            . '<div class="traydigita-contributor-main">'
            . '<header class="traydigita-contributor-topbar">'
            . '<a class="traydigita-contributor-topbar-link" href="' . esc_url(MemberAreaModule::viewUrl('overview')) . '">'
            . esc_html__('Member area', 'traydigita-core') . '</a>'
            . '<h1 class="traydigita-contributor-topbar-title">' . esc_html($title) . '</h1>'
            . '<span class="traydigita-contributor-save-state" id="traydigita-contributor-save-state"></span>'
            . '<span class="traydigita-contributor-topbar-actions">' . $topActions . '</span>'
            . '<a class="traydigita-contributor-topbar-link" href="' . esc_url(home_url('/')) . '">'
            . esc_html__('View site', 'traydigita-core') . '</a>'
            . '<a class="traydigita-contributor-topbar-link" href="'
            . esc_url(wp_logout_url(MemberAreaModule::memberAreaUrl())) . '">'
            . esc_html__('Log out', 'traydigita-core') . '</a>'
            . '</header>'
            . '<div class="traydigita-contributor-content">' . $html . $content . '</div>'
            . '</div>'
            . '</div>';
    }

    /**
     * Render the Overview: own status counts, views statistics
     * (real data only), notifications and announcements.
     *
     * @return string
     */
    private static function renderOverview(): string
    {
        $userId = get_current_user_id();
        $isEditor = current_user_can(EditorialWorkflowModule::EDITORIAL_CAPABILITY);
        $counts = [
            'draft' => self::countOwnPosts('draft'),
            'pending' => self::countOwnPosts('pending'),
            'needs-revision' => self::countOwnPosts('needs-revision'),
            'publish' => self::countOwnPosts('publish') + self::countOwnPosts('future'),
            'rejected' => self::countOwnPosts('rejected'),
        ];
        $labels = [
            'draft' => __('Drafts', 'traydigita-core'),
            'pending' => __('In Review', 'traydigita-core'),
            'needs-revision' => __('Needs Revision', 'traydigita-core'),
            'publish' => __('Published', 'traydigita-core'),
            'rejected' => __('Rejected', 'traydigita-core'),
        ];
        $filters = [
            'draft' => 'draft',
            'pending' => 'pending',
            'needs-revision' => 'needs-revision',
            'publish' => 'publish',
            'rejected' => 'rejected',
        ];
        $html = '<div class="traydigita-contributor-overview">';
        $html .= '<div class="traydigita-contributor-stat-cards">';
        foreach ($counts as $key => $count) {
            $html .= '<a class="traydigita-contributor-stat-card" href="'
                . esc_url(MemberAreaModule::viewUrl('contributions', [MemberAreaModule::STATUS_FLAG => $filters[$key]])) . '">'
                . '<span class="traydigita-contributor-stat-number">' . esc_html(number_format_i18n($count)) . '</span>'
                . '<span class="traydigita-contributor-stat-label">' . esc_html($labels[$key]) . '</span>'
                . '</a>';
        }
        if ($isEditor) {
            $queueCount = (int) (wp_count_posts('post')->pending ?? 0);
            $html .= '<a class="traydigita-contributor-stat-card" href="'
                . esc_url(MemberAreaModule::viewUrl('posts', [MemberAreaModule::STATUS_FLAG => 'pending'])) . '">'
                . '<span class="traydigita-contributor-stat-number">' . esc_html(number_format_i18n($queueCount)) . '</span>'
                . '<span class="traydigita-contributor-stat-label">' . esc_html__('Review queue', 'traydigita-core') . '</span>'
                . '</a>';
        }
        $html .= '</div>';
        $html .= '<div class="traydigita-contributor-quick-actions">';
        if (RoleAccessModule::isDashboardUser($userId) && self::canUseFrontendEditor()) {
            $html .= '<a class="traydigita-contributor-button traydigita-contributor-button-primary" href="'
                . esc_url(self::writeUrl()) . '">' . esc_html__('Write New', 'traydigita-core') . '</a> ';
        }
        $html .= '<a class="traydigita-contributor-button traydigita-contributor-button-secondary" href="'
            . esc_url(MemberAreaModule::viewUrl('contributions')) . '">'
            . esc_html__('My Contributions', 'traydigita-core') . '</a> '
            . '<a class="traydigita-contributor-button traydigita-contributor-button-secondary" href="'
            . esc_url(MemberAreaModule::viewUrl('profile')) . '">' . esc_html__('Edit profile', 'traydigita-core') . '</a>'
            . '</div>';
        if (self::statsAvailable()) {
            $stats = self::fetchStats();
            $html .= '<h2 class="traydigita-contributor-heading">' . esc_html__('Views', 'traydigita-core') . '</h2>';
            $html .= '<div class="traydigita-contributor-stat-cards">'
                . '<div class="traydigita-contributor-stat-card">'
                . '<span class="traydigita-contributor-stat-number">'
                . esc_html(number_format_i18n($stats['total'])) . '</span>'
                . '<span class="traydigita-contributor-stat-label">'
                . esc_html__('Views in the last 30 days', 'traydigita-core') . '</span>'
                . '</div></div>';
            $html .= '<ul class="traydigita-contributor-list">';
            foreach (array_slice($stats['rows'], 0, 5) as $row) {
                $post = $row['post'];
                $url = get_permalink($post);
                $title = esc_html($post->post_title);
                if (is_string($url) && $url !== '') {
                    $title = '<a href="' . esc_url($url) . '">' . $title . '</a>';
                }
                $html .= '<li class="traydigita-contributor-item">'
                    . '<span class="traydigita-contributor-item-title">' . $title . '</span> '
                    . '<span class="traydigita-contributor-item-views">'
                    . esc_html(number_format_i18n($row['views'])) . ' '
                    . esc_html__('views', 'traydigita-core') . '</span>'
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<h2 class="traydigita-contributor-heading">' . esc_html__('Notifications', 'traydigita-core') . '</h2>';
        $notifications = self::fetchNotifications();
        if ($notifications === []) {
            $html .= '<p class="traydigita-contributor-empty">'
                . esc_html__('No notifications yet. Status changes on your articles will appear here.', 'traydigita-core')
                . '</p>';
        } else {
            $html .= '<ul class="traydigita-contributor-list">';
            foreach ($notifications as $notification) {
                $message = esc_html($notification['message']);
                if ($notification['url'] !== '') {
                    $message = '<a href="' . esc_url($notification['url']) . '">' . $message . '</a>';
                }
                $html .= '<li class="traydigita-contributor-item">'
                    . '<span class="traydigita-contributor-item-title">' . $message . '</span> '
                    . '<span class="traydigita-contributor-item-date">' . esc_html($notification['date']) . '</span>'
                    . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<h2 class="traydigita-contributor-heading">' . esc_html__('Announcements', 'traydigita-core') . '</h2>';
        $announcement = self::announcementText();
        if ($announcement === '') {
            $html .= '<p class="traydigita-contributor-empty">'
                . esc_html__('No announcements.', 'traydigita-core') . '</p>';
        } else {
            $html .= '<p class="traydigita-contributor-announcement">' . esc_html($announcement) . '</p>';
        }
        return $html . '</div>';
    }

    /**
     * Posts for the Posts list filter.
     *
     * Published posts are everyone's; every other filter is
     * scoped: drafts, needs-revision and rejected are always
     * the current user's own, and pending is the review queue
     * for editors (all pending posts) or the user's own pending
     * posts for everyone else.
     *
     * @param string $filter Filter id.
     * @return array<int, \WP_Post>
     */
    private static function postsListPosts(string $filter): array
    {
        $userId = get_current_user_id();
        $isEditor = current_user_can(EditorialWorkflowModule::EDITORIAL_CAPABILITY);
        if ($filter === 'publish') {
            return get_posts([
                'post_type' => 'post',
                'post_status' => ['publish', 'future'],
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
        }
        if ($filter === 'pending') {
            $args = [
                'post_type' => 'post',
                'post_status' => ['pending'],
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            ];
            if (!$isEditor) {
                $args['author'] = $userId;
            }
            return get_posts($args);
        }
        if ($filter === 'all') {
            $published = get_posts([
                'post_type' => 'post',
                'post_status' => ['publish', 'future'],
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            $own = get_posts([
                'author' => $userId,
                'post_type' => 'post',
                'post_status' => ['draft', 'pending', 'needs-revision', 'rejected'],
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            $merged = [];
            foreach (array_merge($published, $own) as $post) {
                $merged[$post->ID] = $post;
            }
            $posts = array_values($merged);
            usort($posts, static fn (\WP_Post $a, \WP_Post $b): int => strcmp($b->post_date, $a->post_date));
            return $posts;
        }
        return get_posts([
            'author' => $userId,
            'post_type' => 'post',
            'post_status' => [$filter],
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Count for one Posts list filter.
     *
     * @param string $filter Filter id.
     * @return int
     */
    private static function postsListCount(string $filter): int
    {
        $isEditor = current_user_can(EditorialWorkflowModule::EDITORIAL_CAPABILITY);
        if ($filter === 'publish') {
            $counts = wp_count_posts('post');
            return (int) ($counts->publish ?? 0) + (int) ($counts->future ?? 0);
        }
        if ($filter === 'pending') {
            if ($isEditor) {
                return (int) (wp_count_posts('post')->pending ?? 0);
            }
            return self::countOwnPosts('pending');
        }
        if ($filter === 'all') {
            $counts = wp_count_posts('post');
            $total = (int) ($counts->publish ?? 0) + (int) ($counts->future ?? 0);
            $total += self::countOwnPosts('draft') + self::countOwnPosts('needs-revision')
                + self::countOwnPosts('rejected');
            $total += $isEditor
                ? (int) ($counts->pending ?? 0)
                : self::countOwnPosts('pending');
            return $total;
        }
        return self::countOwnPosts($filter);
    }

    /**
     * Render the Posts list: status filters with counts and a
     * table of posts (published for everyone, own
     * non-published, and the pending review queue for editors).
     *
     * @return string
     */
    private static function renderPostsList(): string
    {
        $filter = isset($_GET[MemberAreaModule::STATUS_FLAG]) && is_string($_GET[MemberAreaModule::STATUS_FLAG])
            ? sanitize_text_field(wp_unslash($_GET[MemberAreaModule::STATUS_FLAG]))
            : 'all';
        if (!in_array($filter, ['all', 'draft', 'pending', 'needs-revision', 'publish', 'rejected'], true)) {
            $filter = 'all';
        }
        $page = isset($_GET[MemberAreaModule::PAGE_FLAG]) ? max(1, absint(wp_unslash((string) $_GET[MemberAreaModule::PAGE_FLAG]))) : 1;
        $filters = [
            'all' => __('All', 'traydigita-core'),
            'publish' => __('Published', 'traydigita-core'),
            'pending' => __('In Review', 'traydigita-core'),
            'draft' => __('Draft', 'traydigita-core'),
            'needs-revision' => __('Needs Revision', 'traydigita-core'),
            'rejected' => __('Rejected', 'traydigita-core'),
        ];
        $html = '<div class="traydigita-contributor-posts">';
        if (RoleAccessModule::isDashboardUser(get_current_user_id()) && self::canUseFrontendEditor()) {
            $html .= '<div class="traydigita-contributor-list-head">'
                . '<a class="traydigita-contributor-button traydigita-contributor-button-primary" href="'
                . esc_url(self::writeUrl()) . '">' . esc_html__('Write New', 'traydigita-core') . '</a>'
                . '</div>';
        }
        $html .= '<ul class="traydigita-contributor-filters">';
        foreach ($filters as $key => $label) {
            $count = self::postsListCount($key);
            $html .= '<li class="traydigita-contributor-filter">'
                . '<a class="traydigita-contributor-filter-link'
                . ($filter === $key ? ' traydigita-contributor-filter-link-active' : '')
                . '" href="' . esc_url(MemberAreaModule::viewUrl('posts', [MemberAreaModule::STATUS_FLAG => $key])) . '">'
                . esc_html($label)
                . ' <span class="traydigita-contributor-filter-count">' . esc_html(number_format_i18n($count)) . '</span>'
                . '</a></li>';
        }
        $html .= '</ul>';
        $posts = self::postsListPosts($filter);
        $total = count($posts);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
        }
        $pagePosts = array_slice($posts, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        if ($pagePosts === []) {
            $html .= '<p class="traydigita-contributor-empty">'
                . esc_html__('No articles here yet.', 'traydigita-core') . '</p>';
            return $html . '</div>';
        }
        $html .= self::renderPostTable($pagePosts, false);
        if ($pages > 1) {
            $html .= '<p class="traydigita-contributor-pagination">';
            if ($page > 1) {
                $html .= '<a href="' . esc_url(MemberAreaModule::viewUrl('posts', [
                    MemberAreaModule::STATUS_FLAG => $filter,
                    MemberAreaModule::PAGE_FLAG => $page - 1,
                ])) . '">' . esc_html__('Previous page', 'traydigita-core') . '</a> ';
            }
            $html .= '<span>' . sprintf(
                /* translators: 1: current page, 2: total pages. */
                esc_html__('Page %1$d of %2$d', 'traydigita-core'),
                $page,
                $pages
            ) . '</span>';
            if ($page < $pages) {
                $html .= ' <a href="' . esc_url(MemberAreaModule::viewUrl('posts', [
                    MemberAreaModule::STATUS_FLAG => $filter,
                    MemberAreaModule::PAGE_FLAG => $page + 1,
                ])) . '">' . esc_html__('Next page', 'traydigita-core') . '</a>';
            }
            $html .= '</p>';
        }
        return $html . '</div>';
    }

    /**
     * The current user's own posts for a contributions filter.
     *
     * @param string $filter Filter id.
     * @return array<int, \WP_Post>
     */
    private static function ownPosts(string $filter): array
    {
        $statuses = match ($filter) {
            'publish' => ['publish', 'future'],
            'all' => array_merge(self::OWN_STATUSES, ['trash']),
            default => [$filter],
        };
        return get_posts([
            'author' => get_current_user_id(),
            'post_type' => 'post',
            'post_status' => $statuses,
            'posts_per_page' => 100,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    /**
     * Render My Contributions: the user's own posts in every
     * status, including trash.
     *
     * Demotion-proof: the list is ownership-scoped, never
     * capability-scoped - a user demoted to subscriber still
     * sees their own posts, read-only when they no longer have
     * edit_posts.
     *
     * @return string
     */
    private static function renderContributions(): string
    {
        $filter = isset($_GET[MemberAreaModule::STATUS_FLAG]) && is_string($_GET[MemberAreaModule::STATUS_FLAG])
            ? sanitize_text_field(wp_unslash($_GET[MemberAreaModule::STATUS_FLAG]))
            : 'all';
        if (!in_array($filter, ['all', 'draft', 'pending', 'needs-revision', 'publish', 'rejected', 'trash'], true)) {
            $filter = 'all';
        }
        $page = isset($_GET[MemberAreaModule::PAGE_FLAG]) ? max(1, absint(wp_unslash((string) $_GET[MemberAreaModule::PAGE_FLAG]))) : 1;
        $filters = [
            'all' => __('All', 'traydigita-core'),
            'draft' => __('Draft', 'traydigita-core'),
            'pending' => __('In Review', 'traydigita-core'),
            'needs-revision' => __('Needs Revision', 'traydigita-core'),
            'publish' => __('Published', 'traydigita-core'),
            'rejected' => __('Rejected', 'traydigita-core'),
            'trash' => __('Trash', 'traydigita-core'),
        ];
        $html = '<div class="traydigita-contributor-posts">';
        if (RoleAccessModule::isDashboardUser(get_current_user_id()) && self::canUseFrontendEditor()) {
            $html .= '<div class="traydigita-contributor-list-head">'
                . '<a class="traydigita-contributor-button traydigita-contributor-button-primary" href="'
                . esc_url(self::writeUrl()) . '">' . esc_html__('Write New', 'traydigita-core') . '</a>'
                . '</div>';
        }
        $html .= '<ul class="traydigita-contributor-filters">';
        foreach ($filters as $key => $label) {
            $count = $key === 'publish'
                ? self::countOwnPosts('publish') + self::countOwnPosts('future')
                : self::countOwnPosts($key);
            $html .= '<li class="traydigita-contributor-filter">'
                . '<a class="traydigita-contributor-filter-link'
                . ($filter === $key ? ' traydigita-contributor-filter-link-active' : '')
                . '" href="' . esc_url(MemberAreaModule::viewUrl('contributions', [MemberAreaModule::STATUS_FLAG => $key])) . '">'
                . esc_html($label)
                . ' <span class="traydigita-contributor-filter-count">' . esc_html(number_format_i18n($count)) . '</span>'
                . '</a></li>';
        }
        $html .= '</ul>';
        $posts = self::ownPosts($filter);
        $total = count($posts);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
        }
        $pagePosts = array_slice($posts, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        if ($pagePosts === []) {
            $html .= '<p class="traydigita-contributor-empty">'
                . esc_html__('No articles here yet.', 'traydigita-core') . '</p>';
            return $html . '</div>';
        }
        $html .= self::renderPostTable($pagePosts, true);
        if ($pages > 1) {
            $html .= '<p class="traydigita-contributor-pagination">';
            if ($page > 1) {
                $html .= '<a href="' . esc_url(MemberAreaModule::viewUrl('contributions', [
                    MemberAreaModule::STATUS_FLAG => $filter,
                    MemberAreaModule::PAGE_FLAG => $page - 1,
                ])) . '">' . esc_html__('Previous page', 'traydigita-core') . '</a> ';
            }
            $html .= '<span>' . sprintf(
                /* translators: 1: current page, 2: total pages. */
                esc_html__('Page %1$d of %2$d', 'traydigita-core'),
                $page,
                $pages
            ) . '</span>';
            if ($page < $pages) {
                $html .= ' <a href="' . esc_url(MemberAreaModule::viewUrl('contributions', [
                    MemberAreaModule::STATUS_FLAG => $filter,
                    MemberAreaModule::PAGE_FLAG => $page + 1,
                ])) . '">' . esc_html__('Next page', 'traydigita-core') . '</a>';
            }
            $html .= '</p>';
        }
        return $html . '</div>';
    }

    /**
     * Render the posts table shared by the Posts and
     * Contributions lists.
     *
     * Columns: Title (with featured-image thumb), Status,
     * Author, Category, Date. Row actions follow the status
     * matrix; the Contributions list additionally allows
     * trash, restore and permanent delete for the author's own
     * posts.
     *
     * @param array<int, \WP_Post> $posts Posts to list.
     * @param bool $ownList Whether this is the own-posts list (trash actions apply).
     * @return string
     */
    private static function renderPostTable(array $posts, bool $ownList): string
    {
        $html = '<table class="traydigita-contributor-table">';
        $html .= '<thead><tr>'
            . '<th>' . esc_html__('Title', 'traydigita-core') . '</th>'
            . '<th>' . esc_html__('Status', 'traydigita-core') . '</th>'
            . '<th>' . esc_html__('Author', 'traydigita-core') . '</th>'
            . '<th>' . esc_html__('Category', 'traydigita-core') . '</th>'
            . '<th>' . esc_html__('Date', 'traydigita-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($posts as $post) {
            $categories = get_the_category($post->ID);
            $names = [];
            if (is_array($categories)) {
                foreach ($categories as $category) {
                    $names[] = $category->name;
                }
            }
            $author = get_userdata((int) $post->post_author);
            $thumb = '';
            $thumbId = get_post_thumbnail_id($post->ID);
            if ($thumbId > 0) {
                $src = wp_get_attachment_image_src($thumbId, 'thumbnail');
                if (is_array($src) && isset($src[0])) {
                    $thumb = '<img class="traydigita-contributor-thumb" src="' . esc_url((string) $src[0])
                        . '" alt="" loading="lazy">';
                }
            }
            $html .= '<tr class="traydigita-contributor-row">'
                . '<td class="traydigita-contributor-cell-title">' . $thumb
                . '<span class="traydigita-contributor-item-title">' . esc_html($post->post_title) . '</span>'
                . '<span class="traydigita-contributor-row-actions">' . self::rowActions($post, $ownList) . '</span>'
                . '</td>'
                . '<td>' . self::statusPill($post->post_status) . '</td>'
                . '<td>' . esc_html($author !== false ? $author->display_name : '') . '</td>'
                . '<td>' . esc_html($names === [] ? __('Uncategorized', 'traydigita-core') : implode(', ', $names)) . '</td>'
                . '<td>' . esc_html((string) get_the_date('', $post)) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * Row actions for one post, following the status matrix.
     *
     * Edit goes through editUrl() (role-mode destination, or
     * no link when there is no frontend way to edit the post).
     * Trash, restore and permanent delete only render in the
     * own-posts list, for the author's own posts, and only when
     * the user still has edit_posts.
     *
     * @param \WP_Post $post Post row.
     * @param bool $ownList Whether this is the own-posts list.
     * @return string
     */
    private static function rowActions(\WP_Post $post, bool $ownList): string
    {
        $status = $post->post_status;
        $userId = get_current_user_id();
        $own = (int) $post->post_author === $userId;
        $actions = [];
        if ($status === 'trash') {
            if ($ownList && $own && current_user_can('edit_posts')) {
                $actions[] = '<a href="' . esc_url(wp_nonce_url(
                    add_query_arg(
                        ['action' => FrontendEditorModule::RESTORE_ACTION, 'traydigita_post_id' => $post->ID],
                        admin_url('admin-post.php')
                    ),
                    FrontendEditorModule::RESTORE_ACTION
                )) . '">' . esc_html__('Restore', 'traydigita-core') . '</a>';
                $actions[] = '<a href="' . esc_url(wp_nonce_url(
                    add_query_arg(
                        ['action' => FrontendEditorModule::PERMANENT_DELETE_ACTION, 'traydigita_post_id' => $post->ID],
                        admin_url('admin-post.php')
                    ),
                    FrontendEditorModule::PERMANENT_DELETE_ACTION
                )) . '">' . esc_html__('Delete permanently', 'traydigita-core') . '</a>';
            }
            return implode(' <span aria-hidden="true">|</span> ', $actions);
        }
        $edit = self::editUrl($post);
        if ($edit !== '') {
            $actions[] = '<a href="' . esc_url($edit) . '">' . esc_html__('Edit', 'traydigita-core') . '</a>';
        }
        if ($status === 'pending' || $status === 'needs-revision' || $status === 'rejected') {
            if ($own || current_user_can(EditorialWorkflowModule::EDITORIAL_CAPABILITY)) {
                $preview = get_preview_post_link($post);
                if (is_string($preview) && $preview !== '') {
                    $actions[] = '<a href="' . esc_url($preview) . '" target="_blank" rel="noopener">'
                        . esc_html__('Preview', 'traydigita-core') . '</a>';
                }
            }
        }
        if ($status === 'publish') {
            $url = get_permalink($post);
            if (is_string($url) && $url !== '') {
                $actions[] = '<a href="' . esc_url($url) . '">' . esc_html__('View', 'traydigita-core') . '</a>';
            }
        }
        if ($status === 'future') {
            if ($own || current_user_can(EditorialWorkflowModule::EDITORIAL_CAPABILITY)) {
                $preview = get_preview_post_link($post);
                if (is_string($preview) && $preview !== '') {
                    $actions[] = '<a href="' . esc_url($preview) . '" target="_blank" rel="noopener">'
                        . esc_html__('Preview', 'traydigita-core') . '</a>';
                }
            }
        }
        if ($ownList && $own && current_user_can('edit_posts')
            && in_array($status, FrontendEditorModule::TRASHABLE_STATUSES, true)
        ) {
            $actions[] = '<a href="' . esc_url(wp_nonce_url(
                add_query_arg(
                    ['action' => FrontendEditorModule::DELETE_ACTION, 'traydigita_post_id' => $post->ID],
                    admin_url('admin-post.php')
                ),
                FrontendEditorModule::DELETE_ACTION
            )) . '">' . esc_html__('Trash', 'traydigita-core') . '</a>';
        }
        return implode(' <span aria-hidden="true">|</span> ', $actions);
    }

    /**
     * Status pill for a post status.
     *
     * Scheduled posts show a "Scheduled" pill; every other
     * status shows its admin label.
     *
     * @param string $status Post status.
     * @return string
     */
    private static function statusPill(string $status): string
    {
        if ($status === 'future') {
            $label = __('Scheduled', 'traydigita-core');
        } else {
            $object = get_post_status_object($status);
            $label = $object !== null && is_string($object->label) && $object->label !== ''
                ? $object->label
                : $status;
        }
        return '<span class="traydigita-contributor-pill traydigita-contributor-pill-' . esc_attr($status) . '">'
            . esc_html($label) . '</span>';
    }
}
