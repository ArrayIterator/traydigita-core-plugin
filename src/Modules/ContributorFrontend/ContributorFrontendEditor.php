<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ContributorFrontend;

use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Modules\Avatar\AvatarStorage;
use TrayDigita\WP\Plugin\Core\Bookmarks;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Customizer\Settings;
use function __;
use function absint;
use function add_query_arg;
use function admin_url;
use function array_filter;
use function array_map;
use function array_merge;
use function array_shift;
use function array_slice;
use function ceil;
use function count;
use function current_user_can;
use function email_exists;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function esc_url_raw;
use function get_current_user_id;
use function get_permalink;
use function get_post;
use function get_post_meta;
use function get_post_status_object;
use function get_post_thumbnail_id;
use function get_posts;
use function get_preview_post_link;
use function get_queried_object_id;
use function get_term;
use function get_terms;
use function get_the_category;
use function get_the_date;
use function get_theme_mod;
use function get_user_meta;
use function get_userdata;
use function home_url;
use function implode;
use function in_array;
use function is_array;
use function is_callable;
use function is_email;
use function is_scalar;
use function is_string;
use function is_user_logged_in;
use function is_wp_error;
use function json_decode;
use function number_format_i18n;
use function parse_blocks;
use function preg_match;
use function preg_split;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function selected;
use function send_confirmation_on_profile_email;
use function serialize_block;
use function sprintf;
use function str_replace;
use function strpos;
use function substr_count;
use function trim;
use function usort;
use function wp_count_posts;
use function wp_create_nonce;
use function wp_delete_post;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_get_attachment_image_src;
use function wp_get_post_categories;
use function wp_insert_post;
use function wp_json_encode;
use function wp_kses;
use function wp_login_url;
use function wp_logout_url;
use function wp_nonce_field;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_strip_all_tags;
use function wp_trash_post;
use function wp_unslash;
use function wp_untrash_post;
use function wp_update_post;
use function wp_update_user;
use function wp_verify_nonce;

/**
 * Frontend member dashboard.
 *
 * Renders the `[traydigita_contributor_editor]` shortcode: one
 * unified dashboard shell (sidebar + top bar) for every
 * logged-in user, with sections from the member registry (see
 * ContributorFrontendModule::memberSections()): Overview,
 * Posts, My Contributions, Write, Profile and Change Email.
 * Guests see a login prompt. Every section renders only when
 * the user has the capability for it AND the underlying
 * feature is available; a hidden menu never means an
 * accessible URL — the view resolver redirects server-side.
 *
 * The contributor-only rule applies to the EDITOR section
 * only: non-contributors hitting Write are redirected to
 * wp-admin. Everyone else — including subscribers — sees the
 * shell: Posts lists published posts plus their own
 * non-published ones (editors additionally see the pending
 * review queue), and My Contributions always lists the
 * user's own posts, even after a demotion to subscriber
 * (read-only when they no longer have edit_posts).
 *
 * Writing uses a simple block editor (paragraph, heading, list
 * and quote only). The editor is a small React bundle that
 * talks to the global `wp` object shipped by WordPress core;
 * the plain textarea stays in the form underneath it, so the
 * form still works when the editor scripts fail to load. On
 * save the submitted blocks are parsed and filtered
 * server-side (see sanitizeBlocks()): anything outside the
 * allowlist is stripped, never trusted from the browser.
 *
 * The status matrix, enforced here and in the module:
 *
 * - draft: edit + trash.
 * - pending (in review): read-only + preview.
 * - needs-revision: edit + resubmit (the editor's note shows
 *   when one exists).
 * - published (and scheduled): read-only + view live.
 * - rejected: read-only + preview + trash.
 * - trash: restore + delete permanently.
 *
 * Delete always means trash first (wp_trash_post); permanent
 * deletion (wp_delete_post) is only offered for posts already
 * in the trash. Contributors may only ever produce draft or
 * pending posts — the editorial statuses are enforced in
 * ContributorFrontendModule::enforceEditorialStatus().
 */
final class ContributorFrontendEditor
{
    /**
     * Query arg flagging a just-saved submission.
     */
    public const string SAVED_FLAG = 'traydigita_saved';

    /**
     * Query arg carrying a save error code.
     */
    public const string ERROR_FLAG = 'traydigita_error';

    /**
     * Query arg carrying an info notice code.
     */
    public const string NOTICE_FLAG = 'traydigita_notice';

    /**
     * Query arg set by the admin redirect (see
     * ContributorFrontendModule::maybeRedirectAdmin()).
     */
    public const string NO_DASHBOARD_FLAG = 'traydigita_no_dashboard';

    /**
     * Query arg carrying the post id to edit.
     */
    public const string EDIT_FLAG = 'traydigita_edit';

    /**
     * Query arg carrying the dashboard section.
     */
    public const string VIEW_FLAG = 'traydigita_view';

    /**
     * Query arg carrying the list status filter.
     */
    public const string STATUS_FLAG = 'traydigita_status';

    /**
     * Query arg carrying the list page number.
     */
    public const string PAGE_FLAG = 'traydigita_page';

    /**
     * Posts per list page.
     */
    private const int PER_PAGE = 20;

    /**
     * Statuses an author may edit (draft + needs-revision).
     *
     * @var array<int, string>
     */
    private const array EDITABLE_STATUSES = ['draft', 'needs-revision'];

    /**
     * Statuses an author may trash (draft + rejected).
     *
     * @var array<int, string>
     */
    private const array TRASHABLE_STATUSES = ['draft', 'rejected'];

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
     * Inline formatting tags kept in block content on save.
     *
     * Links are added only when the allow-links setting is on.
     *
     * @var array<string, array<string, bool>>
     */
    private const array INLINE_TAGS = [
        'strong' => [],
        'em' => [],
        'b' => [],
        'i' => [],
        'br' => [],
    ];

    /**
     * Block attributes kept on save, per block type.
     *
     * Everything else (class names, anchors, colours, font
     * sizes) is dropped: the frontend editor offers none of it.
     *
     * @var array<string, array<int, string>>
     */
    private const array ALLOWED_ATTRS = [
        'core/paragraph' => ['align', 'dropCap'],
        'core/heading' => ['level', 'align'],
        'core/list' => ['ordered', 'reversed', 'start'],
        'core/quote' => ['align'],
        'core/list-item' => [],
    ];

    /**
     * Whether renderEditor() refused the requested edit: the
     * top bar must not offer Save / Submit buttons for a form
     * that was never rendered.
     */
    private static bool $editorNotEditable = false;

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
            return self::renderNotice(
                'info',
                sprintf(
                    /* translators: %s: login URL. */
                    __(
                        'Please <a href="%s">log in</a> to use the member area.',
                        'traydigita-core'
                    ),
                    esc_url(wp_login_url(self::currentPageUrl()))
                ),
                true
            );
        }
        return self::renderDashboard();
    }

    /**
     * Handle an editor submission via admin-post.php.
     *
     * Verifies the nonce and the `edit_posts` capability, then
     * inserts or updates a post as a draft ("Save draft") or
     * pending ("Submit for review"). When editing, the post
     * must exist, belong to the current user, be a post, and be
     * a draft or needs-revision — anything else is refused with
     * a `not-editable` error (never silently turned into a new
     * post). Content blocks are filtered server-side against
     * the allowlist. Contributors may only produce draft or
     * pending here. Always redirects back (never renders), so
     * a refresh cannot resubmit.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        $fallback = ContributorFrontendModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $redirect = $fallback;
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, ContributorFrontendModule::SAVE_ACTION)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'nonce', $redirect));
            exit;
        }
        if (!current_user_can('edit_posts')) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'capability', $redirect));
            exit;
        }
        $userId = get_current_user_id();
        $title = isset($_POST['traydigita_title']) && is_string($_POST['traydigita_title'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_title']))
            : '';
        $rawContent = isset($_POST['traydigita_content']) && is_string($_POST['traydigita_content'])
            ? wp_unslash($_POST['traydigita_content'])
            : '';
        $excerpt = isset($_POST['traydigita_excerpt']) && is_string($_POST['traydigita_excerpt'])
            ? sanitize_textarea_field(wp_unslash($_POST['traydigita_excerpt']))
            : '';
        $categoryId = isset($_POST['traydigita_category']) && is_scalar($_POST['traydigita_category'])
            ? absint(wp_unslash((string) $_POST['traydigita_category']))
            : 0;
        $postId = isset($_POST['traydigita_post_id']) && is_scalar($_POST['traydigita_post_id'])
            ? absint(wp_unslash((string) $_POST['traydigita_post_id']))
            : 0;
        $submit = isset($_POST['traydigita_submit']) && is_string($_POST['traydigita_submit'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_submit']))
            : 'draft';
        if ($title === '') {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'title', $redirect));
            exit;
        }
        if ($categoryId > 0) {
            $term = get_term($categoryId, 'category');
            if ($term === null || is_wp_error($term)) {
                $categoryId = 0;
            }
        }
        $isEdit = false;
        if ($postId > 0) {
            $existing = get_post($postId);
            if ($existing !== null
                && (int) $existing->post_author === $userId
                && $existing->post_type === 'post'
                && in_array($existing->post_status, self::EDITABLE_STATUSES, true)
            ) {
                $isEdit = true;
            } else {
                wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'not-editable', $redirect));
                exit;
            }
        }
        $settings = ContributorFrontendModule::getSettings();
        $content = self::sanitizeBlocks($rawContent, $settings);
        $postData = [
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status' => $submit === 'review' ? 'pending' : 'draft',
            'post_type' => 'post',
            'post_author' => $userId,
        ];
        if ($categoryId > 0) {
            $postData['post_category'] = [$categoryId];
        }
        if ($isEdit) {
            $postData['ID'] = $postId;
            $result = wp_update_post($postData, true);
        } else {
            $result = wp_insert_post($postData, true);
        }
        if (is_wp_error($result) || $result === 0) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'save', $redirect));
            exit;
        }
        $savedId = (int) $result;
        if ($submit === 'review') {
            $target = add_query_arg(
                [self::VIEW_FLAG => 'posts', self::STATUS_FLAG => 'pending', self::SAVED_FLAG => '1'],
                self::currentPageUrl()
            );
        } else {
            $target = add_query_arg(
                [self::VIEW_FLAG => 'write', self::EDIT_FLAG => $savedId, self::SAVED_FLAG => '1'],
                self::currentPageUrl()
            );
        }
        wp_safe_redirect($target);
        exit;
    }

    /**
     * Filter submitted block content against the allowlist.
     *
     * Parses the submitted HTML with parse_blocks() and keeps
     * only paragraph, heading, list and quote blocks (and
     * list-item blocks inside a list). Everything else —
     * HTML, code, image, media, embed, reusable and pattern
     * blocks — is dropped entirely. Inline formatting is
     * reduced to bold, italic and line breaks, plus links only
     * when the allow-links setting is on. Block attributes are
     * reduced to a small safe list per block type. Each kept
     * block keeps its own wrapper tag (<p>, <h1>-<h6>, <li>,
     * <ul>/<ol>, <blockquote>), so the saved HTML is valid
     * Gutenberg that round-trips through wp.blocks.parse();
     * wrapper-less legacy content is re-wrapped on save.
     * Legacy plain-text content (no block markup) is converted into
     * paragraph blocks instead of being dropped.
     *
     * @param string $content Submitted content (block HTML).
     * @param array<string, mixed> $settings Module settings.
     * @return string Serialized allowed blocks, or ''.
     */
    public static function sanitizeBlocks(string $content, array $settings): string
    {
        $allowedBlocks = $settings['allowed_blocks'] ?? ContributorFrontendModule::ALLOWED_BLOCKS;
        if (!is_array($allowedBlocks) || $allowedBlocks === []) {
            $allowedBlocks = ContributorFrontendModule::ALLOWED_BLOCKS;
        }
        $allowLinks = !empty($settings['allow_links']);
        $blocks = parse_blocks($content);
        $kept = [];
        foreach ($blocks as $block) {
            $converted = self::sanitizeBlock($block, $allowedBlocks, $allowLinks, false);
            if ($converted !== null) {
                $kept[] = $converted;
            }
        }
        if ($kept === []) {
            return '';
        }
        $out = '';
        foreach ($kept as $block) {
            $out .= serialize_block($block);
        }
        return $out;
    }

    /**
     * Sanitize one parsed block (recursively for inner blocks).
     *
     * Returns null when the block is dropped. A freeform block
     * (no block name — plain pasted text) is converted into
     * paragraph blocks.
     *
     * @param array<string, mixed> $block Parsed block.
     * @param array<int, string> $allowedBlocks Allowed full block names.
     * @param bool $allowLinks Whether links survive sanitizing.
     * @param bool $insideList Whether this block sits inside a list block.
     * @return array<string, mixed>|null Sanitized block, or null to drop it.
     */
    private static function sanitizeBlock(array $block, array $allowedBlocks, bool $allowLinks, bool $insideList): ?array
    {
        $name = $block['blockName'] ?? null;
        if (!is_string($name) || $name === '') {
            return self::freeformToParagraphs($block);
        }
        if ($name === 'core/list-item') {
            if (!$insideList) {
                return null;
            }
            return self::cleanBlock($block, $allowedBlocks, $allowLinks, false);
        }
        if (!in_array($name, $allowedBlocks, true)) {
            return null;
        }
        return self::cleanBlock($block, $allowedBlocks, $allowLinks, $name === 'core/list');
    }

    /**
     * Clean a kept block: sanitize its inner HTML and its
     * attributes, and recurse into its inner blocks.
     *
     * Each allowed block keeps its own wrapper tag, or the
     * saved HTML is not valid Gutenberg: wp.blocks.parse()
     * sources paragraph content from <p>, heading content
     * from <h1>-<h6> and list-item content from <li>, so
     * stripping those wrappers makes the editor read the
     * content back as undefined — and a re-save then
     * overwrites the author's text with that undefined.
     * Leaf blocks are therefore re-wrapped when the wrapper
     * is missing, which also repairs drafts saved before
     * this fix (bare text, no wrapper). Container blocks
     * (list, quote) keep the null placeholders in
     * innerContent: replacing them with one string would
     * drop every inner block on serialize_block(). A quote
     * with no inner blocks at all gets the same repair one
     * level up (see repairQuote()): its bare text is
     * rebuilt as an inner paragraph block, so a legacy
     * quote stripped by the old sanitizer is valid again
     * after one re-save instead of staying invalid forever.
     *
     * @param array<string, mixed> $block Parsed block.
     * @param array<int, string> $allowedBlocks Allowed full block names.
     * @param bool $allowLinks Whether links survive sanitizing.
     * @param bool $isList Whether this block is a list (its inner blocks are list items).
     * @return array<string, mixed>
     */
    private static function cleanBlock(array $block, array $allowedBlocks, bool $allowLinks, bool $isList): array
    {
        $name = is_string($block['blockName'] ?? null) ? (string) $block['blockName'] : '';
        $tags = self::blockTags($name, $allowLinks);
        $isContainer = $name === 'core/list' || $name === 'core/quote';
        if ($isContainer) {
            $innerHtml = is_string($block['innerHTML'] ?? null) ? (string) $block['innerHTML'] : '';
            if ($innerHtml !== '') {
                $block['innerHTML'] = wp_kses($innerHtml, $tags);
            }
            $innerContent = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [];
            $cleanContent = [];
            foreach ($innerContent as $piece) {
                $cleanContent[] = is_string($piece) ? wp_kses($piece, $tags) : $piece;
            }
            if ($name === 'core/quote') {
                // Gutenberg's quote save output carries
                // class="wp-block-quote"; without it the block
                // parses as invalid and the editor logs a block
                // validation error on reopen. The class value is
                // fixed here, never taken from the input: wp_kses
                // above already stripped every class, so the tag
                // is always bare <blockquote> at this point.
                $block['innerHTML'] = str_replace(
                    '<blockquote>',
                    '<blockquote class="wp-block-quote">',
                    $block['innerHTML']
                );
                foreach ($cleanContent as $index => $piece) {
                    if (is_string($piece)) {
                        $cleanContent[$index] = str_replace(
                            '<blockquote>',
                            '<blockquote class="wp-block-quote">',
                            $piece
                        );
                    }
                }
            }
            $block['innerContent'] = $cleanContent;
            if ($name === 'core/quote') {
                $block = self::repairQuote($block);
            }
        } else {
            $innerHtml = is_string($block['innerHTML'] ?? null) ? (string) $block['innerHTML'] : '';
            if ($innerHtml !== '') {
                $cleanHtml = wp_kses($innerHtml, $tags);
                $wrapped = self::ensureBlockWrapper($cleanHtml, $name, $block['attrs'] ?? []);
                $block['innerHTML'] = $wrapped;
                $block['innerContent'] = [$wrapped];
            }
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $keptAttrs = [];
        foreach (self::ALLOWED_ATTRS[$name] ?? [] as $key) {
            if (isset($attrs[$key])) {
                $keptAttrs[$key] = $attrs[$key];
            }
        }
        $block['attrs'] = $keptAttrs;
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $cleanInner = [];
        foreach ($innerBlocks as $inner) {
            if (!is_array($inner)) {
                continue;
            }
            $cleaned = self::sanitizeBlock($inner, $allowedBlocks, $allowLinks, $isList);
            if ($cleaned !== null) {
                $cleanInner[] = $cleaned;
            }
        }
        $block['innerBlocks'] = $cleanInner;
        return $block;
    }

    /**
     * Repair a quote block whose inner paragraph is missing.
     *
     * The old sanitizer stripped the inner <p>, so a legacy
     * quote reaches save as bare text inside the blockquote
     * with no inner blocks — and wp.blocks.parse() reads that
     * back as an invalid block forever: the leaf repair in
     * cleanBlock() never runs for a container, and clicking
     * "Attempt recovery" in the editor wipes the text. When
     * the quote has no inner blocks but holds bare text, the
     * text is rebuilt as an inner core/paragraph block (the
     * same repair the leaf blocks get, one level up), with
     * innerContent carrying the null placeholder so
     * serialize_block() renders the paragraph delimiters.
     *
     * A quote that already has inner blocks is left
     * untouched, and a quote with empty content stays empty
     * — text is never invented. A trailing citation stays
     * outside the paragraph, and a lone <p> without inner
     * block delimiters is kept as the paragraph HTML.
     *
     * @param array<string, mixed> $block Quote block after wp_kses and class normalization.
     * @return array<string, mixed> Repaired block, or the block unchanged.
     */
    private static function repairQuote(array $block): array
    {
        $innerBlocks = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        if ($innerBlocks !== []) {
            return $block;
        }
        $innerHtml = is_string($block['innerHTML'] ?? null) ? (string) $block['innerHTML'] : '';
        if (preg_match('/\A<blockquote class="wp-block-quote">(.*)<\/blockquote>\z/s', $innerHtml, $matches) !== 1) {
            return $block;
        }
        $inner = trim($matches[1]);
        if ($inner === '') {
            return $block;
        }
        $cite = '';
        if (preg_match('/(.*)<cite>(.*)<\/cite>\s*\z/s', $inner, $citeMatches) === 1) {
            $inner = trim($citeMatches[1]);
            $cite = '<cite>' . $citeMatches[2] . '</cite>';
            if ($inner === '') {
                return $block;
            }
        }
        if (preg_match('/\A<p>(.*)<\/p>\z/s', $inner, $pMatches) === 1 && substr_count($inner, '<p>') === 1) {
            $paragraphHtml = '<p>' . $pMatches[1] . '</p>';
        } elseif (strpos($inner, '<p') === false) {
            $paragraphHtml = '<p>' . $inner . '</p>';
        } else {
            $text = trim(wp_strip_all_tags($inner));
            if ($text === '') {
                return $block;
            }
            $paragraphHtml = '<p>' . esc_html($text) . '</p>';
        }
        $block['innerBlocks'] = [
            [
                'blockName' => 'core/paragraph',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => $paragraphHtml,
                'innerContent' => [$paragraphHtml],
            ],
        ];
        $block['innerHTML'] = '<blockquote class="wp-block-quote">' . $paragraphHtml . $cite . '</blockquote>';
        $block['innerContent'] = ['<blockquote class="wp-block-quote">', null, $cite . '</blockquote>'];
        return $block;
    }

    /**
     * Allowed HTML tags for one block type on save.
     *
     * Inline formatting plus the block's own wrapper tag:
     * paragraph keeps <p>, heading keeps <h1>-<h6>,
     * list-item keeps <li>, list keeps <ul>/<ol> (with the
     * numeric start / reversed attributes of an ordered
     * list), quote keeps <blockquote>/<cite> (plus <p> and
     * inline tags for a citation). No other tag and no other
     * attribute survives: class names, colours, font sizes
     * and anchors stay dropped, and links only when the
     * allow-links setting is on.
     *
     * @param string $name Full block name.
     * @param bool $allowLinks Whether links survive sanitizing.
     * @return array<string, array<string, bool>>
     */
    private static function blockTags(string $name, bool $allowLinks): array
    {
        $tags = self::INLINE_TAGS;
        if ($allowLinks) {
            $tags['a'] = ['href' => true, 'title' => true, 'target' => true, 'rel' => true];
        }
        switch ($name) {
            case 'core/paragraph':
                $tags['p'] = [];
                break;
            case 'core/heading':
                foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
                    $tags[$tag] = [];
                }
                break;
            case 'core/list-item':
                $tags['li'] = [];
                break;
            case 'core/list':
                return [
                    'ul' => [],
                    'ol' => ['start' => true, 'reversed' => true],
                ];
            case 'core/quote':
                $tags['blockquote'] = [];
                $tags['cite'] = [];
                $tags['p'] = [];
                break;
        }
        return $tags;
    }

    /**
     * Wrap cleaned leaf-block HTML in its own wrapper tag.
     *
     * Content that already has its wrapper is kept (a
     * heading is rebuilt at the level from its attributes,
     * default 2, so the tag and the stored level always
     * agree). Bare text — as saved by the old sanitizer,
     * which stripped the wrapper — is wrapped here, so a
     * legacy draft loads as text and survives a no-edit
     * re-save instead of coming back as undefined.
     *
     * @param string $html Cleaned inner HTML.
     * @param string $name Full block name.
     * @param mixed $attrs Stored block attributes.
     * @return string Wrapped inner HTML.
     */
    private static function ensureBlockWrapper(string $html, string $name, mixed $attrs): string
    {
        $trimmed = trim($html);
        if ($name === 'core/heading') {
            $level = 2;
            if (is_array($attrs) && isset($attrs['level'])) {
                $level = (int) $attrs['level'];
            }
            if ($level < 1 || $level > 6) {
                $level = 2;
            }
            if (preg_match('/\A<h[1-6]>(.*)<\/h[1-6]>\z/s', $trimmed, $matches) === 1) {
                return '<h' . $level . '>' . $matches[1] . '</h' . $level . '>';
            }
            return '<h' . $level . '>' . $trimmed . '</h' . $level . '>';
        }
        if ($name === 'core/list-item') {
            if (preg_match('/\A<li>(.*)<\/li>\z/s', $trimmed, $matches) === 1) {
                return '<li>' . $matches[1] . '</li>';
            }
            return '<li>' . $trimmed . '</li>';
        }
        if ($name === 'core/paragraph') {
            if (preg_match('/\A<p>(.*)<\/p>\z/s', $trimmed, $matches) === 1) {
                return '<p>' . $matches[1] . '</p>';
            }
            return '<p>' . $trimmed . '</p>';
        }
        return $html;
    }

    /**
     * Convert a freeform block (plain text, no block markup)
     * into paragraph blocks.
     *
     * Strips all tags, splits on blank lines, and wraps each
     * non-empty chunk in a core/paragraph block. Returns null
     * when nothing readable remains.
     *
     * @param array<string, mixed> $block Parsed freeform block.
     * @return array<string, mixed>|null Paragraph block, or null.
     */
    private static function freeformToParagraphs(array $block): ?array
    {
        $html = is_string($block['innerHTML'] ?? null) ? (string) $block['innerHTML'] : '';
        $text = trim(wp_strip_all_tags($html));
        if ($text === '') {
            return null;
        }
        $chunks = array_filter(array_map('trim', preg_split('/\n\s*\n/', $text) ?: []));
        if ($chunks === []) {
            $chunks = [$text];
        }
        $first = array_shift($chunks);
        $joined = $first;
        if ($chunks !== []) {
            $joined .= "\n\n" . implode("\n\n", $chunks);
        }
        // The <p> wrapper is part of a valid paragraph block:
        // without it wp.blocks.parse() reads the content back
        // as undefined.
        $wrapped = '<p>' . esc_html($joined) . '</p>';
        return [
            'blockName' => 'core/paragraph',
            'attrs' => [],
            'innerBlocks' => [],
            'innerHTML' => $wrapped,
            'innerContent' => [$wrapped],
        ];
    }

    /**
     * Handle trashing an own post via admin-post.php.
     *
     * Only the author's own draft or rejected posts may be
     * trashed. The post is soft-deleted (wp_trash_post), never
     * hard-deleted, so it can be restored from the trash filter.
     *
     * @return void
     */
    public static function handleDelete(): void
    {
        self::handlePostAction('trash');
    }

    /**
     * Handle restoring an own trashed post via admin-post.php.
     *
     * @return void
     */
    public static function handleRestore(): void
    {
        self::handlePostAction('restore');
    }

    /**
     * Handle permanently deleting an own trashed post.
     *
     * Only offered for posts already in the trash: this is the
     * one place wp_delete_post is used, and only for the
     * author's own trashed post.
     *
     * @return void
     */
    public static function handlePermanentDelete(): void
    {
        self::handlePostAction('delete-permanent');
    }

    /**
     * Shared handler for the trash / restore / delete actions.
     *
     * Verifies login, nonce and `edit_posts`, then checks the
     * post exists, belongs to the current user, is a post, and
     * is in a status the action allows. Always redirects back
     * to the contributions list (never renders).
     *
     * @param string $action One of trash, restore, delete-permanent.
     * @return void
     */
    private static function handlePostAction(string $action): void
    {
        $fallback = ContributorFrontendModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $listUrl = add_query_arg(
            [self::VIEW_FLAG => 'contributions', self::STATUS_FLAG => $action === 'delete-permanent' ? 'trash' : 'all'],
            self::currentPageUrl()
        );
        $nonceAction = match ($action) {
            'restore' => ContributorFrontendModule::RESTORE_ACTION,
            'delete-permanent' => ContributorFrontendModule::PERMANENT_DELETE_ACTION,
            default => ContributorFrontendModule::DELETE_ACTION,
        };
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $nonceAction)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'nonce', $fallback));
            exit;
        }
        if (!current_user_can('edit_posts')) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'capability', $fallback));
            exit;
        }
        $postId = isset($_GET['traydigita_post_id']) ? absint(wp_unslash((string) $_GET['traydigita_post_id'])) : 0;
        $post = $postId > 0 ? get_post($postId) : null;
        if ($post === null
            || (int) $post->post_author !== get_current_user_id()
            || $post->post_type !== 'post'
        ) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'not-found', $fallback));
            exit;
        }
        $allowed = match ($action) {
            'restore' => $post->post_status === 'trash',
            'delete-permanent' => $post->post_status === 'trash',
            default => in_array($post->post_status, self::TRASHABLE_STATUSES, true),
        };
        if (!$allowed) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'not-editable', $fallback));
            exit;
        }
        if ($action === 'restore') {
            wp_untrash_post($postId);
            $flag = 'restored';
        } elseif ($action === 'delete-permanent') {
            wp_delete_post($postId, true);
            $flag = 'deleted-permanent';
        } else {
            wp_trash_post($postId);
            $flag = 'trashed';
        }
        wp_safe_redirect(add_query_arg(self::SAVED_FLAG, $flag, $listUrl));
        exit;
    }

    /**
     * Handle a profile save via admin-post.php.
     *
     * Saves the contributor's own display name, website and
     * bio, and — when the Avatar module is active — an uploaded
     * avatar through AvatarStorage. No capability beyond being
     * logged in is needed: wp_update_user() only allows users
     * to edit themselves, and the posted user id is never read
     * — the id is always the current user. Password changes
     * stay on the wp-admin profile page (which frontend-only
     * users may reach).
     *
     * @return void
     */
    public static function handleProfile(): void
    {
        $fallback = ContributorFrontendModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $profileUrl = add_query_arg([self::VIEW_FLAG => 'profile'], self::currentPageUrl());
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, ContributorFrontendModule::PROFILE_ACTION)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'nonce', $profileUrl));
            exit;
        }
        $userId = get_current_user_id();
        $displayName = isset($_POST['traydigita_display_name']) && is_string($_POST['traydigita_display_name'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_display_name']))
            : '';
        $userUrl = isset($_POST['traydigita_user_url']) && is_string($_POST['traydigita_user_url'])
            ? esc_url_raw(wp_unslash($_POST['traydigita_user_url']))
            : '';
        $description = isset($_POST['traydigita_description']) && is_string($_POST['traydigita_description'])
            ? sanitize_textarea_field(wp_unslash($_POST['traydigita_description']))
            : '';
        $result = wp_update_user([
            'ID' => $userId,
            'display_name' => $displayName,
            'user_url' => $userUrl,
            'description' => $description,
        ]);
        if (is_wp_error($result)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'save', $profileUrl));
            exit;
        }
        if (isset($_FILES['traydigita_avatar']) && is_array($_FILES['traydigita_avatar'])) {
            $avatarError = self::saveAvatar($userId);
            if ($avatarError !== '') {
                wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'avatar', $profileUrl));
                exit;
            }
        }
        wp_safe_redirect(add_query_arg(self::SAVED_FLAG, 'profile', $profileUrl));
        exit;
    }

    /**
     * Store an uploaded avatar for the current user.
     *
     * Returns an empty string on success (or when no file was
     * uploaded), and an error code when the Avatar module is
     * unavailable or the upload failed. The file itself is
     * validated and cropped by AvatarStorage.
     *
     * @param int $userId User id.
     * @return string Error code, or '' on success.
     */
    private static function saveAvatar(int $userId): string
    {
        $file = $_FILES['traydigita_avatar'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if (!ModuleRegistry::isEnabled('avatar') || !class_exists(AvatarStorage::class)) {
            return 'avatar';
        }
        $result = AvatarStorage::storeUploadedFile($userId, $file);
        if (empty($result['success'])) {
            return 'avatar';
        }
        return '';
    }

    /**
     * Handle an email change via admin-post.php.
     *
     * Only when the allow-email-change setting is on. The new
     * address is validated (real email, not used by another
     * user) and then sent through WordPress core's own
     * confirmation flow: the address is stored pending in
     * `_new_email` and a confirmation link is mailed to the new
     * address. The link points at profile.php, which
     * frontend-only users may reach. The address only changes
     * after the user clicks the link.
     *
     * @return void
     */
    public static function handleEmail(): void
    {
        $fallback = ContributorFrontendModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $emailUrl = add_query_arg([self::VIEW_FLAG => 'change-email'], self::currentPageUrl());
        $overviewUrl = add_query_arg(
            [self::VIEW_FLAG => 'overview', self::NOTICE_FLAG => 'section-disabled'],
            self::currentPageUrl()
        );
        if (!ContributorFrontendModule::getSettings()['allow_email_change']) {
            wp_safe_redirect($overviewUrl);
            exit;
        }
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, ContributorFrontendModule::EMAIL_ACTION)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'nonce', $emailUrl));
            exit;
        }
        $userId = get_current_user_id();
        $user = get_userdata($userId);
        if ($user === false) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'capability', $fallback));
            exit;
        }
        $newEmail = isset($_POST['traydigita_new_email']) && is_string($_POST['traydigita_new_email'])
            ? sanitize_text_field(wp_unslash($_POST['traydigita_new_email']))
            : '';
        if ($newEmail === '' || !is_email($newEmail)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'email-invalid', $emailUrl));
            exit;
        }
        if ($newEmail === $user->user_email) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'email-same', $emailUrl));
            exit;
        }
        if (email_exists($newEmail)) {
            wp_safe_redirect(add_query_arg(self::ERROR_FLAG, 'email-taken', $emailUrl));
            exit;
        }
        // Core's confirmation flow reads the new address from
        // $_POST['email'] and mails a confirmation link to it.
        $_POST['email'] = $newEmail;
        send_confirmation_on_profile_email($userId);
        wp_safe_redirect(add_query_arg(self::SAVED_FLAG, 'email-sent', $emailUrl));
        exit;
    }

    /**
     * URL of the page the shortcode is rendering on.
     *
     * Used for the login redirect and for building dashboard
     * view URLs. The configured member page wins: during an
     * admin-post.php request there is no queried object, so
     * falling back to it there would send every post-save
     * redirect to the homepage instead of the member area.
     * Without a member page the queried object (the page the
     * shortcode renders on) is used, and only then the
     * homepage.
     *
     * @return string
     */
    private static function currentPageUrl(): string
    {
        $pageId = ContributorFrontendModule::getSettings()['member_page_id'];
        if ($pageId > 0) {
            $url = get_permalink($pageId);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        $pageId = get_queried_object_id();
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
     * Dashboard URL for a section, optionally with extra args.
     *
     * @param string $view Section id (overview, posts, contributions, write, profile, change-email).
     * @param array<string, string|int> $extra Extra query args.
     * @return string
     */
    private static function viewUrl(string $view, array $extra = []): string
    {
        return add_query_arg(array_merge([self::VIEW_FLAG => $view], $extra), self::currentPageUrl());
    }

    /**
     * URL of the "Write new" destination for the current user.
     *
     * Dashboard-mode users (the default for unconfigured roles,
     * and always for administrators) go to wp-admin
     * post-new.php — the full Gutenberg editor. Frontend-only
     * users open the frontend block editor: sending them to
     * wp-admin would only bounce them straight back to the
     * member area.
     *
     * @return string
     */
    public static function writeUrl(): string
    {
        if (ContributorFrontendModule::isFrontendOnlyUser()) {
            return self::viewUrl('write');
        }
        return admin_url('post-new.php');
    }

    /**
     * URL for editing one post, or '' when there is no edit
     * destination for the current user.
     *
     * Dashboard-mode users edit in wp-admin (post.php); the
     * frontend editor is only a destination for frontend-only
     * users editing their own draft or needs-revision post —
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
        if (!$own && !current_user_can(ContributorFrontendModule::EDITORIAL_CAPABILITY)) {
            return '';
        }
        if (ContributorFrontendModule::isFrontendOnlyUser()) {
            if ($own && in_array($post->post_status, self::EDITABLE_STATUSES, true)) {
                return self::viewUrl('write', [self::EDIT_FLAG => $post->ID]);
            }
            return '';
        }
        return admin_url('post.php?post=' . $post->ID . '&action=edit');
    }

    /**
     * Resolve the requested dashboard section, enforcing the
     * menu-availability rule server-side.
     *
     * Unknown sections fall back to Overview. A disabled
     * section redirects to Overview with a notice. The Write
     * section is frontend-only: a dashboard-mode user (or an
     * administrator or editor) asking for it goes to wp-admin
     * post-new.php instead of the frontend editor — the menu
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
        $sections = ContributorFrontendModule::enabledSections();
        $ids = array_map(static fn (array $section): string => (string) $section['id'], $sections);
        $view = isset($_GET[self::VIEW_FLAG]) && is_string($_GET[self::VIEW_FLAG])
            ? sanitize_text_field(wp_unslash($_GET[self::VIEW_FLAG]))
            : 'overview';
        if (isset($_GET[self::EDIT_FLAG])) {
            $view = 'write';
        }
        if ($view === 'write' && !ContributorFrontendModule::isFrontendOnlyUser()) {
            wp_safe_redirect(admin_url('post-new.php'));
            exit;
        }
        if (!in_array($view, $ids, true)) {
            if ($view === 'stats') {
                // The old Statistics section merged into Overview.
                return 'overview';
            }
            if ($view === 'write' && !ContributorFrontendModule::isDashboardUser()) {
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
        if (!ContributorFrontendModule::sectionVisible($section)) {
            if ($view === 'write') {
                self::redirectToAdmin();
            }
            wp_safe_redirect(add_query_arg(
                [self::VIEW_FLAG => 'overview', self::NOTICE_FLAG => 'section-disabled'],
                self::currentPageUrl()
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
        if (ContributorFrontendModule::isFrontendOnlyUser()) {
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
        foreach (ContributorFrontendModule::enabledSections() as $section) {
            if (!ContributorFrontendModule::sectionVisible($section)) {
                continue;
            }
            $id = (string) $section['id'];
            $url = $id === 'write' ? self::writeUrl() : self::viewUrl($id);
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
     * no statistics block — numbers are never invented.
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
     * contributor module records one row per status change on
     * the user's own posts (see ContributorFrontendModule::
     * recordStatusChange()). Returns an empty list when the
     * module is off or nothing was recorded — the Overview
     * then shows an honest empty state.
     *
     * @return array<int, array{message: string, date: string, url: string}>
     */
    private static function fetchNotifications(): array
    {
        if (!ModuleRegistry::isEnabled('activity-log') || !class_exists(ActivityLogModule::class)) {
            return [];
        }
        try {
            $result = ActivityLogModule::query([
                'module' => 'contributor-frontend',
                'event_type' => 'status-changed',
                'user_id' => get_current_user_id(),
                'per_page' => 5,
            ]);
        } catch (Throwable) {
            return [];
        }
        $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
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
        return self::viewUrl('contributions');
    }

    /**
     * The site announcement, when the theme has one.
     *
     * The real source is the Customizer announcement bar text
     * (Settings::get('announcement_text')). An empty
     * setting means no announcements — the Overview then shows
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
    private static function renderNotice(string $kind, string $message, bool $wrap = false): string
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
        $noDashboard = isset($_GET[self::NO_DASHBOARD_FLAG]) && $_GET[self::NO_DASHBOARD_FLAG] === '1';
        if ($noDashboard) {
            $html .= self::renderNotice(
                'info',
                esc_html__(
                    'You do not have access to the WordPress dashboard. Use this page to write and submit articles.',
                    'traydigita-core'
                )
            );
        }
        if (isset($_GET[self::SAVED_FLAG]) && is_string($_GET[self::SAVED_FLAG])) {
            $message = self::savedMessage(sanitize_text_field(wp_unslash($_GET[self::SAVED_FLAG])));
            if ($message !== '') {
                $html .= self::renderNotice('success', $message);
            }
        }
        if (isset($_GET[self::ERROR_FLAG]) && is_string($_GET[self::ERROR_FLAG])) {
            $message = self::errorMessage(sanitize_text_field(wp_unslash($_GET[self::ERROR_FLAG])));
            if ($message !== '') {
                $html .= self::renderNotice('error', $message);
            }
        }
        if (isset($_GET[self::NOTICE_FLAG]) && is_string($_GET[self::NOTICE_FLAG])) {
            $message = self::noticeMessage(sanitize_text_field(wp_unslash($_GET[self::NOTICE_FLAG])));
            if ($message !== '') {
                $html .= self::renderNotice('info', $message);
            }
        }
        $title = '';
        $section = null;
        foreach (ContributorFrontendModule::enabledSections() as $candidate) {
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
                'write' => self::renderEditor(),
                'bookmark' => self::renderBookmarks(),
                'profile' => self::renderProfile(),
                'change-email' => self::renderChangeEmail(),
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
        if ($view === 'write' && !self::$editorNotEditable) {
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
            . '<a class="traydigita-contributor-topbar-link" href="' . esc_url(self::viewUrl('overview')) . '">'
            . esc_html__('Member area', 'traydigita-core') . '</a>'
            . '<h1 class="traydigita-contributor-topbar-title">' . esc_html($title) . '</h1>'
            . '<span class="traydigita-contributor-save-state" id="traydigita-contributor-save-state"></span>'
            . '<span class="traydigita-contributor-topbar-actions">' . $topActions . '</span>'
            . '<a class="traydigita-contributor-topbar-link" href="' . esc_url(home_url('/')) . '">'
            . esc_html__('View site', 'traydigita-core') . '</a>'
            . '<a class="traydigita-contributor-topbar-link" href="'
            . esc_url(wp_logout_url(ContributorFrontendModule::memberAreaUrl())) . '">'
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
        $isEditor = current_user_can(ContributorFrontendModule::EDITORIAL_CAPABILITY);
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
                . esc_url(self::viewUrl('contributions', [self::STATUS_FLAG => $filters[$key]])) . '">'
                . '<span class="traydigita-contributor-stat-number">' . esc_html(number_format_i18n($count)) . '</span>'
                . '<span class="traydigita-contributor-stat-label">' . esc_html($labels[$key]) . '</span>'
                . '</a>';
        }
        if ($isEditor) {
            $queueCount = (int) (wp_count_posts('post')->pending ?? 0);
            $html .= '<a class="traydigita-contributor-stat-card" href="'
                . esc_url(self::viewUrl('posts', [self::STATUS_FLAG => 'pending'])) . '">'
                . '<span class="traydigita-contributor-stat-number">' . esc_html(number_format_i18n($queueCount)) . '</span>'
                . '<span class="traydigita-contributor-stat-label">' . esc_html__('Review queue', 'traydigita-core') . '</span>'
                . '</a>';
        }
        $html .= '</div>';
        $html .= '<div class="traydigita-contributor-quick-actions">';
        if (ContributorFrontendModule::isDashboardUser($userId)) {
            $html .= '<a class="traydigita-contributor-button traydigita-contributor-button-primary" href="'
                . esc_url(self::writeUrl()) . '">' . esc_html__('Write New', 'traydigita-core') . '</a> ';
        }
        $html .= '<a class="traydigita-contributor-button traydigita-contributor-button-secondary" href="'
            . esc_url(self::viewUrl('contributions')) . '">'
            . esc_html__('My Contributions', 'traydigita-core') . '</a> '
            . '<a class="traydigita-contributor-button traydigita-contributor-button-secondary" href="'
            . esc_url(self::viewUrl('profile')) . '">' . esc_html__('Edit profile', 'traydigita-core') . '</a>'
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
        $isEditor = current_user_can(ContributorFrontendModule::EDITORIAL_CAPABILITY);
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
        $isEditor = current_user_can(ContributorFrontendModule::EDITORIAL_CAPABILITY);
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
        $filter = isset($_GET[self::STATUS_FLAG]) && is_string($_GET[self::STATUS_FLAG])
            ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FLAG]))
            : 'all';
        if (!in_array($filter, ['all', 'draft', 'pending', 'needs-revision', 'publish', 'rejected'], true)) {
            $filter = 'all';
        }
        $page = isset($_GET[self::PAGE_FLAG]) ? max(1, absint(wp_unslash((string) $_GET[self::PAGE_FLAG]))) : 1;
        $filters = [
            'all' => __('All', 'traydigita-core'),
            'publish' => __('Published', 'traydigita-core'),
            'pending' => __('In Review', 'traydigita-core'),
            'draft' => __('Draft', 'traydigita-core'),
            'needs-revision' => __('Needs Revision', 'traydigita-core'),
            'rejected' => __('Rejected', 'traydigita-core'),
        ];
        $html = '<div class="traydigita-contributor-posts">';
        if (ContributorFrontendModule::isDashboardUser(get_current_user_id())) {
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
                . '" href="' . esc_url(self::viewUrl('posts', [self::STATUS_FLAG => $key])) . '">'
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
                $html .= '<a href="' . esc_url(self::viewUrl('posts', [
                    self::STATUS_FLAG => $filter,
                    self::PAGE_FLAG => $page - 1,
                ])) . '">' . esc_html__('Previous page', 'traydigita-core') . '</a> ';
            }
            $html .= '<span>' . sprintf(
                /* translators: 1: current page, 2: total pages. */
                esc_html__('Page %1$d of %2$d', 'traydigita-core'),
                $page,
                $pages
            ) . '</span>';
            if ($page < $pages) {
                $html .= ' <a href="' . esc_url(self::viewUrl('posts', [
                    self::STATUS_FLAG => $filter,
                    self::PAGE_FLAG => $page + 1,
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
     * capability-scoped — a user demoted to subscriber still
     * sees their own posts, read-only when they no longer have
     * edit_posts.
     *
     * @return string
     */
    private static function renderContributions(): string
    {
        $filter = isset($_GET[self::STATUS_FLAG]) && is_string($_GET[self::STATUS_FLAG])
            ? sanitize_text_field(wp_unslash($_GET[self::STATUS_FLAG]))
            : 'all';
        if (!in_array($filter, ['all', 'draft', 'pending', 'needs-revision', 'publish', 'rejected', 'trash'], true)) {
            $filter = 'all';
        }
        $page = isset($_GET[self::PAGE_FLAG]) ? max(1, absint(wp_unslash((string) $_GET[self::PAGE_FLAG]))) : 1;
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
        if (ContributorFrontendModule::isDashboardUser(get_current_user_id())) {
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
                . '" href="' . esc_url(self::viewUrl('contributions', [self::STATUS_FLAG => $key])) . '">'
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
                $html .= '<a href="' . esc_url(self::viewUrl('contributions', [
                    self::STATUS_FLAG => $filter,
                    self::PAGE_FLAG => $page - 1,
                ])) . '">' . esc_html__('Previous page', 'traydigita-core') . '</a> ';
            }
            $html .= '<span>' . sprintf(
                /* translators: 1: current page, 2: total pages. */
                esc_html__('Page %1$d of %2$d', 'traydigita-core'),
                $page,
                $pages
            ) . '</span>';
            if ($page < $pages) {
                $html .= ' <a href="' . esc_url(self::viewUrl('contributions', [
                    self::STATUS_FLAG => $filter,
                    self::PAGE_FLAG => $page + 1,
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
                        ['action' => ContributorFrontendModule::RESTORE_ACTION, 'traydigita_post_id' => $post->ID],
                        admin_url('admin-post.php')
                    ),
                    ContributorFrontendModule::RESTORE_ACTION
                )) . '">' . esc_html__('Restore', 'traydigita-core') . '</a>';
                $actions[] = '<a href="' . esc_url(wp_nonce_url(
                    add_query_arg(
                        ['action' => ContributorFrontendModule::PERMANENT_DELETE_ACTION, 'traydigita_post_id' => $post->ID],
                        admin_url('admin-post.php')
                    ),
                    ContributorFrontendModule::PERMANENT_DELETE_ACTION
                )) . '">' . esc_html__('Delete permanently', 'traydigita-core') . '</a>';
            }
            return implode(' <span aria-hidden="true">|</span> ', $actions);
        }
        $edit = self::editUrl($post);
        if ($edit !== '') {
            $actions[] = '<a href="' . esc_url($edit) . '">' . esc_html__('Edit', 'traydigita-core') . '</a>';
        }
        if ($status === 'pending' || $status === 'needs-revision' || $status === 'rejected') {
            if ($own || current_user_can(ContributorFrontendModule::EDITORIAL_CAPABILITY)) {
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
            if ($own || current_user_can(ContributorFrontendModule::EDITORIAL_CAPABILITY)) {
                $preview = get_preview_post_link($post);
                if (is_string($preview) && $preview !== '') {
                    $actions[] = '<a href="' . esc_url($preview) . '" target="_blank" rel="noopener">'
                        . esc_html__('Preview', 'traydigita-core') . '</a>';
                }
            }
        }
        if ($ownList && $own && current_user_can('edit_posts')
            && in_array($status, self::TRASHABLE_STATUSES, true)
        ) {
            $actions[] = '<a href="' . esc_url(wp_nonce_url(
                add_query_arg(
                    ['action' => ContributorFrontendModule::DELETE_ACTION, 'traydigita_post_id' => $post->ID],
                    admin_url('admin-post.php')
                ),
                ContributorFrontendModule::DELETE_ACTION
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

    /**
     * Render the Write view: the editor form.
     *
     * Prefills from the user's own draft or needs-revision post
     * when `?traydigita_edit=<id>` names one; anything else
     * (another user's post, a published or rejected post, a
     * missing post) shows only an error notice with a link
     * back to the author's own posts — never a blank new-post
     * form, so the screen cannot invite an accidental new
     * post.
     *
     * @return string
     */
    private static function renderEditor(): string
    {
        $userId = get_current_user_id();
        $postId = 0;
        $title = '';
        $content = '';
        $excerpt = '';
        $categoryId = 0;
        $revisionNote = '';
        $notEditable = false;
        if (isset($_GET[self::EDIT_FLAG])) {
            $candidateId = absint(wp_unslash($_GET[self::EDIT_FLAG]));
            if ($candidateId > 0) {
                $existing = get_post($candidateId);
                if ($existing !== null
                    && (int) $existing->post_author === $userId
                    && $existing->post_type === 'post'
                    && in_array($existing->post_status, self::EDITABLE_STATUSES, true)
                ) {
                    $postId = $candidateId;
                    $title = $existing->post_title;
                    $content = $existing->post_content;
                    $excerpt = $existing->post_excerpt;
                    $categories = wp_get_post_categories($candidateId, ['fields' => 'ids']);
                    if (is_array($categories) && isset($categories[0])) {
                        $categoryId = absint($categories[0]);
                    }
                    if ($existing->post_status === ContributorFrontendModule::STATUS_NEEDS_REVISION) {
                        $note = get_post_meta($candidateId, ContributorFrontendModule::REVISION_NOTE_META, true);
                        $revisionNote = is_string($note) ? $note : '';
                    }
                } else {
                    $notEditable = true;
                }
            }
        }
        if ($notEditable) {
            self::$editorNotEditable = true;
            return self::renderNotice('error', esc_html__('This article cannot be edited.', 'traydigita-core'))
                . '<p><a class="traydigita-contributor-item-edit" href="'
                . esc_url(self::viewUrl('contributions')) . '">'
                . esc_html__('My Contributions', 'traydigita-core') . '</a></p>';
        }
        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        $settings = ContributorFrontendModule::getSettings();
        self::enqueueEditorAssets();
        $html = '';
        if ($revisionNote !== '') {
            $html .= '<div class="traydigita-contributor-revision-note"><p><strong>'
                . esc_html__('The editor asked for revisions:', 'traydigita-core') . '</strong></p><p>'
                . esc_html($revisionNote) . '</p></div>';
        }
        $html .= '<h2 class="traydigita-contributor-heading">' . esc_html__('Write', 'traydigita-core') . '</h2>';
        $html .= '<form id="traydigita-contributor-form" class="traydigita-contributor-form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '">';
        $html .= wp_nonce_field(ContributorFrontendModule::SAVE_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="'
            . esc_attr(ContributorFrontendModule::SAVE_ACTION) . '">';
        $html .= '<input type="hidden" name="traydigita_post_id" value="' . esc_attr((string) $postId) . '">';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-title">' . esc_html__('Title', 'traydigita-core') . '</label>'
            . '<input type="text" id="traydigita-contributor-title" name="traydigita_title" required maxlength="200" value="'
            . esc_attr($title) . '">'
            . '</p>';
        $editorSettings = wp_json_encode([
            'allowedBlocks' => $settings['allowed_blocks'],
            'allowLinks' => $settings['allow_links'],
            'accent' => $settings['editor_accent'],
            'paper' => $settings['editor_paper'],
            'text' => $settings['editor_text'],
        ]);
        $html .= '<div class="traydigita-contributor-field traydigita-contributor-field-editor">'
            . '<label id="traydigita-contributor-content-label" for="traydigita-contributor-content">'
            . esc_html__('Content', 'traydigita-core') . '</label>'
            . '<div id="traydigita-contributor-block-editor" class="traydigita-contributor-block-editor"'
            . ' data-settings="' . esc_attr(is_string($editorSettings) ? $editorSettings : '{}') . '"'
            . ' data-msg-dirty="' . esc_attr(__('Unsaved changes', 'traydigita-core')) . '"'
            . ' data-msg-saved="' . esc_attr(__('All changes saved', 'traydigita-core')) . '"'
            . ' style="--traydigita-editor-accent:' . esc_attr($settings['editor_accent'])
            . ';--traydigita-editor-paper:' . esc_attr($settings['editor_paper'])
            . ';--traydigita-editor-text:' . esc_attr($settings['editor_text']) . '"></div>'
            . '<textarea id="traydigita-contributor-content" name="traydigita_content" rows="12">'
            . esc_textarea($content) . '</textarea>'
            . '</div>';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-excerpt">' . esc_html__('Excerpt', 'traydigita-core') . '</label>'
            . '<textarea id="traydigita-contributor-excerpt" name="traydigita_excerpt" rows="3">'
            . esc_textarea($excerpt) . '</textarea>'
            . '</p>';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-category">' . esc_html__('Category', 'traydigita-core') . '</label>'
            . '<select id="traydigita-contributor-category" name="traydigita_category">'
            . '<option value="0">' . esc_html__('Uncategorized', 'traydigita-core') . '</option>';
        if (!is_wp_error($terms) && is_array($terms)) {
            foreach ($terms as $term) {
                if (!$term instanceof \WP_Term) {
                    continue;
                }
                $html .= '<option value="' . esc_attr((string) $term->term_id) . '"'
                    . selected($categoryId, (int) $term->term_id, false) . '>'
                    . esc_html($term->name) . '</option>';
            }
        }
        $html .= '</select></p>';
        $html .= self::renderFeaturedPanel($postId);
        $html .= '<p class="traydigita-contributor-actions">'
            . '<button type="submit" name="traydigita_submit" value="draft" class="traydigita-contributor-button traydigita-contributor-button-secondary">'
            . esc_html__('Save draft', 'traydigita-core') . '</button> '
            . '<button type="submit" name="traydigita_submit" value="review" class="traydigita-contributor-button traydigita-contributor-button-primary">'
            . esc_html__('Submit for review', 'traydigita-core') . '</button>'
            . '</p>';
        $html .= '</form>';
        return $html;
    }

    /**
     * Render the featured-image panel for the editor form.
     *
     * For a saved post the panel is a mount point for the
     * upload UI (the block editor bundle renders the upload,
     * preview, replace and remove controls into it). For a new
     * post there is no post to attach to yet, so the panel says
     * to save a draft first.
     *
     * @param int $postId Post being edited, or 0 for a new post.
     * @return string
     */
    private static function renderFeaturedPanel(int $postId): string
    {
        $html = '<div class="traydigita-contributor-field traydigita-contributor-field-featured">'
            . '<span class="traydigita-contributor-field-label">' . esc_html__('Featured image', 'traydigita-core') . '</span>';
        if ($postId <= 0) {
            $html .= '<p class="traydigita-contributor-empty">'
                . esc_html__('Save the article as a draft first, then add a featured image.', 'traydigita-core')
                . '</p></div>';
            return $html;
        }
        $thumbId = get_post_thumbnail_id($postId);
        $thumbUrl = '';
        if ($thumbId > 0) {
            $src = wp_get_attachment_image_src($thumbId, 'medium');
            if (is_array($src) && isset($src[0])) {
                $thumbUrl = (string) $src[0];
            }
        }
        $nonce = wp_create_nonce(ContributorFrontendModule::FEATURED_NONCE_ACTION);
        $html .= '<div id="traydigita-contributor-featured" class="traydigita-contributor-featured"'
            . ' data-post-id="' . esc_attr((string) $postId) . '"'
            . ' data-ajax-url="' . esc_attr(admin_url('admin-ajax.php')) . '"'
            . ' data-nonce="' . esc_attr($nonce) . '"'
            . ' data-thumbnail-id="' . esc_attr((string) $thumbId) . '"'
            . ' data-thumbnail-url="' . esc_attr($thumbUrl) . '"'
            . ' data-msg-upload="' . esc_attr(__('Upload featured image', 'traydigita-core')) . '"'
            . ' data-msg-replace="' . esc_attr(__('Replace image', 'traydigita-core')) . '"'
            . ' data-msg-remove="' . esc_attr(__('Remove image', 'traydigita-core')) . '"'
            . ' data-msg-uploading="' . esc_attr(__('Uploading…', 'traydigita-core')) . '"'
            . ' data-msg-confirm-remove="'
            . esc_attr(__('Remove the featured image?', 'traydigita-core')) . '"'
            . ' data-msg-error="' . esc_attr(__('The image could not be uploaded. Please try again.', 'traydigita-core')) . '"'
            . '></div>'
            . '</div>';
        return $html;
    }

    /**
     * Enqueue the block editor scripts and styles for the Write view.
     *
     * WordPress core ships the editor: the classic script
     * handles (wp-blocks, wp-block-editor, wp-element and
     * friends) provide the global `wp` object the bundle uses,
     * and the core editor styles dress the canvas. The bundle
     * itself is the `contributor-editor` Vite entry, loaded
     * only on this view — never site-wide.
     *
     * @return void
     */
    private static function enqueueEditorAssets(): void
    {
        $coreHandles = [
            'wp-blocks',
            'wp-element',
            'wp-block-editor',
            'wp-block-library',
            'wp-components',
            'wp-data',
            'wp-compose',
            'wp-rich-text',
            'wp-viewport',
            'wp-keyboard-shortcuts',
            'wp-a11y',
            'wp-i18n',
            'wp-dom-ready',
            'wp-html-entities',
            'wp-primitives',
            'wp-undo-manager',
            'wp-notices',
        ];
        foreach ($coreHandles as $handle) {
            wp_enqueue_script($handle);
        }
        wp_enqueue_style('wp-block-editor');
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-block-library-theme');
        wp_enqueue_style('wp-components');
        wp_enqueue_style('wp-edit-blocks');
        Core::assets()->enqueueScriptEntry(
            'contributor-editor',
            'traydigita-theme-contributor-editor',
            $coreHandles
        );
    }

    /**
     * Render the Profile view: the contributor's own display
     * name, website, bio and avatar.
     *
     * The avatar upload only renders when the Avatar module is
     * active. Password and two-factor changes stay on the
     * wp-admin profile page (linked from here): frontend-only
     * users may reach profile.php, and building password
     * changes into the dashboard would widen what the
     * dashboard may do.
     *
     * @return string
     */
    private static function renderProfile(): string
    {
        $user = get_userdata(get_current_user_id());
        if ($user === false) {
            return self::renderNotice('error', esc_html__('You do not have permission to use the member area.', 'traydigita-core'));
        }
        $avatarEnabled = ModuleRegistry::isEnabled('avatar') && class_exists(AvatarStorage::class);
        $html = '<h2 class="traydigita-contributor-heading">' . esc_html__('Profile', 'traydigita-core') . '</h2>';
        $html .= '<form class="traydigita-contributor-form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        $html .= wp_nonce_field(ContributorFrontendModule::PROFILE_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="'
            . esc_attr(ContributorFrontendModule::PROFILE_ACTION) . '">';
        if ($avatarEnabled) {
            $avatar = AvatarStorage::getAvatar((int) $user->ID);
            $html .= '<p class="traydigita-contributor-field">';
            if (is_array($avatar) && isset($avatar['url']) && is_string($avatar['url']) && $avatar['url'] !== '') {
                $html .= '<img class="traydigita-contributor-avatar" src="' . esc_url($avatar['url']) . '" alt="">';
            }
            $html .= '<label for="traydigita-contributor-avatar">' . esc_html__('Avatar', 'traydigita-core') . '</label>'
                . '<input type="file" id="traydigita-contributor-avatar" name="traydigita_avatar" accept="image/jpeg,image/png,image/gif,image/webp">'
                . '</p>';
        }
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-display-name">' . esc_html__('Display name', 'traydigita-core') . '</label>'
            . '<input type="text" id="traydigita-contributor-display-name" name="traydigita_display_name" maxlength="100" value="'
            . esc_attr($user->display_name) . '">'
            . '</p>';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-user-url">' . esc_html__('Website', 'traydigita-core') . '</label>'
            . '<input type="url" id="traydigita-contributor-user-url" name="traydigita_user_url" maxlength="200" value="'
            . esc_attr($user->user_url) . '">'
            . '</p>';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-description">' . esc_html__('Biography', 'traydigita-core') . '</label>'
            . '<textarea id="traydigita-contributor-description" name="traydigita_description" rows="4">'
            . esc_textarea($user->description) . '</textarea>'
            . '</p>';
        $html .= '<p class="traydigita-contributor-actions">'
            . '<button type="submit" class="traydigita-contributor-button traydigita-contributor-button-primary">'
            . esc_html__('Save profile', 'traydigita-core') . '</button>'
            . '</p>';
        $html .= '</form>';
        $html .= '<p class="traydigita-contributor-empty">'
            . '<a href="' . esc_url(admin_url('profile.php')) . '">'
            . esc_html__('Change your password and security settings on your WordPress profile page.', 'traydigita-core')
            . '</a></p>';
        return $html;
    }

    /**
     * Render the Change Email view.
     *
     * Only reachable when the allow-email-change setting is on
     * (the resolver redirects otherwise). Users who can access
     * wp-admin are reminded they can always change their email
     * on profile.php, setting or not.
     *
     * @return string
     */
    private static function renderChangeEmail(): string
    {
        $user = get_userdata(get_current_user_id());
        if ($user === false) {
            return self::renderNotice('error', esc_html__('You do not have permission to use the member area.', 'traydigita-core'));
        }
        $pending = get_user_meta((int) $user->ID, '_new_email', true);
        $html = '<h2 class="traydigita-contributor-heading">' . esc_html__('Change email', 'traydigita-core') . '</h2>';
        $html .= '<p class="traydigita-contributor-empty">'
            . esc_html__('Your current email address is:', 'traydigita-core') . ' <strong>'
            . esc_html($user->user_email) . '</strong></p>';
        if (is_array($pending) && isset($pending['newemail']) && is_string($pending['newemail'])) {
            $html .= '<p class="traydigita-contributor-notice traydigita-contributor-notice-info">'
                . sprintf(
                    /* translators: %s: pending new email address. */
                    esc_html__('A change to %s is waiting for confirmation. Check your inbox for the confirmation email.', 'traydigita-core'),
                    esc_html($pending['newemail'])
                )
                . '</p>';
        }
        $html .= '<form class="traydigita-contributor-form" method="post" action="'
            . esc_url(admin_url('admin-post.php')) . '">';
        $html .= wp_nonce_field(ContributorFrontendModule::EMAIL_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="'
            . esc_attr(ContributorFrontendModule::EMAIL_ACTION) . '">';
        $html .= '<p class="traydigita-contributor-field">'
            . '<label for="traydigita-contributor-new-email">' . esc_html__('New email address', 'traydigita-core') . '</label>'
            . '<input type="email" id="traydigita-contributor-new-email" name="traydigita_new_email" required maxlength="100" value="">'
            . '</p>';
        $html .= '<p class="traydigita-contributor-actions">'
            . '<button type="submit" class="traydigita-contributor-button traydigita-contributor-button-primary">'
            . esc_html__('Send confirmation email', 'traydigita-core') . '</button>'
            . '</p>';
        $html .= '</form>';
        $html .= '<p class="traydigita-contributor-empty">'
            . esc_html__('The new address only takes effect after you click the confirmation link sent to it.', 'traydigita-core')
            . '</p>';
        if (!ContributorFrontendModule::isFrontendOnlyUser()) {
            $html .= '<p class="traydigita-contributor-empty">'
                . '<a href="' . esc_url(admin_url('profile.php')) . '">'
                . esc_html__('You can also change your email on your WordPress profile page.', 'traydigita-core')
                . '</a></p>';
        }
        return $html;
    }

    /**
     * Render the Bookmarks section: the reader's saved articles.
     *
     * The list shell is the same one the bookmark.php page
     * renders — the entries themselves live in the browser's
     * IndexedDB store (see Bookmarks), so the list is filled
     * in by JavaScript on both surfaces.
     *
     * @return string
     */
    private static function renderBookmarks(): string
    {
        $html = '<h2 class="traydigita-contributor-heading">' . esc_html__('Bookmarks', 'traydigita-core') . '</h2>';
        $html .= '<p class="traydigita-contributor-empty">'
            . esc_html__('Articles you saved for later. Bookmarks live in this browser only — they do not sync to other devices.', 'traydigita-core')
            . '</p>';
        $html .= Bookmarks::renderListShell();
        return $html;
    }
}
