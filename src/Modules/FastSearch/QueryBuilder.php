<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FastSearch;

use wpdb;
use function array_map;
use function count;
use function implode;
use function is_string;
use function mb_strlen;
use function preg_replace;
use function preg_split;
use function trim;

/**
 * Boolean query builder for the Fast Search FULLTEXT search.
 *
 * Turns a raw frontend search string into a MySQL boolean-mode
 * query string, using the same design as the reference gist
 * (TrayDigita WP Fast Search) but written here from scratch:
 * boolean operators and other special characters are stripped,
 * words shorter than two characters are dropped (MySQL's default
 * fulltext minimum word length ignores them anyway), and every
 * remaining word becomes a required prefix token (`+word*`).
 *
 * The builder is pure string work — no database access — so the
 * output is fully unit-testable without WordPress. The resulting
 * string is always passed to MySQL through `$wpdb->prepare()`
 * as a `%s` placeholder, never interpolated into SQL.
 */
final class QueryBuilder
{
    /**
     * Maximum number of words kept from one search string.
     *
     * Caps the MATCH ... AGAINST argument so a pasted paragraph
     * cannot produce an unbounded boolean query.
     */
    public const int MAX_WORDS = 20;

    /**
     * Maximum number of posts matched through taxonomy terms.
     *
     * Term-matched post ids are fetched with a LIMIT so a very
     * common term cannot blow up the `IN (...)` list.
     */
    public const int TERM_POST_LIMIT = 500;

    /**
     * Characters stripped from the raw search string.
     *
     * MySQL boolean-mode operators (`+ - < > ( ) ~ * "`) plus `@`
     * (which has no boolean meaning but is noise in a search).
     * They are replaced with spaces so `hello+world` becomes two
     * words instead of one glued token.
     */
    private const string STRIP_PATTERN = '/[+\-><\(\)~*"@]+/';

    /**
     * Build a MySQL boolean-mode query string from raw user input.
     *
     * Returns an empty string when nothing usable remains (empty
     * input, only special characters, or only one-character words);
     * callers must fall back to the standard WordPress search in
     * that case instead of running an empty MATCH.
     *
     * @param string $input Raw search string, e.g. from `?s=`.
     * @return string Boolean query, e.g. `+hello* +world*`, or ''.
     */
    public static function buildBooleanQuery(string $input): string
    {
        $cleaned = preg_replace(self::STRIP_PATTERN, ' ', $input);
        if (!is_string($cleaned)) {
            return '';
        }
        $words = preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }
        $tokens = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }
            $tokens[] = '+' . $word . '*';
            if (count($tokens) >= self::MAX_WORDS) {
                break;
            }
        }
        return implode(' ', $tokens);
    }

    /**
     * Find post ids whose category or tag name matches the query.
     *
     * Runs a FULLTEXT MATCH on the terms table (which needs the
     * `idx_td_terms_name_ft` index) and walks the term taxonomy
     * and relationship tables to the posts. The result is capped
     * at TERM_POST_LIMIT ids.
     *
     * @param wpdb $wpdb Database connection.
     * @param string $booleanQuery Output of buildBooleanQuery().
     * @return array<int> Post ids, possibly empty.
     */
    public static function termPostIds(wpdb $wpdb, string $booleanQuery): array
    {
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT tr.object_id
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy IN ('category', 'post_tag')
                AND MATCH(t.name) AGAINST (%s IN BOOLEAN MODE)
                LIMIT %d",
                $booleanQuery,
                self::TERM_POST_LIMIT
            )
        );
        if (!is_array($ids)) {
            return [];
        }
        return array_map('intval', $ids);
    }

    /**
     * Build the `posts_search` replacement clause for a search.
     *
     * Returns a MATCH ... AGAINST clause over the posts FULLTEXT
     * index, OR-ed with the term-matched post ids when any exist,
     * so a search for a category or tag name finds its posts even
     * when the words never appear in the title or content.
     *
     * @param wpdb $wpdb Database connection.
     * @param string $booleanQuery Output of buildBooleanQuery().
     * @return string SQL fragment starting with ` AND ...`.
     */
    public static function buildSearchClause(wpdb $wpdb, string $booleanQuery): string
    {
        $match = $wpdb->prepare(
            "MATCH({$wpdb->posts}.post_title, {$wpdb->posts}.post_content, {$wpdb->posts}.post_excerpt) AGAINST (%s IN BOOLEAN MODE)",
            $booleanQuery
        );
        $termPostIds = self::termPostIds($wpdb, $booleanQuery);
        if ($termPostIds === []) {
            return " AND {$match} ";
        }
        $idsIn = implode(',', array_map('intval', $termPostIds));
        return " AND ( {$match} OR {$wpdb->posts}.ID IN ({$idsIn}) ) ";
    }
}
