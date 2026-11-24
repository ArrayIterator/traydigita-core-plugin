<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Toc;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function absint;
use function add_action;
use function add_filter;
use function array_merge;
use function checked;
use function count;
use function current_user_can;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function get_option;
use function get_post;
use function get_post_field;
use function get_the_ID;
use function get_theme_mod;
use function in_array;
use function in_the_loop;
use function is_admin;
use function is_array;
use function is_feed;
use function is_int;
use function is_main_query;
use function is_singular;
use function is_string;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function sanitize_key;
use function sanitize_title;
use function selected;
use function set_theme_mod;
use function stripos;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function update_option;
use function wp_nonce_field;
use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;

/**
 * Table of Contents module.
 *
 * Auto-generates a table of contents from the h2/h3 headings of a
 * single post. Headings without an id get a stable slug id injected
 * (for example `toc-getting-started`, with `-2`, `-3` suffixes for
 * duplicates); headings that already carry an id keep it. The list
 * itself is plain anchor links, so the content stays fully usable
 * with JavaScript disabled — the JavaScript only adds smooth
 * scrolling, active-section highlighting, and the collapse toggle.
 *
 * Headings inside blockquotes, `<pre>` blocks, and inline `<code>`
 * elements are never listed and never get an id injected: quoted or
 * sample code is not part of the article outline.
 *
 * Placement is a setting: `before` (above the content), `side`
 * (floating sticky rail on desktop, full-width block on mobile),
 * `after_first_paragraph`, or `manual` (the `the_content` filter
 * only injects heading ids and the coordinator places the partial
 * `templates/sections/table-of-contents.php` — or calls
 * `TocModule::render()` — wherever the TOC belongs). The TOC is
 * shown only when the post has at least `min_headings` headings
 * (default 3); below that threshold the content is returned
 * untouched, with no ids injected.
 *
 * Settings live in the single option `traydigita_toc` (autoload
 * `no`) and are saved through the phase-2 admin AJAX dispatcher,
 * which verifies the nonce and the `manage_options` capability;
 * saveSettings() still checks the capability itself and casts
 * every field to its own type.
 */
final class TocModule implements ModuleInterface
{
    /**
     * Option name for all table-of-contents settings (autoload `no`).
     */
    public const string OPTION = 'traydigita_toc';

    /**
     * Customizer setting id for the position (theme mod).
     */
    public const string CUSTOMIZER_POSITION_ID = 'toc_position';

    /**
     * Customizer setting id for the minimum headings (theme mod).
     */
    public const string CUSTOMIZER_MIN_HEADINGS_ID = 'toc_min_headings';

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_toc_save';

    /**
     * Default minimum number of headings before a TOC is shown.
     */
    public const int DEFAULT_MIN_HEADINGS = 3;

    /**
     * Allowed placement values.
     *
     * @var array<int, string>
     */
    public const array POSITIONS = ['before', 'side', 'after_first_paragraph', 'manual'];

    /**
     * Tag names that disqualify a heading: anything inside one of
     * these ancestors is quoted or sample code, not article outline.
     *
     * @var array<int, string>
     */
    private const array SKIP_ANCESTORS = ['blockquote', 'pre', 'code'];

    /**
     * Re-entrancy guard for the `the_content` filter.
     *
     * Set while filterContent() runs, so a nested `the_content`
     * application (shortcodes, embeds) cannot inject a second TOC
     * or re-parse the same markup.
     */
    private static bool $filtering = false;

    /**
     * Whether boot() already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'table-of-contents';
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
        return __('Table of Contents', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Auto-generates a table of contents from the post headings, with stable anchor ids, smooth scrolling, and active-section highlighting. Headings inside quotes or code blocks are skipped.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'list-view';
    }

    /**
     * Module version.
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
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Auto-generates a table of contents from post headings.', 'traydigita-core'),
            __('Stable anchor ids and smooth scrolling.', 'traydigita-core'),
            __('Active-section highlighting.', 'traydigita-core'),
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
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-table-of-contents';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * No setup is needed: the defaults produce a working TOC, so
     * this is always true.
     *
     * @return bool Always true.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
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
     * One form: placement select, minimum-headings number,
     * collapse-toggle checkbox, and sticky checkbox (only the
     * `side` placement sticks). The form is submitted by the
     * generic admin JavaScript via the dispatcher.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $settings = self::getSettings();
        ?>
        <div class="traydigita-module-settings toc-settings">
            <?php
            SettingsControls::intro(
                __('The table of contents is built from the h2 and h3 headings '
                    . 'of each single post.', 'traydigita-core'),
                __('Headings without an id get a stable slug id (for example toc-getting-started); headings inside blockquotes or code blocks are never listed.', 'traydigita-core')
            );
            ?>

            <form class="toc-settings-form" data-traydigita-module-form data-traydigita-module="table-of-contents" data-module="table-of-contents" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>

                <p class="toc-field">
                    <label for="toc-position"><?php esc_html_e('Position', 'traydigita-core'); ?></label>
                    <select id="toc-position" name="position">
                        <option value="before" <?php selected($settings['position'], 'before'); ?>><?php esc_html_e('Before the content', 'traydigita-core'); ?></option>
                        <option value="side" <?php selected($settings['position'], 'side'); ?>><?php esc_html_e('Floating side rail on desktop', 'traydigita-core'); ?></option>
                        <option value="after_first_paragraph" <?php selected($settings['position'], 'after_first_paragraph'); ?>><?php esc_html_e('After the first paragraph', 'traydigita-core'); ?></option>
                        <option value="manual" <?php selected($settings['position'], 'manual'); ?>><?php esc_html_e('Manual placement (template partial only)', 'traydigita-core'); ?></option>
                    </select>
                    <span class="toc-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('With manual placement the content filter only injects heading ids.', 'traydigita-core'),
                            __('Place the table-of-contents partial (or call TocModule::render()) where the list belongs.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="toc-field">
                    <label for="toc-min-headings"><?php esc_html_e('Minimum headings', 'traydigita-core'); ?></label>
                    <input type="number" id="toc-min-headings" name="min_headings" value="<?php echo esc_attr((string) $settings['min_headings']); ?>" min="1" max="10" step="1">
                    <span class="toc-field-help">
                        <?php
                        SettingsControls::helpText(
                            __('Show the table of contents only when the post has at least '
                                . 'this many headings.', 'traydigita-core'),
                            __('Below the threshold the content is left untouched.', 'traydigita-core')
                        );
                        ?>
                    </span>
                </p>

                <p class="toc-field">
                    <label class="traydigita-ui-toggle" for="toc-show-toggle"><input class="traydigita-ui-toggle-input" type="checkbox" id="toc-show-toggle" name="show_toggle" value="1" <?php checked($settings['show_toggle']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Show a collapse toggle', 'traydigita-core'); ?></span></label>
                    <span class="toc-field-help">
                        <?php esc_html_e('Lets readers collapse the list. The list is expanded by default.', 'traydigita-core'); ?>
                    </span>
                </p>

                <p class="toc-field">
                    <label class="traydigita-ui-toggle" for="toc-sticky"><input class="traydigita-ui-toggle-input" type="checkbox" id="toc-sticky" name="sticky" value="1" <?php checked($settings['sticky']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Sticky side rail', 'traydigita-core'); ?></span></label>
                    <span class="toc-field-help">
                        <?php esc_html_e('Only applies to the floating side rail on desktop: it stays visible while the reader scrolls the article.', 'traydigita-core'); ?>
                    </span>
                </p>

                <?php SettingsControls::saveBar(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat keys: `position` (allowlisted), `min_headings`
     * (integer clamped to 1–10), `show_toggle` and `sticky`
     * (checkboxes: `1` when checked, absent when unchecked).
     * Unknown keys are dropped.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change Table of Contents settings.', 'traydigita-core'),
            ];
        }
        $position = isset($data['position']) && is_string($data['position'])
            ? sanitize_key($data['position'])
            : 'before';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'before';
        }
        $minHeadings = isset($data['min_headings']) ? absint($data['min_headings']) : self::DEFAULT_MIN_HEADINGS;
        if ($minHeadings < 1) {
            $minHeadings = 1;
        } elseif ($minHeadings > 10) {
            $minHeadings = 10;
        }
        update_option(self::OPTION, [
            'position' => $position,
            'min_headings' => $minHeadings,
            'show_toggle' => !empty($data['show_toggle']),
            'sticky' => !empty($data['sticky']),
        ], false);
        // Keep the Customizer copies in sync (see
        // syncFromCustomizer() for the other direction).
        set_theme_mod(self::CUSTOMIZER_POSITION_ID, $position);
        set_theme_mod(self::CUSTOMIZER_MIN_HEADINGS_ID, $minHeadings);
        return [
            'success' => true,
            'message' => __('Table of Contents settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the module hooks. Called only when the module is enabled.
     *
     * Registers the `the_content` filter (priority 12: after wpautop
     * at 10 and do_shortcode at 11, so headings exist when the
     * parser runs). The filter injects heading ids and, unless the
     * placement is `manual`, inserts the TOC itself. Also registers
     * the Customizer definitions for position and minimum headings
     * and the Customizer-save sync, so the two settings never drift
     * between the module page and the Customizer. A disabled
     * module registers nothing; the isEnabled() guard is belt and
     * braces for a direct call.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        self::$booted = true;
        add_filter('traydigita:core:customizer/definitions', [self::class, 'registerCustomizerDefinitions']);
        add_action('customize_save_after', [self::class, 'syncFromCustomizer']);
        add_filter('the_content', [self::class, 'filterContent'], 12);
    }

    /**
     * Render the table of contents for a post.
     *
     * Parses the post's raw content for h2/h3 headings (the same
     * parser the content filter uses), injects stable ids, and
     * returns the `<nav>` markup — or an empty string when the post
     * does not exist, is not a post, or has fewer headings than the
     * minimum. The returned markup is a complete, escaped nav; the
     * ids it links to are injected into the rendered content by the
     * `the_content` filter, so this method is safe to call from a
     * template placed before the content.
     *
     * @param int|null $postId Post id, or null for the current post in the loop.
     * @return string TOC nav markup, or '' when there is nothing to list.
     */
    public static function render(?int $postId = null): string
    {
        $postId = $postId ?? get_the_ID();
        if (!is_int($postId) || $postId <= 0) {
            return '';
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'post') {
            return '';
        }
        $settings = self::getSettings();
        $content = get_post_field('post_content', $postId);
        if (!is_string($content) || $content === '') {
            return '';
        }
        $parsed = self::parseHeadings($content);
        if (count($parsed['headings']) < $settings['min_headings']) {
            return '';
        }
        return self::buildNav($parsed['headings'], $postId, $settings);
    }

    /**
     * Filter `the_content`: inject heading ids and insert the TOC.
     *
     * Runs only in the main loop on a single post (never in feeds,
     * excerpts, widgets, or the admin). Below the minimum-headings
     * threshold the content is returned untouched. With the
     * `manual` placement only the ids are injected; the TOC markup
     * itself comes from the template partial.
     *
     * @param string $content Filtered post content.
     * @return string Content with heading ids injected and, unless manual, the TOC inserted.
     */
    public static function filterContent(string $content): string
    {
        if (self::$filtering || is_admin() || is_feed() || !in_the_loop() || !is_main_query() || !is_singular('post')) {
            return $content;
        }
        self::$filtering = true;
        try {
            $settings = self::getSettings();
            $parsed = self::parseHeadings($content);
            if (count($parsed['headings']) < $settings['min_headings']) {
                return $content;
            }
            $content = $parsed['html'];
            if ($settings['position'] === 'manual') {
                return $content;
            }
            $postId = get_the_ID();
            if ($postId === false) {
                return $content;
            }
            $toc = self::buildNav($parsed['headings'], $postId, $settings);
            if ($settings['position'] === 'after_first_paragraph') {
                $close = stripos($content, '</p>');
                if ($close === false) {
                    return $toc . $content;
                }
                $insertAt = $close + strlen('</p>');
                return substr($content, 0, $insertAt) . $toc . substr($content, $insertAt);
            }
            return $toc . $content;
        } finally {
            self::$filtering = false;
        }
    }

    /**
     * Parse headings out of HTML and inject stable ids.
     *
     * Finds h2/h3 elements, skipping any heading inside a
     * `<blockquote>`, `<pre>`, or `<code>` ancestor (quoted or
     * sample code is not article outline) and any heading with no
     * text. Headings without an id get `toc-<slug>` where the slug
     * comes from sanitize_title() of the heading text; duplicates
     * get `-2`, `-3` suffixes. A heading that already has an id
     * keeps it, unless that id is already used, in which case it
     * gets a suffix too. The returned HTML is the input with the
     * ids injected; skipped headings are left exactly as they were.
     *
     * @param string $html Post content HTML (raw or filtered).
     * @return array{headings: array<int, array{id: string, level: int, text: string}>, html: string}
     */
    public static function parseHeadings(string $html): array
    {
        if (trim($html) === '') {
            return ['headings' => [], 'html' => $html];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            // The XML prolog forces libxml to parse the input as UTF-8.
            // mb_convert_encoding() to HTML-ENTITIES is deprecated.
            $loaded = $doc->loadHTML(
                '<?xml encoding="UTF-8" ?><div>' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
            if (!$loaded || !$doc->documentElement instanceof DOMElement) {
                return ['headings' => [], 'html' => $html];
            }
            $usedIds = [];
            $headings = [];
            // XPath returns nodes in document order (unlike one
            // getElementsByTagName() call per tag, which would group
            // all h2s before all h3s). Snapshot the live result
            // first: setting attributes while iterating it would
            // skip elements.
            $xpath = new DOMXPath($doc);
            $found = $xpath->query('//h2 | //h3');
            $candidates = [];
            if ($found !== false) {
                foreach ($found as $node) {
                    $candidates[] = $node;
                }
            }
            foreach ($candidates as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                if (self::hasSkippedAncestor($node)) {
                    continue;
                }
                $text = trim($node->textContent);
                if ($text === '') {
                    continue;
                }
                $id = $node->getAttribute('id');
                if ($id === '') {
                    $slug = sanitize_title($text, 'section');
                    $id = 'toc-' . ($slug !== '' ? $slug : 'section');
                }
                $id = self::deduplicateId($id, $usedIds);
                $usedIds[$id] = true;
                $node->setAttribute('id', $id);
                $headings[] = [
                    'id' => $id,
                    'level' => strtolower($node->tagName) === 'h3' ? 3 : 2,
                    'text' => $text,
                ];
            }
            $inner = '';
            foreach ($doc->documentElement->childNodes as $child) {
                $inner .= $doc->saveHTML($child);
            }
            return ['headings' => $headings, 'html' => $inner];
        } catch (Throwable) {
            return ['headings' => [], 'html' => $html];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Whether a heading sits inside a skipped ancestor element.
     *
     * Walks up the parent chain; a heading inside `<blockquote>`,
     * `<pre>`, or `<code>` is quoted or sample code.
     *
     * @param DOMElement $node Heading element.
     * @return bool True when an ancestor is a skipped element.
     */
    private static function hasSkippedAncestor(DOMElement $node): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof DOMNode) {
            if ($parent instanceof DOMElement && in_array(strtolower($parent->tagName), self::SKIP_ANCESTORS, true)) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    /**
     * Make an id unique among the ids already used in this content.
     *
     * Appends `-2`, `-3`, and so on until the id is unused.
     *
     * @param string $id Candidate id.
     * @param array<string, bool> $usedIds Ids already taken.
     * @return string A unique id.
     */
    private static function deduplicateId(string $id, array $usedIds): string
    {
        if (!isset($usedIds[$id])) {
            return $id;
        }
        $suffix = 2;
        while (isset($usedIds[$id . '-' . $suffix])) {
            $suffix++;
        }
        return $id . '-' . $suffix;
    }

    /**
     * Build the TOC `<nav>` markup for a list of headings.
     *
     * h3 headings nest under the preceding h2; a leading h3 with
     * no h2 above it is promoted to the top level. The list is
     * plain anchor links — no JavaScript is required to use it.
     *
     * @param array<int, array{id: string, level: int, text: string}> $headings Parsed headings.
     * @param int $postId Post id (used to keep the list id unique on the page).
     * @param array{position: string, min_headings: int, show_toggle: bool, sticky: bool} $settings Module settings.
     * @return string TOC nav markup.
     */
    private static function buildNav(array $headings, int $postId, array $settings): string
    {
        $index = 0;
        $total = count($headings);
        $tree = self::buildTree($headings, $index, 2, $total);
        $classes = ['table-of-contents', 'toc-position-' . $settings['position']];
        if ($settings['position'] === 'side' && $settings['sticky']) {
            $classes[] = 'toc-sticky';
        }
        $listId = 'toc-list-' . $postId;
        $html = '<nav class="' . esc_attr(implode(' ', $classes)) . '" aria-label="'
            . esc_attr__('Table of contents', 'traydigita-core') . '" data-table-of-contents>';
        $html .= '<div class="toc-header">';
        $html .= '<span class="toc-title">' . esc_html__('On this page', 'traydigita-core') . '</span>';
        if ($settings['show_toggle']) {
            $html .= '<button type="button" class="toc-toggle" data-toc-toggle aria-expanded="true" aria-controls="'
                . esc_attr($listId) . '" data-msg-hide="' . esc_attr__('Hide', 'traydigita-core') . '" data-msg-show="'
                . esc_attr__('Show', 'traydigita-core') . '">' . esc_html__('Hide', 'traydigita-core') . '</button>';
        }
        $html .= '</div>';
        $html .= '<div class="toc-list-wrap" id="' . esc_attr($listId) . '">';
        $html .= self::renderTree($tree, false);
        $html .= '</div></nav>';
        return $html;
    }

    /**
     * Group a flat heading list into a nested tree.
     *
     * Consumes headings from `$index` while their level is at or
     * below `$minLevel`; a deeper heading becomes children of the
     * last node. A deeper heading with no preceding node is
     * promoted to the current level instead of being dropped.
     *
     * @param array<int, array{id: string, level: int, text: string}> $headings Flat headings.
     * @param int $index Current position in $headings (advanced by reference).
     * @param int $minLevel Deepest level handled at this depth.
     * @param int $total Number of headings.
     * @return array<int, array{heading: array{id: string, level: int, text: string}, children: array<int, mixed>}>
     */
    private static function buildTree(array $headings, int &$index, int $minLevel, int $total): array
    {
        $nodes = [];
        while ($index < $total) {
            $heading = $headings[$index];
            if ($heading['level'] < $minLevel) {
                break;
            }
            if ($heading['level'] > $minLevel) {
                if ($nodes === []) {
                    $nodes[] = ['heading' => $heading, 'children' => []];
                    $index++;
                    continue;
                }
                $last = count($nodes) - 1;
                $nodes[$last]['children'] = self::buildTree($headings, $index, $heading['level'], $total);
                continue;
            }
            $nodes[] = ['heading' => $heading, 'children' => []];
            $index++;
        }
        return $nodes;
    }

    /**
     * Render a heading tree as nested ordered lists.
     *
     * @param array<int, array{heading: array{id: string, level: int, text: string}, children: array<int, mixed>}> $nodes Tree nodes.
     * @param bool $nested Whether this is a nested list (adds the nested class).
     * @return string List markup.
     */
    private static function renderTree(array $nodes, bool $nested): string
    {
        $html = '<ol class="toc-list' . ($nested ? ' toc-list-nested' : '') . '">';
        foreach ($nodes as $node) {
            $heading = $node['heading'];
            $html .= '<li class="toc-item toc-item-level-' . (int) $heading['level'] . '">';
            $html .= '<a class="toc-link" href="#' . esc_attr($heading['id']) . '">'
                . esc_html($heading['text']) . '</a>';
            if ($node['children'] !== []) {
                $html .= self::renderTree($node['children'], true);
            }
            $html .= '</li>';
        }
        $html .= '</ol>';
        return $html;
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{position: string, min_headings: int, show_toggle: bool, sticky: bool}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $position = isset($settings['position']) && is_string($settings['position'])
            ? sanitize_key($settings['position'])
            : 'before';
        if (!in_array($position, self::POSITIONS, true)) {
            $position = 'before';
        }
        $minHeadings = isset($settings['min_headings']) ? absint($settings['min_headings']) : self::DEFAULT_MIN_HEADINGS;
        if ($minHeadings < 1) {
            $minHeadings = 1;
        } elseif ($minHeadings > 10) {
            $minHeadings = 10;
        }
        return [
            'position' => $position,
            'min_headings' => $minHeadings,
            'show_toggle' => (bool) ($settings['show_toggle'] ?? false),
            'sticky' => (bool) ($settings['sticky'] ?? false),
        ];
    }

    /**
     * Default settings.
     *
     * Placed before the content, shown from 3 headings up, with a
     * collapse toggle and a sticky side rail when that placement
     * is chosen.
     *
     * @return array{position: string, min_headings: int, show_toggle: bool, sticky: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'position' => 'before',
            'min_headings' => self::DEFAULT_MIN_HEADINGS,
            'show_toggle' => true,
            'sticky' => true,
        ];
    }

    /**
     * Customizer definitions for this module.
     *
     * Registered by boot() through the
     * `traydigita:core:customizer/definitions` filter (see
     * SettingsCustomizer). Only the two settings readers most
     * often reach for are exposed here — the full set stays on
     * the module page. The module option stays the source of
     * truth; the theme mods are kept in sync by
     * saveSettings() and syncFromCustomizer().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function customizerDefinitions(): array
    {
        return [
            [
                'id' => 'toc_position',
                'type' => 'select',
                'section' => 'traydigita_article',
                'default' => 'before',
                'label' => __('Table of contents position', 'traydigita-core'),
                'choices' => [
                    'before' => __('Before the content', 'traydigita-core'),
                    'side' => __('Floating side rail on desktop', 'traydigita-core'),
                    'after_first_paragraph' => __('After the first paragraph', 'traydigita-core'),
                    'manual' => __('Manual placement (template partial only)', 'traydigita-core'),
                ],
            ],
            [
                'id' => 'toc_min_headings',
                'type' => 'number',
                'section' => 'traydigita_article',
                'default' => self::DEFAULT_MIN_HEADINGS,
                'label' => __('Minimum headings for the table of contents', 'traydigita-core'),
                'input_attrs' => ['min' => 1, 'max' => 10, 'step' => 1],
            ],
        ];
    }

    /**
     * Append this module's definitions to the Customizer list.
     *
     * Filter callback for `traydigita:core:customizer/definitions`,
     * registered by boot().
     *
     * @param array<int, mixed> $definitions Existing definitions.
     * @return array<int, mixed> Definitions with the TOC settings appended.
     */
    public static function registerCustomizerDefinitions(array $definitions): array
    {
        return array_merge($definitions, self::customizerDefinitions());
    }

    /**
     * Copy the Customizer values into the module option.
     *
     * Runs on `customize_save_after`, so merely previewing a value
     * in the Customizer never writes to the database. Only valid
     * values are copied; an invalid theme mod is ignored and the
     * stored option keeps its value. The other direction
     * (module page to Customizer) is handled by saveSettings().
     *
     * @return void
     */
    public static function syncFromCustomizer(): void
    {
        if (!current_user_can('edit_theme_options')) {
            return;
        }
        $settings = self::getSettings();
        $changed = false;
        $position = get_theme_mod(self::CUSTOMIZER_POSITION_ID, $settings['position']);
        if (is_string($position)
            && in_array($position, self::POSITIONS, true)
            && $position !== $settings['position']
        ) {
            $settings['position'] = $position;
            $changed = true;
        }
        $minHeadings = get_theme_mod(self::CUSTOMIZER_MIN_HEADINGS_ID, $settings['min_headings']);
        $minHeadings = is_numeric($minHeadings) ? (int) $minHeadings : $settings['min_headings'];
        if ($minHeadings < 1) {
            $minHeadings = 1;
        } elseif ($minHeadings > 10) {
            $minHeadings = 10;
        }
        if ($minHeadings !== $settings['min_headings']) {
            $settings['min_headings'] = $minHeadings;
            $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $settings, false);
        }
    }
}
