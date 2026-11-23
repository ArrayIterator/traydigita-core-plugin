<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use Throwable;
use TrayDigita\WP\Plugin\Core\Core;
use function basename;
use function dirname;
use function end;
use function file_exists;
use function function_exists;
use function glob;
use function is_array;
use function is_string;
use function sort;
use function str_replace;
use function strrpos;
use function substr;
use function wp_enqueue_script;
use function wp_enqueue_script_module;
use function wp_enqueue_style;
use function wp_register_script_module;
use function wp_register_style;
use function wp_script_modules;
use function wp_style_is;

/**
 * Plain asset loader for TrayDigita Core.
 *
 * The theme builds its admin JavaScript and CSS with Vite and
 * resolves entries through a manifest. Core v1.0.0 ships no
 * build step: the admin bundles the theme already built
 * (admin, select2, two-factor, turnstile, contributor-editor)
 * are copied verbatim into assets/dist/ and registered here as
 * WordPress script modules (WP 6.5+) with their compiled CSS.
 * The file names carry the build hash; when the bundles are
 * rebuilt, update the ASSETS map below to the new file names.
 *
 * Handles the modules already expect are kept verbatim
 * (`traydigita-theme-two-factor`, `traydigita-theme-turnstile`,
 * `traydigita-theme-contributor-editor`): the modules check
 * registration through those handles, so renaming them would
 * silently disable the login challenge and the contributor
 * editor. The admin entry itself uses Core handles.
 */
final class AssetLoader
{
    /**
     * Script-module handle of the admin entry.
     */
    public const string ADMIN_HANDLE = 'traydigita-core-admin';

    /**
     * Style handle of the CSS bundled with the admin entry.
     */
    public const string ADMIN_STYLE_HANDLE = 'traydigita-core-admin';

    /**
     * Script-module handle of the select2 entry.
     */
    public const string SELECT2_HANDLE = 'traydigita-core-select2';

    /**
     * Style handle of the CSS bundled with the select2 entry.
     */
    public const string SELECT2_STYLE_HANDLE = 'traydigita-core-select2';

    /**
     * Bundled entries: name => [js, css, script-module handle, style handle].
     *
     * The js/css paths are relative to assets/dist/. The admin
     * entry depends on select2 and the WordPress color picker,
     * exactly like the theme's Vite ASSETS_PATH map.
     *
     * @var array<string, array{js: string, css: string, handle: string, style: string}>
     */
    public const array ASSETS = [
        'admin' => [
            'js' => 'js/admin-0db8693c.min.js',
            'css' => 'css/admin-0db8693c.min.css',
            'handle' => self::ADMIN_HANDLE,
            'style' => self::ADMIN_STYLE_HANDLE,
        ],
        'select2' => [
            'js' => 'js/select2-0db8693c.min.js',
            'css' => 'css/select2-0db8693c.min.css',
            'handle' => self::SELECT2_HANDLE,
            'style' => self::SELECT2_STYLE_HANDLE,
        ],
        'two-factor' => [
            'js' => 'js/two-factor-0db8693c.min.js',
            'css' => 'css/two-factor-0db8693c.min.css',
            'handle' => 'traydigita-theme-two-factor',
            'style' => 'traydigita-theme-two-factor',
        ],
        'turnstile' => [
            'js' => 'js/turnstile-0db8693c.min.js',
            'css' => '',
            'handle' => 'traydigita-theme-turnstile',
            'style' => '',
        ],
        'contributor-editor' => [
            'js' => 'blocks/contributor-editor-0db8693c.min.js',
            'css' => '',
            'handle' => 'traydigita-theme-contributor-editor',
            'style' => '',
        ],
        'charts' => [
            'js' => 'js/charts-0db8693c.min.js',
            'css' => '',
            // The handle is kept verbatim: SvgChart::render()
            // enqueues it for chart hover tooltips.
            'handle' => 'traydigita-theme-charts',
            'style' => '',
        ],
    ];

    /**
     * @var bool
     */
    private bool $registered = false;

    /**
     * @param Core $core Core kernel instance.
     */
    public function __construct(public readonly Core $core)
    {
    }

    /**
     * Register every bundled entry as a script module (+ style).
     *
     * Runs once, on `init` priority 0, so the modules can check
     * registration (TwoFactor/Turnstile `isLoginChallengeRenderable()`)
     * and enqueue by handle at any later hook. Registration is a
     * no-op on WordPress without the script-modules API.
     *
     * @return void
     */
    public function registerAssets(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        if (!function_exists('wp_register_script_module')) {
            return;
        }
        foreach (self::ASSETS as $name => $entry) {
            $js = $this->resolveFile($entry['js']);
            if ($js === null) {
                continue;
            }
            $deps = [];
            if ($name === 'admin') {
                $deps[] = self::SELECT2_HANDLE;
            }
            wp_register_script_module(
                $entry['handle'],
                $this->core->pluginUrl . '/assets/dist/' . $entry['js'],
                $deps,
                null,
                ['in_footer' => true]
            );
            if ($entry['css'] !== '' && $this->resolveFile($entry['css']) !== null) {
                wp_register_style(
                    $entry['style'],
                    $this->core->pluginUrl . '/assets/dist/' . $entry['css'],
                    [],
                    null
                );
            }
        }
    }

    /**
     * Resolve a bundled file, tolerating a rebuilt hash.
     *
     * The exact file name from ASSETS is tried first; when it is
     * missing (the bundles were rebuilt with a new hash), the
     * newest file matching the entry's base name is used instead,
     * so a stale map degrades to the fresh build rather than
     * dropping the entry silently.
     *
     * @param string $relative Path relative to assets/dist/.
     * @return string|null Resolved relative path, or null when absent.
     */
    private function resolveFile(string $relative): ?string
    {
        $full = $this->core->pluginDir . '/assets/dist/' . $relative;
        if (file_exists($full)) {
            return $relative;
        }
        $dir = dirname($relative);
        $base = $relative;
        $dash = strrpos($relative, '-');
        $dot = strrpos($relative, '.');
        if ($dash !== false && $dot !== false && $dash < $dot) {
            $base = substr($relative, 0, $dash);
        }
        $pattern = $this->core->pluginDir . '/assets/dist/' . $dir . '/' . basename($base) . '-*.min.*';
        $matches = glob($pattern);
        if (!is_array($matches) || $matches === []) {
            return null;
        }
        sort($matches);
        $match = end($matches);
        if (!is_string($match) || $match === '') {
            return null;
        }
        return str_replace($this->core->pluginDir . '/assets/dist/', '', $match);
    }

    /**
     * Enqueue the admin entry (shell + module JavaScript + admin CSS).
     *
     * Called by AdminMenu only on TrayDigita pages. The select2
     * entry is enqueued directly, not only as a dependency of
     * the admin entry: WordPress prints a <script type="module">
     * tag only for enqueued modules, while their dependencies
     * get a mere modulepreload link, and a preloaded module
     * never executes — so $.fn.select2 would never register.
     *
     * @return void
     */
    public function enqueueAdminEntry(): void
    {
        $this->registerAssets();
        if (!function_exists('wp_enqueue_script_module')) {
            return;
        }
        wp_enqueue_script('jquery');
        wp_enqueue_script('wp-color-picker');
        if ($this->isScriptModuleRegistered(self::SELECT2_HANDLE)) {
            wp_enqueue_script_module(self::SELECT2_HANDLE);
            $this->enqueueStyle(self::SELECT2_STYLE_HANDLE);
        }
        if ($this->isScriptModuleRegistered(self::ADMIN_HANDLE)) {
            wp_enqueue_script_module(self::ADMIN_HANDLE);
            $this->enqueueStyle(self::ADMIN_STYLE_HANDLE);
        }
        $this->enqueueModalAssets();
        $this->enqueueSettingsLayoutAssets();
    }

    /**
     * Enqueue the shared modal assets (plain CSS + JS, no build).
     *
     * One modal component serves the module info popup and the
     * Mailer provider / SMTP preset pickers on every TrayDigita
     * page: assets/css/traydigita-modal.css holds the single
     * modal style block and assets/js/traydigita-modal.js the
     * single controller. Core has no Vite build, so these are
     * plain files versioned with Core::VERSION.
     *
     * @return void
     */
    private function enqueueModalAssets(): void
    {
        $css = $this->core->pluginDir . '/assets/css/traydigita-modal.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'traydigita-core-modal',
                $this->core->pluginUrl . '/assets/css/traydigita-modal.css',
                [],
                Core::VERSION
            );
        }
        $js = $this->core->pluginDir . '/assets/js/traydigita-modal.js';
        if (file_exists($js)) {
            wp_enqueue_script(
                'traydigita-core-modal',
                $this->core->pluginUrl . '/assets/js/traydigita-modal.js',
                [],
                Core::VERSION,
                true
            );
        }
    }

    /**
     * Enqueue the settings layout fixes (plain CSS, no build).
     *
     * assets/css/traydigita-settings-layout.css holds the
     * save-bar clearance, field rhythm and Contact Form table
     * rules the bundled admin stylesheet does not cover. Same
     * pattern as the modal assets above: a plain file versioned
     * with Core::VERSION, enqueued only when it exists.
     *
     * @return void
     */
    private function enqueueSettingsLayoutAssets(): void
    {
        $css = $this->core->pluginDir . '/assets/css/traydigita-settings-layout.css';
        if (file_exists($css)) {
            wp_enqueue_style(
                'traydigita-core-settings-layout',
                $this->core->pluginUrl . '/assets/css/traydigita-settings-layout.css',
                [],
                Core::VERSION
            );
        }
    }

    /**
     * Enqueue a standalone bundled entry by name.
     *
     * Mirrors the theme's enqueueScriptEntry(): a missing entry
     * enqueues nothing and the caller degrades gracefully (the
     * contributor editor falls back to its plain textarea).
     *
     * @param string $name Entry base name, e.g. 'contributor-editor'.
     * @param string $handle Script-module handle to enqueue.
     * @param array<int, string> $dependencies Classic script handles that must load first.
     * @return void
     */
    public function enqueueScriptEntry(string $name, string $handle, array $dependencies = []): void
    {
        if ($handle === '') {
            return;
        }
        $this->registerAssets();
        if (!function_exists('wp_enqueue_script_module')) {
            return;
        }
        foreach ($dependencies as $dependency) {
            wp_enqueue_script($dependency);
        }
        if (!$this->isScriptModuleRegistered($handle)) {
            return;
        }
        wp_enqueue_script_module($handle);
        $entry = self::ASSETS[$name] ?? null;
        if ($entry !== null && $entry['style'] !== '') {
            $this->enqueueStyle($entry['style']);
        }
    }

    /**
     * Enqueue a bundled stylesheet by handle when registered.
     *
     * @param string $handle Style handle.
     * @return void
     */
    private function enqueueStyle(string $handle): void
    {
        if ($handle !== '' && wp_style_is($handle, 'registered')) {
            wp_enqueue_style($handle);
        }
    }

    /**
     * Whether a script module handle is registered.
     *
     * @param string $handle Script-module handle.
     * @return bool
     */
    private function isScriptModuleRegistered(string $handle): bool
    {
        if (!function_exists('wp_script_modules')) {
            return false;
        }
        try {
            return wp_script_modules()->get_registered($handle) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
