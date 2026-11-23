<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FormBuilder;

use function __;
use function array_keys;
use function array_values;
use function count;
use function esc_html;
use function esc_url;
use function esc_url_raw;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function nl2br;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function str_replace;
use function trim;

/**
 * Reusable block-based email template builder.
 *
 * A template is one plain array:
 *
 *     [
 *         'blocks' => [
 *             ['type' => 'header', 'settings' => ['text' => 'Hello']],
 *             ['type' => 'text',   'settings' => ['text' => '...']],
 *             ['type' => 'button', 'settings' => ['label' => 'Visit', 'url' => 'https://...']],
 *             ['type' => 'divider','settings' => []],
 *             ['type' => 'footer', 'settings' => ['text' => '...']],
 *         ],
 *     ]
 *
 * Block text supports `{{field_id}}` placeholders, replaced with
 * the submitted values when rendering (Contact Form notification
 * mail). The same helpers are used by the admin builder and by
 * renderEmail(), so the preview and the sent mail can never drift
 * apart. The output is a single-column table email with inline
 * styles only, so it renders in plain mail clients too.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\FormBuilder
 */
final class EmailBuilder
{
    /**
     * Allowlisted block types, in the default canvas order.
     *
     * @var array<int, string>
     */
    public const array BLOCK_TYPES = [
        'header',
        'text',
        'button',
        'divider',
        'footer',
    ];

    /**
     * Maximum number of blocks in one template.
     */
    public const int MAX_BLOCKS = 20;

    /**
     * Maximum length (characters) of a block text.
     */
    public const int MAX_TEXT_LENGTH = 5000;

    /**
     * Translatable display labels for the block types.
     *
     * @return array<string, string> Block type => label.
     */
    public static function blockLabels(): array
    {
        return [
            'header' => __('Header', 'traydigita-core'),
            'text' => __('Text', 'traydigita-core'),
            'button' => __('Button', 'traydigita-core'),
            'divider' => __('Divider', 'traydigita-core'),
            'footer' => __('Footer', 'traydigita-core'),
        ];
    }

    /**
     * Default template: header, one text block, footer.
     *
     * @return array<string, mixed>
     */
    public static function defaultTemplate(): array
    {
        return [
            'blocks' => [
                ['type' => 'header', 'settings' => ['text' => '']],
                ['type' => 'text', 'settings' => ['text' => '']],
                ['type' => 'footer', 'settings' => ['text' => '']],
            ],
        ];
    }

    /**
     * Deeply sanitize untrusted input into a template.
     *
     * Unknown block types and unknown keys are dropped. A
     * template with no valid block at all is rejected.
     *
     * @param mixed $input Untrusted input (usually the posted form).
     * @return array{template: array<string, mixed>|null, error: string|null}
     */
    public static function sanitizeTemplate(mixed $input): array
    {
        if (!is_array($input)) {
            return [
                'template' => null,
                'error' => __('The email template is invalid.', 'traydigita-core'),
            ];
        }
        $blocks = $input['blocks'] ?? null;
        if (!is_array($blocks)) {
            return [
                'template' => null,
                'error' => __('The email template has no blocks.', 'traydigita-core'),
            ];
        }
        $sanitized = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $clean = self::sanitizeBlock($block);
            if ($clean === null) {
                continue;
            }
            $sanitized[] = $clean;
            if (count($sanitized) >= self::MAX_BLOCKS) {
                break;
            }
        }
        if ($sanitized === []) {
            return [
                'template' => null,
                'error' => __('The email template has no valid blocks.', 'traydigita-core'),
            ];
        }
        return [
            'template' => ['blocks' => $sanitized],
            'error' => null,
        ];
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
        $text = isset($settings['text']) && is_string($settings['text']) ? sanitize_textarea_field($settings['text']) : '';
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH);
        }
        $clean = match ($type) {
            'header', 'text', 'footer' => ['text' => $text],
            'button' => [
                'label' => isset($settings['label']) && is_string($settings['label']) ? sanitize_text_field($settings['label']) : '',
                'url' => isset($settings['url']) && is_string($settings['url']) ? esc_url_raw(trim($settings['url'])) : '',
            ],
            'divider' => [],
        };
        if ($type === 'button' && ($clean['label'] === '' || $clean['url'] === '')) {
            return null;
        }
        return ['type' => $type, 'settings' => $clean];
    }

    /**
     * Structural validation of a template array.
     *
     * @param array<string, mixed> $template Template to check.
     * @return bool True when the shape is valid.
     */
    public static function validateTemplate(array $template): bool
    {
        $blocks = $template['blocks'] ?? null;
        if (!is_array($blocks) || $blocks === [] || count($blocks) > self::MAX_BLOCKS) {
            return false;
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || self::sanitizeBlock($block) === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Render a template as an HTML email body.
     *
     * `{{field_id}}` placeholders in block text are replaced with
     * the matching values from $data (arrays are joined with
     * ", "). Values are escaped, so a submitted value can never
     * inject markup into the mail.
     *
     * @param array<string, mixed> $template Sanitized template.
     * @param array<string, mixed> $data Placeholder values keyed by field id.
     * @return string Rendered HTML email (empty when the template is invalid).
     */
    public static function renderEmail(array $template, array $data = []): string
    {
        if (!self::validateTemplate($template)) {
            return '';
        }
        $rows = '';
        foreach ($template['blocks'] as $block) {
            $type = (string) $block['type'];
            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            if ($type === 'header') {
                $text = self::replacePlaceholders((string) ($settings['text'] ?? ''), $data);
                if ($text === '') {
                    continue;
                }
                $rows .= '<tr><td style="padding:24px 24px 8px;font-family:Arial,sans-serif;font-size:22px;font-weight:bold;color:#111827;">' . esc_html($text) . '</td></tr>';
            } elseif ($type === 'text') {
                $text = self::replacePlaceholders((string) ($settings['text'] ?? ''), $data);
                if ($text === '') {
                    continue;
                }
                $rows .= '<tr><td style="padding:8px 24px;font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#374151;">' . nl2br(esc_html($text)) . '</td></tr>';
            } elseif ($type === 'button') {
                $label = (string) ($settings['label'] ?? '');
                $url = (string) ($settings['url'] ?? '');
                if ($label === '' || $url === '') {
                    continue;
                }
                $rows .= '<tr><td style="padding:16px 24px;"><a href="' . esc_url($url) . '" style="display:inline-block;padding:12px 24px;background-color:#0B6B3A;color:#ffffff;text-decoration:none;border-radius:6px;font-family:Arial,sans-serif;font-size:15px;font-weight:bold;">' . esc_html($label) . '</a></td></tr>';
            } elseif ($type === 'divider') {
                $rows .= '<tr><td style="padding:16px 24px;"><hr style="border:none;border-top:1px solid #e5e7eb;"></td></tr>';
            } elseif ($type === 'footer') {
                $text = self::replacePlaceholders((string) ($settings['text'] ?? ''), $data);
                if ($text === '') {
                    continue;
                }
                $rows .= '<tr><td style="padding:8px 24px 24px;font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:#6b7280;">' . nl2br(esc_html($text)) . '</td></tr>';
            }
        }
        if ($rows === '') {
            return '';
        }
        return '<!DOCTYPE html><html><body style="margin:0;padding:0;background-color:#f9fafb;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;">'
            . '<tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">'
            . $rows
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Replace {{field}} placeholders with escaped-safe values.
     *
     * Values are inserted raw here and escaped by the caller at
     * render time; this method only substitutes the text.
     *
     * @param string $text Block text.
     * @param array<string, mixed> $data Placeholder values.
     * @return string Text with placeholders replaced.
     */
    private static function replacePlaceholders(string $text, array $data): string
    {
        if ($text === '' || $data === []) {
            return $text;
        }
        $replacements = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($value)) {
                $parts = [];
                foreach ($value as $part) {
                    if (is_string($part)) {
                        $parts[] = $part;
                    }
                }
                $value = implode(', ', $parts);
            } elseif (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $replacements['{{' . $key . '}}'] = (string) $value;
        }
        if ($replacements === []) {
            return $text;
        }
        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }
}
