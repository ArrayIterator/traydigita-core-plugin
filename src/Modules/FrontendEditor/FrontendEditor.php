<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FrontendEditor;

use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Core;
use TrayDigita\WP\Plugin\Core\Modules\EditorialWorkflow\EditorialWorkflowModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberAreaModule;
use TrayDigita\WP\Plugin\Core\Modules\MemberArea\MemberDashboard;
use TrayDigita\WP\Plugin\Core\Modules\RoleAccess\RoleAccessModule;
use function __;
use function absint;
use function add_query_arg;
use function admin_url;
use function array_filter;
use function array_map;
use function array_shift;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_textarea;
use function esc_url;
use function get_current_user_id;
use function get_post;
use function get_post_meta;
use function get_post_thumbnail_id;
use function get_term;
use function get_terms;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function is_user_logged_in;
use function is_wp_error;
use function parse_blocks;
use function preg_match;
use function preg_split;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function selected;
use function serialize_block;
use function str_replace;
use function strpos;
use function substr_count;
use function trim;
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
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_strip_all_tags;
use function wp_trash_post;
use function wp_unslash;
use function wp_untrash_post;
use function wp_update_post;
use function wp_verify_nonce;

/**
 * Frontend block editor for contributors.
 *
 * The Write view of the member dashboard: a simple block editor
 * (paragraph, heading, list and quote only) plus the scoped
 * featured-image upload. On save the submitted blocks are parsed
 * and filtered server-side (see sanitizeBlocks()): anything
 * outside the allowlist is stripped, never trusted from the
 * browser. The editor is a small React bundle that talks to the
 * global `wp` object shipped by WordPress core; the plain
 * textarea stays in the form underneath it, so the form still
 * works when the editor scripts fail to load.
 *
 * Submissions arrive through admin-post.php (see
 * FrontendEditorModule::SAVE_ACTION and friends): a submission
 * inserts or updates a post as a draft ("Save draft") or pending
 * ("Submit for review"). Contributors may only ever produce draft
 * or pending posts — the editorial statuses are enforced in
 * EditorialWorkflowModule::enforceEditorialStatus().
 *
 * The shortcode entry point (shortcode()) renders the full
 * dashboard shell when the Member Area module is enabled and
 * delegates to it; when the Member Area module is disabled it
 * renders just this editor, so the block editor keeps working
 * without the dashboard shell.
 */
final class FrontendEditor
{
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
     * Shortcode `[traydigita_contributor_editor]`.
     *
     * When the Member Area module is enabled the dashboard shell
     * owns the shortcode (see MemberDashboard::shortcode()) and
     * this only delegates to it. When the Member Area module is
     * disabled the block editor still works on its own: guests
     * see the login prompt, dashboard-mode users are sent to
     * wp-admin (the full Gutenberg editor, exactly as the
     * dashboard resolver would send them), and frontend-only
     * users get just the editor form with its save notices.
     *
     * @param array<string, mixed> $atts Shortcode attributes (unused).
     * @return string
     */
    public static function shortcode(array $atts = []): string
    {
        if (ModuleRegistry::isEnabled(MemberAreaModule::id())) {
            return MemberDashboard::shortcode($atts);
        }
        if (!is_user_logged_in()) {
            return MemberDashboard::renderLoginPrompt();
        }
        if (!RoleAccessModule::isFrontendOnlyUser()) {
            wp_safe_redirect(admin_url('post-new.php'));
            exit;
        }
        if (!current_user_can('edit_posts')) {
            return MemberDashboard::renderNotice(
                'error',
                esc_html__('You do not have permission to submit articles.', 'traydigita-core'),
                true
            );
        }
        return '<div class="traydigita-contributor-editor">'
            . MemberDashboard::renderNotices()
            . self::renderEditor()
            . '</div>';
    }

    /**
     * Render the Write section of the dashboard shell.
     *
     * The section render callable the Frontend Editor registers
     * through the `traydigita:core:member/sections` filter, so
     * the shell renders the editor through the hook, never by
     * calling this class directly.
     *
     * @return string
     */
    public static function renderWriteSection(): string
    {
        return self::renderEditor();
    }

    /**
     * Whether renderEditor() refused the requested edit.
     *
     * Read by the dashboard shell: the top bar must not offer
     * Save / Submit buttons for a form that was never rendered.
     *
     * @return bool
     */
    public static function isNotEditable(): bool
    {
        return self::$editorNotEditable;
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
        $fallback = MemberAreaModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $redirect = $fallback;
        $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, FrontendEditorModule::SAVE_ACTION)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'nonce', $redirect));
            exit;
        }
        if (!current_user_can('edit_posts')) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'capability', $redirect));
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
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'title', $redirect));
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
                wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'not-editable', $redirect));
                exit;
            }
        }
        $settings = FrontendEditorModule::getSettings();
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
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'save', $redirect));
            exit;
        }
        $savedId = (int) $result;
        if ($submit === 'review') {
            $target = add_query_arg(
                [MemberAreaModule::VIEW_FLAG => 'posts', MemberAreaModule::STATUS_FLAG => 'pending', MemberAreaModule::SAVED_FLAG => '1'],
                MemberAreaModule::currentPageUrl()
            );
        } else {
            $target = add_query_arg(
                [MemberAreaModule::VIEW_FLAG => 'write', MemberAreaModule::EDIT_FLAG => $savedId, MemberAreaModule::SAVED_FLAG => '1'],
                MemberAreaModule::currentPageUrl()
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
        $allowedBlocks = $settings['allowed_blocks'] ?? FrontendEditorModule::ALLOWED_BLOCKS;
        if (!is_array($allowedBlocks) || $allowedBlocks === []) {
            $allowedBlocks = FrontendEditorModule::ALLOWED_BLOCKS;
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
        $fallback = MemberAreaModule::memberAreaUrl();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($fallback));
            exit;
        }
        $listUrl = add_query_arg(
            [MemberAreaModule::VIEW_FLAG => 'contributions', MemberAreaModule::STATUS_FLAG => $action === 'delete-permanent' ? 'trash' : 'all'],
            MemberAreaModule::currentPageUrl()
        );
        $nonceAction = match ($action) {
            'restore' => FrontendEditorModule::RESTORE_ACTION,
            'delete-permanent' => FrontendEditorModule::PERMANENT_DELETE_ACTION,
            default => FrontendEditorModule::DELETE_ACTION,
        };
        $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, $nonceAction)) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'nonce', $fallback));
            exit;
        }
        if (!current_user_can('edit_posts')) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'capability', $fallback));
            exit;
        }
        $postId = isset($_GET['traydigita_post_id']) ? absint(wp_unslash((string) $_GET['traydigita_post_id'])) : 0;
        $post = $postId > 0 ? get_post($postId) : null;
        if ($post === null
            || (int) $post->post_author !== get_current_user_id()
            || $post->post_type !== 'post'
        ) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'not-found', $fallback));
            exit;
        }
        $allowed = match ($action) {
            'restore' => $post->post_status === 'trash',
            'delete-permanent' => $post->post_status === 'trash',
            default => in_array($post->post_status, self::TRASHABLE_STATUSES, true),
        };
        if (!$allowed) {
            wp_safe_redirect(add_query_arg(MemberAreaModule::ERROR_FLAG, 'not-editable', $fallback));
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
        wp_safe_redirect(add_query_arg(MemberAreaModule::SAVED_FLAG, $flag, $listUrl));
        exit;
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
        if (isset($_GET[MemberAreaModule::EDIT_FLAG])) {
            $candidateId = absint(wp_unslash($_GET[MemberAreaModule::EDIT_FLAG]));
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
                    if ($existing->post_status === EditorialWorkflowModule::STATUS_NEEDS_REVISION) {
                        $note = get_post_meta($candidateId, EditorialWorkflowModule::REVISION_NOTE_META, true);
                        $revisionNote = is_string($note) ? $note : '';
                    }
                } else {
                    $notEditable = true;
                }
            }
        }
        if ($notEditable) {
            self::$editorNotEditable = true;
            return MemberDashboard::renderNotice('error', esc_html__('This article cannot be edited.', 'traydigita-core'))
                . '<p><a class="traydigita-contributor-item-edit" href="'
                . esc_url(MemberAreaModule::viewUrl('contributions')) . '">'
                . esc_html__('My Contributions', 'traydigita-core') . '</a></p>';
        }
        $terms = get_terms([
            'taxonomy' => 'category',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        $settings = FrontendEditorModule::getSettings();
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
        $html .= wp_nonce_field(FrontendEditorModule::SAVE_ACTION, '_wpnonce', true, false);
        $html .= '<input type="hidden" name="action" value="'
            . esc_attr(FrontendEditorModule::SAVE_ACTION) . '">';
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
        $nonce = wp_create_nonce(FrontendEditorModule::FEATURED_NONCE_ACTION);
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
}
