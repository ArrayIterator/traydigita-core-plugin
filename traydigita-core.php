<?php
/**
 * Plugin Name: TrayDigita Core
 * Plugin URI: https://traydigita.com
 * Description: Core kernel and modules for TrayDigita sites: module registry, settings framework, admin menu host, menu icons, mailer, security, SEO, performance and publishing modules. The theme keeps presentation only and depends on this plugin.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Author: TrayDigita
 * Author URI: https://traydigita.com
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: traydigita-core
 * Domain Path: /languages
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Core;

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.3', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('TrayDigita Core requires PHP 8.3 or later.', 'traydigita-core')
            . '</p></div>';
    });
    return;
}

// Composer autoloader for psr/log and psr/container (the only
// external dependencies). Core's own classes load through the
// PSR-4 autoloader registered by Core::getInstance().
$vendorAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

// ctype polyfills for the vendored Stack libraries (no-op when
// the ctype extension is present, as on PHP 8.3).
require_once __DIR__ . '/src/Stack/compat/back-compat.php';

require_once __DIR__ . '/src/Core.php';

add_action('plugins_loaded', static function (): void {
    Core::getInstance(__FILE__)->init();
});

register_activation_hook(__FILE__, static function (): void {
    // Custom rewrite rules (Agenda, E-Paper) are registered by the
    // modules on `init`, which has not run yet during activation,
    // so register them directly here before flushing — otherwise
    // the flushed rules would miss them until the next flush.
    Core::getInstance(__FILE__)->init();
    if (class_exists(\TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer::class)) {
        \TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer::registerRoutes();
    }
    if (class_exists(\TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperFrontend::class)) {
        \TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperFrontend::registerRewrites();
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});
