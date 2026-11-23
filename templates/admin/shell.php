<?php
/**
 * TrayDigita admin shell.
 *
 * Every TrayDigita admin page renders through this shell: a top
 * bar with the brand, the section navigation and the user menu,
 * a page card with the header (back link, breadcrumb, title) and
 * — on module settings pages — the tab bar at its bottom edge,
 * the content area, and the toast area. Module settings pages
 * additionally get a context rail beside the content: a card
 * with the module's icon, name, description and status, so the
 * right-hand space is information, not dead air. The tabs are
 * the module's tabs as underline buttons when it declares at
 * least two, otherwise anchor links to its declared sections
 * (see SettingsPage::navItems()), or a single item with the
 * module name when it declares neither. The tab buttons keep
 * their [data-traydigita-tab] attributes, so the shell
 * JavaScript switches panels exactly as it did with the old
 * left nav; only the placement changed.
 *
 * Template variables, set by AdminMenu::renderPage():
 * - $traydigita_title (string): page title.
 * - $traydigita_module_class (class-string<ModuleInterface>|null):
 *   the module being edited, or null for the modules grid / hub.
 * - $traydigita_tabs (array<int, array{id: string, label: string}>):
 *   tab list for the module, if any.
 * - $traydigita_is_hub (bool): true for the Settings hub page.
 * - $traydigita_setup_page (bool): true for the Setup checklist
 *   page. A setup page is neither the modules grid nor a module
 *   page, even though it shares their null module class.
 * - $traydigita_topbar_items (array): sections for the top-bar
 *   dropdown (AdminMenu::navItems()), in submenu order.
 * - $traydigita_topbar_current (string): id of the section being
 *   rendered, for the active dropdown entry.
 * - $traydigita_back_url / $traydigita_back_label (string): back
 *   link in the header (module page -> Settings hub, hub ->
 *   Modules list, setup -> Modules list); empty on the Modules
 *   list itself.
 *
 * The shell root element carries the AJAX endpoint and nonce as data
 * attributes (read by resources/js/admin.ts) plus the translatable
 * UI strings the JavaScript needs — JavaScript strings always come
 * from these attributes, never from hardcoded text in the script.
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\AdminMenu;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

$traydigita_title = isset($traydigita_title) && is_string($traydigita_title)
    ? $traydigita_title
    : __('TrayDigita', 'traydigita-core');
$traydigita_module_class = $traydigita_module_class ?? null;
$traydigita_tabs = isset($traydigita_tabs) && is_array($traydigita_tabs)
    ? $traydigita_tabs
    : [];
$traydigita_is_hub = isset($traydigita_is_hub) && $traydigita_is_hub === true;
$traydigita_setup_page = isset($traydigita_setup_page) && $traydigita_setup_page === true;
$traydigita_topbar_items = isset($traydigita_topbar_items) && is_array($traydigita_topbar_items)
    ? $traydigita_topbar_items
    : [];
$traydigita_topbar_current = isset($traydigita_topbar_current) && is_string($traydigita_topbar_current)
    ? $traydigita_topbar_current
    : '';
$traydigita_current_user = wp_get_current_user();
$traydigita_back_url = isset($traydigita_back_url) && is_string($traydigita_back_url)
    ? $traydigita_back_url
    : '';
$traydigita_back_label = isset($traydigita_back_label) && is_string($traydigita_back_label)
    ? $traydigita_back_label
    : '';
$traydigita_is_modules_page = $traydigita_module_class === null && !$traydigita_is_hub && !$traydigita_setup_page;
$traydigita_is_module_page = $traydigita_module_class !== null;
/** @var class-string<\TrayDigita\WP\Plugin\Core\Admin\ModuleInterface>|null $traydigita_module_class */
$traydigita_nav_items = $traydigita_is_module_page && is_string($traydigita_module_class)
    ? SettingsPage::navItems($traydigita_module_class)
    : [];
$traydigita_nav_has_tabs = $traydigita_nav_items !== []
    && ($traydigita_nav_items[0]['kind'] ?? '') === 'tab';
// Context rail (module pages only): the module class behind the
// page, so the rail can read its name, description, icon,
// version, author and state straight from the module — no new
// data, no new strings.
/** @var class-string<\TrayDigita\WP\Plugin\Core\Admin\ModuleInterface>|null $traydigita_rail_module */
$traydigita_rail_module = $traydigita_is_module_page && is_string($traydigita_module_class)
    && class_exists($traydigita_module_class)
    ? $traydigita_module_class
    : null;
$traydigita_body_class = $traydigita_rail_module !== null ? ' traydigita-admin-body-has-rail' : '';
$traydigita_rail_icon = $traydigita_rail_module !== null ? $traydigita_rail_module::icon() : '';
?>
<div class="wrap traydigita-admin-shell"
    data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
    data-nonce="<?php echo esc_attr(wp_create_nonce(AdminAjax::NONCE_ACTION)); ?>"
    data-msg-saved="<?php echo esc_attr(__('Settings saved.', 'traydigita-core')); ?>"
    data-msg-error="<?php echo esc_attr(__('Something went wrong. Please try again.', 'traydigita-core')); ?>"
    data-msg-saving="<?php echo esc_attr(__('Saving…', 'traydigita-core')); ?>"
    data-msg-toggle-error="<?php echo esc_attr(__('Could not change the module state. Please try again.', 'traydigita-core')); ?>"
    data-msg-enabled="<?php echo esc_attr(__('Module enabled', 'traydigita-core')); ?>"
    data-msg-disabled="<?php echo esc_attr(__('Module disabled', 'traydigita-core')); ?>"
    data-msg-save-changes="<?php echo esc_attr(__('Save changes', 'traydigita-core')); ?>"
    data-msg-help="<?php echo esc_attr(__('Help', 'traydigita-core')); ?>"
    data-msg-hide-help="<?php echo esc_attr(__('Hide help', 'traydigita-core')); ?>"
    data-msg-copy="<?php echo esc_attr(__('Copy', 'traydigita-core')); ?>"
    data-msg-copied="<?php echo esc_attr(__('Copied', 'traydigita-core')); ?>"
    data-msg-dismiss="<?php echo esc_attr(__('Dismiss', 'traydigita-core')); ?>"
    data-msg-toast-done="<?php echo esc_attr(__('Done', 'traydigita-core')); ?>"
    data-msg-toast-failed="<?php echo esc_attr(__('Failed', 'traydigita-core')); ?>"
    data-pointer-tour="<?php echo esc_attr((string) wp_json_encode($traydigita_pointer_tour ?? [])); ?>"
    data-msg-loading="<?php echo esc_attr(__('Loading modules…', 'traydigita-core')); ?>"
    data-msg-no-modules="<?php echo esc_attr(__('No modules match your search.', 'traydigita-core')); ?>"
    data-msg-search="<?php echo esc_attr(__('Search modules', 'traydigita-core')); ?>"
    data-msg-all="<?php echo esc_attr(__('All', 'traydigita-core')); ?>"
    data-msg-core="<?php echo esc_attr(__('Core', 'traydigita-core')); ?>"
    data-msg-needs-setup="<?php echo esc_attr(__('Needs setup', 'traydigita-core')); ?>"
    data-msg-not-available="<?php echo esc_attr(__('Not available', 'traydigita-core')); ?>"
    data-msg-open="<?php echo esc_attr(__('Open', 'traydigita-core')); ?>"
    data-msg-author="<?php echo esc_attr(__('By %s', 'traydigita-core')); ?>"
    data-msg-page="<?php echo esc_attr(__('Page %s of %s', 'traydigita-core')); ?>"
    data-msg-previous="<?php echo esc_attr(__('Previous', 'traydigita-core')); ?>"
    data-msg-next="<?php echo esc_attr(__('Next', 'traydigita-core')); ?>"
    data-msg-version="<?php echo esc_attr(__('Version %s', 'traydigita-core')); ?>"
    data-msg-categories="<?php echo esc_attr(__('Categories', 'traydigita-core')); ?>"
    data-msg-all-categories="<?php echo esc_attr(__('All categories', 'traydigita-core')); ?>"
    data-msg-manage-categories="<?php echo esc_attr(__('Manage categories', 'traydigita-core')); ?>"
    data-msg-category-dialog-title="<?php echo esc_attr(__('Manage module categories', 'traydigita-core')); ?>"
    data-msg-custom-categories="<?php echo esc_attr(__('Custom categories', 'traydigita-core')); ?>"
    data-msg-create-category="<?php echo esc_attr(__('Create category', 'traydigita-core')); ?>"
    data-msg-category-name="<?php echo esc_attr(__('Category name', 'traydigita-core')); ?>"
    data-msg-category-icon="<?php echo esc_attr(__('Icon', 'traydigita-core')); ?>"
    data-msg-create="<?php echo esc_attr(__('Create', 'traydigita-core')); ?>"
    data-msg-rename="<?php echo esc_attr(__('Rename', 'traydigita-core')); ?>"
    data-msg-delete="<?php echo esc_attr(__('Delete', 'traydigita-core')); ?>"
    data-msg-save="<?php echo esc_attr(__('Save', 'traydigita-core')); ?>"
    data-msg-cancel="<?php echo esc_attr(__('Cancel', 'traydigita-core')); ?>"
    data-msg-assign-module="<?php echo esc_attr(__('Assign a module', 'traydigita-core')); ?>"
    data-msg-module="<?php echo esc_attr(__('Module', 'traydigita-core')); ?>"
    data-msg-category="<?php echo esc_attr(__('Category', 'traydigita-core')); ?>"
    data-msg-choose-module="<?php echo esc_attr(__('Choose a module…', 'traydigita-core')); ?>"
    data-msg-choose-category="<?php echo esc_attr(__('Choose a category…', 'traydigita-core')); ?>"
    data-msg-assign="<?php echo esc_attr(__('Assign', 'traydigita-core')); ?>"
    data-msg-no-custom-categories="<?php echo esc_attr(__('No custom categories yet.', 'traydigita-core')); ?>"
    data-msg-category-created="<?php echo esc_attr(__('Category created.', 'traydigita-core')); ?>"
    data-msg-category-renamed="<?php echo esc_attr(__('Category renamed.', 'traydigita-core')); ?>"
    data-msg-category-deleted="<?php echo esc_attr(__('Category deleted.', 'traydigita-core')); ?>"
    data-msg-module-assigned="<?php echo esc_attr(__('Module assigned to %s.', 'traydigita-core')); ?>"
    data-msg-category-delete-confirm="<?php echo esc_attr(__('Modules in this category will return to their default categories.', 'traydigita-core')); ?>"
    data-msg-category-delete-confirm-one="<?php echo esc_attr(__('1 module will return to its default category.', 'traydigita-core')); ?>"
    data-msg-category-delete-confirm-count="<?php echo esc_attr(__('%s modules will return to their default categories.', 'traydigita-core')); ?>"
    data-msg-category-name-required="<?php echo esc_attr(__('Enter a category name.', 'traydigita-core')); ?>"
    data-msg-one-module="<?php echo esc_attr(__('1 module', 'traydigita-core')); ?>"
    data-msg-modules-count="<?php echo esc_attr(__('%s modules', 'traydigita-core')); ?>"
    data-msg-category-save-error="<?php echo esc_attr(__('Could not save the category. Please try again.', 'traydigita-core')); ?>"
    data-msg-category-assign-error="<?php echo esc_attr(__('Could not assign the module. Please try again.', 'traydigita-core')); ?>"
    data-msg-assign-incomplete="<?php echo esc_attr(__('Choose a module and a category first.', 'traydigita-core')); ?>"
    data-msg-module-info="<?php echo esc_attr(__('Module info', 'traydigita-core')); ?>"
    data-msg-more="<?php echo esc_attr(__('More', 'traydigita-core')); ?>"
    data-msg-features="<?php echo esc_attr(__('Features', 'traydigita-core')); ?>"
    data-msg-changelog="<?php echo esc_attr(__('Changelog', 'traydigita-core')); ?>"
    data-msg-details="<?php echo esc_attr(__('Details', 'traydigita-core')); ?>"
    data-msg-open-settings="<?php echo esc_attr(__('Open settings', 'traydigita-core')); ?>"
    data-msg-close="<?php echo esc_attr(__('Close', 'traydigita-core')); ?>"
    data-msg-label-version="<?php echo esc_attr(__('Version', 'traydigita-core')); ?>"
    data-msg-label-author="<?php echo esc_attr(__('Author', 'traydigita-core')); ?>"
    data-msg-label-category="<?php echo esc_attr(__('Category', 'traydigita-core')); ?>"
    data-msg-label-status="<?php echo esc_attr(__('Status', 'traydigita-core')); ?>">
    <div class="traydigita-admin-topbar">
        <a class="traydigita-admin-brand"
            href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::MENU_SLUG)); ?>">
            <img class="traydigita-admin-brand-logo"
                src="<?php echo esc_url(\TrayDigita\WP\Plugin\Core\Core::pluginUrl() . '/assets/images/traydigita-logo.svg'); ?>"
                alt="" width="32" height="32">
            <span class="traydigita-admin-brand-name">TrayDigita</span>
        </a>
        <div class="traydigita-admin-topbar-actions">
            <div class="traydigita-admin-menu" data-traydigita-menu>
                <button type="button" class="traydigita-admin-menu-button" data-traydigita-menu-button
                    aria-haspopup="true" aria-expanded="false" aria-controls="traydigita-topbar-nav">
                    <span class="material-icons" aria-hidden="true">menu</span>
                    <span class="traydigita-admin-menu-button-label"><?php esc_html_e('Navigation', 'traydigita-core'); ?></span>
                    <span class="material-icons traydigita-admin-menu-chevron" aria-hidden="true">expand_more</span>
                </button>
                <div class="traydigita-admin-menu-panel" id="traydigita-topbar-nav" data-traydigita-menu-panel hidden>
                    <nav aria-label="<?php echo esc_attr(__('TrayDigita sections', 'traydigita-core')); ?>">
                        <ul class="traydigita-admin-menu-list">
                            <?php foreach ($traydigita_topbar_items as $traydigita_topbar_item) : ?>
                                <li>
                                    <a class="traydigita-admin-menu-item<?php echo $traydigita_topbar_item['id'] === $traydigita_topbar_current ? ' is-active' : ''; ?>"
                                        href="<?php echo esc_url($traydigita_topbar_item['url']); ?>"
                                        <?php echo $traydigita_topbar_item['id'] === $traydigita_topbar_current ? 'aria-current="page"' : ''; ?>>
                                        <?php echo esc_html($traydigita_topbar_item['label']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="traydigita-admin-menu-separator" role="separator"></div>
                        <ul class="traydigita-admin-menu-list">
                            <li>
                                <a class="traydigita-admin-menu-item" href="<?php echo esc_url(admin_url('index.php')); ?>">
                                    <?php esc_html_e('WordPress Dashboard', 'traydigita-core'); ?>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
            <div class="traydigita-admin-menu" data-traydigita-menu>
                <button type="button" class="traydigita-admin-menu-button" data-traydigita-menu-button
                    aria-haspopup="true" aria-expanded="false" aria-controls="traydigita-topbar-user">
                    <?php echo get_avatar($traydigita_current_user->ID, 28, '', '', ['class' => 'traydigita-admin-menu-avatar']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns core-built avatar markup. ?>
                    <span class="traydigita-admin-menu-button-label"><?php echo esc_html($traydigita_current_user->display_name); ?></span>
                    <span class="material-icons traydigita-admin-menu-chevron" aria-hidden="true">expand_more</span>
                </button>
                <div class="traydigita-admin-menu-panel" id="traydigita-topbar-user" data-traydigita-menu-panel hidden>
                    <div class="traydigita-admin-menu-user">
                        <p class="traydigita-admin-menu-user-name"><?php echo esc_html($traydigita_current_user->display_name); ?></p>
                        <p class="traydigita-admin-menu-user-email"><?php echo esc_html($traydigita_current_user->user_email); ?></p>
                    </div>
                    <ul class="traydigita-admin-menu-list">
                        <li>
                            <a class="traydigita-admin-menu-item" href="<?php echo esc_url(admin_url('profile.php')); ?>">
                                <?php esc_html_e('Profile', 'traydigita-core'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="traydigita-admin-menu-item" href="<?php echo esc_url(admin_url('index.php')); ?>">
                                <?php esc_html_e('WordPress Dashboard', 'traydigita-core'); ?>
                            </a>
                        </li>
                        <li>
                            <a class="traydigita-admin-menu-item" href="<?php echo esc_url(wp_logout_url()); ?>">
                                <?php esc_html_e('Log out', 'traydigita-core'); ?>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="traydigita-admin-page">
    <header class="traydigita-admin-header">
        <div class="traydigita-admin-header-text">
            <?php if ($traydigita_back_url !== '' && $traydigita_back_label !== '') : ?>
                <p class="traydigita-admin-back">
                    <a class="traydigita-admin-back-link"
                        href="<?php echo esc_url($traydigita_back_url); ?>">
                        &larr; <?php echo esc_html($traydigita_back_label); ?>
                    </a>
                </p>
            <?php endif; ?>
            <nav class="traydigita-admin-eyebrow"
                aria-label="<?php echo esc_attr(__('Breadcrumb', 'traydigita-core')); ?>">
                <?php if ($traydigita_is_module_page) : ?>
                    <a href="<?php echo esc_url(AdminMenu::hubUrl()); ?>">
                        <?php esc_html_e('TrayDigita', 'traydigita-core'); ?>
                    </a>
                    <span aria-hidden="true"> / </span>
                    <a href="<?php echo esc_url(AdminMenu::hubUrl()); ?>">
                        <?php esc_html_e('Settings', 'traydigita-core'); ?>
                    </a>
                    <span aria-hidden="true"> / </span>
                    <span aria-current="page"><?php echo esc_html($traydigita_title); ?></span>
                <?php elseif ($traydigita_is_hub) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::MENU_SLUG)); ?>">
                        <?php esc_html_e('TrayDigita', 'traydigita-core'); ?>
                    </a>
                    <span aria-hidden="true"> / </span>
                    <span aria-current="page"><?php echo esc_html($traydigita_title); ?></span>
                <?php else : ?>
                    <?php esc_html_e('TrayDigita', 'traydigita-core'); ?>
                <?php endif; ?>
            </nav>
            <h1 class="traydigita-admin-title"><?php echo esc_html($traydigita_title); ?></h1>
            <?php if ($traydigita_is_modules_page) : ?>
                <p class="traydigita-admin-subtitle">
                    <?php esc_html_e(
                        'Enable the features your site needs. Disabled modules register no hooks and load nothing.',
                        'traydigita-core'
                    ); ?>
                </p>
            <?php elseif ($traydigita_is_hub) : ?>
                <p class="traydigita-admin-subtitle">
                    <?php esc_html_e(
                        'Settings for the modules that are currently active. Inactive modules are listed on the Modules page.',
                        'traydigita-core'
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($traydigita_is_module_page) : ?>
        <?php
        // Critical tab-bar CSS (fixes round, Task 16): the admin
        // stylesheet is a Vite bundle that arrives after first
        // paint on a cold load, so without this the nav renders
        // as a plain vertical <ul> (~408px tall) and snaps to a
        // horizontal bar once the bundle lands, and the active
        // tab has no styling until the shell script runs. These
        // rules mirror resources/css/components/
        // admin-settings-framework.css exactly, so the bar paints
        // correctly the first time and the bundle only confirms
        // it — there is no restyle flash.
        ?>
        <style>
        .td-settings-nav{margin:1.25rem -1.5rem 0;padding:0 1.5rem;border-top:1px solid var(--traydigita-admin-line)}
        .td-settings-nav .traydigita-admin-tabs,.td-settings-nav-list{display:flex;flex-direction:row;gap:0 1rem;margin:0;padding:0;overflow-x:auto;border:0;border-radius:0;background:transparent;list-style:none;scrollbar-width:thin}
        .td-settings-nav .traydigita-admin-tabs{flex-wrap:nowrap;margin-bottom:0;border-bottom:0}
        .td-settings-nav .traydigita-admin-tab,.td-settings-nav-link{display:inline-flex;flex-shrink:0;align-items:center;gap:.25rem;margin:0 0 -1px;padding:.75rem .125rem;border:0;border-bottom:2px solid transparent;border-radius:0;background:transparent;color:var(--traydigita-admin-muted);font-size:.875rem;font-weight:600;line-height:1.4;white-space:nowrap;text-decoration:none;cursor:pointer;box-shadow:none}
        .td-settings-nav .traydigita-admin-tab-active,.td-settings-nav-link.is-active{border-bottom-color:var(--traydigita-admin-accent);background:transparent;color:var(--traydigita-admin-accent);box-shadow:none}
        .td-settings-nav-link .dashicons{flex-shrink:0;width:18px;height:18px;font-size:18px}
        </style>
        <div class="td-settings-nav">
            <?php if ($traydigita_nav_has_tabs) : ?>
                <nav class="traydigita-admin-tabs" role="tablist"
                    aria-label="<?php echo esc_attr(__('Module settings', 'traydigita-core')); ?>">
                    <?php foreach ($traydigita_nav_items as $index => $tab) : ?>
                        <button type="button"
                            class="traydigita-admin-tab<?php echo $index === 0 ? ' traydigita-admin-tab-active' : ''; ?>"
                            role="tab"
                            id="traydigita-tab-<?php echo esc_attr($tab['id']); ?>"
                            aria-controls="traydigita-panel-<?php echo esc_attr($tab['id']); ?>"
                            aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                            <?php echo !empty($tab['disabled']) ? 'disabled aria-disabled="true"' : ''; ?>
                            data-traydigita-tab="<?php echo esc_attr($tab['id']); ?>">
                            <?php echo esc_html($tab['label']); ?>
                        </button>
                    <?php endforeach; ?>
                </nav>
            <?php else : ?>
                <ul class="td-settings-nav-list">
                    <?php foreach ($traydigita_nav_items as $index => $item) : ?>
                        <li>
                            <a class="td-settings-nav-link<?php echo $index === 0 ? ' is-active' : ''; ?>"
                                href="<?php echo $item['id'] === 'top' ? '#top' : '#td-section-' . esc_attr($item['id']); ?>"
                                data-td-nav-section="<?php echo esc_attr($item['id']); ?>">
                                <?php if ($item['icon'] !== '') : ?>
                                    <span class="dashicons dashicons-<?php echo esc_attr($item['icon']); ?>" aria-hidden="true"></span>
                                <?php endif; ?>
                                <?php echo esc_html($item['label']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>

    <div class="traydigita-admin-body<?php echo $traydigita_body_class; ?>">
    <div class="traydigita-admin-content" id="top">
        <?php if ($traydigita_is_modules_page) : ?>
            <?php require __DIR__ . '/modules-page.php'; ?>
        <?php elseif ($traydigita_is_hub) : ?>
            <?php require __DIR__ . '/hub-page.php'; ?>
        <?php elseif ($traydigita_setup_page) : ?>
            <?php require __DIR__ . '/setup-page.php'; ?>
        <?php elseif (is_string($traydigita_module_class) && class_exists($traydigita_module_class)) : ?>
            <?php $traydigita_module_class::renderSettingsPage(); ?>
        <?php else : ?>
            <div class="traydigita-admin-empty">
                <span class="traydigita-admin-empty-icon dashicons dashicons-admin-generic" aria-hidden="true"></span>
                <p><?php esc_html_e('This module is not available.', 'traydigita-core'); ?></p>
                <p>
                    <a class="traydigita-admin-button"
                        href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::MENU_SLUG)); ?>">
                        <?php esc_html_e('Back to Modules', 'traydigita-core'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($traydigita_rail_module !== null) : ?>
        <aside class="traydigita-admin-rail" aria-label="<?php echo esc_attr($traydigita_rail_module::name()); ?>"
            data-traydigita-module-info="<?php echo esc_attr((string) wp_json_encode(ModuleRegistry::moduleInfoData($traydigita_rail_module))); ?>">
            <div class="traydigita-admin-rail-card">
                <div class="traydigita-admin-rail-head">
                    <span class="traydigita-admin-rail-icon" aria-hidden="true">
                        <span class="dashicons dashicons-<?php echo esc_attr($traydigita_rail_icon); ?>"></span>
                    </span>
                    <div class="traydigita-admin-rail-titles">
                        <h2 class="traydigita-admin-rail-name">
                            <?php echo esc_html($traydigita_rail_module::name()); ?>
                        </h2>
                        <p class="traydigita-admin-rail-author">
                            <?php
                            /* translators: %s is the module author name. */
                            echo esc_html(
                                sprintf(
                                    __('By %s', 'traydigita-core'),
                                    ModuleRegistry::author($traydigita_rail_module)
                                )
                            );
                            ?>
                        </p>
                    </div>
                </div>
                <p class="traydigita-admin-rail-description">
                    <?php echo esc_html($traydigita_rail_module::description()); ?>
                </p>
                <?php
                // "More" trigger for the module info popup. The
                // description is clamped to three lines by CSS
                // (see assets/css/traydigita-modal.css); the
                // button starts hidden and the modal script
                // reveals it only when the clamped text actually
                // overflows, so short descriptions never get a
                // broken "more".
                ?>
                <button type="button" class="traydigita-admin-rail-more" data-traydigita-rail-more hidden>
                    <?php esc_html_e('More', 'traydigita-core'); ?>
                </button>
                <dl class="traydigita-admin-rail-meta">
                    <div class="traydigita-admin-rail-meta-row">
                        <dt><?php esc_html_e('Status', 'traydigita-core'); ?></dt>
                        <dd>
                            <?php if ($traydigita_rail_module::isCore()) : ?>
                                <?php esc_html_e('Core module', 'traydigita-core'); ?>
                            <?php elseif (ModuleRegistry::isEnabled($traydigita_rail_module::id())) : ?>
                                <?php esc_html_e('Enabled', 'traydigita-core'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Disabled', 'traydigita-core'); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div class="traydigita-admin-rail-meta-row">
                        <dt><?php esc_html_e('Version', 'traydigita-core'); ?></dt>
                        <dd><?php echo esc_html($traydigita_rail_module::version()); ?></dd>
                    </div>
                    <div class="traydigita-admin-rail-meta-row">
                        <dt><?php esc_html_e('Setup', 'traydigita-core'); ?></dt>
                        <dd>
                            <?php if ($traydigita_rail_module::isConfigured()) : ?>
                                <?php esc_html_e('Configured', 'traydigita-core'); ?>
                            <?php else : ?>
                                <?php esc_html_e('Not configured', 'traydigita-core'); ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </aside>
    <?php endif; ?>
    </div>

    <div class="traydigita-admin-toasts" role="status" aria-live="polite"></div>
</div>
