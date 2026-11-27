<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core;

use TrayDigita\WP\Plugin\Core\Admin\AdminMenu;
use TrayDigita\WP\Plugin\Core\Dependencies\ThemeSupport;
use TrayDigita\WP\Plugin\Core\Stack\Libs\Collections\RegistryDefinitions\Value;
use TrayDigita\WP\Plugin\Core\Stack\Stack;
use function add_action;
use function defined;
use function dirname;
use function file_exists;
use function is_string;
use function load_plugin_textdomain;
use function plugin_basename;
use function plugins_url;
use function spl_autoload_register;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * TrayDigita Core kernel.
 *
 * The plugin-side counterpart of the theme's Core class: it owns
 * the PSR-4 autoloader for the `TrayDigita\WP\Plugin\Core\`
 * namespace, the vendored Stack instance, the minimal template
 * loader, the hook helper and the plain asset loader. Module
 * booting itself lives in AdminMenu::init(), mirroring the
 * theme: enabled modules are booted on every request (they own
 * frontend, login and mail hooks), while the menu, pages and
 * AJAX endpoints are admin-only.
 *
 * @mixin Stack
 */
final class Core
{
    /**
     * Hook prefix for every Core filter and action.
     */
    public const string HOOK_PREFIX = 'traydigita:core';

    /**
     * Plugin version.
     */
    public const string VERSION = '1.0.0';

    /**
     * Plugin text domain.
     */
    public const string TEXT_DOMAIN = 'traydigita-core';

    /**
     * @var Stack
     */
    public readonly Stack $stack;

    /**
     * @var Templating
     */
    public readonly Templating $template;

    /**
     * @var Hooks
     */
    public readonly Hooks $hooks;

    /**
     * @var Admin\AssetLoader
     */
    public readonly Admin\AssetLoader $assets;

    /**
     * @var string
     */
    public readonly string $hookPrefix;

    /**
     * @var string
     */
    public readonly string $pluginDir;

    /**
     * Absolute path of the plugin bootstrap file
     * (traydigita-core.php). Kept so paths that must be
     * relative to the plugin root - the languages directory
     * for load_plugin_textdomain() - resolve from the
     * bootstrap file, not from a class file under src/.
     *
     * @var string
     */
    public readonly string $pluginFile;

    /**
     * @var string
     */
    public readonly string $pluginUrl;

    /**
     * @var bool
     */
    private bool $initialized = false;

    /**
     * @var self
     */
    private static self $instance;

    /**
     * @var AdminMenu
     */
    private AdminMenu $adminMenu;

    /**
     * TrayDigita Core constructor.
     *
     * @param string $pluginFile Absolute path of traydigita-core.php.
     */
    private function __construct(string $pluginFile)
    {
        spl_autoload_register([$this, 'autoloader'], true, true);
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        $hook = defined('TRAYDIGITA_CORE_HOOK')
            && is_string(\TRAYDIGITA_CORE_HOOK)
            && \TRAYDIGITA_CORE_HOOK !== ''
            ? \TRAYDIGITA_CORE_HOOK
            : self::HOOK_PREFIX;
        $this->pluginDir = dirname($pluginFile);
        $this->pluginFile = $pluginFile;
        $this->pluginUrl = plugins_url('', $pluginFile);
        $this->hookPrefix = $hook;
        $this->stack = Stack::getInstance();
        $this->template = new Templating($this);
        $this->hooks = new Hooks($this);
        $this->assets = new Admin\AssetLoader($this);
        $this->stack->set(Core::class, $this, true);
        $this->stack->set(Templating::class, Value::create($this->template), true);
        $this->stack->set(Hooks::class, Value::create($this->hooks), true);
        $this->stack->set(Admin\AssetLoader::class, Value::create($this->assets), true);
        $this->adminMenu = new AdminMenu($this);
    }

    /**
     * Get the singleton instance of TrayDigita Core.
     *
     * The plugin file path is only needed on the first call (from
     * the bootstrap); later calls return the stored instance.
     *
     * @param string $pluginFile Absolute path of traydigita-core.php.
     * @return self
     */
    public static function getInstance(string $pluginFile = ''): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self($pluginFile);
        }
        return self::$instance;
    }

    /**
     * Absolute path of the plugin directory (no trailing slash).
     *
     * @return string
     */
    public static function pluginDir(): string
    {
        return self::getInstance()->pluginDir;
    }

    /**
     * Base URL of the plugin directory (no trailing slash).
     *
     * @return string
     */
    public static function pluginUrl(): string
    {
        return self::getInstance()->pluginUrl;
    }

    /**
     * Get the template loader instance.
     *
     * @return Templating
     */
    public static function template(): Templating
    {
        return self::getInstance()->template;
    }

    /**
     * Get the vendored Stack instance.
     *
     * @return Stack
     */
    public static function stack(): Stack
    {
        return self::getInstance()->stack;
    }

    /**
     * Get the hook helper instance.
     *
     * @return Hooks
     */
    public static function hooks(): Hooks
    {
        return self::getInstance()->hooks;
    }

    /**
     * Get the asset loader instance.
     *
     * @return Admin\AssetLoader
     */
    public static function assets(): Admin\AssetLoader
    {
        return self::getInstance()->assets;
    }

    /**
     * PSR-4 autoloader for the TrayDigita\WP\Plugin\Core\ namespace.
     *
     * @param string $class Fully qualified class name.
     * @return void
     */
    private function autoloader(string $class): void
    {
        if (str_starts_with($class, __NAMESPACE__ . '\\')) {
            $relativeClass = substr($class, strlen(__NAMESPACE__) + 1);
            $file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }

    /**
     * Initialize TrayDigita Core.
     *
     * Runs once, on `plugins_loaded`: the text domain is loaded,
     * the admin wiring (menu, AJAX, module booting) is registered,
     * and the image sizes the modules rely on are registered on
     * `after_setup_theme`.
     *
     * @return void
     */
    public function init(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(plugin_basename($this->pluginFile)) . '/languages/'
        );
        $this->adminMenu->init();
        add_action('after_setup_theme', [ThemeSupport::class, 'register']);
        add_action('init', [$this->assets, 'registerAssets'], 0);
    }

    /**
     * Magic method to handle static calls to the Stack instance.
     *
     * @param string $name Method name.
     * @param array<array-key, mixed> $arguments Arguments.
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        return self::stack()->$name(...$arguments);
    }

    /**
     * Magic method to handle calls to the Stack instance.
     *
     * @param string $name Method name.
     * @param array<array-key, mixed> $arguments Arguments.
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return $this->stack->$name(...$arguments);
    }

    /**
     * Magic method to get properties from the Stack instance.
     *
     * @param string $name Property name.
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->stack->$name;
    }

    /**
     * Magic method to set properties on the Stack instance.
     *
     * @param string $name Property name.
     * @param mixed $value Value.
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->stack->$name = $value;
    }

    /**
     * Magic method to check if a property is set on the Stack instance.
     *
     * @param string $name Property name.
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->stack->$name);
    }

    /**
     * Magic method to unset a property on the Stack instance.
     *
     * @param string $name Property name.
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->stack->$name);
    }
}
