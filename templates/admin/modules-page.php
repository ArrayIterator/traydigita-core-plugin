<?php
/**
 * Modules list page (the TrayDigita parent menu page).
 *
 * The list is rendered by JavaScript from the JSON data below —
 * no cards are rendered server-side. The data is printed once as
 * JSON blobs in data attributes (escaped with wp_json_encode,
 * read by resources/js/modules/admin-ui.ts): data-modules holds
 * one entry per module and data-categories holds the full
 * category list with counts. The JS renders filter tabs
 * (All / Enabled / Disabled / Core / Needs setup, like the
 * WordPress plugin-install screen), a category dropdown filter
 * built from data-categories, a live search box, client-side
 * pagination, a skeleton loading state, a card/list view switch
 * (the saved per-user view comes from user meta as
 * data-modules-view), and one card per module showing its
 * author. The dropdown is the single place categories appear on
 * the page — cards and list rows carry no category label.
 *
 * Each module entry carries `category` (resolved slug) and
 * `category_label` next to every pre-existing field; the
 * top-level categories list (slug, label, icon, count, custom)
 * lives in data-categories and in the
 * traydigita_admin_modules_list AJAX response, which returns
 * the same ModuleRegistry::moduleListData() shape the page
 * renders — so the first render and every refresh after a
 * category change agree.
 *
 * Status is one exclusive bucket, in this order: not available,
 * core, needs setup (only when the module is enabled — a disabled
 * module that is also unconfigured is Disabled, never Needs
 * setup, so the tab counts match the registry's enabled /
 * disabled split), enabled, disabled.
 *
 * Included by templates/admin/shell.php; the data comes from
 * ModuleRegistry::moduleListData(), the single source of truth
 * for the list JSON. Enable/disable toggles post to the same
 * AJAX endpoint the shell already uses, via event delegation in
 * resources/js/admin.ts. The view switch posts to
 * traydigita_admin_modules_view (src/Admin/AdminAjax.php).
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;

if (!defined('ABSPATH')) {
    exit;
}

$traydigita_list_data = ModuleRegistry::moduleListData();
$traydigita_modules_data = $traydigita_list_data['modules'];
$traydigita_categories_data = $traydigita_list_data['categories'];

// Saved per-user list view (user meta, default 'cards'); the JS
// reads it from data-modules-view and applies it before the first
// render, so the list never flashes in the wrong view.
$traydigita_modules_view = get_user_meta(
    get_current_user_id(),
    AdminAjax::MODULES_VIEW_META,
    true
);
if (!in_array($traydigita_modules_view, AdminAjax::MODULES_VIEWS, true)) {
    $traydigita_modules_view = 'cards';
}
?>
<div class="traydigita-admin-module-list"
    data-traydigita-module-list
    data-modules="<?php echo esc_attr((string) wp_json_encode($traydigita_modules_data)); ?>"
    data-categories="<?php echo esc_attr((string) wp_json_encode($traydigita_categories_data)); ?>"
    data-modules-view="<?php echo esc_attr($traydigita_modules_view); ?>"
    data-msg-view-cards="<?php echo esc_attr(__('Card view', 'traydigita-core')); ?>"
    data-msg-view-list="<?php echo esc_attr(__('List view', 'traydigita-core')); ?>">
    <noscript>
        <div class="traydigita-admin-empty">
            <p><?php esc_html_e('The module list needs JavaScript. Enable JavaScript to browse and manage modules.', 'traydigita-core'); ?></p>
        </div>
    </noscript>
</div>
