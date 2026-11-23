<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Login;

use function __;
use function absint;
use function array_key_exists;
use function base64_decode;
use function count;
use function esc_attr;
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
use function substr;
use function trim;
use function wp_get_attachment_image_url;

/**
 * Design storage, validation, sanitization and rendering for the
 * Custom Login Page Builder.
 *
 * A design is one plain array stored in the `traydigita_login_design`
 * option (autoload `no`):
 *
 *     [
 *         'version' => 1,
 *         'blocks'  => [
 *             ['type' => 'background', 'settings' => [...]],
 *             ['type' => 'logo',       'settings' => [...]],
 *             ['type' => 'welcome',    'settings' => [...]],
 *             ['type' => 'form',       'settings' => [...]],
 *             ['type' => 'social',     'settings' => [...]],
 *             ['type' => 'custom_css', 'settings' => [...]],
 *         ],
 *     ]
 *
 * Every block type may appear at most once; unknown types and unknown
 * setting keys are dropped by sanitizeDesign(). The same helpers are
 * used by the admin builder, the live preview and the real login page,
 * so the preview and the frontend can never drift apart in values.
 *
 * Custom CSS sanitization allowlist (see sanitizeCss()): only plain
 * CSS declarations are kept. Tags, HTML brackets, comments, every
 * at-rule except `@media`, `@import`, `@charset`, `@font-face`,
 * `@keyframes` and friends, `url()` values with a `javascript:`,
 * `data:` or `vbscript:` scheme, `expression()`, `behavior:` and
 * `-moz-binding` are removed. The result must have balanced braces,
 * otherwise it is discarded entirely. The sanitized CSS is emitted
 * verbatim inside a `<style>` element on wp-login.php and inside the
 * admin preview; it is never passed through esc_html() (which would
 * break it) and never trusted without this pass.
 */
final class Designer
{
    /**
     * Option name holding the whole login design.
     */
    public const string OPTION = 'traydigita_login_design';

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
        'welcome',
        'form',
        'social',
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
                    'type' => 'welcome',
                    'settings' => [
                        'heading' => '',
                        'subtext' => '',
                    ],
                ],
                [
                    'type' => 'form',
                    'settings' => [
                        'background_color' => '#ffffff',
                        'button_color' => '#166534',
                        'border_radius' => 8,
                    ],
                ],
                [
                    'type' => 'social',
                    'settings' => [
                        'links' => [],
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
            'welcome' => __('Welcome text', 'traydigita-core'),
            'form' => __('Login form', 'traydigita-core'),
            'social' => __('Social links', 'traydigita-core'),
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
            'logo' => __('Site logo shown above the login form.', 'traydigita-core'),
            'welcome' => __('Heading and short text above the login form.', 'traydigita-core'),
            'form' => __('Login form colours and corner radius.', 'traydigita-core'),
            'social' => __('Links shown below the login form.', 'traydigita-core'),
            'custom_css' => __('Extra CSS for the login page.', 'traydigita-core'),
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
                'error' => __('The login design is invalid.', 'traydigita-core'),
            ];
        }
        $blocks = $input['blocks'] ?? null;
        if (!is_array($blocks)) {
            return [
                'design' => null,
                'error' => __('The login design has no blocks.', 'traydigita-core'),
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
                'error' => __('The login design has no valid blocks.', 'traydigita-core'),
            ];
        }
        $design = [
            'version' => self::SCHEMA_VERSION,
            'blocks' => $sanitized,
        ];
        if (!self::validateDesign($design)) {
            return [
                'design' => null,
                'error' => __('The login design is invalid.', 'traydigita-core'),
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
            'welcome' => self::sanitizeWelcomeSettings($settings),
            'form' => self::sanitizeFormSettings($settings),
            'social' => self::sanitizeSocialSettings($settings),
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
     * Sanitize welcome-text block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeWelcomeSettings(array $settings): array
    {
        $heading = sanitize_text_field(is_string($settings['heading'] ?? null) ? $settings['heading'] : '');
        $subtext = sanitize_text_field(is_string($settings['subtext'] ?? null) ? $settings['subtext'] : '');
        return [
            'heading' => mb_substr($heading, 0, 200),
            'subtext' => mb_substr($subtext, 0, 500),
        ];
    }

    /**
     * Sanitize login-form block settings.
     *
     * @param array<string, mixed> $settings Raw settings.
     * @return array<string, mixed>
     */
    private static function sanitizeFormSettings(array $settings): array
    {
        $background = sanitize_hex_color(is_string($settings['background_color'] ?? null) ? $settings['background_color'] : '');
        $button = sanitize_hex_color(is_string($settings['button_color'] ?? null) ? $settings['button_color'] : '');
        $radius = absint($settings['border_radius'] ?? 8);
        if ($radius > 24) {
            $radius = 24;
        }
        return [
            'background_color' => $background === null ? '' : $background,
            'button_color' => $button === null ? '' : $button,
            'border_radius' => $radius,
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
                $rules[] = 'body.login { background-image: url("' . esc_url($url) . '");'
                    . ' background-size: cover; background-position: center;'
                    . ' background-repeat: no-repeat; background-attachment: fixed; }';
            }
        } elseif ($mode === 'gradient') {
            $preset = $background['gradient'] ?? 'forest';
            $gradient = is_string($preset) && array_key_exists($preset, self::GRADIENT_PRESETS)
                ? self::GRADIENT_PRESETS[$preset]
                : self::GRADIENT_PRESETS['forest'];
            $rules[] = 'body.login { background: ' . $gradient . '; }';
        } else {
            $color = sanitize_hex_color(is_string($background['color'] ?? null) ? $background['color'] : '');
            if ($color !== null && $color !== '') {
                $rules[] = 'body.login { background-color: ' . $color . '; }';
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
            $rules[] = '#login h1 a, .login h1 a { background-image: url("' . esc_url($logoUrl) . '");'
                . ' background-size: contain; background-repeat: no-repeat; background-position: center;'
                . ' width: ' . $width . 'px; height: 84px; }';
        }
        $form = self::blockSettings($design, 'form');
        $formBackground = sanitize_hex_color(is_string($form['background_color'] ?? null) ? $form['background_color'] : '');
        $radius = absint($form['border_radius'] ?? 8);
        if ($radius > 24) {
            $radius = 24;
        }
        if (($formBackground !== null && $formBackground !== '') || $radius !== 8) {
            $declarations = '';
            if ($formBackground !== null && $formBackground !== '') {
                $declarations .= ' background-color: ' . $formBackground . ';';
            }
            $declarations .= ' border-radius: ' . $radius . 'px;';
            $rules[] = '#loginform, .login form { ' . trim($declarations) . ' }';
        }
        $button = sanitize_hex_color(is_string($form['button_color'] ?? null) ? $form['button_color'] : '');
        if ($button !== null && $button !== '') {
            $rules[] = '#loginform .button-primary, .login .button-primary {'
                . ' background: ' . $button . '; border-color: ' . $button . '; }';
        }
        $rules[] = '.traydigita-login-welcome { text-align: center; margin-bottom: 24px; }'
            . ' .traydigita-login-welcome h2 { font-size: 20px; margin: 0 0 8px; }'
            . ' .traydigita-login-welcome p { margin: 0; color: #50575e; }'
            . ' .traydigita-login-social { list-style: none; margin: 24px 0 0; padding: 0;'
            . ' text-align: center; }'
            . ' .traydigita-login-social li { display: inline-block; margin: 0 8px; }';
        $custom = self::blockSettings($design, 'custom_css');
        $customCss = is_string($custom['css'] ?? null) ? self::sanitizeCss($custom['css']) : '';
        if ($customCss !== '') {
            $rules[] = $customCss;
        }
        return implode("\n", $rules);
    }

    /**
     * Render the extra markup injected above the login form.
     *
     * Used by the `login_message` filter on wp-login.php. Only the
     * welcome block (heading/subtext) and the social-links block
     * produce markup; every value is escaped. Returns an empty
     * string when neither block has content, so the default login
     * page is untouched.
     *
     * @param array<string, mixed> $design Design array.
     * @return string HTML (already escaped), or an empty string.
     */
    public static function renderLoginExtras(array $design): string
    {
        $html = '';
        $welcome = self::blockSettings($design, 'welcome');
        $heading = is_string($welcome['heading'] ?? null) ? $welcome['heading'] : '';
        $subtext = is_string($welcome['subtext'] ?? null) ? $welcome['subtext'] : '';
        if ($heading !== '' || $subtext !== '') {
            $html .= '<div class="traydigita-login-welcome">';
            if ($heading !== '') {
                $html .= '<h2>' . esc_html($heading) . '</h2>';
            }
            if ($subtext !== '') {
                $html .= '<p>' . esc_html($subtext) . '</p>';
            }
            $html .= '</div>';
        }
        $social = self::blockSettings($design, 'social');
        $links = $social['links'] ?? [];
        if (is_array($links) && $links !== []) {
            $items = '';
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $label = is_string($link['label'] ?? null) ? $link['label'] : '';
                $url = is_string($link['url'] ?? null) ? $link['url'] : '';
                if ($label === '' || $url === '') {
                    continue;
                }
                $items .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
            }
            if ($items !== '') {
                $html .= '<ul class="traydigita-login-social">' . $items . '</ul>';
            }
        }
        return $html;
    }

    /**
     * Render the admin preview mock of the login page.
     *
     * The preview mirrors the real login page structure (logo,
     * welcome text, form, social links) in the same block order as
     * the design, using the same values the frontend renders
     * (logo URL, welcome text, social links, generated background
     * and form colours). The form itself is a disabled mock: the
     * real wp-login.php form markup belongs to WordPress core and
     * is never submitted from here. The builder JavaScript updates
     * this markup live from the same block data.
     *
     * @param array<string, mixed> $design Design array.
     * @return string HTML (already escaped).
     */
    public static function renderPreview(array $design): string
    {
        $blocks = $design['blocks'] ?? [];
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
        $html = '<div class="login-preview-page" data-login-preview-page'
            . ($pageStyle !== '' ? ' style="' . esc_attr($pageStyle) . '"' : '');
        if ($mode === 'image') {
            $bgId = absint($background['attachment_id'] ?? 0);
            $bgUrl = self::attachmentUrl($bgId);
            if ($bgId > 0 && $bgUrl !== '') {
                $html .= ' data-background-attachment-id="' . esc_attr((string) $bgId) . '"'
                    . ' data-background-image-url="' . esc_attr($bgUrl) . '"';
            }
        }
        $html .= '>';
        $html .= '<div class="login-preview-card">';
        if (!is_array($blocks)) {
            $blocks = [];
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
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
                $html .= '<div class="login-preview-logo" data-login-preview-logo>';
                if ($logoUrl !== '') {
                    $html .= '<img src="' . esc_url($logoUrl) . '" alt="' . esc_attr($alt) . '"'
                        . ' width="' . esc_attr((string) $width) . '"'
                        . ' data-attachment-id="' . esc_attr((string) absint($logo['attachment_id'] ?? 0)) . '">';
                } else {
                    $html .= '<span class="login-preview-logo-placeholder">' . esc_html(get_bloginfo('name')) . '</span>';
                }
                $html .= '</div>';
            }
            if ($type === 'welcome') {
                $welcome = self::blockSettings($design, 'welcome');
                $heading = is_string($welcome['heading'] ?? null) ? $welcome['heading'] : '';
                $subtext = is_string($welcome['subtext'] ?? null) ? $welcome['subtext'] : '';
                $html .= '<div class="login-preview-welcome" data-login-preview-welcome>';
                if ($heading !== '') {
                    $html .= '<h2>' . esc_html($heading) . '</h2>';
                }
                if ($subtext !== '') {
                    $html .= '<p>' . esc_html($subtext) . '</p>';
                }
                $html .= '</div>';
            }
            if ($type === 'form') {
                $form = self::blockSettings($design, 'form');
                $formBackground = sanitize_hex_color(is_string($form['background_color'] ?? null) ? $form['background_color'] : '');
                $button = sanitize_hex_color(is_string($form['button_color'] ?? null) ? $form['button_color'] : '');
                $radius = absint($form['border_radius'] ?? 8);
                if ($radius > 24) {
                    $radius = 24;
                }
                $formStyle = '';
                if ($formBackground !== null && $formBackground !== '') {
                    $formStyle .= 'background-color:' . $formBackground . ';';
                }
                $formStyle .= 'border-radius:' . $radius . 'px;';
                $buttonStyle = ($button !== null && $button !== '')
                    ? 'background-color:' . $button . ';border-color:' . $button . ';'
                    : '';
                $html .= '<div class="login-preview-form" data-login-preview-form'
                    . ' style="' . esc_attr($formStyle) . '">';
                $html .= '<p><label>' . esc_html__('Username or Email Address', 'traydigita-core') . '</label>'
                    . '<input type="text" disabled></p>';
                $html .= '<p><label>' . esc_html__('Password', 'traydigita-core') . '</label>'
                    . '<input type="password" disabled></p>';
                $html .= '<p><button type="button" class="login-preview-submit" disabled'
                    . ($buttonStyle !== '' ? ' style="' . esc_attr($buttonStyle) . '"' : '') . '>'
                    . esc_html__('Log In', 'traydigita-core') . '</button></p>';
                $html .= '</div>';
            }
            if ($type === 'social') {
                $social = self::blockSettings($design, 'social');
                $links = $social['links'] ?? [];
                $html .= '<ul class="login-preview-social" data-login-preview-social>';
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
            }
        }
        $html .= '</div>';
        $custom = self::blockSettings($design, 'custom_css');
        $customCss = is_string($custom['css'] ?? null) ? self::sanitizeCss($custom['css']) : '';
        $html .= '<style data-login-preview-custom-css>' . $customCss . '</style>';
        $html .= '</div>';
        return $html;
    }
}
