<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Login;

use TrayDigita\WP\Plugin\Core\Libs\DesignerEngine;
use TrayDigita\WP\Plugin\Core\Modules\Email\TemplateStore;
use function __;
use function absint;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function get_bloginfo;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function sanitize_hex_color;
use function sanitize_text_field;
use function trim;

/**
 * Design storage, validation, sanitization and rendering for the
 * Custom Login Page Builder.
 *
 * A thin subclass of the shared DesignerEngine: this class keeps
 * only the Login block vocabulary (welcome, form on top of the
 * shared background, logo, social and custom_css blocks), its
 * defaults, labels, hints and render paths. Validation,
 * sanitization, the Custom CSS sanitizer and storage all live
 * in the engine.
 *
 * A design is one plain array:
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
 * The design is stored as the published row of type 'login' in
 * the unified `traydigita_templates` table (see DesignerEngine);
 * the `traydigita_login_design` option below is only the legacy
 * store, kept as the rollback source after the one-time
 * migration and never written again.
 *
 * Every block type may appear at most once; unknown types and unknown
 * setting keys are dropped by sanitizeDesign(). The same helpers are
 * used by the admin builder, the live preview and the real login page,
 * so the preview and the frontend can never drift apart in values.
 */
final class Designer extends DesignerEngine
{
    /**
     * Legacy option name that held the whole login design.
     *
     * Read once by the one-time migration into the templates
     * table, then never written again.
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
     * TemplateStore type of this designer's designs.
     *
     * @return string
     */
    protected static function templateType(): string
    {
        return TemplateStore::TYPE_LOGIN;
    }

    /**
     * Display name stored on the design row.
     *
     * @return string
     */
    protected static function designName(): string
    {
        return 'Login design';
    }

    /**
     * Error message for a structurally invalid design.
     *
     * @return string
     */
    protected static function invalidDesignMessage(): string
    {
        return __('The login design is invalid.', 'traydigita-core');
    }

    /**
     * Error message for a design with no blocks array.
     *
     * @return string
     */
    protected static function noBlocksMessage(): string
    {
        return __('The login design has no blocks.', 'traydigita-core');
    }

    /**
     * Error message for a design with no valid blocks.
     *
     * @return string
     */
    protected static function noValidBlocksMessage(): string
    {
        return __('The login design has no valid blocks.', 'traydigita-core');
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
            'welcome' => self::sanitizeWelcomeSettings($settings),
            'form' => self::sanitizeFormSettings($settings),
            'social' => self::sanitizeSocialSettings($settings),
            'custom_css' => self::sanitizeCustomCssSettings($settings),
        };
        return ['type' => $type, 'settings' => $clean];
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
        $rules = self::backgroundCssRules($design, 'body.login');
        $logo = self::blockSettings($design, 'logo');
        $logoUrl = self::attachmentUrl(absint($logo['attachment_id'] ?? 0));
        if ($logoUrl !== '') {
            $rules[] = '#login h1 a, .login h1 a { background-image: url("' . esc_url($logoUrl) . '");'
                . ' background-size: contain; background-repeat: no-repeat; background-position: center;'
                . ' width: ' . self::logoWidth($design) . 'px; height: 84px; }';
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
        $customCss = self::customCssRule($design);
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
        $pageStyle = self::pageBackgroundStyle($design);
        $html = '<div class="login-preview-page" data-login-preview-page'
            . ($pageStyle !== '' ? ' style="' . esc_attr($pageStyle) . '"' : '');
        $background = self::blockSettings($design, 'background');
        $mode = $background['mode'] ?? 'color';
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
                $width = self::logoWidth($design);
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
        $customCss = self::customCssRule($design);
        $html .= '<style data-login-preview-custom-css>' . $customCss . '</style>';
        $html .= '</div>';
        return $html;
    }
}
