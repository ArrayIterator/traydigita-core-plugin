<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Maintenance;

use function __;
use function absint;
use function array_key_exists;
use function base64_decode;
use function count;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_url;
use function esc_url_raw;
use function get_bloginfo;
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
use function wp_get_attachment_image_url;

/**
 * Design storage, validation, sanitization and rendering for the
 * Maintenance Page Builder.
 *
 * A design is one plain array stored in the
 * `traydigita_maintenance_design` option (autoload `no`):
 *
 *     [
 *         'version' => 1,
 *         'blocks'  => [
 *             ['type' => 'background', 'settings' => [...]],
 *             ['type' => 'logo',       'settings' => [...]],
 *             ['type' => 'heading',    'settings' => [...]],
 *             ['type' => 'text',       'settings' => [...]],
 *             ['type' => 'countdown',  'settings' => [...]],
 *             ['type' => 'button',     'settings' => [...]],
 *             ['type' => 'social',     'settings' => [...]],
 *             ['type' => 'newsletter', 'settings' => [...]],
 *             ['type' => 'custom_css', 'settings' => [...]],
 *         ],
 *     ]
 *
 * The pattern is the same as the Login Designer (block-based,
 * live preview, custom CSS): every block type may appear at
 * most once; unknown types and unknown setting keys are dropped
 * by sanitizeDesign(). The same helpers are used by the admin
 * builder, the live preview and the real maintenance page, so
 * the preview and the frontend can never drift apart in values.
 *
 * Blocks with empty content fall back to the module settings:
 * an empty heading block uses the maintenance title, an empty
 * text block uses the maintenance message, and the countdown
 * block reads the countdown target from the module settings.
 *
 * Custom CSS sanitization allowlist (see sanitizeCss()): only
 * plain CSS declarations are kept. Tags, HTML brackets,
 * comments, every at-rule except `@media`, `@import`,
 * `@charset`, `@font-face`, `@keyframes` and friends, `url()`
 * values with a `javascript:`, `data:` or `vbscript:` scheme,
 * `expression()`, `behavior:` and `-moz-binding` are removed.
 * The result must have balanced braces, otherwise it is
 * discarded entirely. The sanitized CSS is emitted verbatim
 * inside a `<style>` element on the maintenance page and
 * inside the admin preview; it is never passed through
 * esc_html() (which would break it) and never trusted without
 * this pass.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Maintenance
 */
final class MaintenanceDesigner
{
    /**
     * Option name holding the whole maintenance design.
     */
    public const string OPTION = 'traydigita_maintenance_design';

    /**
     * Design schema version. Bump when the stored shape changes.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * Allowlisted block types, in the default canvas order.
     *
     * @var array<int, string>
     */
    public const array BLOCK_TYPES = [
        'background',
        'logo',
        'heading',
        'text',
        'countdown',
        'button',
        'social',
        'newsletter',
        'custom_css',
    ];

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
     * Default design: every block once, in canvas order, with neutral values.
     *
     * @return array<string, mixed>
     */
    public static function defaultDesign(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'blocks' => [
                [
                    'type' => 'background',
                    'settings' => [
                        'mode' => 'color',
                        'color' => '#f8f7f4',
                        'attachment_id' => 0,
                        'gradient' => 'forest',
                    ],
                ],
                [
                    'type' => 'logo',
                    'settings' => [
                        'attachment_id' => 0,
                        'link_url' => '',
                        'alt' => '',
                        'width' => 280,
                    ],
                ],
                [
                    'type' => 'heading',
                    'settings' => [
                        'heading' => '',
                        'subtext' => '',
                    ],
                ],
                [
                    'type' => 'text',
                    'settings' => [
                        'text' => '',
                    ],
                ],
                [
                    'type' => 'countdown',
                    'settings' => [
                        'show' => true,
                    ],
                ],
                [
                    'type' => 'button',
                    'settings' => [
                        'label' => '',
                        'url' => '',
                    ],
                ],
                [
                    'type' => 'social',
                    'settings' => [
                        'links' => [],
                    ],
                ],
                [
                    'type' => 'newsletter',
                    'settings' => [
                        'show' => false,
                        'heading' => '',
                    ],
                ],
                [
                    'type' => 'custom_css',
                    'settings' => [
                        'css' => '',
                    ],
                ],
            ],
        ];
    }

    /**
     * Translatable display labels for the block types.
     *
     * @return array<string, string> Block type => label.
     */
    public static function blockLabels(): array
    {
        return [
            'background' => __('Background', 'traydigita-core'),
            'logo' => __('Logo', 'traydigita-core'),
            'heading' => __('Heading', 'traydigita-core'),
            'text' => __('Text', 'traydigita-core'),
            'countdown' => __('Countdown', 'traydigita-core'),
            'button' => __('Button', 'traydigita-core'),
            'social' => __('Social links', 'traydigita-core'),
            'newsletter' => __('Newsletter signup', 'traydigita-core'),
            'custom_css' => __('Custom CSS', 'traydigita-core'),
        ];
    }

    /**
     * Translatable one-line hint for each block type.
     *
     * @return array<string, string> Block type => hint.
     */
    public static function blockHints(): array
    {
        return [
            'background' => __('Page background: colour, image or gradient.', 'traydigita-core'),
            'logo' => __('Site logo shown at the top of the page.', 'traydigita-core'),
            'heading' => __('Main heading and short text. Empty uses the maintenance title.', 'traydigita-core'),
            'text' => __('Body text. Empty uses the maintenance message.', 'traydigita-core'),
            'countdown' => __('Countdown to the date set in the General settings.', 'traydigita-core'),
            'button' => __('A link button, e.g. to the status page.', 'traydigita-core'),
            'social' => __('Links shown below the page content.', 'traydigita-core'),
            'newsletter' => __('Email signup form (subscribes to the newsletter).', 'traydigita-core'),
            'custom_css' => __('Extra CSS for the maintenance page.', 'traydigita-core'),
        ];
    }

    /**
     * Read the saved design, or null when nothing valid is stored.
     *
     * @return array<string, mixed>|null
     */
    public static function getSavedDesign(): ?array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored) || !self::validateDesign($stored)) {
            return null;
        }
        return $stored;
    }

    /**
     * Read the effective design: the saved one, or the default.
     *
     * @return array<string, mixed>
     */
    public static function getDesign(): array
    {
        return self::getSavedDesign() ?? self::defaultDesign();
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
        if (($design['version'] ?? null) !== self::SCHEMA_VERSION) {
            return false;
        }
        $blocks = $design['blocks'] ?? null;
        if (!is_array($blocks) || $blocks === [] || count($blocks) > count(self::BLOCK_TYPES)) {
            return false;
        }
        $seen = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                return false;
            }
            $type = $block['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::BLOCK_TYPES, true)) {
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
                'error' => __('The maintenance design is invalid.', 'traydigita-core'),
            ];
        }
        $blocks = $input['blocks'] ?? null;
        if (!is_array($blocks)) {
            return [
                'design' => null,
                'error' => __('The maintenance design has no blocks.', 'traydigita-core'),
            ];
        }
        $sanitized = [];
        $seenTypes = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $clean = self::sanitizeBlock($block);
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
                'error' => __('The maintenance design has no valid blocks.', 'traydigita-core'),
            ];
        }
        $design = [
            'version' => self::SCHEMA_VERSION,
            'blocks' => $sanitized,
        ];
        if (!self::validateDesign($design)) {
            return [
                'design' => null,
                'error' => __('The maintenance design is invalid.', 'traydigita-core'),
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
     * Sanitize one block; returns null when the block must be dropped.
     *
     * @param array<string, mixed> $block Raw block.
     * @return array<string, mixed>|null
     */
    private static function sanitizeBlock(array $block): ?array
    {
        $type = $block['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::BLOCK_TYPES, true)) {
            return null;
        }
        $settings = $block['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        $clean = match ($type) {
            'background' => self::sanitizeBackgroundSettings($settings),
            'logo' => self::sanitizeLogoSettings($settings),
            'heading' => self::sanitizeHeadingSettings($settings),
            'text' => self::sanitizeTextSettings($settings),
            'countdown' => self::sanitizeCountdownSettings($settings),
            'button' => self::sanitizeButtonSettings($settings),
            'social' => self::sanitizeSocialSettings($settings),
            'newsletter' => self::sanitizeNewsletterSettings($settings),
            'custom_css' => self::sanitizeCustomCssSettings($settings),
        };
        return ['type' => $type, 'settings' => $clean];
    }

    /**
     * Sanitize background block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeBackgroundSettings(array $settings): array
    {
        $mode = $settings['mode'] ?? 'color';
        if (!is_string($mode) || !in_array($mode, ['color', 'image', 'gradient'], true)) {
            $mode = 'color';
        }
        $color = sanitize_hex_color(is_string($settings['color'] ?? null) ? $settings['color'] : '');
        $gradient = $settings['gradient'] ?? 'forest';
        if (!is_string($gradient) || !array_key_exists($gradient, self::GRADIENT_PRESETS)) {
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
    private static function sanitizeLogoSettings(array $settings): array
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
     * Sanitize heading block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeHeadingSettings(array $settings): array
    {
        $heading = sanitize_text_field(is_string($settings['heading'] ?? null) ? $settings['heading'] : '');
        $subtext = sanitize_text_field(is_string($settings['subtext'] ?? null) ? $settings['subtext'] : '');
        return [
            'heading' => mb_substr($heading, 0, 200),
            'subtext' => mb_substr($subtext, 0, 500),
        ];
    }

    /**
     * Sanitize text block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeTextSettings(array $settings): array
    {
        $text = sanitize_text_field(is_string($settings['text'] ?? null) ? $settings['text'] : '');
        return ['text' => mb_substr($text, 0, 2000)];
    }

    /**
     * Sanitize countdown block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeCountdownSettings(array $settings): array
    {
        return ['show' => !empty($settings['show'])];
    }

    /**
     * Sanitize button block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeButtonSettings(array $settings): array
    {
        $label = sanitize_text_field(is_string($settings['label'] ?? null) ? $settings['label'] : '');
        $url = esc_url_raw(is_string($settings['url'] ?? null) ? $settings['url'] : '');
        return [
            'label' => mb_substr($label, 0, 100),
            'url' => $url,
        ];
    }

    /**
     * Sanitize social-links block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeSocialSettings(array $settings): array
    {
        $links = $settings['links'] ?? [];
        $clean = [];
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link) || count($clean) >= self::MAX_SOCIAL_LINKS) {
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
     * Sanitize newsletter block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeNewsletterSettings(array $settings): array
    {
        $heading = sanitize_text_field(is_string($settings['heading'] ?? null) ? $settings['heading'] : '');
        return [
            'show' => !empty($settings['show']),
            'heading' => mb_substr($heading, 0, 200),
        ];
    }

    /**
     * Sanitize Custom CSS block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeCustomCssSettings(array $settings): array
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
        if (mb_strlen($css) > self::MAX_CUSTOM_CSS_LENGTH) {
            $css = mb_substr($css, 0, self::MAX_CUSTOM_CSS_LENGTH);
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
     * Build the generated stylesheet for a design.
     *
     * Every value is re-validated here (hex colours, integer sizes,
     * attachment URLs from the media library, gradient presets from
     * the code allowlist), so the returned CSS is safe to print
     * inside a `<style>` element without further escaping. The
     * sanitized Custom CSS block is appended verbatim at the end.
     *
     * @param array<string, mixed> $design Design array.
     * @return string CSS, or an empty string when nothing is set.
     */
    public static function buildCss(array $design): string
    {
        $rules = [];
        $background = self::blockSettings($design, 'background');
        $mode = $background['mode'] ?? 'color';
        if ($mode === 'image') {
            $url = self::attachmentUrl(absint($background['attachment_id'] ?? 0));
            if ($url !== '') {
                $rules[] = 'body.traydigita-maintenance { background-image: url("' . esc_url($url) . '");'
                    . ' background-size: cover; background-position: center;'
                    . ' background-repeat: no-repeat; background-attachment: fixed; }';
            }
        } elseif ($mode === 'gradient') {
            $preset = $background['gradient'] ?? 'forest';
            $gradient = is_string($preset) && array_key_exists($preset, self::GRADIENT_PRESETS)
                ? self::GRADIENT_PRESETS[$preset]
                : self::GRADIENT_PRESETS['forest'];
            $rules[] = 'body.traydigita-maintenance { background: ' . $gradient . '; }';
        } else {
            $color = sanitize_hex_color(is_string($background['color'] ?? null) ? $background['color'] : '');
            if ($color !== null && $color !== '') {
                $rules[] = 'body.traydigita-maintenance { background-color: ' . $color . '; }';
            }
        }
        $logo = self::blockSettings($design, 'logo');
        $logoUrl = self::attachmentUrl(absint($logo['attachment_id'] ?? 0));
        if ($logoUrl !== '') {
            $width = absint($logo['width'] ?? 280);
            if ($width < 80) {
                $width = 80;
            }
            if ($width > 600) {
                $width = 600;
            }
            $rules[] = '.traydigita-maintenance-logo img { width: ' . $width . 'px; max-width: 100%; height: auto; }';
        }
        $button = self::blockSettings($design, 'button');
        $buttonLabel = is_string($button['label'] ?? null) ? $button['label'] : '';
        $buttonUrl = is_string($button['url'] ?? null) ? $button['url'] : '';
        if ($buttonLabel !== '' && $buttonUrl !== '') {
            $rules[] = '.traydigita-maintenance-button { display: inline-block; padding: 12px 24px;'
                . ' background-color: #0B6B3A; color: #ffffff; text-decoration: none;'
                . ' border-radius: 6px; font-weight: bold; }';
        }
        $custom = self::blockSettings($design, 'custom_css');
        $customCss = is_string($custom['css'] ?? null) ? self::sanitizeCss($custom['css']) : '';
        if ($customCss !== '') {
            $rules[] = $customCss;
        }
        return implode("\n", $rules);
    }

    /**
     * Render the admin preview of the maintenance page.
     *
     * The preview mirrors the real maintenance page structure in
     * the same block order as the design, using the same values
     * the frontend renders. The newsletter form is a disabled
     * mock: the real form posts to admin-post.php and is never
     * submitted from the preview. The countdown shows the live
     * parts passed in (or zeros when no countdown is set).
     *
     * @param array<string, mixed> $design Design array.
     * @param string $title Fallback title (maintenance settings).
     * @param string $message Fallback message (maintenance settings).
     * @param array{days: int, hours: int, minutes: int, seconds: int} $countdownParts Countdown parts.
     * @return string HTML (already escaped).
     */
    public static function renderPreview(array $design, string $title, string $message, array $countdownParts): string
    {
        $blocks = $design['blocks'] ?? [];
        if (!is_array($blocks)) {
            $blocks = [];
        }
        $pageStyle = '';
        $background = self::blockSettings($design, 'background');
        $mode = $background['mode'] ?? 'color';
        if ($mode === 'image') {
            $url = self::attachmentUrl(absint($background['attachment_id'] ?? 0));
            if ($url !== '') {
                $pageStyle = 'background-image:url(' . esc_url($url) . ');'
                    . 'background-size:cover;background-position:center;';
            }
        } elseif ($mode === 'gradient') {
            $preset = $background['gradient'] ?? 'forest';
            $gradient = is_string($preset) && array_key_exists($preset, self::GRADIENT_PRESETS)
                ? self::GRADIENT_PRESETS[$preset]
                : self::GRADIENT_PRESETS['forest'];
            $pageStyle = 'background:' . $gradient . ';';
        } else {
            $color = sanitize_hex_color(is_string($background['color'] ?? null) ? $background['color'] : '');
            if ($color !== null && $color !== '') {
                $pageStyle = 'background-color:' . $color . ';';
            }
        }
        $html = '<div class="maintenance-preview-page" data-maintenance-preview-page'
            . ($pageStyle !== '' ? ' style="' . esc_attr($pageStyle) . '"' : '') . '>';
        $html .= '<div class="maintenance-preview-card">';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            $html .= self::renderPreviewBlock($design, (string) $type, $title, $message, $countdownParts);
        }
        $html .= '</div>';
        $custom = self::blockSettings($design, 'custom_css');
        $customCss = is_string($custom['css'] ?? null) ? self::sanitizeCss($custom['css']) : '';
        $html .= '<style data-maintenance-preview-custom-css>' . $customCss . '</style>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Render one block inside the admin preview.
     *
     * @param array<string, mixed> $design Design array.
     * @param string $type Block type.
     * @param string $title Fallback title.
     * @param string $message Fallback message.
     * @param array{days: int, hours: int, minutes: int, seconds: int} $countdownParts Countdown parts.
     * @return string Block HTML (already escaped).
     */
    private static function renderPreviewBlock(array $design, string $type, string $title, string $message, array $countdownParts): string
    {
        if ($type === 'logo') {
            $logo = self::blockSettings($design, 'logo');
            $logoUrl = self::attachmentUrl(absint($logo['attachment_id'] ?? 0));
            $alt = is_string($logo['alt'] ?? null) ? $logo['alt'] : '';
            $width = absint($logo['width'] ?? 280);
            if ($width < 80) {
                $width = 80;
            }
            if ($width > 600) {
                $width = 600;
            }
            $html = '<div class="maintenance-preview-logo" data-maintenance-preview-logo>';
            if ($logoUrl !== '') {
                $html .= '<img src="' . esc_url($logoUrl) . '" alt="' . esc_attr($alt) . '"'
                    . ' width="' . esc_attr((string) $width) . '"'
                    . ' data-attachment-id="' . esc_attr((string) absint($logo['attachment_id'] ?? 0)) . '">';
            } else {
                $html .= '<span class="maintenance-preview-logo-placeholder">' . esc_html(get_bloginfo('name')) . '</span>';
            }
            $html .= '</div>';
            return $html;
        }
        if ($type === 'heading') {
            $heading = self::blockSettings($design, 'heading');
            $headingText = is_string($heading['heading'] ?? null) && $heading['heading'] !== '' ? $heading['heading'] : $title;
            $subtext = is_string($heading['subtext'] ?? null) ? $heading['subtext'] : '';
            $html = '<div class="maintenance-preview-heading" data-maintenance-preview-heading>';
            $html .= '<h2>' . esc_html($headingText) . '</h2>';
            if ($subtext !== '') {
                $html .= '<p>' . esc_html($subtext) . '</p>';
            }
            $html .= '</div>';
            return $html;
        }
        if ($type === 'text') {
            $text = self::blockSettings($design, 'text');
            $textValue = is_string($text['text'] ?? null) && $text['text'] !== '' ? $text['text'] : $message;
            if ($textValue === '') {
                return '';
            }
            return '<div class="maintenance-preview-text" data-maintenance-preview-text><p>' . esc_html($textValue) . '</p></div>';
        }
        if ($type === 'countdown') {
            $countdown = self::blockSettings($design, 'countdown');
            if (empty($countdown['show'])) {
                return '';
            }
            $html = '<div class="maintenance-preview-countdown" data-maintenance-preview-countdown>';
            foreach (['days' => __('Days', 'traydigita-core'), 'hours' => __('Hours', 'traydigita-core'), 'minutes' => __('Minutes', 'traydigita-core'), 'seconds' => __('Seconds', 'traydigita-core')] as $part => $label) {
                $html .= '<span class="maintenance-preview-countdown-part"><strong>' . (int) ($countdownParts[$part] ?? 0) . '</strong> ' . esc_html($label) . '</span>';
            }
            $html .= '</div>';
            return $html;
        }
        if ($type === 'button') {
            $button = self::blockSettings($design, 'button');
            $label = is_string($button['label'] ?? null) ? $button['label'] : '';
            $url = is_string($button['url'] ?? null) ? $button['url'] : '';
            if ($label === '' || $url === '') {
                return '';
            }
            return '<div class="maintenance-preview-button" data-maintenance-preview-button><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></div>';
        }
        if ($type === 'social') {
            $social = self::blockSettings($design, 'social');
            $links = $social['links'] ?? [];
            $html = '<ul class="maintenance-preview-social" data-maintenance-preview-social>';
            if (is_array($links)) {
                foreach ($links as $link) {
                    if (!is_array($link)) {
                        continue;
                    }
                    $label = is_string($link['label'] ?? null) ? $link['label'] : '';
                    $url = is_string($link['url'] ?? null) ? $link['url'] : '';
                    if ($label === '' || $url === '') {
                        continue;
                    }
                    $html .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
                }
            }
            $html .= '</ul>';
            return $html;
        }
        if ($type === 'newsletter') {
            $newsletter = self::blockSettings($design, 'newsletter');
            if (empty($newsletter['show'])) {
                return '';
            }
            $heading = is_string($newsletter['heading'] ?? null) ? $newsletter['heading'] : '';
            $html = '<div class="maintenance-preview-newsletter" data-maintenance-preview-newsletter>';
            if ($heading !== '') {
                $html .= '<h3>' . esc_html($heading) . '</h3>';
            }
            $html .= '<p><input type="email" disabled placeholder="' . esc_attr__('Email address', 'traydigita-core') . '">'
                . ' <button type="button" disabled>' . esc_html__('Notify me', 'traydigita-core') . '</button></p>';
            $html .= '</div>';
            return $html;
        }
        return '';
    }
}
