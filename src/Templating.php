<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core;

use function apply_filters;
use function file_exists;
use function is_string;

/**
 * Minimal template loader for TrayDigita Core.
 *
 * The theme ships a full templating engine (layouts, template
 * arguments, post loops). Core does not need it: the only
 * templates modules render through this loader are the frontend
 * section partials under templates/sections/ (share buttons,
 * breadcrumb), which read the current post and their module
 * settings directly. This loader therefore only resolves a
 * section slug to its file under the plugin's templates/
 * directory and includes it, returning the resolved path (or
 * null when the file does not exist).
 *
 * Admin templates are included directly by AdminMenu and the
 * modules, never through this class.
 */
final class Templating
{
    /**
     * @param Core $core Core kernel instance.
     */
    public function __construct(public readonly Core $core)
    {
    }

    /**
     * Template arguments placeholder.
     *
     * The theme's loader returns a TemplateArguments object; the
     * section partials Core ships never read it, so Core returns
     * null. Callers pass the return value straight back into
     * loadSection(), which accepts it.
     *
     * @return null
     */
    public function getArguments(): mixed
    {
        return null;
    }

    /**
     * Load a section template by slug.
     *
     * Resolves templates/sections/<slug>.php (or
     * templates/sections/<slug>-<name>.php when a name is given)
     * inside the plugin directory and includes it in this scope,
     * so the partial sees the $args variable. A theme or child
     * theme may override the section by filtering
     * `traydigita:core:template:section` with a replacement path.
     *
     * @param string $slug Section slug, e.g. 'share-buttons'.
     * @param string|false|null $name Optional section variant.
     * @param mixed $args Arguments passed to the partial as $args.
     * @return string|null Resolved template path, or null when missing.
     */
    public function loadSection(
        string $slug,
        string|false|null $name = null,
        mixed $args = null
    ): ?string {
        $candidates = [];
        if (is_string($name) && $name !== '') {
            $candidates[] = $this->core->pluginDir . '/templates/sections/' . $slug . '-' . $name . '.php';
        }
        $candidates[] = $this->core->pluginDir . '/templates/sections/' . $slug . '.php';
        foreach ($candidates as $candidate) {
            $candidate = (string) apply_filters('traydigita:core:template:section', $candidate, $slug, $name);
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                require $candidate;
                return $candidate;
            }
        }
        return null;
    }
}
