<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Maintenance;

use TrayDigita\WP\Plugin\Core\Libs\DesignerEngine;
use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateStore;
use function __;
use function absint;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html__;
use function esc_url;
use function esc_url_raw;
use function get_bloginfo;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function sanitize_text_field;

/**
 * Design storage, validation, sanitization and rendering for the
 * Maintenance Page Builder.
 *
 * A thin subclass of the shared DesignerEngine: this class keeps
 * only the Maintenance block vocabulary (heading, text, countdown,
 * button, newsletter on top of the shared background, logo, social
 * and custom_css blocks), its defaults, labels, hints and render
 * paths. Validation, sanitization, the Custom CSS sanitizer and
 * storage all live in the engine.
 *
 * A design is one plain array:
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
 * The design is stored as the published row of type 'maintenance'
 * in the unified `traydigita_templates` table (see DesignerEngine);
 * the `traydigita_maintenance_design` option below is only the
 * legacy store, kept as the rollback source after the one-time
 * migration and never written again.
 *
 * Blocks with empty content fall back to the module settings:
 * an empty heading block uses the maintenance title, an empty
 * text block uses the maintenance message, and the countdown
 * block reads the countdown target from the module settings.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Maintenance
 */
final class MaintenanceDesigner extends DesignerEngine
{
    /**
     * Legacy option name that held the whole maintenance design.
     *
     * Read once by the one-time migration into the templates
     * table, then never written again.
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
     * TemplateStore type of this designer's designs.
     *
     * @return string
     */
    protected static function templateType(): string
    {
        return TemplateStore::TYPE_MAINTENANCE;
    }

    /**
     * Display name stored on the design row.
     *
     * @return string
     */
    protected static function designName(): string
    {
        return 'Maintenance design';
    }

    /**
     * Error message for a structurally invalid design.
     *
     * @return string
     */
    protected static function invalidDesignMessage(): string
    {
        return __('The maintenance design is invalid.', 'traydigita-core');
    }

    /**
     * Error message for a design with no blocks array.
     *
     * @return string
     */
    protected static function noBlocksMessage(): string
    {
        return __('The maintenance design has no blocks.', 'traydigita-core');
    }

    /**
     * Error message for a design with no valid blocks.
     *
     * @return string
     */
    protected static function noValidBlocksMessage(): string
    {
        return __('The maintenance design has no valid blocks.', 'traydigita-core');
    }

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
     * Sanitize one block; returns null when the block must be dropped.
     *
     * @param array<string, mixed> $block Raw block.
     * @return array<string, mixed>|null
     */
    protected static function sanitizeBlock(array $block): ?array
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
        $rules = self::backgroundCssRules($design, 'body.traydigita-maintenance');
        $logo = self::blockSettings($design, 'logo');
        $logoUrl = self::attachmentUrl(absint($logo['attachment_id'] ?? 0));
        if ($logoUrl !== '') {
            $width = self::logoWidth($design);
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
        $customCss = self::customCssRule($design);
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
        $pageStyle = self::pageBackgroundStyle($design);
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
        $customCss = self::customCssRule($design);
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
            $width = self::logoWidth($design);
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
