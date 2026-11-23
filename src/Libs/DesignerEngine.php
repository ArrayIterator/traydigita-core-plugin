<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Libs;

use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateStore;
use function absint;
use function array_key_exists;
use function base64_decode;
use function count;
use function esc_url;
use function esc_url_raw;
use function function_exists;
use function get_current_user_id;
use function get_option;
use function get_post_mime_type;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sanitize_hex_color;
use function sanitize_text_field;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function update_option;
use function wp_get_attachment_image_url;

/**
 * Shared block-designer engine for the Maintenance and Login designers.
 *
 * One engine, two thin subclasses: MaintenanceDesigner and
 * Login\Designer keep only their own block vocabularies, defaults,
 * labels, hints and render paths. Everything both designers did
 * identically lives here: design validation, deep sanitization,
 * the Custom CSS sanitizer, the background/logo/social helpers,
 * and design storage.
 *
 * A design is one plain array:
 *
 *     [
 *         'version' => 1,
 *         'blocks'  => [
 *             ['type' => 'background', 'settings' => [...]],
 *             ...
 *         ],
 *     ]
 *
 * Every block type may appear at most once; unknown types and
 * unknown setting keys are dropped by sanitizeDesign(). The same
 * helpers are used by the admin builder, the live preview and the
 * real page, so the preview and the frontend can never drift
 * apart in values.
 *
 * Storage: one published row per type in the unified
 * `traydigita_templates` table (TemplateStore), slug = the type
 * ('maintenance' | 'login'), marked as the default row of its
 * type. The row's `blocks` column holds ONLY the blocks array;
 * `version` is not stored in the row (the table has no version
 * column) — the engine re-attaches the current SCHEMA_VERSION
 * when a row is read back into a design array, so a row written
 * by this engine is by construction a design of the current
 * schema version. If SCHEMA_VERSION is ever bumped, rows written
 * under the old version must be migrated or re-validated before
 * the new version is read; the version is deliberately not
 * silently assumed from the stored data.
 *
 * Migration: designs stored under the legacy wp_options keys
 * (`traydigita_maintenance_design`, `traydigita_login_design`)
 * are imported once, lazily, on the first design read (or save)
 * of each type. The import is guarded by a per-type flag option
 * (set even when there was nothing to migrate, so the legacy
 * option is read at most once) and by an existence check, so a
 * design row saved after the table arrived is never overwritten
 * by the legacy option. The legacy option is NEVER deleted or
 * written after migration — it stays as the rollback source.
 * The imported row holds the sanitized legacy design's blocks,
 * byte-identical to the sanitized legacy blocks array.
 *
 * Custom CSS sanitization allowlist (see sanitizeCss()): only
 * plain CSS declarations are kept. Tags, HTML brackets,
 * comments, every at-rule except `@media`, `@import`,
 * `@charset`, `@font-face`, `@keyframes` and friends, `url()`
 * values with a `javascript:`, `data:` or `vbscript:` scheme,
 * `expression()`, `behavior:` and `-moz-binding` are removed.
 * The result must have balanced braces, otherwise it is
 * discarded entirely. The sanitized CSS is emitted verbatim
 * inside a `<style>` element on the page and inside the admin
 * preview; it is never passed through esc_html() (which would
 * break it) and never trusted without this pass.
 *
 * @package TrayDigita\WP\Plugin\Core\Libs
 */
abstract class DesignerEngine
{
    /**
     * Maximum number of social links in one design.
     */
    public const int MAX_SOCIAL_LINKS = 8;

    /**
     * Maximum length (characters) of the Custom CSS block.
     */
    public const int MAX_CUSTOM_CSS_LENGTH = 10000;

    /**
     * Gradient presets for the background block.
     *
     * Keys are stored; values are theme constants, never user input,
     * so they are safe to emit into generated CSS as-is.
     *
     * @var array<string, string>
     */
    public const array GRADIENT_PRESETS = [
        'forest' => 'linear-gradient(160deg, #063a20 0%, #166534 55%, #14532d 100%)',
        'sunrise' => 'linear-gradient(160deg, #b45309 0%, #e3b93e 55%, #f6ecd2 100%)',
        'ocean' => 'linear-gradient(160deg, #0c4a6e 0%, #0369a1 55%, #e0f2fe 100%)',
        'mono' => 'linear-gradient(160deg, #1c1917 0%, #57534e 60%, #f8f7f4 100%)',
    ];

    /**
     * Whether the legacy migration has already run in this request,
     * keyed by template type.
     *
     * Keyed (not one bool per subclass) because a static property
     * declared here would otherwise be shared by both subclasses.
     *
     * @var array<string, bool>
     */
    private static array $migrationAttempted = [];

    /**
     * TemplateStore type of this designer's designs.
     *
     * @return string TemplateStore::TYPE_MAINTENANCE or TYPE_LOGIN.
     */
    abstract protected static function templateType(): string;

    /**
     * Display name stored on the design row.
     *
     * A plain English literal, never translated: it is a stored
     * row label, not user-facing text.
     *
     * @return string
     */
    abstract protected static function designName(): string;

    /**
     * Default design: every block once, in canvas order, with neutral values.
     *
     * @return array<string, mixed>
     */
    abstract public static function defaultDesign(): array;

    /**
     * Translatable display labels for the block types.
     *
     * @return array<string, string> Block type => label.
     */
    abstract public static function blockLabels(): array;

    /**
     * Translatable one-line hint for each block type.
     *
     * @return array<string, string> Block type => hint.
     */
    abstract public static function blockHints(): array;

    /**
     * Build the generated stylesheet for a design.
     *
     * @param array<string, mixed> $design Design array.
     * @return string CSS, or an empty string when nothing is set.
     */
    abstract public static function buildCss(array $design): string;

    /**
     * Sanitize one block; returns null when the block must be dropped.
     *
     * Dispatches on the block type to the per-type sanitizers; the
     * shared ones (background, logo, social, custom_css) live in
     * this engine, the vocabulary-specific ones in the subclass.
     *
     * @param array<string, mixed> $block Raw block.
     * @return array<string, mixed>|null
     */
    abstract protected static function sanitizeBlock(array $block): ?array;

    /**
     * Error message for a structurally invalid design.
     *
     * Subclass-specific wording, so the translatable string stays
     * exactly where it was (same msgid as before the extraction).
     *
     * @return string
     */
    abstract protected static function invalidDesignMessage(): string;

    /**
     * Error message for a design with no blocks array.
     *
     * @return string
     */
    abstract protected static function noBlocksMessage(): string;

    /**
     * Error message for a design with no valid blocks.
     *
     * @return string
     */
    abstract protected static function noValidBlocksMessage(): string;

    /**
     * Slug of this designer's row in the templates table.
     *
     * One published row per type; the slug is the type itself.
     *
     * @return string
     */
    protected static function designSlug(): string
    {
        return static::templateType();
    }

    /**
     * Option flag marking this type's legacy migration as done.
     *
     * @return string
     */
    protected static function migrationFlagOption(): string
    {
        return 'traydigita_' . static::templateType() . '_design_table_migrated';
    }

    /**
     * Read the saved design, or null when nothing valid is stored.
     *
     * Runs the one-time legacy migration first, then reads the
     * default published row of the type (falling back to the
     * fixed type slug when the default flag was cleared).
     *
     * @return array<string, mixed>|null
     */
    public static function getSavedDesign(): ?array
    {
        static::ensureMigrated();
        $type = static::templateType();
        $slug = TemplateStore::getDefaultSlug($type);
        $row = $slug !== '' ? TemplateStore::getRow($type, $slug, TemplateStore::STATUS_PUBLISHED) : null;
        if ($row === null) {
            $row = TemplateStore::getRow($type, static::designSlug(), TemplateStore::STATUS_PUBLISHED);
        }
        if ($row === null) {
            return null;
        }
        $design = static::rowToDesign($row);
        if ($design === null || !static::validateDesign($design)) {
            return null;
        }
        return $design;
    }

    /**
     * Read the effective design: the saved one, or the default.
     *
     * @return array<string, mixed>
     */
    public static function getDesign(): array
    {
        return static::getSavedDesign() ?? static::defaultDesign();
    }

    /**
     * Save a design as the published row of its type.
     *
     * The design is re-sanitized here (idempotent for an already
     * sanitized design), so a direct caller can never store an
     * invalid design. Only the blocks array is stored; the
     * version is re-attached on read. Returns false when the
     * design does not sanitize or the row cannot be written.
     *
     * @param array<string, mixed> $design Design array to store.
     * @return bool True when the row was written.
     */
    public static function saveDesign(array $design): bool
    {
        static::ensureMigrated();
        $result = static::sanitizeDesign($design);
        if ($result['design'] === null) {
            return false;
        }
        $stored = TemplateStore::upsertRow(
            static::templateType(),
            static::designSlug(),
            static::designName(),
            TemplateStore::STATUS_PUBLISHED,
            $result['design']['blocks'],
            true,
            null,
            function_exists('get_current_user_id') ? (int) get_current_user_id() : 0
        );
        return $stored !== null;
    }

    /**
     * Rebuild a design array from a stored row.
     *
     * The blocks column holds the blocks array only; the version
     * is re-attached here (see the class docblock).
     *
     * @param array<string, mixed> $row Decoded store row.
     * @return array<string, mixed>|null Null when the row holds no blocks array.
     */
    protected static function rowToDesign(array $row): ?array
    {
        $blocks = $row['blocks'] ?? null;
        if (!is_array($blocks)) {
            return null;
        }
        return [
            'version' => static::SCHEMA_VERSION,
            'blocks' => $blocks,
        ];
    }

    /**
     * Run the one-time legacy migration for this type, if needed.
     *
     * The per-type in-request guard makes the lazy call cheap;
     * the flag option (see migrateLegacyDesign()) makes it run
     * at most once ever.
     *
     * @return void
     */
    private static function ensureMigrated(): void
    {
        $type = static::templateType();
        if (!empty(self::$migrationAttempted[$type])) {
            return;
        }
        self::$migrationAttempted[$type] = true;
        static::migrateLegacyDesign();
    }

    /**
     * Migrate the legacy option design into the templates table.
     *
     * One time, idempotent: guarded by the per-type flag option
     * (set even when there was nothing to migrate) and by an
     * existence check, so a design row saved after the table
     * arrived is never overwritten by the legacy option. The
     * legacy option is NEVER deleted or written here — it stays
     * as the rollback source. The imported row holds the
     * sanitized legacy design's blocks, byte-identical to the
     * sanitized legacy blocks array.
     *
     * @return array{already_migrated: bool, imported: int, skipped: int}
     */
    public static function migrateLegacyDesign(): array
    {
        if (get_option(static::migrationFlagOption(), '') === '1') {
            return ['already_migrated' => true, 'imported' => 0, 'skipped' => 0];
        }
        if (!TemplateStore::ensureTableExists()) {
            return ['already_migrated' => false, 'imported' => 0, 'skipped' => 0];
        }
        $type = static::templateType();
        $imported = 0;
        $skipped = 0;
        if (TemplateStore::getRow($type, static::designSlug(), TemplateStore::STATUS_PUBLISHED) !== null) {
            // A design row already exists; never overwrite it with
            // the legacy option.
            $skipped++;
        } else {
            $stored = get_option(static::OPTION, null);
            if (is_array($stored) && static::validateDesign($stored)) {
                $result = static::sanitizeDesign($stored);
                if ($result['design'] !== null) {
                    $saved = TemplateStore::upsertRow(
                        $type,
                        static::designSlug(),
                        static::designName(),
                        TemplateStore::STATUS_PUBLISHED,
                        $result['design']['blocks'],
                        true,
                        null,
                        function_exists('get_current_user_id') ? (int) get_current_user_id() : 0
                    );
                    if ($saved !== null) {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } else {
                    $skipped++;
                }
            } else {
                $skipped++;
            }
        }
        update_option(static::migrationFlagOption(), '1', false);
        return ['already_migrated' => false, 'imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Structural validation of a design array.
     *
     * Checks the shape only (version, block list, known types, no
     * duplicates, settings are arrays). Value-level checks live in
     * sanitizeDesign(); a stored design that passes this check is
     * safe to render because every renderer re-escapes its values.
     *
     * @param array<string, mixed> $design Design to check.
     * @return bool True when the shape is valid.
     */
    public static function validateDesign(array $design): bool
    {
        if (($design['version'] ?? null) !== static::SCHEMA_VERSION) {
            return false;
        }
        $blocks = $design['blocks'] ?? null;
        if (!is_array($blocks) || $blocks === [] || count($blocks) > count(static::BLOCK_TYPES)) {
            return false;
        }
        $seen = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                return false;
            }
            $type = $block['type'] ?? null;
            if (!is_string($type) || !in_array($type, static::BLOCK_TYPES, true)) {
                return false;
            }
            if (in_array($type, $seen, true)) {
                return false;
            }
            $seen[] = $type;
            if (!is_array($block['settings'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Deeply sanitize untrusted input into a design array.
     *
     * Accepts either the design array itself, an array wrapping it
     * under a `design` key, a JSON string, or a base64-encoded JSON
     * string. The base64 shape exists because the shell's generic
     * save endpoint deep-sanitizes every posted string with
     * sanitize_text_field(), which strips `%XX` sequences — a raw
     * JSON payload carrying a `%20` URL or a `%` CSS value would be
     * corrupted before it reaches this method. Unknown block types,
     * duplicate blocks and unknown setting keys are dropped. Every
     * field is sanitized per type (see sanitizeBlock()). An input
     * that yields no valid block at all is rejected.
     *
     * @param mixed $input Untrusted input (usually the AJAX payload).
     * @return array{design: array<string, mixed>|null, error: string|null}
     */
    public static function sanitizeDesign(mixed $input): array
    {
        if (is_array($input) && array_key_exists('design', $input)) {
            $input = $input['design'];
        }
        if (is_string($input)) {
            $input = self::decodeDesignString($input);
        }
        if (!is_array($input)) {
            return [
                'design' => null,
                'error' => static::invalidDesignMessage(),
            ];
        }
        $blocks = $input['blocks'] ?? null;
        if (!is_array($blocks)) {
            return [
                'design' => null,
                'error' => static::noBlocksMessage(),
            ];
        }
        $sanitized = [];
        $seenTypes = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $clean = static::sanitizeBlock($block);
            if ($clean === null) {
                continue;
            }
            // Each block type may appear at most once; keep the first.
            if (in_array($clean['type'], $seenTypes, true)) {
                continue;
            }
            $seenTypes[] = $clean['type'];
            $sanitized[] = $clean;
        }
        if ($sanitized === []) {
            return [
                'design' => null,
                'error' => static::noValidBlocksMessage(),
            ];
        }
        $design = [
            'version' => static::SCHEMA_VERSION,
            'blocks' => $sanitized,
        ];
        if (!static::validateDesign($design)) {
            return [
                'design' => null,
                'error' => static::invalidDesignMessage(),
            ];
        }
        return ['design' => $design, 'error' => null];
    }

    /**
     * Decode a design supplied as a string.
     *
     * Tries plain JSON first, then base64-encoded JSON (see
     * sanitizeDesign()). Returns the original string when neither
     * decodes to an array, so the caller rejects it as invalid.
     *
     * @param string $input Raw string payload.
     * @return mixed Decoded array, or the original string.
     */
    private static function decodeDesignString(string $input): mixed
    {
        $decoded = json_decode($input, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $raw = base64_decode($input, true);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $input;
    }

    /**
     * Sanitize background block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    protected static function sanitizeBackgroundSettings(array $settings): array
    {
        $mode = $settings['mode'] ?? 'color';
        if (!is_string($mode) || !in_array($mode, ['color', 'image', 'gradient'], true)) {
            $mode = 'color';
        }
        $color = sanitize_hex_color(is_string($settings['color'] ?? null) ? $settings['color'] : '');
        $gradient = $settings['gradient'] ?? 'forest';
        if (!is_string($gradient) || !array_key_exists($gradient, static::GRADIENT_PRESETS)) {
            $gradient = 'forest';
        }
        return [
            'mode' => $mode,
            'color' => $color === null ? '' : $color,
            'attachment_id' => self::sanitizeImageAttachmentId($settings['attachment_id'] ?? 0),
            'gradient' => $gradient,
        ];
    }

    /**
     * Sanitize logo block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    protected static function sanitizeLogoSettings(array $settings): array
    {
        $width = absint($settings['width'] ?? 280);
        if ($width < 80) {
            $width = 80;
        }
        if ($width > 600) {
            $width = 600;
        }
        return [
            'attachment_id' => self::sanitizeImageAttachmentId($settings['attachment_id'] ?? 0),
            'link_url' => esc_url_raw(is_string($settings['link_url'] ?? null) ? $settings['link_url'] : ''),
            'alt' => sanitize_text_field(is_string($settings['alt'] ?? null) ? $settings['alt'] : ''),
            'width' => $width,
        ];
    }

    /**
     * Sanitize social-links block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    protected static function sanitizeSocialSettings(array $settings): array
    {
        $links = $settings['links'] ?? [];
        $clean = [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link) || count($clean) >= static::MAX_SOCIAL_LINKS) {
                    continue;
                }
                $label = sanitize_text_field(is_string($link['label'] ?? null) ? $link['label'] : '');
                $url = esc_url_raw(is_string($link['url'] ?? null) ? $link['url'] : '');
                if ($label === '' || $url === '') {
                    continue;
                }
                $clean[] = ['label' => mb_substr($label, 0, 50), 'url' => $url];
            }
        }
        return ['links' => $clean];
    }

    /**
     * Sanitize Custom CSS block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    protected static function sanitizeCustomCssSettings(array $settings): array
    {
        $css = is_string($settings['css'] ?? null) ? $settings['css'] : '';
        return ['css' => self::sanitizeCss($css)];
    }

    /**
     * Validate an attachment id: it must reference an existing image.
     *
     * @param mixed $value Raw value.
     * @return int Attachment id, or 0 when it is not an image.
     */
    private static function sanitizeImageAttachmentId(mixed $value): int
    {
        $id = absint($value);
        if ($id === 0) {
            return 0;
        }
        $mime = get_post_mime_type($id);
        if (!is_string($mime) || !str_contains($mime, 'image/')) {
            return 0;
        }
        return $id;
    }

    /**
     * Sanitize arbitrary Custom CSS.
     *
     * Allowlist: plain CSS declarations only. Removed, in order:
     * HTML tags and `<` / `>` / backticks; `/* ... *\/` comments;
     * every at-rule except `@media` (`@import`, `@charset`,
     * `@font-face`, `@keyframes`, `@namespace`, `@supports`, ...);
     * `url()` values whose scheme is `javascript:`, `data:` or
     * `vbscript:`; `expression(`, `behavior:` and `-moz-binding`.
     * The result is truncated to MAX_CUSTOM_CSS_LENGTH characters
     * and must have balanced braces, otherwise an empty string is
     * returned so a broken or hostile value can never be emitted.
     *
     * @param string $css Raw CSS.
     * @return string Sanitized CSS (possibly empty).
     */
    public static function sanitizeCss(string $css): string
    {
        // Drop <script>/<style> elements including their contents, so
        // pasted markup cannot leave executable text behind.
        $css = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $css) ?? '';
        // Strip tags first so a pasted <style> or <script> block loses
        // its markup before any other pass runs.
        $css = trim(preg_replace('#<[^>]*>#', '', $css) ?? '');
        // Remove CSS comments (they can hide @import and url() tricks).
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        // HTML brackets and backticks never belong in CSS.
        $css = str_replace(['<', '>', '`'], '', $css);
        // Remove every at-rule except @media, including its block.
        $css = self::stripAtRulesExceptMedia($css);
        // Drop url() values with a dangerous scheme. The pattern
        // consumes one level of nested parentheses so
        // url(javascript:alert(1)) is removed whole.
        $css = preg_replace(
            '#url\(\s*[\'"]?\s*(?:javascript|data|vbscript):(?:[^()]|\([^()]*\))*\)#i',
            'url()',
            $css
        ) ?? '';
        // Legacy IE / Mozilla script vectors.
        $css = preg_replace('#expression\s*\(#i', '(', $css) ?? '';
        $css = preg_replace('#behavior\s*:#i', '', $css) ?? '';
        $css = preg_replace('#-moz-binding#i', '', $css) ?? '';
        $css = preg_replace('#javascript\s*:#i', '', $css) ?? '';
        $css = trim($css);
        if (mb_strlen($css) > static::MAX_CUSTOM_CSS_LENGTH) {
            $css = mb_substr($css, 0, static::MAX_CUSTOM_CSS_LENGTH);
        }
        if (!self::hasBalancedBraces($css)) {
            return '';
        }
        return $css;
    }

    /**
     * Remove at-rules other than `@media` from a CSS string.
     *
     * A rule is removed up to its terminating `;` (for statement
     * rules such as `@import ...;`) or its balanced closing brace
     * (for block rules such as `@font-face { ... }`).
     *
     * @param string $css CSS to clean.
     * @return string
     */
    private static function stripAtRulesExceptMedia(string $css): string
    {
        $length = strlen($css);
        $offset = 0;
        $output = '';
        while ($offset < $length) {
            $at = strpos($css, '@', $offset);
            if ($at === false) {
                $output .= substr($css, $offset);
                break;
            }
            $output .= substr($css, $offset, $at - $offset);
            $rest = substr($css, $at);
            if (preg_match('#^@media\b#i', $rest) === 1) {
                // Keep @media and continue scanning inside its block.
                $output .= '@media';
                $offset = $at + 6;
                continue;
            }
            // Skip the at-rule name and whitespace.
            $cursor = $at + 1;
            while ($cursor < $length && preg_match('#[a-zA-Z0-9_-]#', $css[$cursor]) === 1) {
                $cursor++;
            }
            // Find the end: either ';' or a balanced '{ ... }' block.
            $brace = strpos($css, '{', $cursor);
            $semi = strpos($css, ';', $cursor);
            if ($brace === false || ($semi !== false && $semi < $brace)) {
                $offset = $semi === false ? $length : $semi + 1;
                continue;
            }
            $depth = 0;
            $end = $brace;
            while ($end < $length) {
                if ($css[$end] === '{') {
                    $depth++;
                }
                if ($css[$end] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $end++;
            }
            $offset = $end < $length ? $end + 1 : $length;
        }
        return $output;
    }

    /**
     * Check that `{` and `}` are balanced in a CSS string.
     *
     * @param string $css CSS to check.
     * @return bool
     */
    private static function hasBalancedBraces(string $css): bool
    {
        $depth = 0;
        $length = strlen($css);
        for ($i = 0; $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            }
            if ($css[$i] === '}') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }
        return $depth === 0;
    }

    /**
     * Find one block's settings in a design.
     *
     * @param array<string, mixed> $design Design array.
     * @param string $type Block type.
     * @return array<string, mixed> Settings, or an empty array.
     */
    public static function blockSettings(array $design, string $type): array
    {
        $blocks = $design['blocks'] ?? [];
        if (!is_array($blocks)) {
            return [];
        }
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === $type && is_array($block['settings'] ?? null)) {
                return $block['settings'];
            }
        }
        return [];
    }

    /**
     * Public URL of a logo/background image attachment, or empty.
     *
     * @param int $attachmentId Attachment id.
     * @return string
     */
    public static function attachmentUrl(int $attachmentId): string
    {
        if ($attachmentId <= 0) {
            return '';
        }
        $url = wp_get_attachment_image_url($attachmentId, 'full');
        return is_string($url) ? $url : '';
    }

    /**
     * Generated background rules for a design, for a body selector.
     *
     * The background block is identical in both designers; only
     * the body selector differs (maintenance vs login page).
     *
     * @param array<string, mixed> $design Design array.
     * @param string $bodySelector Body selector the rules target.
     * @return array<int, string> CSS rules (possibly empty).
     */
    protected static function backgroundCssRules(array $design, string $bodySelector): array
    {
        $rules = [];
        $background = static::blockSettings($design, 'background');
        $mode = $background['mode'] ?? 'color';
        if ($mode === 'image') {
            $url = static::attachmentUrl(absint($background['attachment_id'] ?? 0));
            if ($url !== '') {
                $rules[] = $bodySelector . ' { background-image: url("' . esc_url($url) . '");'
                    . ' background-size: cover; background-position: center;'
                    . ' background-repeat: no-repeat; background-attachment: fixed; }';
            }
        } elseif ($mode === 'gradient') {
            $preset = $background['gradient'] ?? 'forest';
            $gradient = is_string($preset) && array_key_exists($preset, static::GRADIENT_PRESETS)
                ? static::GRADIENT_PRESETS[$preset]
                : static::GRADIENT_PRESETS['forest'];
            $rules[] = $bodySelector . ' { background: ' . $gradient . '; }';
        } else {
            $color = sanitize_hex_color(is_string($background['color'] ?? null) ? $background['color'] : '');
            if ($color !== null && $color !== '') {
                $rules[] = $bodySelector . ' { background-color: ' . $color . '; }';
            }
        }
        return $rules;
    }

    /**
     * Inline page background style for a design (preview pages).
     *
     * The same background resolution as backgroundCssRules(),
     * as one `style` attribute value instead of a rule.
     *
     * @param array<string, mixed> $design Design array.
     * @return string Style declarations, or an empty string.
     */
    protected static function pageBackgroundStyle(array $design): string
    {
        $background = static::blockSettings($design, 'background');
        $mode = $background['mode'] ?? 'color';
        if ($mode === 'image') {
            $url = static::attachmentUrl(absint($background['attachment_id'] ?? 0));
            if ($url !== '') {
                return 'background-image:url(' . esc_url($url) . ');'
                    . 'background-size:cover;background-position:center;';
            }
            return '';
        }
        if ($mode === 'gradient') {
            $preset = $background['gradient'] ?? 'forest';
            $gradient = is_string($preset) && array_key_exists($preset, static::GRADIENT_PRESETS)
                ? static::GRADIENT_PRESETS[$preset]
                : static::GRADIENT_PRESETS['forest'];
            return 'background:' . $gradient . ';';
        }
        $color = sanitize_hex_color(is_string($background['color'] ?? null) ? $background['color'] : '');
        if ($color !== null && $color !== '') {
            return 'background-color:' . $color . ';';
        }
        return '';
    }

    /**
     * Logo width for a design, clamped to the allowed range.
     *
     * @param array<string, mixed> $design Design array.
     * @return int Width in pixels (80–600).
     */
    protected static function logoWidth(array $design): int
    {
        $logo = static::blockSettings($design, 'logo');
        $width = absint($logo['width'] ?? 280);
        if ($width < 80) {
            $width = 80;
        }
        if ($width > 600) {
            $width = 600;
        }
        return $width;
    }

    /**
     * Sanitized Custom CSS block of a design, or an empty string.
     *
     * @param array<string, mixed> $design Design array.
     * @return string
     */
    protected static function customCssRule(array $design): string
    {
        $custom = static::blockSettings($design, 'custom_css');
        return is_string($custom['css'] ?? null) ? static::sanitizeCss($custom['css']) : '';
    }
}
