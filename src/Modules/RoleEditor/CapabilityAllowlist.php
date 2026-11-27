<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\RoleEditor;

use function array_keys;
use function in_array;

/**
 * Allowlist of capability names the Role Editor accepts.
 *
 * WordPress core capabilities, grouped for the settings UI, plus a
 * small documented set of common plugin capabilities (WooCommerce
 * and Yoast SEO). A capability name arriving in a save request
 * that is not on this list is rejected and nothing is written:
 * capability names are never accepted as arbitrary strings, so a
 * request cannot invent a capability or smuggle in a typo that
 * silently does nothing.
 *
 * The list is data only (no translated labels here): group labels
 * live in `RoleEditorModule::capabilityGroups()`, where they can
 * go through `__()`.
 *
 * Deliberately excluded:
 * - Legacy `level_0` ... `level_10` capabilities: deprecated since
 *   WordPress 3.0 and no longer used by core.
 * - `manage_links`: the Links Manager is long deprecated.
 * - Meta capabilities (`edit_post`, `edit_user`, ...): they are
 *   mapped by WordPress at runtime from the primitive
 *   capabilities below and must never be granted directly.
 * - Any capability belonging to a plugin that is not listed
 *   here: the list stays small on purpose. A site that needs a
 *   further plugin capability must add it here deliberately,
 *   not by pasting a name into a form.
 */
final class CapabilityAllowlist
{
    /**
     * Capability groups: group key => list of capability names.
     *
     * @var array<string, array<int, string>>
     */
    public const array GROUPS = [
        'general' => [
            'read',
            'manage_options',
        ],
        'posts' => [
            'edit_posts',
            'edit_others_posts',
            'edit_published_posts',
            'edit_private_posts',
            'publish_posts',
            'delete_posts',
            'delete_others_posts',
            'delete_published_posts',
            'delete_private_posts',
            'read_private_posts',
        ],
        'pages' => [
            'edit_pages',
            'edit_others_pages',
            'edit_published_pages',
            'edit_private_pages',
            'publish_pages',
            'delete_pages',
            'delete_others_pages',
            'delete_published_pages',
            'delete_private_pages',
            'read_private_pages',
        ],
        'media' => [
            'upload_files',
        ],
        'comments' => [
            'moderate_comments',
            'edit_comment',
        ],
        'users' => [
            'create_users',
            'delete_users',
            'edit_users',
            'list_users',
            'promote_users',
            'remove_users',
        ],
        'appearance' => [
            'customize',
            'edit_theme_options',
            'switch_themes',
            'edit_themes',
            'install_themes',
            'update_themes',
            'delete_themes',
            'edit_css',
        ],
        'plugins' => [
            'activate_plugins',
            'deactivate_plugins',
            'install_plugins',
            'update_plugins',
            'delete_plugins',
            'edit_plugins',
        ],
        'advanced' => [
            'import',
            'unfiltered_html',
            'unfiltered_upload',
            'update_core',
            'edit_files',
            'manage_network',
            'manage_sites',
            'manage_network_users',
            'manage_network_plugins',
            'manage_network_themes',
            'manage_network_options',
            'upgrade_network',
            'setup_network',
        ],
        'woocommerce' => [
            'manage_woocommerce',
            'view_woocommerce_reports',
            'edit_products',
            'publish_products',
            'delete_products',
            'edit_shop_orders',
            'publish_shop_orders',
            'delete_shop_orders',
            'edit_shop_coupons',
            'publish_shop_coupons',
        ],
        'seo' => [
            'wpseo_manage_options',
            'wpseo_bulk_edit',
        ],
    ];

    /**
     * Every allowlisted capability, keyed by name, with its group.
     *
     * @return array<string, string> Capability name => group key.
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::GROUPS as $group => $caps) {
            foreach ($caps as $cap) {
                $all[$cap] = $group;
            }
        }
        return $all;
    }

    /**
     * Whether a capability name is on the allowlist.
     *
     * @param string $capability Capability name to check.
     * @return bool
     */
    public static function isAllowed(string $capability): bool
    {
        return in_array($capability, array_keys(self::all()), true);
    }

    /**
     * Group key for a capability, or null when it is not allowlisted.
     *
     * @param string $capability Capability name.
     * @return string|null
     */
    public static function groupOf(string $capability): ?string
    {
        return self::all()[$capability] ?? null;
    }
}
