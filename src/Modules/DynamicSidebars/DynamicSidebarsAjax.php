<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\DynamicSidebars;

use TrayDigita\WP\Plugin\Core\Admin\AdminAjax;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function is_array;
use function is_string;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the Dynamic Sidebars module.
 *
 * Two actions, both admin-only (`manage_options`) behind the
 * shared `traydigita-admin` nonce (`_ajax_nonce`, the field
 * check_ajax_referer() reads):
 * - `traydigita_dynamic_sidebars_save`: create or update one
 *   sidebar from the edit form. POST fields: `sidebar_id` (empty
 *   when creating), `name`, `description`, `area`, `priority`,
 *   `category`, `tag`, `author`, `post`. Values are only
 *   unslashed here; all sanitizing and validation lives in
 *   DynamicSidebarsModule::saveSidebar().
 * - `traydigita_dynamic_sidebars_delete`: delete one sidebar by
 *   `sidebar_id`. Only the definition is removed; WordPress keeps
 *   the widget instances (see DynamicSidebarsModule::deleteSidebar()).
 *
 * Both endpoints refuse to run while the module is disabled.
 */
final class DynamicSidebarsAjax
{
    /**
     * AJAX action saving one sidebar.
     */
    public const string AJAX_SAVE = 'traydigita_dynamic_sidebars_save';

    /**
     * AJAX action deleting one sidebar.
     */
    public const string AJAX_DELETE = 'traydigita_dynamic_sidebars_delete';

    /**
     * Register the AJAX actions.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_SAVE, [self::class, 'saveSidebar']);
        add_action('wp_ajax_' . self::AJAX_DELETE, [self::class, 'deleteSidebar']);
    }

    /**
     * Create or update one sidebar from the edit form.
     *
     * @return void
     */
    public static function saveSidebar(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        if (!self::isModuleEnabled()) {
            wp_send_json_error(
                ['message' => esc_html__('The Dynamic Sidebars module is disabled.', 'traydigita-core')],
                403
            );
        }
        $data = self::postData();
        $result = DynamicSidebarsModule::saveSidebar($data);
        if ($result['success']) {
            $payload = ['message' => $result['message']];
            if (isset($result['id'])) {
                $payload['id'] = $result['id'];
            }
            wp_send_json_success($payload);
        }
        $payload = ['message' => $result['message']];
        if (isset($result['errors'])) {
            $payload['errors'] = $result['errors'];
        }
        wp_send_json_error($payload, 400);
    }

    /**
     * Delete one sidebar by id.
     *
     * @return void
     */
    public static function deleteSidebar(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        if (!self::isModuleEnabled()) {
            wp_send_json_error(
                ['message' => esc_html__('The Dynamic Sidebars module is disabled.', 'traydigita-core')],
                403
            );
        }
        $id = isset($_POST['sidebar_id']) && is_string($_POST['sidebar_id'])
            ? wp_unslash($_POST['sidebar_id'])
            : '';
        $result = DynamicSidebarsModule::deleteSidebar($id);
        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        }
        wp_send_json_error(['message' => $result['message']], 400);
    }

    /**
     * Whether the module is enabled right now.
     *
     * Small wrapper around the registry, used by the AJAX
     * endpoints so they refuse to run while disabled.
     *
     * @return bool
     */
    private static function isModuleEnabled(): bool
    {
        return ModuleRegistry::isEnabled(DynamicSidebarsModule::id());
    }

    /**
     * Read the sidebar form fields from POST, unslashed only.
     *
     * Sanitizing and validation happen in
     * DynamicSidebarsModule::saveSidebar().
     *
     * @return array<string, mixed>
     */
    private static function postData(): array
    {
        $keys = ['sidebar_id', 'name', 'description', 'area', 'priority', 'category', 'tag', 'author', 'post'];
        $data = [];
        foreach ($keys as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = wp_unslash($_POST[$key]);
            if (is_string($value) || is_array($value)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }
}
