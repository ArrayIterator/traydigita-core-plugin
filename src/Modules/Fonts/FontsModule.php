<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Fonts;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function add_action;
use function add_filter;
use function array_key_exists;
use function array_slice;
use function array_values;
use function content_url;
use function current_user_can;
use function esc_html__;
use function explode;
use function file_exists;
use function file_put_contents;
use function filemtime;
use function get_option;
use function get_theme_mod;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_title;
use function set_theme_mod;
use function sort;
use function sprintf;
use function trim;
use function unlink;
use function update_option;
use function wp_add_inline_style;
use function wp_enqueue_style;
use function wp_mkdir_p;

/**
 * Font Manager module.
 *
 * Downloads Google Fonts to local files (or accepts uploaded
 * woff2/woff/ttf files), stores them under
 * `wp-content/traydigita-fonts/` - never in the uploads directory -
 * generates one `@font-face` stylesheet from the stored files, and
 * enqueues it on the frontend. Google Fonts are never hotlinked:
 * once a family is downloaded, the frontend only ever loads the
 * local files.
 *
 * Registry: the single option `traydigita_fonts` (autoload `no`)
 * holds `['fonts' => [...]]`; each family record is
 * `['family' => string, 'slug' => string, 'files' => [['weight'
 * => int, 'style' => 'normal'|'italic', 'file' => string,
 * 'format' => 'woff2'|'woff'|'ttf', 'source' =>
 * 'google'|'upload'], ...]]`. The generated stylesheet lives at
 * `wp-content/traydigita-fonts/traydigita-fonts.css` and is
 * regenerated after every change (download, upload, delete); it is
 * removed again when the last font is deleted.
 *
 * Applying a font: the module registers two Customizer settings
 * through the `traydigita:core:customizer/definitions` filter -
 * `traydigita_body_font` and `traydigita_heading_font` - each a
 * select over the installed families (plus "System default"). The
 * module settings page offers the same two selects, saved as
 * theme mods by saveSettings(). On the frontend the body font is
 * applied as a `body` font-family rule and the heading font by
 * overriding the theme's `--font-serif` variable, which every
 * heading style already reads.
 *
 * Settings page forms: the font-assignment form posts to the
 * shell's generic save endpoint (`data-traydigita-module-form`,
 * `data-module="fonts"`); download, upload, and delete use the
 * module-owned AJAX endpoints in FontsAjax (nonce action
 * `traydigita-admin`, capability `manage_options`).
 */
final class FontsModule implements ModuleInterface
{
    /**
     * Option name holding the font registry (autoload `no`).
     */
    public const string OPTION = 'traydigita_fonts';

    /**
     * Directory name under wp-content holding the font files.
     */
    public const string DIR_NAME = 'traydigita-fonts';

    /**
     * Generated stylesheet file name inside the font directory.
     */
    public const string CSS_FILE = 'traydigita-fonts.css';

    /**
     * Customizer setting id for the body font (theme mod).
     */
    public const string BODY_FONT_SETTING = 'traydigita_body_font';

    /**
     * Customizer setting id for the heading font (theme mod).
     */
    public const string HEADING_FONT_SETTING = 'traydigita_heading_font';

    /**
     * Allowed font styles.
     *
     * @var array<int, string>
     */
    public const array STYLES = ['normal', 'italic'];

    /**
     * Allowed font sources.
     *
     * @var array<int, string>
     */
    public const array SOURCES = ['google', 'upload'];

    /**
     * Whether `boot()` has already registered the hooks.
     */
    private static bool $booted = false;

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'fonts';
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
        return 'design';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Font Manager', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Host Google Fonts locally or upload your own font files, and pick the body and heading fonts in the Customizer. No font is ever hotlinked from Google on the frontend.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'admin-appearance';
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
            __('Host Google Fonts locally or upload your own font files.', 'traydigita-core'),
            __('Pick body and heading fonts in the Customizer.', 'traydigita-core'),
            __('No font is ever hotlinked from Google on the frontend.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here - never invent earlier versions.
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
    /**
     * Author shown on the module card in the admin module list.
     *
     * Modules may override this to name their own author; the
     * registry falls back to "TrayDigita" when a module does not
     * declare one, so every card always shows an author.
     *
     * @return string
     */
    public static function author(): string
    {
        return 'TrayDigita';
    }

    public static function settingsPageSlug(): string
    {
        return 'traydigita-fonts';
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
     * Configured means at least one font family is installed; with
     * no fonts the module enqueues nothing on the frontend.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        return self::getFonts() !== [];
    }

    /**
     * Whether this is a core module.
     *
     * @return bool False: the module is optional and toggleable.
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Register the module's hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Registers the Customizer definitions filter, the
     * frontend enqueue (priority 20, after the theme stylesheet,
     * so the heading-font variable override wins), and the
     * module-owned AJAX endpoints.
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
        add_filter('traydigita:core:customizer/definitions', [self::class, 'addCustomizerDefinitions']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueFonts'], 20);
        FontsAjax::register();
    }

    /**
     * Add the body/heading font settings to the Customizer.
     *
     * Both are selects over the installed families (plus "System
     * default"); a family deleted from the module disappears from
     * the choices, and sanitizeFontChoice() resets a stored value
     * that no longer exists to the system default.
     *
     * @param array<int, array<string, mixed>> $definitions Built-in definitions.
     * @return array<int, array<string, mixed>>
     */
    public static function addCustomizerDefinitions(array $definitions): array
    {
        $choices = ['' => __('System default', 'traydigita-core')] + self::familyChoices();
        $definitions[] = [
            'id' => self::BODY_FONT_SETTING,
            'type' => 'select',
            'section' => 'traydigita_brand',
            'default' => '',
            'label' => __('Body font', 'traydigita-core'),
            'description' => __('Font used for body text. Only fonts installed by the Font Manager are listed; the files are hosted locally, never loaded from Google.', 'traydigita-core'),
            'transport' => 'refresh',
            'choices' => $choices,
            'sanitize_callback' => [self::class, 'sanitizeFontChoice'],
        ];
        $definitions[] = [
            'id' => self::HEADING_FONT_SETTING,
            'type' => 'select',
            'section' => 'traydigita_brand',
            'default' => '',
            'label' => __('Heading font', 'traydigita-core'),
            'description' => __('Font used for headings, through the theme’s serif variable. Only fonts installed by the Font Manager are listed.', 'traydigita-core'),
            'transport' => 'refresh',
            'choices' => $choices,
            'sanitize_callback' => [self::class, 'sanitizeFontChoice'],
        ];
        return $definitions;
    }

    /**
     * Sanitize a Customizer font choice (or a module-form value).
     *
     * Returns the slug when it names an installed family, or an
     * empty string (system default) for anything else - including
     * a slug whose family was deleted after the value was stored.
     *
     * @param mixed $value Submitted value.
     * @return string
     */
    public static function sanitizeFontChoice(mixed $value): string
    {
        $slug = is_string($value) ? sanitize_key($value) : '';
        if ($slug === '') {
            return '';
        }
        return array_key_exists($slug, self::familyChoices()) ? $slug : '';
    }

    /**
     * Enqueue the generated font stylesheet plus the application CSS.
     *
     * Nothing is enqueued when no font stylesheet exists (no fonts
     * installed). The version is the stylesheet's modification time,
     * so a regenerated stylesheet is never served stale from cache.
     * No remote URL is ever enqueued here.
     *
     * @return void
     */
    public static function enqueueFonts(): void
    {
        $cssUrl = self::cssUrl();
        if ($cssUrl === null) {
            return;
        }
        $modified = filemtime(self::cssPath());
        wp_enqueue_style(
            'traydigita-fonts',
            $cssUrl,
            [],
            $modified !== false ? (string) $modified : null
        );
        $inline = self::fontApplicationCss();
        if ($inline !== '') {
            wp_add_inline_style('traydigita-fonts', $inline);
        }
    }

    /**
     * CSS that applies the chosen fonts, or an empty string.
     *
     * The body font becomes a `body` font-family rule with a
     * system-ui fallback stack; the heading font overrides the
     * theme's `--font-serif` variable (read by every heading
     * style) with a Georgia fallback stack. A chosen family that
     * is no longer installed is ignored.
     *
     * @return string
     */
    public static function fontApplicationCss(): string
    {
        $css = '';
        $body = self::selectedFontFamily(self::BODY_FONT_SETTING);
        if ($body !== null) {
            $css .= sprintf(
                "body{font-family:'%s',ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;}",
                FontCss::escapeFamily($body)
            );
        }
        $heading = self::selectedFontFamily(self::HEADING_FONT_SETTING);
        if ($heading !== null) {
            $css .= sprintf(
                ":root{--font-serif:'%s',Georgia,'Times New Roman',serif;}",
                FontCss::escapeFamily($heading)
            );
        }
        return $css;
    }

    /**
     * Family name selected for one Customizer font setting.
     *
     * @param string $settingId Theme mod id.
     * @return string|null Family name, or null when unset or uninstalled.
     */
    public static function selectedFontFamily(string $settingId): ?string
    {
        $value = get_theme_mod($settingId, '');
        if (!is_string($value) || $value === '') {
            return null;
        }
        $choices = self::familyChoices();
        return $choices[sanitize_key($value)] ?? null;
    }

    /**
     * Framework sections for the left navigation (R4-5).
     *
     * @return array<int, array{id: string, label: string, icon?: string}>
     */
    public static function settingsSections(): array
    {
        return [
            ['id' => 'installed', 'label' => __('Installed fonts', 'traydigita-core'), 'icon' => 'editor-textcolor'],
            ['id' => 'download', 'label' => __('Download from Google Fonts', 'traydigita-core'), 'icon' => 'download'],
            ['id' => 'upload', 'label' => __('Upload a font file', 'traydigita-core'), 'icon' => 'upload'],
            ['id' => 'apply', 'label' => __('Apply fonts', 'traydigita-core'), 'icon' => 'admin-appearance'],
        ];
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            echo '<div class="traydigita-admin-notice traydigita-admin-notice-error"><p>'
                . esc_html__('You are not allowed to do that.', 'traydigita-core')
                . '</p></div>';
            return;
        }
        // phpcs:disable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- Variables are consumed by the required template partial below, in the same scope.
        $fontsList = self::publicFonts();
        $fontsChoices = self::familyChoices();
        $fontsBodyFont = self::sanitizeFontChoice(get_theme_mod(self::BODY_FONT_SETTING, ''));
        $fontsHeadingFont = self::sanitizeFontChoice(get_theme_mod(self::HEADING_FONT_SETTING, ''));
        $fontsDirLabel = 'wp-content/' . self::DIR_NAME;
        $fontsCssUrl = self::cssUrl();
        $fontsNonceAction = AdminAjax::NONCE_ACTION;
        $fontsDownloadAction = FontsAjax::DOWNLOAD_ACTION;
        $fontsUploadAction = FontsAjax::UPLOAD_ACTION;
        $fontsDeleteAction = FontsAjax::DELETE_ACTION;
        $fontsSaveAction = 'traydigita_admin_save';
        // phpcs:enable SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/admin/modules/fonts.php';
        if (!file_exists($file)) {
            return;
        }
        require $file;
    }

    /**
     * Save the font-assignment settings from the module form.
     *
     * The form carries `body_font` and `heading_font` (family
     * slugs, or empty for the system default). Both are stored as
     * theme mods - the same settings the Customizer exposes - so
     * there is a single source of truth for which font applies.
     * The font registry itself is managed only through the
     * module-owned AJAX endpoints, never through this form.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change font settings.', 'traydigita-core'),
            ];
        }
        set_theme_mod(self::BODY_FONT_SETTING, self::sanitizeFontChoice($data['body_font'] ?? ''));
        set_theme_mod(self::HEADING_FONT_SETTING, self::sanitizeFontChoice($data['heading_font'] ?? ''));
        return [
            'success' => true,
            'message' => __('Font settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Absolute path of the font directory.
     *
     * @return string
     */
    public static function fontsDir(): string
    {
        return WP_CONTENT_DIR . '/' . self::DIR_NAME;
    }

    /**
     * Public base URL of the font directory.
     *
     * @return string
     */
    public static function fontsUrl(): string
    {
        return content_url('/' . self::DIR_NAME);
    }

    /**
     * Absolute path of the generated stylesheet.
     *
     * @return string
     */
    public static function cssPath(): string
    {
        return self::fontsDir() . '/' . self::CSS_FILE;
    }

    /**
     * Public URL of the generated stylesheet, or null when it does not exist.
     *
     * @return string|null
     */
    public static function cssUrl(): ?string
    {
        if (!file_exists(self::cssPath())) {
            return null;
        }
        return self::fontsUrl() . '/' . self::CSS_FILE;
    }

    /**
     * Create the font directory (with an index.php silence file).
     *
     * @return bool True when the directory exists and is usable.
     */
    public static function ensureDir(): bool
    {
        $dir = self::fontsDir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        $silence = $dir . '/index.php';
        if (!file_exists($silence)) {
            file_put_contents($silence, "<?php\n// Silence is golden.\n");
        }
        return true;
    }

    /**
     * Read the font registry, normalized and in stored order.
     *
     * Malformed records (unknown format, out-of-range weight, file
     * name that does not belong to the family) are dropped, so a
     * hand-edited option can never produce broken CSS or file
     * operations outside the font directory.
     *
     * @return array<int, array{family: string, slug: string, files: array<int, array{weight: int, style: string, file: string, format: string, source: string}>}>
     */
    public static function getFonts(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            return [];
        }
        $fonts = $stored['fonts'] ?? [];
        if (!is_array($fonts)) {
            return [];
        }
        $clean = [];
        foreach ($fonts as $font) {
            $record = self::normalizeFont($font);
            if ($record !== null) {
                $clean[] = $record;
            }
        }
        return $clean;
    }

    /**
     * Installed families as slug => family name, in registry order.
     *
     * @return array<string, string>
     */
    public static function familyChoices(): array
    {
        $choices = [];
        foreach (self::getFonts() as $font) {
            $choices[$font['slug']] = $font['family'];
        }
        return $choices;
    }

    /**
     * Safe font list for admin responses and the settings template.
     *
     * Only family names, slugs, sources, and per-file weight/style/
     * format are exposed - never absolute paths.
     *
     * @return array<int, array{family: string, slug: string, source: string, files: array<int, array{weight: int, style: string, format: string}>}>
     */
    public static function publicFonts(): array
    {
        $list = [];
        foreach (self::getFonts() as $font) {
            $files = [];
            foreach ($font['files'] as $file) {
                $files[] = [
                    'weight' => $file['weight'],
                    'style' => $file['style'],
                    'format' => $file['format'],
                ];
            }
            $list[] = [
                'family' => $font['family'],
                'slug' => $font['slug'],
                'source' => self::familySource($font['files']),
                'files' => $files,
            ];
        }
        return $list;
    }

    /**
     * Family-level source label for a file list.
     *
     * `google` when every file came from Google Fonts, `upload`
     * when every file was uploaded, `mixed` otherwise.
     *
     * @param array<int, array<string, mixed>> $files Normalized file entries.
     * @return string
     */
    public static function familySource(array $files): string
    {
        $sources = [];
        foreach ($files as $file) {
            $source = is_string($file['source'] ?? null) ? (string) $file['source'] : 'upload';
            if (!in_array($source, $sources, true)) {
                $sources[] = $source;
            }
        }
        if ($sources === ['google']) {
            return 'google';
        }
        if ($sources === ['upload']) {
            return 'upload';
        }
        return 'mixed';
    }

    /**
     * Slug for a family name.
     *
     * @param string $family Family name.
     * @return string
     */
    public static function slugForFamily(string $family): string
    {
        return sanitize_title($family);
    }

    /**
     * Whether a family name is acceptable.
     *
     * Letters, digits, spaces, apostrophes, and hyphens only, up
     * to 60 characters, starting with a letter or digit. The name
     * is sent to the Google Fonts API, so anything else is refused
     * rather than encoded into a surprising request.
     *
     * @param string $family Family name.
     * @return bool
     */
    public static function isValidFamilyName(string $family): bool
    {
        return preg_match("/^[A-Za-z0-9][A-Za-z0-9'\\- ]{0,59}$/", $family) === 1;
    }

    /**
     * Parse a comma-separated weight list into sorted unique weights.
     *
     * Only weights 100-900 in steps of 100 are kept, at most
     * FontDownloader::MAX_WEIGHTS of them.
     *
     * @param string $raw Raw input, e.g. `400, 700,400`.
     * @return array<int, int>
     */
    public static function parseWeights(string $raw): array
    {
        $weights = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim(sanitize_text_field($part));
            if ($part === '' || !is_numeric($part)) {
                continue;
            }
            $weight = (int) $part;
            if ($weight < 100 || $weight > 900 || $weight % 100 !== 0) {
                continue;
            }
            if (!in_array($weight, $weights, true)) {
                $weights[] = $weight;
            }
        }
        sort($weights);
        return array_slice($weights, 0, FontDownloader::MAX_WEIGHTS);
    }

    /**
     * Stored file name for one font variant.
     *
     * The name is fully derived from validated parts, so a caller
     * can never smuggle a path into it.
     *
     * @param string $slug Family slug.
     * @param int $weight Font weight.
     * @param string $style Font style.
     * @param string $format Font format.
     * @return string
     */
    public static function fileNameFor(string $slug, int $weight, string $style, string $format): string
    {
        return sprintf('%s-%d-%s.%s', $slug, $weight, $style, $format);
    }

    /**
     * Store one font file's bytes in the font directory.
     *
     * @param string $fileName File name from fileNameFor().
     * @param string $bytes File contents.
     * @return bool True when the file was written.
     */
    public static function storeFile(string $fileName, string $bytes): bool
    {
        if (!self::ensureDir()) {
            return false;
        }
        return file_put_contents(self::fontsDir() . '/' . $fileName, $bytes, LOCK_EX) !== false;
    }

    /**
     * Delete one stored font file, if it exists.
     *
     * @param string $fileName File name from fileNameFor().
     * @return void
     */
    public static function deleteStoredFile(string $fileName): void
    {
        $path = self::fontsDir() . '/' . $fileName;
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /**
     * Replace (or add) one family in the registry and regenerate the CSS.
     *
     * The record is normalized first; an invalid record is refused
     * and nothing is stored. The registry is kept in stored order,
     * with a replaced family keeping its original position.
     *
     * @param array<string, mixed> $record Family record.
     * @return bool True when stored and the CSS regenerated.
     */
    public static function replaceFamily(array $record): bool
    {
        $normalized = self::normalizeFont($record);
        if ($normalized === null) {
            return false;
        }
        $fonts = self::getFonts();
        $replaced = false;
        foreach ($fonts as $index => $font) {
            if ($font['slug'] === $normalized['slug']) {
                $fonts[$index] = $normalized;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $fonts[] = $normalized;
        }
        update_option(self::OPTION, ['fonts' => $fonts], false);
        return self::regenerateCss();
    }

    /**
     * Delete one family: its files, its registry record, and the CSS.
     *
     * Any theme mod selecting the deleted family is reset to the
     * system default, so the frontend never references a font that
     * no longer exists.
     *
     * @param string $slug Family slug.
     * @return bool True when the family existed and was removed.
     */
    public static function removeFamily(string $slug): bool
    {
        $slug = sanitize_key($slug);
        $fonts = self::getFonts();
        $found = false;
        $remaining = [];
        foreach ($fonts as $font) {
            if ($font['slug'] === $slug) {
                $found = true;
                foreach ($font['files'] as $file) {
                    self::deleteStoredFile($file['file']);
                }
                continue;
            }
            $remaining[] = $font;
        }
        if (!$found) {
            return false;
        }
        update_option(self::OPTION, ['fonts' => $remaining], false);
        self::clearSelection($slug);
        self::regenerateCss();
        return true;
    }

    /**
     * Delete one variant (weight/style) of a family.
     *
     * The file is removed from disk and from the registry; when it
     * was the family's last file, the whole family is removed (and
     * any theme mod selecting it is reset).
     *
     * @param string $slug Family slug.
     * @param int $weight Font weight.
     * @param string $style Font style.
     * @return bool True when a variant was removed.
     */
    public static function removeVariant(string $slug, int $weight, string $style): bool
    {
        $slug = sanitize_key($slug);
        $fonts = self::getFonts();
        foreach ($fonts as $index => $font) {
            if ($font['slug'] !== $slug) {
                continue;
            }
            $kept = [];
            $removed = false;
            foreach ($font['files'] as $file) {
                if ($file['weight'] === $weight && $file['style'] === $style) {
                    $removed = true;
                    self::deleteStoredFile($file['file']);
                    continue;
                }
                $kept[] = $file;
            }
            if (!$removed) {
                return false;
            }
            if ($kept === []) {
                return self::removeFamily($slug);
            }
            $fonts[$index]['files'] = $kept;
            update_option(self::OPTION, ['fonts' => $fonts], false);
            return self::regenerateCss();
        }
        return false;
    }

    /**
     * Regenerate the `@font-face` stylesheet from the registry.
     *
     * Writes the stylesheet when there is at least one usable
     * file; when the registry is empty (or has no usable files)
     * the stylesheet is removed instead, so nothing stale is
     * served.
     *
     * @return bool True when the stylesheet state was updated.
     */
    public static function regenerateCss(): bool
    {
        if (!self::ensureDir()) {
            return false;
        }
        $css = FontCss::build(self::getFonts(), self::fontsUrl());
        if ($css === '') {
            if (file_exists(self::cssPath())) {
                unlink(self::cssPath());
            }
            return true;
        }
        return file_put_contents(self::cssPath(), $css, LOCK_EX) !== false;
    }

    /**
     * Reset theme mods that select a deleted family to the system default.
     *
     * @param string $slug Deleted family slug.
     * @return void
     */
    private static function clearSelection(string $slug): void
    {
        foreach ([self::BODY_FONT_SETTING, self::HEADING_FONT_SETTING] as $settingId) {
            $value = get_theme_mod($settingId, '');
            if (is_string($value) && sanitize_key($value) === $slug) {
                set_theme_mod($settingId, '');
            }
        }
    }

    /**
     * Normalize one font registry record, or null when unusable.
     *
     * A record needs a valid family name, a slug matching that
     * name, and at least one usable file. File names must match
     * the `slug-weight-style.format` pattern, so a hand-edited
     * option cannot point the CSS at an arbitrary file.
     *
     * @param mixed $font Raw record.
     * @return array{family: string, slug: string, files: array<int, array{weight: int, style: string, file: string, format: string, source: string}>}|null
     */
    private static function normalizeFont(mixed $font): ?array
    {
        if (!is_array($font)) {
            return null;
        }
        $family = $font['family'] ?? '';
        if (!is_string($family) || !self::isValidFamilyName($family)) {
            return null;
        }
        $slug = $font['slug'] ?? '';
        if (!is_string($slug) || $slug === '' || $slug !== self::slugForFamily($family)) {
            return null;
        }
        $files = $font['files'] ?? [];
        if (!is_array($files) || $files === []) {
            return null;
        }
        $cleanFiles = [];
        foreach ($files as $file) {
            $normalized = self::normalizeFile($file, $slug);
            if ($normalized === null) {
                continue;
            }
            $key = $normalized['weight'] . '|' . $normalized['style'];
            $cleanFiles[$key] = $normalized;
        }
        if ($cleanFiles === []) {
            return null;
        }
        return [
            'family' => $family,
            'slug' => $slug,
            'files' => array_values($cleanFiles),
        ];
    }

    /**
     * Normalize one file entry, or null when unusable.
     *
     * @param mixed $file Raw file entry.
     * @param string $slug Family slug the file must belong to.
     * @return array{weight: int, style: string, file: string, format: string, source: string}|null
     */
    private static function normalizeFile(mixed $file, string $slug): ?array
    {
        if (!is_array($file)) {
            return null;
        }
        $weight = $file['weight'] ?? 0;
        if (!is_int($weight) || $weight < 100 || $weight > 900 || $weight % 100 !== 0) {
            return null;
        }
        $style = $file['style'] ?? '';
        if (!is_string($style) || !in_array($style, self::STYLES, true)) {
            return null;
        }
        $format = $file['format'] ?? '';
        if (!is_string($format) || !array_key_exists($format, FontCss::FORMAT_HINTS)) {
            return null;
        }
        $fileName = $file['file'] ?? '';
        if (!is_string($fileName) || $fileName !== self::fileNameFor($slug, $weight, $style, $format)) {
            return null;
        }
        $source = $file['source'] ?? 'upload';
        if (!is_string($source) || !in_array($source, self::SOURCES, true)) {
            $source = 'upload';
        }
        return [
            'weight' => $weight,
            'style' => $style,
            'file' => $fileName,
            'format' => $format,
            'source' => $source,
        ];
    }
}
