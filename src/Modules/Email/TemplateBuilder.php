<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Email;

use function __;
use function absint;
use function array_values;
use function count;
use function esc_attr;
use function esc_html;
use function esc_url;
use function esc_url_raw;
use function file_exists;
use function function_exists;
use function get_bloginfo;
use function get_option;
use function home_url;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function sanitize_hex_color;
use function sanitize_key;
use function sanitize_text_field;
use function str_replace;
use function time;
use function uniqid;
use function update_option;
use function wp_get_attachment_image_url;
use function wp_kses;

/**
 * Block definitions, sanitization and rendering for email templates.
 *
 * A template is a plain array:
 * [
 *   'id'         => string (sanitize_key),
 *   'name'       => string,
 *   'blocks'     => list of block arrays,
 *   'created_at' => int (Unix timestamp),
 * ]
 *
 * A block is an array with a 'type' key. Supported types and fields:
 * - header:  text, subtitle, align (left|center|right), background (hex colour)
 * - text:    content (tight-allowlist HTML), align (left|center|right)
 * - button:  label, url, align (left|center|right), background (hex colour)
 * - image:   attachment_id (int) or url, alt, link (url), align, width (px)
 * - divider: style (solid|dashed), color (hex colour)
 * - footer:  text, align (left|center|right)
 *
 * All templates live in the single option 'traydigita_email_templates'
 * (autoload 'no') as ['templates' => [id => template], 'default_id' => string].
 *
 * This is the ONE shared builder and store for every email template
 * in the plugin: the Newsletter (Email module) and the Contact Form
 * notification mail (via the FormBuilder module) both read and write
 * here, so a template made in either place works in the other. The
 * old FormBuilder store ('traydigita_form_builder_email_templates')
 * is migrated into this store once, on first read, by
 * migrateLegacyTemplates(); the old option is kept afterwards as a
 * read-only backup and is never written again.
 *
 * The rendered HTML always starts with the marker comment
 * <!-- traydigita-email-template --> so the wp_mail wrapper can detect
 * mail that is already templated and leave it untouched.
 */
final class TemplateBuilder
{
    /**
     * Option name holding every template plus the default template id.
     */
    public const string OPTION_TEMPLATES = 'traydigita_email_templates';

    /**
     * Legacy FormBuilder templates option (pre-unification store).
     *
     * Read once by migrateLegacyTemplates() and never written again
     * afterwards. The option is deliberately NOT deleted: it stays
     * as the rollback source for the migration.
     */
    public const string LEGACY_OPTION_TEMPLATES = 'traydigita_form_builder_email_templates';

    /**
     * Option flag marking the legacy migration as done ('1').
     *
     * Set by migrateLegacyTemplates() even when there was nothing
     * to migrate, so the legacy option is read at most once.
     */
    public const string MIGRATION_FLAG_OPTION = 'traydigita_email_templates_migrated';

    /**
     * Whether the legacy migration has already run in this request.
     *
     * @var bool
     */
    private static bool $migrationAttempted = false;

    /**
     * Marker comment included in every rendered template.
     *
     * The wp_mail filter looks for this marker (or an existing <html>
     * document) to decide that a message is already templated.
     */
    public const string MARKER = '<!-- traydigita-email-template -->';

    /**
     * Maximum number of blocks a single template may hold.
     */
    public const int MAX_BLOCKS = 50;

    /**
     * Block types supported by the builder.
     *
     * @var list<string>
     */
    public const array BLOCK_TYPES = ['header', 'text', 'button', 'image', 'divider', 'footer'];

    /**
     * Tight HTML allowlist for text blocks and wrapped mail bodies.
     *
     * No script, style, iframe, event-handler attributes or javascript:
     * URLs can survive this list. Links keep only href and title.
     *
     * @var array<string, array<string, bool>>
     */
    public const array ALLOWED_HTML = [
        'p' => [],
        'br' => [],
        'b' => [],
        'strong' => [],
        'em' => [],
        'a' => [
            'href' => true,
            'title' => true,
        ],
    ];

    /**
     * Default brand colour used for headers and buttons.
     */
    public const string DEFAULT_COLOR = '#0b6b3a';

    /**
     * Read the raw templates option.
     *
     * Runs the one-time legacy migration first, so any caller
     * that reads templates also triggers the import of the old
     * FormBuilder store (see migrateLegacyTemplates()).
     *
     * @return array{templates: array<string, array<string, mixed>>, default_id: string}
     */
    public static function getStored(): array
    {
        self::migrateLegacyTemplates();
        $stored = get_option(self::OPTION_TEMPLATES, []);
        if (!is_array($stored)) {
            return ['templates' => [], 'default_id' => ''];
        }
        $templates = $stored['templates'] ?? [];
        $defaultId = $stored['default_id'] ?? '';
        if (!is_array($templates)) {
            $templates = [];
        }
        return [
            'templates' => $templates,
            'default_id' => is_string($defaultId) ? sanitize_key($defaultId) : '',
        ];
    }

    /**
     * Get all templates, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getTemplates(): array
    {
        return self::getStored()['templates'];
    }

    /**
     * Get one template by id, or null when it does not exist.
     *
     * @param string $id Template id.
     * @return array<string, mixed>|null
     */
    public static function getTemplate(string $id): ?array
    {
        $templates = self::getTemplates();
        $template = $templates[sanitize_key($id)] ?? null;
        return is_array($template) ? $template : null;
    }

    /**
     * Get the default template, or null when none is marked default.
     *
     * @return array<string, mixed>|null
     */
    public static function getDefaultTemplate(): ?array
    {
        $stored = self::getStored();
        if ($stored['default_id'] === '') {
            return null;
        }
        return self::getTemplate($stored['default_id']);
    }

    /**
     * Get the default template id (empty string when none).
     *
     * @return string
     */
    public static function getDefaultId(): string
    {
        return self::getStored()['default_id'];
    }

    /**
     * Persist the whole templates option.
     *
     * @param array<string, array<string, mixed>> $templates Templates keyed by id.
     * @param string $defaultId Id of the default template.
     * @return void
     */
    public static function saveStored(array $templates, string $defaultId): void
    {
        update_option(
            self::OPTION_TEMPLATES,
            ['templates' => $templates, 'default_id' => sanitize_key($defaultId)],
            false
        );
    }

    /**
     * Sanitize one raw block array.
     *
     * Returns null for unknown types or blocks that carry no usable data.
     *
     * @param mixed $block Raw block data (usually decoded JSON).
     * @return array<string, mixed>|null
     */
    public static function sanitizeBlock(mixed $block): ?array
    {
        if (!is_array($block)) {
            return null;
        }
        $type = sanitize_key((string) ($block['type'] ?? ''));
        if (!in_array($type, self::BLOCK_TYPES, true)) {
            return null;
        }
        $align = sanitize_key((string) ($block['align'] ?? 'left'));
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }
        $color = sanitize_hex_color((string) ($block['background'] ?? $block['color'] ?? ''));
        // Keep an empty string here; each renderer falls back to its own
        // default colour so a divider never inherits the brand green.
        $color = is_string($color) ? $color : '';

        switch ($type) {
            case 'header':
                $text = sanitize_text_field((string) ($block['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                return [
                    'type' => 'header',
                    'text' => $text,
                    'subtitle' => sanitize_text_field((string) ($block['subtitle'] ?? '')),
                    'align' => $align,
                    'background' => $color,
                ];
            case 'text':
                $content = wp_kses((string) ($block['content'] ?? ''), self::ALLOWED_HTML);
                if ($content === '') {
                    return null;
                }
                return [
                    'type' => 'text',
                    'content' => $content,
                    'align' => $align,
                ];
            case 'button':
                $label = sanitize_text_field((string) ($block['label'] ?? ''));
                $url = esc_url_raw((string) ($block['url'] ?? ''));
                if ($label === '' || $url === '') {
                    return null;
                }
                return [
                    'type' => 'button',
                    'label' => $label,
                    'url' => $url,
                    'align' => $align,
                    'background' => $color,
                ];
            case 'image':
                $attachmentId = absint($block['attachment_id'] ?? 0);
                $url = esc_url_raw((string) ($block['url'] ?? ''));
                if ($attachmentId <= 0 && $url === '') {
                    return null;
                }
                $width = absint($block['width'] ?? 0);
                if ($width <= 0 || $width > 1200) {
                    $width = 600;
                }
                return [
                    'type' => 'image',
                    'attachment_id' => $attachmentId,
                    'url' => $url,
                    'alt' => sanitize_text_field((string) ($block['alt'] ?? '')),
                    'link' => esc_url_raw((string) ($block['link'] ?? '')),
                    'align' => $align,
                    'width' => $width,
                ];
            case 'divider':
                $style = sanitize_key((string) ($block['style'] ?? 'solid'));
                if (!in_array($style, ['solid', 'dashed'], true)) {
                    $style = 'solid';
                }
                return [
                    'type' => 'divider',
                    'style' => $style,
                    'color' => $color,
                ];
            case 'footer':
                $text = sanitize_text_field((string) ($block['text'] ?? ''));
                if ($text === '') {
                    return null;
                }
                return [
                    'type' => 'footer',
                    'text' => $text,
                    'align' => $align,
                ];
        }
    }

    /**
     * Sanitize raw template input (decoded JSON or a posted array).
     *
     * Returns null when the input has no usable name or no blocks.
     *
     * @param mixed $data Raw template data.
     * @return array<string, mixed>|null
     */
    public static function sanitizeTemplate(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        $name = sanitize_text_field((string) ($data['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $rawBlocks = $data['blocks'] ?? [];
        if (!is_array($rawBlocks)) {
            return null;
        }
        $blocks = [];
        foreach (array_values($rawBlocks) as $rawBlock) {
            $block = self::sanitizeBlock($rawBlock);
            if ($block !== null) {
                $blocks[] = $block;
            }
            if (count($blocks) >= self::MAX_BLOCKS) {
                break;
            }
        }
        if ($blocks === []) {
            return null;
        }
        $id = sanitize_key((string) ($data['id'] ?? ''));
        if ($id === '') {
            $id = 'tpl_' . sanitize_key(uniqid());
        }
        $createdAt = absint($data['created_at'] ?? 0);
        return [
            'id' => $id,
            'name' => $name,
            'blocks' => $blocks,
            'created_at' => $createdAt > 0 ? $createdAt : time(),
        ];
    }

    /**
     * Create or update a template and persist it.
     *
     * @param array<string, mixed> $template Sanitized template.
     * @return array<string, mixed> The stored template.
     */
    public static function saveTemplate(array $template): array
    {
        $stored = self::getStored();
        $templates = $stored['templates'];
        $existing = $templates[$template['id']] ?? null;
        if (is_array($existing) && isset($existing['created_at'])) {
            $template['created_at'] = (int) $existing['created_at'];
        }
        $templates[$template['id']] = $template;
        self::saveStored($templates, $stored['default_id']);
        return $template;
    }

    /**
     * Delete a template by id. Clears the default pointer when needed.
     *
     * @param string $id Template id.
     * @return bool True when a template was deleted.
     */
    public static function deleteTemplate(string $id): bool
    {
        $stored = self::getStored();
        $id = sanitize_key($id);
        if (!isset($stored['templates'][$id])) {
            return false;
        }
        unset($stored['templates'][$id]);
        if ($stored['default_id'] === $id) {
            $stored['default_id'] = '';
        }
        self::saveStored($stored['templates'], $stored['default_id']);
        return true;
    }

    /**
     * Mark a template as the default template.
     *
     * @param string $id Template id.
     * @return bool True when the template exists and was marked default.
     */
    public static function setDefault(string $id): bool
    {
        $stored = self::getStored();
        $id = sanitize_key($id);
        if (!isset($stored['templates'][$id])) {
            return false;
        }
        self::saveStored($stored['templates'], $id);
        return true;
    }

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
            'image' => __('Image', 'traydigita-core'),
            'divider' => __('Divider', 'traydigita-core'),
            'footer' => __('Footer', 'traydigita-core'),
        ];
    }

    /**
     * Default blocks for a brand-new template: header, one text
     * block, footer — all empty, for the editor to fill in.
     *
     * The blocks are intentionally unsanitized empties: they are
     * editor placeholder rows, dropped by sanitizeTemplate() on
     * the first real save, so a fresh template must gain at
     * least one filled block before it can be saved.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultBlocks(): array
    {
        return [
            ['type' => 'header', 'text' => '', 'subtitle' => '', 'align' => 'left', 'background' => ''],
            ['type' => 'text', 'content' => '', 'align' => 'left'],
            ['type' => 'footer', 'text' => '', 'align' => 'left'],
        ];
    }

    /**
     * Migrate the legacy FormBuilder templates into the shared store.
     *
     * Runs once: guarded by the persisted flag option (set even
     * when there was nothing to migrate) and by an in-request
     * guard, so repeated calls are cheap no-ops. Called lazily
     * from getStored(), so any template read triggers it.
     *
     * Legacy entries live in 'traydigita_form_builder_email_templates'
     * as [id => ['id', 'title', 'template' => ['blocks' => [...]]]]
     * with nested-settings blocks (['type', 'settings' => [...]]).
     * Each entry is converted to the shared flat-block shape and
     * re-sanitized through sanitizeTemplate(); entries that end
     * up with no usable block are skipped (they rendered an empty
     * mail under the old builder too). A legacy id that already
     * exists in the shared store is imported under a suffixed id
     * ('<id>-migrated', then '<id>-migrated-2', ...) and every
     * Contact Form pointing at the old id is remapped to the new
     * one, so no form loses its template.
     *
     * The legacy option is NEVER deleted and never written again:
     * it stays as the rollback source for the migration.
     *
     * @return array{already_migrated: bool, imported: int, skipped: int, remapped: array<string, string>}
     */
    public static function migrateLegacyTemplates(): array
    {
        if (self::$migrationAttempted) {
            return ['already_migrated' => true, 'imported' => 0, 'skipped' => 0, 'remapped' => []];
        }
        self::$migrationAttempted = true;
        if (get_option(self::MIGRATION_FLAG_OPTION, '') === '1') {
            return ['already_migrated' => true, 'imported' => 0, 'skipped' => 0, 'remapped' => []];
        }
        $legacy = get_option(self::LEGACY_OPTION_TEMPLATES, []);
        if (!is_array($legacy) || $legacy === []) {
            update_option(self::MIGRATION_FLAG_OPTION, '1', false);
            return ['already_migrated' => false, 'imported' => 0, 'skipped' => 0, 'remapped' => []];
        }
        // Read the shared store directly (not via getStored(),
        // which calls back into this method).
        $stored = get_option(self::OPTION_TEMPLATES, []);
        $templates = is_array($stored) ? ($stored['templates'] ?? []) : [];
        if (!is_array($templates)) {
            $templates = [];
        }
        $defaultId = is_array($stored) ? ($stored['default_id'] ?? '') : '';
        $defaultId = is_string($defaultId) ? sanitize_key($defaultId) : '';
        $imported = 0;
        $skipped = 0;
        $remapped = [];
        foreach ($legacy as $legacyId => $entry) {
            if (!is_string($legacyId) || !is_array($entry)) {
                $skipped++;
                continue;
            }
            $title = $entry['title'] ?? '';
            $rawBlocks = $entry['template']['blocks'] ?? null;
            if (!is_string($title) || $title === '' || !is_array($rawBlocks)) {
                $skipped++;
                continue;
            }
            $blocks = [];
            foreach ($rawBlocks as $rawBlock) {
                if (!is_array($rawBlock)) {
                    continue;
                }
                $block = self::convertLegacyBlock($rawBlock);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
            $candidate = self::sanitizeTemplate([
                'id' => sanitize_key($legacyId),
                'name' => $title,
                'blocks' => $blocks,
            ]);
            if ($candidate === null) {
                $skipped++;
                continue;
            }
            $id = (string) $candidate['id'];
            if (isset($templates[$id])) {
                $suffixed = $id . '-migrated';
                $suffix = 2;
                while (isset($templates[$suffixed])) {
                    $suffixed = $id . '-migrated-' . $suffix;
                    $suffix++;
                }
                $remapped[$id] = $suffixed;
                $candidate['id'] = $suffixed;
                $id = $suffixed;
            }
            $templates[$id] = $candidate;
            $imported++;
        }
        self::saveStored($templates, $defaultId);
        if ($remapped !== []) {
            // ContactFormModule::OPTION_FORMS ('traydigita_contact_forms'):
            // remap forms that pointed at a renamed legacy template.
            // This is migration-only repair, not a runtime dependency.
            $forms = get_option('traydigita_contact_forms', []);
            if (is_array($forms)) {
                $changed = false;
                foreach ($forms as $formId => $form) {
                    if (!is_array($form)) {
                        continue;
                    }
                    $templateId = $form['email_template_id'] ?? '';
                    if (is_string($templateId) && isset($remapped[$templateId])) {
                        $forms[$formId]['email_template_id'] = $remapped[$templateId];
                        $changed = true;
                    }
                }
                if ($changed) {
                    update_option('traydigita_contact_forms', $forms, false);
                }
            }
        }
        update_option(self::MIGRATION_FLAG_OPTION, '1', false);
        return [
            'already_migrated' => false,
            'imported' => $imported,
            'skipped' => $skipped,
            'remapped' => $remapped,
        ];
    }

    /**
     * Convert one legacy nested-settings block to the flat shape.
     *
     * Legacy blocks look like ['type' => 'text', 'settings' =>
     * ['text' => '...']]; the shared shape carries the fields
     * flat on the block. Line breaks in legacy text become <br>
     * (the old renderer applied nl2br at render time). Returns
     * null for unknown types or blocks sanitizeBlock() drops.
     *
     * @param array<string, mixed> $block Legacy block.
     * @return array<string, mixed>|null
     */
    private static function convertLegacyBlock(array $block): ?array
    {
        $type = sanitize_key((string) ($block['type'] ?? ''));
        $settings = $block['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        $text = (string) ($settings['text'] ?? '');
        $flat = match ($type) {
            'header' => [
                'type' => 'header',
                'text' => $text,
                'subtitle' => '',
                'align' => 'left',
                'background' => '',
            ],
            'text' => [
                'type' => 'text',
                'content' => str_replace(["\r\n", "\n"], '<br>', $text),
                'align' => 'left',
            ],
            'button' => [
                'type' => 'button',
                'label' => (string) ($settings['label'] ?? ''),
                'url' => (string) ($settings['url'] ?? ''),
                'align' => 'left',
                'background' => '',
            ],
            'divider' => ['type' => 'divider', 'style' => 'solid', 'color' => ''],
            'footer' => ['type' => 'footer', 'text' => $text, 'align' => 'left'],
            default => null,
        };
        if ($flat === null) {
            return null;
        }
        return self::sanitizeBlock($flat);
    }

    /**
     * Resolve the display URL of an image block.
     *
     * Prefers the attachment URL when an attachment id is set.
     *
     * @param array<string, mixed> $block Sanitized image block.
     * @return string Image URL (empty when none resolves).
     */
    public static function imageBlockUrl(array $block): string
    {
        $attachmentId = absint($block['attachment_id'] ?? 0);
        if ($attachmentId > 0 && function_exists('wp_get_attachment_image_url')) {
            $url = wp_get_attachment_image_url($attachmentId, 'large');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return (string) ($block['url'] ?? '');
    }

    /**
     * Render one sanitized block to email-safe HTML.
     *
     * All output uses inline styles and tables so the result renders
     * in common mail clients. Every value is escaped here, so the
     * renderer is safe to call on stored (already sanitized) blocks.
     *
     * @param array<string, mixed> $block Sanitized block.
     * @return string
     */
    public static function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $align = (string) ($block['align'] ?? 'left');
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'left';
        }
        switch ($type) {
            case 'header':
                $subtitle = (string) ($block['subtitle'] ?? '');
                $background = (string) ($block['background'] ?? '');
                $background = $background !== '' ? $background : self::DEFAULT_COLOR;
                $subtitleHtml = $subtitle === '' ? '' : '<p style="margin:6px 0 0;font-size:14px;color:#ffffff;opacity:0.85;">'
                    . esc_html($subtitle) . '</p>';
                return '<tr><td style="background-color:' . esc_attr($background)
                    . ';padding:28px 24px;text-align:' . esc_attr($align) . ';">'
                    . '<p style="margin:0;font-size:22px;line-height:1.3;font-weight:700;color:#ffffff;">'
                    . esc_html((string) ($block['text'] ?? '')) . '</p>'
                    . $subtitleHtml . '</td></tr>';
            case 'text':
                return '<tr><td style="padding:20px 24px;text-align:' . esc_attr($align)
                    . ';font-size:15px;line-height:1.6;color:#222222;">'
                    . wp_kses((string) ($block['content'] ?? ''), self::ALLOWED_HTML)
                    . '</td></tr>';
            case 'button':
                $background = (string) ($block['background'] ?? '');
                $background = $background !== '' ? $background : self::DEFAULT_COLOR;
                return '<tr><td style="padding:12px 24px;text-align:' . esc_attr($align) . ';">'
                    . '<a href="' . esc_url((string) ($block['url'] ?? '')) . '" style="display:inline-block;'
                    . 'background-color:' . esc_attr($background)
                    . ';color:#ffffff;text-decoration:none;font-size:15px;font-weight:700;'
                    . 'padding:12px 28px;border-radius:6px;">'
                    . esc_html((string) ($block['label'] ?? '')) . '</a></td></tr>';
            case 'image':
                $url = self::imageBlockUrl($block);
                if ($url === '') {
                    return '';
                }
                $img = '<img src="' . esc_url($url) . '" alt="' . esc_attr((string) ($block['alt'] ?? ''))
                    . '" width="' . absint($block['width'] ?? 600)
                    . '" style="max-width:100%;height:auto;display:block;border:0;" />';
                $link = (string) ($block['link'] ?? '');
                if ($link !== '') {
                    $img = '<a href="' . esc_url($link) . '">' . $img . '</a>';
                }
                return '<tr><td style="padding:12px 24px;text-align:' . esc_attr($align) . ';">'
                    . $img . '</td></tr>';
            case 'divider':
                $style = (string) ($block['style'] ?? 'solid');
                if (!in_array($style, ['solid', 'dashed'], true)) {
                    $style = 'solid';
                }
                $color = (string) ($block['color'] ?? '');
                $color = $color !== '' ? $color : '#dddddd';
                return '<tr><td style="padding:12px 24px;"><hr style="border:0;border-top:1px '
                    . esc_attr($style) . ' ' . esc_attr($color)
                    . ';margin:0;" /></td></tr>';
            case 'footer':
                $siteName = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
                $siteUrl = function_exists('home_url') ? home_url('/') : '';
                $siteHtml = $siteName === '' ? '' : '<p style="margin:8px 0 0;font-size:12px;color:#888888;">'
                    . '<a href="' . esc_url($siteUrl) . '" style="color:#888888;">'
                    . esc_html($siteName) . '</a></p>';
                return '<tr><td style="padding:20px 24px;text-align:' . esc_attr($align)
                    . ';font-size:13px;line-height:1.5;color:#666666;border-top:1px solid #eeeeee;">'
                    . esc_html((string) ($block['text'] ?? '')) . $siteHtml . '</td></tr>';
            default:
                return '';
        }
    }

    /**
     * Render the inner block rows of a template.
     *
     * Header blocks render first, then the optional $contentHtml (the
     * wrapped mail body or newsletter intro), then every remaining
     * block in order. This keeps the template header on top and the
     * footer at the bottom while the wrapped content stays central.
     *
     * @param array<string, mixed> $template Sanitized template.
     * @param string $contentHtml Already-sanitized HTML inserted as the main content.
     * @return string Table rows.
     */
    public static function renderInner(array $template, string $contentHtml = ''): string
    {
        $blocks = $template['blocks'] ?? [];
        if (!is_array($blocks)) {
            return '';
        }
        $html = '';
        $rest = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if ((string) ($block['type'] ?? '') === 'header') {
                $html .= self::renderBlock($block);
            } else {
                $rest[] = $block;
            }
        }
        if ($contentHtml !== '') {
            $html .= '<tr><td style="padding:20px 24px;font-size:15px;line-height:1.6;color:#222222;">'
                . $contentHtml . '</td></tr>';
        }
        foreach ($rest as $block) {
            $html .= self::renderBlock($block);
        }
        return $html;
    }

    /**
     * Render a full template to a complete HTML email document.
     *
     * The document is rendered through templates/emails/default.php
     * when the theme file exists; otherwise a built-in fallback layout
     * with the same structure is used. The output always starts with
     * the marker comment so already-templated mail can be detected.
     *
     * @param array<string, mixed> $template Sanitized template.
     * @param string $contentHtml Already-sanitized HTML inserted as the main content.
     * @return string Complete HTML email document.
     */
    public static function renderTemplate(array $template, string $contentHtml = ''): string
    {
        $innerHtml = self::renderInner($template, $contentHtml);
        $templateName = (string) ($template['name'] ?? '');
        $partial = '';
        if (function_exists('get_template_directory')) {
            $partial = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/emails/default.php';
        }
        if ($partial !== '' && file_exists($partial)) {
            ob_start();
            require $partial;
            $rendered = ob_get_clean();
            if (is_string($rendered) && $rendered !== '') {
                return $rendered;
            }
        }
        return self::renderFallbackDocument($innerHtml, $templateName);
    }

    /**
     * Built-in fallback for the email document layout.
     *
     * Used only when templates/emails/default.php is unavailable, so
     * rendering never depends on a single file existing.
     *
     * @param string $innerHtml Rendered table rows.
     * @param string $templateName Template name (used as the document title).
     * @return string
     */
    public static function renderFallbackDocument(string $innerHtml, string $templateName = ''): string
    {
        return self::MARKER . "\n"
            . '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . esc_html($templateName) . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#f5f5f5;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="background-color:#f5f5f5;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="max-width:600px;width:100%;background-color:#ffffff;border-radius:8px;overflow:hidden;">'
            . $innerHtml
            . '</table></td></tr></table></body></html>';
    }

    /**
     * Render a template as an HTML email body with placeholders.
     *
     * Used by the Contact Form notification mail: `{{field_id}}`
     * placeholders in block text are replaced with the matching
     * submitted values from $data (arrays are joined with ", ").
     * Values are escaped, so a submitted value can never inject
     * markup into the mail. Empty blocks (kept only as editor
     * placeholder rows) are dropped before rendering, so they
     * never produce empty header bars or rows in the sent mail.
     *
     * @param array<string, mixed> $template Sanitized template.
     * @param array<string, mixed> $data Placeholder values keyed by field id.
     * @return string Rendered HTML email (empty when the template is invalid).
     */
    public static function renderEmail(array $template, array $data = []): string
    {
        $blocks = $template['blocks'] ?? [];
        if (!is_array($blocks) || $blocks === []) {
            return '';
        }
        $clean = [];
        foreach ($blocks as $block) {
            $sanitized = is_array($block) ? self::sanitizeBlock($block) : null;
            if ($sanitized !== null) {
                $clean[] = $sanitized;
            }
        }
        if ($clean === []) {
            return '';
        }
        $template['blocks'] = $clean;
        return self::replacePlaceholders(self::renderTemplate($template), $data);
    }

    /**
     * Replace {{field}} placeholders with escaped-safe values.
     *
     * Substitution runs on the already-rendered HTML: the
     * `{{key}}` tokens survive esc_html()/wp_kses() untouched,
     * and the inserted values are escaped here, so a value can
     * never break out of text content or an attribute. Unknown
     * placeholders are left in place.
     *
     * @param string $html Rendered template HTML.
     * @param array<string, mixed> $data Placeholder values.
     * @return string HTML with placeholders replaced.
     */
    private static function replacePlaceholders(string $html, array $data): string
    {
        if ($html === '' || $data === []) {
            return $html;
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
            $replacements['{{' . $key . '}}'] = esc_html((string) $value);
        }
        if ($replacements === []) {
            return $html;
        }
        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Sanitize an original mail body for insertion into a template.
     *
     * Plain-text bodies are escaped and line breaks converted. HTML
     * bodies pass through the same tight allowlist used by text
     * blocks, so no script, style, iframe or event handler survives.
     *
     * @param string $body Original mail body.
     * @param bool $isHtml True when the body is already HTML.
     * @return string Sanitized HTML.
     */
    public static function sanitizeBodyForTemplate(string $body, bool $isHtml): string
    {
        if ($isHtml) {
            return wp_kses($body, self::ALLOWED_HTML);
        }
        return nl2br(esc_html($body));
    }
}
