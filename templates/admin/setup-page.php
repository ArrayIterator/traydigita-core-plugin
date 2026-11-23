<?php
/**
 * TrayDigita Setup page template.
 *
 * Thin caller: the shell (templates/admin/shell.php) requires
 * this file when $traydigita_setup_page is set, and all
 * checklist markup lives in SetupPage::render() — the same shape
 * as InstallerModule::renderSettingsPage(), which renders its
 * markup inline in the class. Keeping the markup in the class
 * keeps the checklist data (SetupPage::items()) and its
 * rendering in one place, so the AJAX endpoints can reuse the
 * same item data (SetupAjax::flushPermalinks() answers with the
 * refreshed permalinks item).
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Admin\SetupPage;

if (!defined('ABSPATH')) {
    exit;
}

SetupPage::render();
