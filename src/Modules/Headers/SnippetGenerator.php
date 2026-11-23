<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Headers;

use function __;
use function implode;
use function str_replace;

/**
 * Server-config snippet generator for the Custom Headers module.
 *
 * Renders the headers the module would send as copy-paste
 * `.htaccess` (Apache / OpenLiteSpeed) or Nginx configuration, so
 * an administrator can move the headers to the server level when
 * the site is behind a cache or a server that strips PHP headers.
 *
 * Display only: the module NEVER writes server configuration
 * files itself. A wrong line in .htaccess or nginx.conf can take
 * the whole site down, so the snippets are shown for a human to
 * review and paste, never applied automatically.
 */
final class SnippetGenerator
{
    /**
     * Render the headers as an Apache / OpenLiteSpeed .htaccess snippet.
     *
     * Each header becomes a `Header always set` line inside an
     * <IfModule mod_headers.c> guard, so a server without
     * mod_headers simply ignores the block instead of erroring.
     *
     * @param array<string, string> $headers Headers to emit (name => value).
     * @return string
     */
    public static function htaccess(array $headers): string
    {
        $lines = [
            '# ' . __('TrayDigita — Custom Headers. Paste into .htaccess (Apache / OpenLiteSpeed).', 'traydigita-core'),
            '# ' . __('The theme never writes this file itself — review before pasting.', 'traydigita-core'),
            '<IfModule mod_headers.c>',
        ];
        foreach ($headers as $name => $value) {
            $lines[] = '    Header always set ' . $name . ' "' . self::escapeValue($value) . '"';
        }
        $lines[] = '</IfModule>';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Render the headers as an Nginx snippet.
     *
     * Each header becomes an `add_header ... always;` line, meant
     * for a server or location block. The `always` flag keeps the
     * header on error responses as well.
     *
     * @param array<string, string> $headers Headers to emit (name => value).
     * @return string
     */
    public static function nginx(array $headers): string
    {
        $lines = [
            '# ' . __('TrayDigita — Custom Headers. Paste into a server or location block, then reload Nginx.', 'traydigita-core'),
            '# ' . __('The theme never writes this file itself — review before pasting.', 'traydigita-core'),
        ];
        foreach ($headers as $name => $value) {
            $lines[] = 'add_header ' . $name . ' "' . self::escapeValue($value) . '" always;';
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * Escape a header value for a double-quoted config string.
     *
     * Backslashes and double quotes are escaped; the header values
     * this module produces never contain line breaks (they are
     * rejected on save), so no further escaping is needed.
     *
     * @param string $value Raw header value.
     * @return string
     */
    private static function escapeValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
