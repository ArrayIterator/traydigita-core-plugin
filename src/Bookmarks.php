<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core;

use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use WP_Post;
use function esc_attr;
use function esc_attr__;
use function esc_html__;
use function get_permalink;
use function get_the_excerpt;
use function get_the_post_thumbnail_url;
use function get_the_title;
use function trim;
use function wp_strip_all_tags;

/**
 * Reader bookmarks, stored in the browser only (IndexedDB).
 *
 * There is NO database storage for bookmarks — no user meta, no
 * REST sync endpoints, nothing on the server. The single source
 * of truth is one IndexedDB database in the reader's browser,
 * shared by the article toggle, the bookmark.php page and the
 * member-area Bookmarks section. Accepted trade-off: bookmarks
 * do not sync across devices and are lost when the browser data
 * is cleared. Guests can bookmark exactly like logged-in users.
 *
 * Store (shared by every surface; see
 * resources/js/modules/bookmarks.ts, which opens the same
 * database):
 *
 *   database: `traydigita-bookmarks` (version 1)
 *   object store: `bookmarks`, keyed by `postId`
 *
 * Entry shape (exact — do not add fields without bumping the
 * database version and documenting the migration):
 *
 *   {
 *     postId:    number,   // WP post id
 *     title:     string,   // post title at bookmark time
 *     url:       string,   // post permalink
 *     excerpt:   string,   // plain-text excerpt at bookmark time
 *     thumbnail: string,   // featured-image URL (medium) or ''
 *     note:      string,   // user-written note, editable
 *     createdAt: number,   // epoch milliseconds
 *   }
 *
 * The toggle, the list shell and the strings the JavaScript
 * needs all come from here, so the three surfaces can never
 * drift apart. JavaScript renders list entries with DOM APIs
 * only (textContent), never innerHTML with entry data.
 *
 * Both public methods return an empty string while the
 * Bookmarks module is disabled (see
 * Modules\Bookmarks\BookmarksModule), so disabling the module
 * hides the toggle on posts and the list shell everywhere.
 */
final class Bookmarks
{
    /**
     * IndexedDB database name shared by all bookmark surfaces.
     */
    public const string DB_NAME = 'traydigita-bookmarks';

    /**
     * IndexedDB database version. Bump on any entry-shape change.
     */
    public const int DB_VERSION = 1;

    /**
     * Object-store name inside the database, keyed by `postId`.
     */
    public const string STORE_NAME = 'bookmarks';

    /**
     * Bookmark toggle button for one post.
     *
     * Renders on single posts (see layouts/entry/header.php).
     * The button carries everything the JavaScript needs to
     * save the entry (post id, title, URL, excerpt, thumbnail)
     * as data attributes, plus the translated labels for both
     * states. Clicking an unsaved post saves it and opens the
     * inline note composer; clicking a saved post removes the
     * bookmark. The note itself is optional and editable later
     * from the list.
     *
     * @param \WP_Post $post Post to render the toggle for.
     * @return string
     */
    public static function renderToggle(WP_Post $post): string
    {
        if (!ModuleRegistry::isEnabled('bookmarks')) {
            return '';
        }
        $permalink = get_permalink($post);
        if ($permalink === false) {
            $permalink = '';
        }
        $thumbnail = get_the_post_thumbnail_url($post, 'medium');
        if ($thumbnail === false) {
            $thumbnail = '';
        }
        $excerpt = trim(wp_strip_all_tags(get_the_excerpt($post)));
        $noteId = 'td-bookmark-note-' . $post->ID;
        return '<div class="td-bookmark" data-bookmark>'
            . '<button type="button" class="td-bookmark-toggle" data-bookmark-toggle'
            . ' data-post-id="' . esc_attr((string) $post->ID) . '"'
            . ' data-title="' . esc_attr(get_the_title($post)) . '"'
            . ' data-url="' . esc_attr($permalink) . '"'
            . ' data-excerpt="' . esc_attr($excerpt) . '"'
            . ' data-thumbnail="' . esc_attr($thumbnail) . '"'
            . ' data-msg-save="' . esc_attr__('Save for later', 'traydigita-core') . '"'
            . ' data-msg-saved="' . esc_attr__('Saved', 'traydigita-core') . '"'
            . ' aria-pressed="false">'
            . '<svg class="icon-small" aria-hidden="true"><use href="#td-icon-bookmark" /></svg>'
            . '<span data-bookmark-label>' . esc_html__('Save for later', 'traydigita-core') . '</span>'
            . '</button>'
            . '<div class="td-bookmark-note" data-bookmark-note hidden>'
            . '<label class="td-bookmark-note-label" for="' . esc_attr($noteId) . '">'
            . esc_html__('Add a note', 'traydigita-core') . '</label>'
            . '<textarea id="' . esc_attr($noteId) . '" data-bookmark-note-input rows="3" maxlength="500"'
            . ' placeholder="' . esc_attr__('Write a note about this article…', 'traydigita-core') . '"></textarea>'
            . '<div class="td-bookmark-note-actions">'
            . '<button type="button" class="td-bookmark-note-save" data-bookmark-note-save>'
            . esc_html__('Save note', 'traydigita-core') . '</button>'
            . '<button type="button" class="td-bookmark-note-cancel" data-bookmark-note-cancel>'
            . esc_html__('Cancel', 'traydigita-core') . '</button>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    /**
     * Empty list shell for saved bookmarks.
     *
     * The entries are rendered client-side from IndexedDB (see
     * resources/js/modules/bookmarks.ts), so the same shell is
     * used by the bookmark.php page and the member-area
     * Bookmarks section. All strings the JavaScript renders
     * travel as data-msg-* attributes — no string is hardcoded
     * in JavaScript. The status line doubles as the loading and
     * empty state; the list itself starts empty.
     *
     * @return string
     */
    public static function renderListShell(): string
    {
        if (!ModuleRegistry::isEnabled('bookmarks')) {
            return '';
        }
        return '<div class="td-bookmarks" data-bookmark-list'
            . ' data-msg-loading="' . esc_attr__('Loading your bookmarks…', 'traydigita-core') . '"'
            . ' data-msg-empty="' . esc_attr__('You have no saved articles yet.', 'traydigita-core') . '"'
            . ' data-msg-empty-hint="' . esc_attr__('Tap the bookmark button on any article to save it here.', 'traydigita-core') . '"'
            . ' data-msg-saved-on="' . esc_attr__('Saved on %s', 'traydigita-core') . '"'
            . ' data-msg-note-label="' . esc_attr__('Your note', 'traydigita-core') . '"'
            . ' data-msg-edit-note="' . esc_attr__('Edit note', 'traydigita-core') . '"'
            . ' data-msg-save-note="' . esc_attr__('Save note', 'traydigita-core') . '"'
            . ' data-msg-cancel="' . esc_attr__('Cancel', 'traydigita-core') . '"'
            . ' data-msg-remove="' . esc_attr__('Remove', 'traydigita-core') . '"'
            . ' data-msg-unavailable="' . esc_attr__('Bookmarks are not available in this browser.', 'traydigita-core') . '"'
            . ' data-msg-note-placeholder="' . esc_attr__('Write a note about this article…', 'traydigita-core') . '">'
            . '<p class="td-bookmarks-status" data-bookmark-status>'
            . esc_html__('Loading your bookmarks…', 'traydigita-core') . '</p>'
            . '<ul class="td-bookmarks-list" data-bookmark-items hidden></ul>'
            . '</div>';
    }
}
