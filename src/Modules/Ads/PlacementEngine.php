<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Ads;

use DOMDocument;
use DOMElement;
use DOMNode;
use function array_filter;
use function array_intersect;
use function array_values;
use function ceil;
use function count;
use function hash;
use function hexdec;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function max;
use function min;
use function str_replace;
use function substr;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;

/**
 * Paragraph-aware ad placement engine for the Ads Inserter module.
 *
 * Pure static class: it calls no WordPress functions, so it can be
 * exercised by a standalone CLI harness (see /tmp/ads-placement-harness.php)
 * and so the render path performs no I/O of its own. The caller
 * (AdsModule) normalizes the rules and builds the request context
 * from WordPress; this class only decides where ads go.
 *
 * Rule shape (normalized by AdsModule::sanitizeRules()):
 * - id: string, stable rule id (used for the seeded-random placement).
 * - enabled: bool.
 * - ad_code: string, raw ad code (may contain <script>/<ins>).
 * - placement: one of the PLACEMENT_* constants.
 * - paragraph: int, 1-based target for PLACEMENT_AFTER_PARAGRAPH.
 * - percent: int, 1-99 target for PLACEMENT_AFTER_PERCENT.
 * - post_types: array<int, string>, allowlist (empty matches nothing).
 * - include_categories / exclude_categories: array<int, int> term ids.
 * - include_tags / exclude_tags: array<int, int> term ids.
 * - exclude_home_archive: bool, true means singular pages only.
 * - device: 'all', 'desktop' or 'mobile'.
 *
 * Context shape (built by AdsModule::filterContent() and AdsAjax):
 * - post_type: string.
 * - category_ids / tag_ids: array<int, int> term ids of the post.
 * - is_singular / is_home / is_archive / is_search: bool.
 * - is_mobile: bool (wp_is_mobile() on the live path).
 * - ads_remaining: int, how many more ads the page budget allows.
 * - already_inserted: array<int, string>, rule ids already placed
 *   for this post on this request.
 *
 * Paragraph counting uses DOMDocument and counts only top-level
 * paragraphs: direct <p> children of the content root. Paragraphs
 * nested inside <blockquote>, <pre>, <table>, <figure> or
 * <figcaption> are never counted and never receive an ad, because
 * they are not direct children of the root. A naive
 * explode('</p>') would count those and would break on malformed
 * markup, so it is never used here.
 *
 * Ad code is inserted through renderAdCode() only (see its docblock
 * for the trust model). The engine never parses ad code as XML or
 * HTML: paragraph insertions are staged as HTML comment markers in
 * the DOM and replaced with the raw ad code by string replacement
 * afterwards, so arbitrary ad markup (including inline scripts
 * containing '<' or '&') survives byte-for-byte.
 *
 * When nothing can be inserted (no applicable rule, no paragraph
 * target, budget exhausted), the original content is returned
 * untouched - it is never re-serialized through DOMDocument.
 */
final class PlacementEngine
{
    /**
     * Insert before the content.
     */
    public const string PLACEMENT_BEFORE_CONTENT = 'before_content';

    /**
     * Insert after paragraph N (1-based, configurable).
     */
    public const string PLACEMENT_AFTER_PARAGRAPH = 'after_paragraph';

    /**
     * Insert after a random paragraph, seeded by post id + rule id
     * so the position is stable for a given post (never jumping
     * between loads).
     */
    public const string PLACEMENT_AFTER_RANDOM_PARAGRAPH = 'after_random_paragraph';

    /**
     * Insert after X% of the content (paragraph count based).
     */
    public const string PLACEMENT_AFTER_PERCENT = 'after_percent';

    /**
     * Insert before the last paragraph.
     */
    public const string PLACEMENT_BEFORE_LAST_PARAGRAPH = 'before_last_paragraph';

    /**
     * Insert after the content.
     */
    public const string PLACEMENT_AFTER_CONTENT = 'after_content';

    /**
     * All placement values, in the order the settings form offers them.
     *
     * @var array<int, string>
     */
    public const array PLACEMENTS = [
        self::PLACEMENT_BEFORE_CONTENT,
        self::PLACEMENT_AFTER_PARAGRAPH,
        self::PLACEMENT_AFTER_RANDOM_PARAGRAPH,
        self::PLACEMENT_AFTER_PERCENT,
        self::PLACEMENT_BEFORE_LAST_PARAGRAPH,
        self::PLACEMENT_AFTER_CONTENT,
    ];

    /**
     * Placements that target a paragraph inside the content (and
     * therefore need the DOM pass). Before/after content are plain
     * string prepend/append operations.
     *
     * @var array<int, string>
     */
    public const array PARAGRAPH_PLACEMENTS = [
        self::PLACEMENT_AFTER_PARAGRAPH,
        self::PLACEMENT_AFTER_RANDOM_PARAGRAPH,
        self::PLACEMENT_AFTER_PERCENT,
        self::PLACEMENT_BEFORE_LAST_PARAGRAPH,
    ];

    /**
     * Device values a rule may carry.
     *
     * @var array<int, string>
     */
    public const array DEVICES = ['all', 'desktop', 'mobile'];

    /**
     * Prefix of the HTML comment markers staged in the DOM.
     *
     * The full marker is `<!--traydigita-ad:<rule-id>-->`. Rule ids
     * are sanitize_key() output, so they can never contain '--'.
     */
    public const string MARKER_PREFIX = 'traydigita-ad:';

    /**
     * The single dedicated output path for ad code.
     *
     * Returns the code exactly as stored, without escaping,
     * sanitizing or filtering of any kind. Ad code is trusted
     * markup (typically AdSense <script>/<ins> pairs): any
     * escaping here would break the ad. The trust boundary is at
     * save time, not at output time - only users with
     * `manage_options` can save ad code (see
     * AdsModule::saveSettings()), the same trust level WordPress
     * grants the Custom HTML widget. Every insertion in this
     * engine goes through this function and no other path outputs
     * ad code.
     *
     * @param string $code Raw ad code as stored.
     * @return string The code, unchanged.
     */
    public static function renderAdCode(string $code): string
    {
        return $code;
    }

    /**
     * Apply the applicable rules to one post's content.
     *
     * Each rule inserts at most once per call. Rules are evaluated
     * in the order given; the page budget (`ads_remaining`) is
     * shared across all rules, so earlier rules win when the
     * budget runs out. A rule whose target does not exist (for
     * example after paragraph 9 in a 4-paragraph post) renders
     * nothing and consumes no budget.
     *
     * @param string $content Post content HTML (already filtered, e.g. after wpautop).
     * @param array<int, array<string, mixed>> $rules Normalized rules.
     * @param int $postId Id of the post being rendered.
     * @param array<string, mixed> $context Request context (see class docblock).
     * @return array{content: string, inserted: array<int, string>} Content with ads and the inserted rule ids.
     */
    public static function insertAds(string $content, array $rules, int $postId, array $context): array
    {
        $remaining = self::contextInt($context, 'ads_remaining', 0);
        $alreadyInserted = self::contextStringList($context, 'already_inserted');

        // Phase 1: applicable rules, in rule order. A rule applies
        // when it passes ruleApplies() and carries a known placement;
        // duplicates and rules already inserted for this post are
        // dropped. No budget is consumed yet.
        $applicable = [];
        $seenIds = [];
        foreach ($rules as $rule) {
            $ruleId = self::ruleString($rule, 'id');
            if ($ruleId === '' || isset($seenIds[$ruleId]) || in_array($ruleId, $alreadyInserted, true)) {
                continue;
            }
            $seenIds[$ruleId] = true;
            if (!self::ruleApplies($rule, $context)) {
                continue;
            }
            $placement = self::ruleString($rule, 'placement');
            if (!in_array($placement, self::PLACEMENTS, true)) {
                continue;
            }
            $applicable[] = $rule;
        }
        if ($applicable === [] || $remaining <= 0) {
            return ['content' => $content, 'inserted' => []];
        }

        // Phase 2: resolve paragraph targets in one DOM pass, so the
        // budget can be allocated in rule order below. A target of 0
        // means the rule has nowhere to go (for example paragraph 9
        // in a 4-paragraph post).
        $paragraphRules = [];
        foreach ($applicable as $rule) {
            if (in_array(self::ruleString($rule, 'placement'), self::PARAGRAPH_PLACEMENTS, true)) {
                $paragraphRules[] = $rule;
            }
        }
        /** @var array<string, int> $targets Target paragraph (1-based) keyed by rule id, 0 when missing. */
        $targets = [];
        $parsed = null;
        if ($paragraphRules !== []) {
            $parsed = self::parseContent($content);
            $paragraphCount = $parsed !== null ? count($parsed['paragraphs']) : 0;
            foreach ($paragraphRules as $rule) {
                $targets[self::ruleString($rule, 'id')] = $paragraphCount > 0
                    ? self::paragraphTarget($rule, $postId, $paragraphCount)
                    : 0;
            }
        }

        // Phase 3: allocate the budget in rule order. Before/after
        // content rules always insert once applicable; a paragraph
        // rule inserts only when its target exists, and a missing
        // target consumes no budget, so a later rule can still use it.
        $inserted = [];
        $prepend = [];
        $append = [];
        $domInsertions = [];
        foreach ($applicable as $rule) {
            if ($remaining <= 0) {
                break;
            }
            $ruleId = self::ruleString($rule, 'id');
            $placement = self::ruleString($rule, 'placement');
            if ($placement === self::PLACEMENT_BEFORE_CONTENT) {
                $prepend[] = $rule;
                $inserted[] = $ruleId;
                $remaining--;
                continue;
            }
            if ($placement === self::PLACEMENT_AFTER_CONTENT) {
                $append[] = $rule;
                $inserted[] = $ruleId;
                $remaining--;
                continue;
            }
            if (($targets[$ruleId] ?? 0) === 0) {
                continue;
            }
            $domInsertions[] = $rule;
            $inserted[] = $ruleId;
            $remaining--;
        }
        if ($inserted === []) {
            return ['content' => $content, 'inserted' => []];
        }

        // Phase 4: execute. The DOM insertions run first (on the
        // parsed document), then the before/after content ads are
        // prepended/appended as plain strings, so ad code never
        // passes through the DOM parser.
        if ($domInsertions !== [] && $parsed !== null) {
            $content = self::insertMarkers($content, $parsed, $domInsertions, $targets);
        }
        foreach ($prepend as $rule) {
            $content = self::renderAdCode(self::ruleString($rule, 'ad_code')) . $content;
        }
        foreach ($append as $rule) {
            $content .= self::renderAdCode(self::ruleString($rule, 'ad_code'));
        }
        return ['content' => $content, 'inserted' => $inserted];
    }

    /**
     * Deterministic "random" paragraph index for a post + rule.
     *
     * Derived from a crc32b hash of "postId:ruleId", so the same
     * post always gets the same paragraph for a given rule, while
     * different posts may differ. The result is 1-based and always
     * within 1..$paragraphCount.
     *
     * @param int $postId Post id.
     * @param string $ruleId Stable rule id.
     * @param int $paragraphCount Number of top-level paragraphs.
     * @return int 1-based paragraph index, or 0 when there is no paragraph.
     */
    public static function seededParagraphIndex(int $postId, string $ruleId, int $paragraphCount): int
    {
        if ($paragraphCount <= 0) {
            return 0;
        }
        $hash = hash('crc32b', $postId . ':' . $ruleId);
        $value = hexdec(substr($hash, 0, 8));
        return (int) ($value % $paragraphCount) + 1;
    }

    /**
     * Whether a rule applies to the current request context.
     *
     * Checks, in order: the rule is enabled and has ad code; the
     * post type is allowlisted; the include/exclude category and
     * tag conditions match; the singular-only flag (when set)
     * restricts the rule to singular pages; the device matches.
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param array<string, mixed> $context Request context.
     * @return bool
     */
    public static function ruleApplies(array $rule, array $context): bool
    {
        if (self::ruleBool($rule, 'enabled') === false) {
            return false;
        }
        if (self::ruleString($rule, 'ad_code') === '') {
            return false;
        }
        $postTypes = self::ruleStringList($rule, 'post_types');
        if (!in_array(self::contextString($context, 'post_type', ''), $postTypes, true)) {
            return false;
        }
        $categoryIds = self::contextIntList($context, 'category_ids');
        $tagIds = self::contextIntList($context, 'tag_ids');
        $includeCategories = self::ruleIntList($rule, 'include_categories');
        if ($includeCategories !== [] && array_intersect($includeCategories, $categoryIds) === []) {
            return false;
        }
        $excludeCategories = self::ruleIntList($rule, 'exclude_categories');
        if ($excludeCategories !== [] && array_intersect($excludeCategories, $categoryIds) !== []) {
            return false;
        }
        $includeTags = self::ruleIntList($rule, 'include_tags');
        if ($includeTags !== [] && array_intersect($includeTags, $tagIds) === []) {
            return false;
        }
        $excludeTags = self::ruleIntList($rule, 'exclude_tags');
        if ($excludeTags !== [] && array_intersect($excludeTags, $tagIds) !== []) {
            return false;
        }
        if (self::ruleBool($rule, 'exclude_home_archive') && !self::contextBool($context, 'is_singular', false)) {
            return false;
        }
        $device = self::ruleString($rule, 'device');
        if ($device === 'desktop' && self::contextBool($context, 'is_mobile', false)) {
            return false;
        }
        if ($device === 'mobile' && !self::contextBool($context, 'is_mobile', false)) {
            return false;
        }
        return true;
    }

    /**
     * Parse content into a DOM document wrapped in a <div>.
     *
     * Returns null when the content cannot be parsed. libxml errors
     * are suppressed internally and the previous error-handling
     * state is restored, so parsing never emits warnings.
     *
     * @param string $content Post content HTML.
     * @return array{dom: DOMDocument, wrapper: DOMElement, paragraphs: array<int, DOMNode>}|null
     */
    private static function parseContent(string $content): ?array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousUseErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8" ?>' . '<div>' . $content . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);
        if ($loaded === false) {
            return null;
        }
        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if (!$wrapper instanceof DOMElement) {
            return null;
        }
        return [
            'dom' => $dom,
            'wrapper' => $wrapper,
            'paragraphs' => self::topLevelParagraphs($wrapper),
        ];
    }

    /**
     * Stage HTML comment markers at the resolved targets, serialize
     * the wrapper's inner HTML, and replace the markers with the
     * raw ad code.
     *
     * Markers keep ad code out of the DOM parser: the code is
     * inserted by string replacement afterwards, so arbitrary ad
     * markup (including inline scripts containing '<' or '&')
     * survives byte-for-byte. Two rules targeting the same
     * paragraph keep their rule order: an "after" marker goes
     * after the last marker already staged after that paragraph,
     * and a "before" marker goes immediately before the paragraph
     * (after any marker already staged there).
     *
     * @param string $original Original content, returned unchanged when no marker could be staged.
     * @param array{dom: DOMDocument, wrapper: DOMElement, paragraphs: array<int, DOMNode>} $parsed Parsed content.
     * @param array<int, array<string, mixed>> $rules Rules to insert, in rule order.
     * @param array<string, int> $targets Target paragraph (1-based) keyed by rule id.
     * @return string Content with ads inserted.
     */
    private static function insertMarkers(string $original, array $parsed, array $rules, array $targets): string
    {
        $dom = $parsed['dom'];
        $wrapper = $parsed['wrapper'];
        $paragraphs = $parsed['paragraphs'];
        /** @var array<string, string> $markers Marker text keyed by rule id. */
        $markers = [];
        /** @var array<int, DOMNode> $lastAfterNode Last node inserted after each target paragraph. */
        $lastAfterNode = [];
        foreach ($rules as $rule) {
            $ruleId = self::ruleString($rule, 'id');
            if ($ruleId === '' || isset($markers[$ruleId])) {
                continue;
            }
            $target = $targets[$ruleId] ?? 0;
            if ($target < 1) {
                continue;
            }
            $paragraph = $paragraphs[$target - 1] ?? null;
            if (!$paragraph instanceof DOMNode) {
                continue;
            }
            $marker = $dom->createComment(self::MARKER_PREFIX . $ruleId);
            if (self::ruleString($rule, 'placement') === self::PLACEMENT_BEFORE_LAST_PARAGRAPH) {
                $wrapper->insertBefore($marker, $paragraph);
            } else {
                $anchor = $lastAfterNode[$target] ?? $paragraph;
                $wrapper->insertBefore($marker, $anchor->nextSibling);
                $lastAfterNode[$target] = $marker;
            }
            $markers[$ruleId] = '<!--' . self::MARKER_PREFIX . $ruleId . '-->';
        }
        if ($markers === []) {
            return $original;
        }
        $html = self::innerHtml($dom, $wrapper);
        foreach ($rules as $rule) {
            $ruleId = self::ruleString($rule, 'id');
            if (!isset($markers[$ruleId])) {
                continue;
            }
            $html = str_replace($markers[$ruleId], self::renderAdCode(self::ruleString($rule, 'ad_code')), $html);
        }
        return $html;
    }

    /**
     * Compute the 1-based target paragraph for a paragraph rule.
     *
     * Returns 0 when the rule has no target (for example paragraph
     * N is beyond the last paragraph, or the percentage resolves
     * outside the content). A missing target renders nothing.
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param int $postId Post id (seeds the random placement).
     * @param int $paragraphCount Number of top-level paragraphs.
     * @return int 1-based paragraph index, or 0 when there is no target.
     */
    private static function paragraphTarget(array $rule, int $postId, int $paragraphCount): int
    {
        $placement = self::ruleString($rule, 'placement');
        if ($placement === self::PLACEMENT_AFTER_PARAGRAPH) {
            $target = self::ruleInt($rule, 'paragraph');
            return $target >= 1 && $target <= $paragraphCount ? $target : 0;
        }
        if ($placement === self::PLACEMENT_AFTER_RANDOM_PARAGRAPH) {
            return self::seededParagraphIndex($postId, self::ruleString($rule, 'id'), $paragraphCount);
        }
        if ($placement === self::PLACEMENT_AFTER_PERCENT) {
            $percent = self::ruleInt($rule, 'percent');
            if ($percent < 1 || $percent > 99) {
                return 0;
            }
            $target = (int) ceil($paragraphCount * $percent / 100);
            return max(1, min($paragraphCount, $target));
        }
        if ($placement === self::PLACEMENT_BEFORE_LAST_PARAGRAPH) {
            return $paragraphCount;
        }
        return 0;
    }

    /**
     * Direct <p> children of the content root, in document order.
     *
     * Only direct children count: a <p> inside a <blockquote>,
     * <pre>, <table>, <figure> or <figcaption> is a descendant of
     * another element, never a direct child of the root, so it is
     * excluded by construction.
     *
     * @param DOMElement $wrapper Content root element.
     * @return array<int, DOMNode>
     */
    private static function topLevelParagraphs(DOMElement $wrapper): array
    {
        $paragraphs = [];
        foreach ($wrapper->childNodes as $child) {
            if ($child instanceof DOMNode && $child->nodeName === 'p') {
                $paragraphs[] = $child;
            }
        }
        return $paragraphs;
    }

    /**
     * Inner HTML of the wrapper element (its children serialized).
     *
     * Serializing child by child keeps the wrapper <div> itself out
     * of the output.
     *
     * @param DOMDocument $dom Owning document.
     * @param DOMElement $wrapper Content root element.
     * @return string
     */
    private static function innerHtml(DOMDocument $dom, DOMElement $wrapper): string
    {
        $html = '';
        foreach ($wrapper->childNodes as $child) {
            $serialized = $dom->saveHTML($child);
            if (is_string($serialized)) {
                $html .= $serialized;
            }
        }
        return $html;
    }

    /**
     * Read a rule field as a string (empty string for anything else).
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param string $key Field name.
     * @return string
     */
    private static function ruleString(array $rule, string $key): string
    {
        $value = $rule[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /**
     * Read a rule field as an int (0 for anything else).
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param string $key Field name.
     * @return int
     */
    private static function ruleInt(array $rule, string $key): int
    {
        $value = $rule[$key] ?? 0;
        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (int) $value;
        }
        return is_int($value) ? $value : 0;
    }

    /**
     * Read a rule field as a bool (false for anything else).
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param string $key Field name.
     * @return bool
     */
    private static function ruleBool(array $rule, string $key): bool
    {
        return (bool) ($rule[$key] ?? false);
    }

    /**
     * Read a rule field as a list of strings.
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param string $key Field name.
     * @return array<int, string>
     */
    private static function ruleStringList(array $rule, string $key): array
    {
        $value = $rule[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== ''
        ));
    }

    /**
     * Read a rule field as a list of ints.
     *
     * @param array<string, mixed> $rule Normalized rule.
     * @param string $key Field name.
     * @return array<int, int>
     */
    private static function ruleIntList(array $rule, string $key): array
    {
        $value = $rule[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $ints = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $ints[] = $item;
                continue;
            }
            if (is_string($item) && is_numeric($item)) {
                $ints[] = (int) $item;
            }
        }
        return $ints;
    }

    /**
     * Read a context field as a string.
     *
     * @param array<string, mixed> $context Request context.
     * @param string $key Field name.
     * @param string $default Fallback value.
     * @return string
     */
    private static function contextString(array $context, string $key, string $default): string
    {
        $value = $context[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * Read a context field as an int.
     *
     * @param array<string, mixed> $context Request context.
     * @param string $key Field name.
     * @param int $default Fallback value.
     * @return int
     */
    private static function contextInt(array $context, string $key, int $default): int
    {
        $value = $context[$key] ?? $default;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Read a context field as a bool.
     *
     * @param array<string, mixed> $context Request context.
     * @param string $key Field name.
     * @param bool $default Fallback value.
     * @return bool
     */
    private static function contextBool(array $context, string $key, bool $default): bool
    {
        $value = $context[$key] ?? $default;
        return is_bool($value) ? $value : $default;
    }

    /**
     * Read a context field as a list of ints.
     *
     * @param array<string, mixed> $context Request context.
     * @param string $key Field name.
     * @return array<int, int>
     */
    private static function contextIntList(array $context, string $key): array
    {
        $value = $context[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        $ints = [];
        foreach ($value as $item) {
            if (is_int($item)) {
                $ints[] = $item;
            } elseif (is_string($item) && is_numeric($item)) {
                $ints[] = (int) $item;
            }
        }
        return $ints;
    }

    /**
     * Read a context field as a list of strings.
     *
     * @param array<string, mixed> $context Request context.
     * @param string $key Field name.
     * @return array<int, string>
     */
    private static function contextStringList(array $context, string $key): array
    {
        $value = $context[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== ''
        ));
    }
}
