<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DbTools;

use function __;
use function in_array;
use function is_string;
use function time;

/**
 * Transient cleaner and smart cleaner for the DB Tools module.
 *
 * Every category is counted before anything is deleted, and each
 * category is selectable on its own. Deleting is done with
 * `$wpdb` placeholders only - no user input ever reaches SQL;
 * the only variable parts are fixed category keys validated
 * against the allowlist below.
 *
 * Transient semantics (WordPress stores a transient as two rows
 * in the options table: `_transient_<name>` and
 * `_transient_timeout_<name>`):
 * - Expired: the timeout row exists and its value is in the
 *   past. Both the value row and the timeout row are deleted.
 * - Orphaned timeout: a timeout row whose value row is missing.
 *   The timeout row is deleted.
 * A value row with no timeout row is a transient without an
 * expiry - that is legitimate and is never deleted. Site
 * transients (`_site_transient_*`, multisite scope) are left
 * alone by design and are not counted here.
 *
 * Revision / auto-draft / trashed-post deletes also remove the
 * posts' postmeta; trashed / spam comment deletes also remove
 * their commentmeta, so no orphaned meta rows are left behind.
 */
final class Cleaner
{
    /**
     * Cleaner category keys.
     *
     * @var array<int, string>
     */
    public const array CATEGORIES = [
        'expired_transients',
        'revisions',
        'auto_drafts',
        'trashed_posts',
        'trashed_comments',
        'spam_comments',
        'orphaned_postmeta',
    ];

    /**
     * Translatable label per category.
     *
     * @return array<string, string>
     */
    public static function categoryLabels(): array
    {
        return [
            'expired_transients' => __('Expired transients', 'traydigita-core'),
            'revisions' => __('Post revisions', 'traydigita-core'),
            'auto_drafts' => __('Auto-drafts', 'traydigita-core'),
            'trashed_posts' => __('Trashed posts', 'traydigita-core'),
            'trashed_comments' => __('Trashed comments', 'traydigita-core'),
            'spam_comments' => __('Spam comments', 'traydigita-core'),
            'orphaned_postmeta' => __('Orphaned postmeta', 'traydigita-core'),
        ];
    }

    /**
     * Human-readable explanation per category, shown on the page.
     *
     * @return array<string, string>
     */
    public static function categoryDescriptions(): array
    {
        return [
            'expired_transients' => __('Expired transient values and their timeout rows, plus timeout rows whose value row is already gone.', 'traydigita-core'),
            'revisions' => __('Old revisions of posts and pages. The published content is never touched.', 'traydigita-core'),
            'auto_drafts' => __('Posts saved automatically while editing but never published.', 'traydigita-core'),
            'trashed_posts' => __('Posts and pages in the Trash, including their postmeta.', 'traydigita-core'),
            'trashed_comments' => __('Comments in the Trash, including their commentmeta.', 'traydigita-core'),
            'spam_comments' => __('Comments marked as spam, including their commentmeta.', 'traydigita-core'),
            'orphaned_postmeta' => __('Postmeta rows whose post no longer exists.', 'traydigita-core'),
        ];
    }

    /**
     * Whether a category key is one of the known categories.
     *
     * @param string $category Category key to check.
     * @return bool
     */
    public static function isKnownCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    /**
     * Count how many rows each category would delete.
     *
     * Counts are computed with the same WHERE clauses the deletes
     * use, so the number shown before deleting matches the number
     * deleted afterwards (unless another process changes the
     * database in between).
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $counts = [];
        foreach (self::CATEGORIES as $category) {
            $counts[$category] = self::countCategory($category);
        }
        return $counts;
    }

    /**
     * Count the rows one category would delete.
     *
     * Unknown categories count as zero; they are never deleted.
     *
     * @param string $category Category key.
     * @return int
     */
    public static function countCategory(string $category): int
    {
        global $wpdb;
        switch ($category) {
            case 'expired_transients':
                $expired = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM `' . $wpdb->options . '`'
                        . ' WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
                        $wpdb->esc_like('_transient_timeout_') . '%',
                        time()
                    )
                );
                $orphanedTimeouts = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM `' . $wpdb->options . '` AS t'
                        . ' WHERE t.option_name LIKE %s'
                        . ' AND NOT EXISTS (SELECT 1 FROM `' . $wpdb->options . '` AS v'
                        . ' WHERE v.option_name = CONCAT(%s, SUBSTRING(t.option_name, 19)))',
                        $wpdb->esc_like('_transient_timeout_') . '%',
                        '_transient_'
                    )
                );
                // One expired pair deletes two rows (value + timeout).
                return $expired * 2 + $orphanedTimeouts;
            case 'revisions':
                return self::countPostsByType('revision');
            case 'auto_drafts':
                return self::countPostsByStatus('auto-draft');
            case 'trashed_posts':
                return self::countPostsByStatus('trash');
            case 'trashed_comments':
                return self::countCommentsByStatus('trash');
            case 'spam_comments':
                return self::countCommentsByStatus('spam');
            case 'orphaned_postmeta':
                return (int) $wpdb->get_var(
                    'SELECT COUNT(*) FROM `' . $wpdb->postmeta . '` AS pm'
                    . ' WHERE NOT EXISTS (SELECT 1 FROM `' . $wpdb->posts . '` AS p WHERE p.ID = pm.post_id)'
                );
            default:
                return 0;
        }
    }

    /**
     * Delete the rows of one category and report how many went.
     *
     * Unknown categories delete nothing and report zero.
     *
     * @param string $category Category key.
     * @return int Number of rows deleted.
     */
    public static function deleteCategory(string $category): int
    {
        global $wpdb;
        switch ($category) {
            case 'expired_transients':
                $deleted = 0;
                // Expired value rows (their timeout row has passed).
                $deleted += (int) $wpdb->query(
                    $wpdb->prepare(
                        'DELETE v FROM `' . $wpdb->options . '` AS v'
                        . ' INNER JOIN `' . $wpdb->options . '` AS t'
                        . ' ON t.option_name = CONCAT(%s, SUBSTRING(v.option_name, 12))'
                        . ' WHERE v.option_name LIKE %s AND v.option_name NOT LIKE %s'
                        . ' AND CAST(t.option_value AS UNSIGNED) < %d',
                        '_transient_timeout_',
                        $wpdb->esc_like('_transient_') . '%',
                        $wpdb->esc_like('_transient_timeout_') . '%',
                        time()
                    )
                );
                // Expired timeout rows themselves.
                $deleted += (int) $wpdb->query(
                    $wpdb->prepare(
                        'DELETE FROM `' . $wpdb->options . '`'
                        . ' WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
                        $wpdb->esc_like('_transient_timeout_') . '%',
                        time()
                    )
                );
                // Orphaned timeout rows (value row already gone).
                $deleted += (int) $wpdb->query(
                    $wpdb->prepare(
                        'DELETE t FROM `' . $wpdb->options . '` AS t'
                        . ' WHERE t.option_name LIKE %s'
                        . ' AND NOT EXISTS (SELECT 1 FROM `' . $wpdb->options . '` AS v'
                        . ' WHERE v.option_name = CONCAT(%s, SUBSTRING(t.option_name, 19)))',
                        $wpdb->esc_like('_transient_timeout_') . '%',
                        '_transient_'
                    )
                );
                return $deleted;
            case 'revisions':
                return self::deletePostsByType('revision');
            case 'auto_drafts':
                return self::deletePostsByStatus('auto-draft');
            case 'trashed_posts':
                return self::deletePostsByStatus('trash');
            case 'trashed_comments':
                return self::deleteCommentsByStatus('trash');
            case 'spam_comments':
                return self::deleteCommentsByStatus('spam');
            case 'orphaned_postmeta':
                $deleted = $wpdb->query(
                    'DELETE pm FROM `' . $wpdb->postmeta . '` AS pm'
                    . ' WHERE NOT EXISTS (SELECT 1 FROM `' . $wpdb->posts . '` AS p WHERE p.ID = pm.post_id)'
                );
                return (int) $deleted;
            default:
                return 0;
        }
    }

    /**
     * Delete every selected category and report per-category totals.
     *
     * Unknown category keys are skipped silently.
     *
     * @param array<int, string> $categories Category keys to clean.
     * @return array{deleted: array<string, int>, total: int}
     */
    public static function clean(array $categories): array
    {
        $deleted = [];
        $total = 0;
        foreach ($categories as $category) {
            if (!is_string($category) || !self::isKnownCategory($category)) {
                continue;
            }
            $count = self::deleteCategory($category);
            $deleted[$category] = $count;
            $total += $count;
        }
        return [
            'deleted' => $deleted,
            'total' => $total,
        ];
    }

    /**
     * Count posts of one post type plus their postmeta rows.
     *
     * The count mirrors deletePostsByType(), so the number shown
     * before deleting is the number of rows deleted afterwards.
     *
     * @param string $postType Fixed post type (never user input).
     * @return int
     */
    private static function countPostsByType(string $postType): int
    {
        global $wpdb;
        $posts = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $wpdb->posts . '` WHERE post_type = %s',
                $postType
            )
        );
        $meta = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $wpdb->postmeta . '` AS pm'
                . ' INNER JOIN `' . $wpdb->posts . '` AS p ON pm.post_id = p.ID'
                . ' WHERE p.post_type = %s',
                $postType
            )
        );
        return $posts + $meta;
    }

    /**
     * Count posts of one post status plus their postmeta rows.
     *
     * The count mirrors deletePostsByStatus().
     *
     * @param string $status Fixed post status (never user input).
     * @return int
     */
    private static function countPostsByStatus(string $status): int
    {
        global $wpdb;
        $posts = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $wpdb->posts . '` WHERE post_status = %s',
                $status
            )
        );
        $meta = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $wpdb->postmeta . '` AS pm'
                . ' INNER JOIN `' . $wpdb->posts . '` AS p ON pm.post_id = p.ID'
                . ' WHERE p.post_status = %s',
                $status
            )
        );
        return $posts + $meta;
    }

    /**
     * Count comments of one approval status plus their commentmeta rows.
     *
     * The count mirrors deleteCommentsByStatus().
     *
     * @param string $status Fixed comment status (never user input).
     * @return int
     */
    private static function countCommentsByStatus(string $status): int
    {
        global $wpdb;
        $comments = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $wpdb->comments . '` WHERE comment_approved = %s',
                $status
            )
        );
        $meta = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . $wpdb->commentmeta . '` AS cm'
                . ' INNER JOIN `' . $wpdb->comments . '` AS c ON cm.comment_id = c.comment_ID'
                . ' WHERE c.comment_approved = %s',
                $status
            )
        );
        return $comments + $meta;
    }

    /**
     * Delete posts of one post type plus their postmeta.
     *
     * @param string $postType Fixed post type (never user input).
     * @return int Rows deleted (posts + postmeta).
     */
    private static function deletePostsByType(string $postType): int
    {
        global $wpdb;
        $deleted = 0;
        $deleted += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE pm FROM `' . $wpdb->postmeta . '` AS pm'
                . ' INNER JOIN `' . $wpdb->posts . '` AS p ON pm.post_id = p.ID'
                . ' WHERE p.post_type = %s',
                $postType
            )
        );
        $deleted += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . $wpdb->posts . '` WHERE post_type = %s',
                $postType
            )
        );
        return $deleted;
    }

    /**
     * Delete posts of one post status plus their postmeta.
     *
     * @param string $status Fixed post status (never user input).
     * @return int Rows deleted (posts + postmeta).
     */
    private static function deletePostsByStatus(string $status): int
    {
        global $wpdb;
        $deleted = 0;
        $deleted += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE pm FROM `' . $wpdb->postmeta . '` AS pm'
                . ' INNER JOIN `' . $wpdb->posts . '` AS p ON pm.post_id = p.ID'
                . ' WHERE p.post_status = %s',
                $status
            )
        );
        $deleted += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . $wpdb->posts . '` WHERE post_status = %s',
                $status
            )
        );
        return $deleted;
    }

    /**
     * Delete comments of one approval status plus their commentmeta.
     *
     * @param string $status Fixed comment status (never user input).
     * @return int Rows deleted (comments + commentmeta).
     */
    private static function deleteCommentsByStatus(string $status): int
    {
        global $wpdb;
        $deleted = 0;
        $deleted += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE cm FROM `' . $wpdb->commentmeta . '` AS cm'
                . ' INNER JOIN `' . $wpdb->comments . '` AS c ON cm.comment_id = c.comment_ID'
                . ' WHERE c.comment_approved = %s',
                $status
            )
        );
        $deleted += (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM `' . $wpdb->comments . '` WHERE comment_approved = %s',
                $status
            )
        );
        return $deleted;
    }
}
