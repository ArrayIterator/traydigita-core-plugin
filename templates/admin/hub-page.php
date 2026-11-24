<?php
/**
 * Settings hub page (the "Settings" submenu under TrayDigita).
 *
 * One entry per ACTIVE module that has a settings page: each entry
 * links to that module's settings page. Disabled modules are not
 * listed here — they live on the Modules page. Core modules link
 * to their settings page, which forwards to the WordPress screen
 * that owns the setting.
 *
 * Included by templates/admin/shell.php; the list reads
 * ModuleRegistry directly.
 *
 * @package TrayDigita Core\Admin
 */
declare(strict_types=1);

use TrayDigita\WP\Plugin\Core\Admin\AdminMenu;
use TrayDigita\WP\Plugin\Core\Admin\AdminPointerTour;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;

if (!defined('ABSPATH')) {
    exit;
}

$traydigita_hub_entries = [];

/**
 * Dashicons slug (without the `dashicons-` prefix) to Material
 * Icons ligature name. Round 4: the admin UI renders Material
 * Icons (self-hosted font), not Dashicons. Keep in sync with
 * DASHICON_TO_MATERIAL in resources/js/modules/admin-ui.ts; an
 * unknown slug falls back to 'extension'.
 *
 * @var array<string, string>
 */
$traydigita_dashicon_to_material = [
    'admin-appearance' => 'palette',
    'admin-comments' => 'forum',
    'admin-customizer' => 'tune',
    'admin-generic' => 'settings',
    'admin-home' => 'home',
    'admin-users' => 'group',
    'bookmark' => 'bookmark',
    'calendar-alt' => 'calendar_month',
    'chart-area' => 'analytics',
    'chart-line' => 'trending_up',
    'database' => 'storage',
    'download' => 'download',
    'edit' => 'edit',
    'edit-page' => 'article',
    'email' => 'mail',
    'email-alt' => 'mail_outline',
    'format-image' => 'image',
    'groups' => 'group',
    'hammer' => 'build',
    'heart' => 'favorite',
    'info' => 'info',
    'layout' => 'view_sidebar',
    'list-view' => 'list',
    'lock' => 'lock',
    'media-document' => 'description',
    'megaphone' => 'campaign',
    'menu' => 'menu',
    'networking' => 'hub',
    'performance' => 'speed',
    'randomize' => 'shuffle',
    'search' => 'search',
    'share-alt' => 'share',
    'shield' => 'shield',
    'smartphone' => 'smartphone',
    'welcome-widgets-menus' => 'widgets',
];

foreach (ModuleRegistry::all() as $moduleClass) {
    $traydigita_id = $moduleClass::id();
    if (!ModuleRegistry::isEnabled($traydigita_id) && !$moduleClass::isCore()) {
        continue;
    }
    $traydigita_url = AdminMenu::moduleUrl($moduleClass);
    $traydigita_note = $moduleClass::isCore() ? __('Core module', 'traydigita-core') : '';
    $traydigita_hub_entries[] = [
        'id' => $traydigita_id,
        'name' => $moduleClass::name(),
        'description' => $moduleClass::description(),
        'icon' => $traydigita_dashicon_to_material[$moduleClass::icon()] ?? 'extension',
        'author' => ModuleRegistry::author($moduleClass),
        'url' => $traydigita_url,
        'note' => $traydigita_note,
        'core' => $moduleClass::isCore(),
    ];
}
?>
<?php
$traydigita_brand_gif = \TrayDigita\WP\Plugin\Core\Brand::logoGif();
$traydigita_brand_svg = $traydigita_brand_gif !== '' ? '' : \TrayDigita\WP\Plugin\Core\Brand::logoSvg();
$traydigita_brand_static = ($traydigita_brand_gif !== '' || $traydigita_brand_svg !== '') ? '' : \TrayDigita\WP\Plugin\Core\Brand::staticLogoUrl();
if ($traydigita_brand_gif !== '' || $traydigita_brand_svg !== '' || $traydigita_brand_static !== '') :
    ?>
    <style>
        .traydigita-admin-about{display:flex;gap:16px;align-items:center;margin-bottom:20px}
        .traydigita-admin-about-logo{flex:0 0 auto;width:72px;height:72px}
        .traydigita-admin-about-logo svg, .traydigita-admin-about-logo video, .traydigita-admin-about-logo img{width:100%;height:100%;display:block}
        .traydigita-admin-about-title{margin:0;font-size:20px;line-height:1.3}
        .traydigita-admin-about-text{margin:4px 0 0;color:#50575e}
    </style>
    <div class="traydigita-admin-about">
        <div class="traydigita-admin-about-logo" aria-hidden="true"><?php
        if ($traydigita_brand_gif !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Brand::logoGif() builds the markup from plugin asset URLs only.
            echo $traydigita_brand_gif;
        } elseif ($traydigita_brand_svg !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Brand::logoSvg() returns the plugin's own SVG asset.
            echo $traydigita_brand_svg;
        } else {
            echo '<img src="' . esc_url($traydigita_brand_static) . '" width="72" height="72" alt="">';
        }
        ?></div>
        <div>
            <h2 class="traydigita-admin-about-title">
                <?php esc_html_e('TrayDigita Core', 'traydigita-core'); ?>
                <span class="traydigita-admin-about-version">
                    <?php echo esc_html(\TrayDigita\WP\Plugin\Core\Core::VERSION); ?>
                </span>
            </h2>
            <p class="traydigita-admin-about-text">
                <?php esc_html_e('The engine behind the TrayDigita theme: modules, mail, security, and performance — one core, everything connected.', 'traydigita-core'); ?>
            </p>
        </div>
    </div>
<?php endif; ?>
<?php if ($traydigita_hub_entries === []) : ?>
    <div class="traydigita-admin-empty">
        <span class="traydigita-admin-empty-icon material-icons" aria-hidden="true">settings</span>
        <p><?php esc_html_e('No active module has settings yet. Enable a module on the Modules page first.', 'traydigita-core'); ?></p>
        <p>
            <a class="traydigita-admin-button traydigita-admin-button-primary"
                href="<?php echo esc_url(admin_url('admin.php?page=' . AdminMenu::MENU_SLUG)); ?>">
                <?php esc_html_e('Go to Modules', 'traydigita-core'); ?>
            </a>
        </p>
    </div>
<?php else : ?>
    <div class="traydigita-admin-hub-actions">
        <a class="traydigita-admin-button"
            data-tour-anchor="admin-palette"
            href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=traydigita_admin_palette')); ?>">
            <?php esc_html_e('Admin palette', 'traydigita-core'); ?>
        </a>
        <a class="traydigita-admin-button"
            data-tour-anchor="mobile-nav"
            href="<?php echo esc_url(admin_url('customize.php?autofocus[section]=traydigita_mobile_nav')); ?>">
            <?php esc_html_e('Mobile bottom navigation', 'traydigita-core'); ?>
        </a>
        <a class="traydigita-admin-button"
            data-tour-anchor="replay-tour"
            href="<?php echo esc_url(AdminPointerTour::replayUrl()); ?>">
            <?php esc_html_e('Replay tour', 'traydigita-core'); ?>
        </a>
    </div>
    <div class="traydigita-admin-hub">
        <?php foreach ($traydigita_hub_entries as $traydigita_entry) : ?>
            <article class="traydigita-admin-card traydigita-admin-hub-card"
                data-tour-anchor="module-<?php echo esc_attr($traydigita_entry['id']); ?>">
                <div class="traydigita-admin-card-head">
                    <span class="traydigita-admin-card-icon material-icons"
                        aria-hidden="true"><?php echo esc_html($traydigita_entry['icon']); ?></span>
                    <div class="traydigita-admin-card-titles">
                        <h2 class="traydigita-admin-card-name"><?php echo esc_html($traydigita_entry['name']); ?></h2>
                        <p class="traydigita-admin-card-version">
                            <?php
                            /* translators: %s is the module author name. */
                            echo esc_html(sprintf(__('By %s', 'traydigita-core'), $traydigita_entry['author']));
                            ?>
                        </p>
                    </div>
                </div>
                <div class="traydigita-admin-card-description-wrap">
                    <p class="traydigita-admin-card-description">
                        <?php echo esc_html($traydigita_entry['description']); ?>
                    </p>
                </div>
                <div class="traydigita-admin-card-foot">
                    <?php if ($traydigita_entry['note'] !== '') : ?>
                        <span class="traydigita-admin-card-note">
                            <?php echo esc_html($traydigita_entry['note']); ?>
                        </span>
                    <?php else : ?>
                        <span></span>
                    <?php endif; ?>
                    <a class="traydigita-admin-button traydigita-admin-button-primary"
                        href="<?php echo esc_url($traydigita_entry['url']); ?>">
                        <?php esc_html_e('Open', 'traydigita-core'); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
