<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\RelatedPosts;

/**
 * Pure scoring for related posts.
 *
 * This class has no WordPress dependencies so the ranking can be
 * unit-tested without a WordPress install: it only works on plain
 * arrays of candidate data. The module (RelatedPostsModule) is
 * responsible for fetching the candidates from WordPress and for
 * the fallback chain; this class only ranks them.
 *
 * Weights: a shared tag is worth 3, a shared category 2, and the
 * same author 1 — so a post sharing one tag outranks a post that
 * only shares a category or only shares the author, and a post
 * sharing two tags outranks one sharing a category and the author.
 * Ties break towards the more recent post, then towards the
 * higher post id, so the order is fully deterministic.
 */
final class RelatedPostsScorer
{
    /**
     * Weight of one shared tag.
     */
    public const int TAG_WEIGHT = 3;

    /**
     * Weight of one shared category.
     */
    public const int CATEGORY_WEIGHT = 2;

    /**
     * Weight of the same author.
     */
    public const int AUTHOR_WEIGHT = 1;

    private function __construct()
    {
    }

    /**
     * Score one candidate against the current post.
     *
     * @param int $sharedTags Number of tags shared with the current post.
     * @param int $sharedCategories Number of categories shared with the current post.
     * @param bool $sameAuthor Whether the candidate has the same author.
     * @return int Weighted score (0 when nothing is shared).
     */
    public static function score(int $sharedTags, int $sharedCategories, bool $sameAuthor): int
    {
        $score = $sharedTags * self::TAG_WEIGHT;
        $score += $sharedCategories * self::CATEGORY_WEIGHT;
        if ($sameAuthor) {
            $score += self::AUTHOR_WEIGHT;
        }
        return $score;
    }

    /**
     * Rank candidates and return their ids in display order.
     *
     * Each candidate is an array with keys `id` (int), `tags`
     * (int[]), `categories` (int[]), `author` (int) and `date`
     * (int Unix timestamp). Candidates are sorted by score
     * descending, then by date descending, then by id descending
     * as a final deterministic tiebreak. Candidates scoring 0
     * are kept (they sort last); the caller decides whether a
     * zero-score candidate may be shown at all.
     *
     * @param array<int, array{id: int, tags: array<int, int>, categories: array<int, int>,
     *     author: int, date: int}> $candidates Candidate posts.
     * @param array<int, int> $tagIds Tag ids of the current post.
     * @param array<int, int> $categoryIds Category ids of the current post.
     * @param int $authorId Author id of the current post (0 when unknown).
     * @return array<int, int> Candidate ids in display order.
     */
    public static function order(array $candidates, array $tagIds, array $categoryIds, int $authorId): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            $sharedTags = count(array_intersect($candidate['tags'], $tagIds));
            $sharedCategories = count(array_intersect($candidate['categories'], $categoryIds));
            $sameAuthor = $authorId > 0 && $candidate['author'] === $authorId;
            $scored[] = [
                'id' => $candidate['id'],
                'score' => self::score($sharedTags, $sharedCategories, $sameAuthor),
                'date' => $candidate['date'],
            ];
        }
        usort(
            $scored,
            static function (array $a, array $b): int {
                if ($a['score'] !== $b['score']) {
                    return $b['score'] <=> $a['score'];
                }
                if ($a['date'] !== $b['date']) {
                    return $b['date'] <=> $a['date'];
                }
                return $b['id'] <=> $a['id'];
            }
        );
        return array_map(static fn (array $row): int => $row['id'], $scored);
    }

    /**
     * Top up a picked list from a fallback list, in order.
     *
     * Fallback ids already present in $picked (or the current post
     * id, when the caller passes it through $picked) are skipped,
     * and the result is capped at $count. Used for both fallback
     * steps: latest posts in the primary category, then latest
     * posts overall.
     *
     * @param array<int, int> $picked Already picked post ids, in order.
     * @param array<int, int> $fallbackIds Fallback post ids, in preference order.
     * @param int $count Maximum number of ids to return.
     * @return array<int, int> Picked ids topped up from the fallback list.
     */
    public static function fill(array $picked, array $fallbackIds, int $count): array
    {
        if (count($picked) >= $count) {
            return array_slice(array_values($picked), 0, $count);
        }
        foreach ($fallbackIds as $id) {
            if (count($picked) >= $count) {
                break;
            }
            if (in_array($id, $picked, true)) {
                continue;
            }
            $picked[] = $id;
        }
        return array_values($picked);
    }
}
