<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function get_current_user_id;
use function is_array;
use function is_numeric;
use function is_string;
use function sanitize_key;
use function sanitize_text_field;
use function sprintf;
use function str_replace;
use function update_user_meta;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * AJAX endpoints for the TrayDigita admin shell.
 *
 * Registration happens in AdminMenu::init() (the single place that
 * owns admin wiring); this class only defines the endpoints.
 *
 * Endpoints (both require the `traydigita-admin` nonce sent as
 * `_ajax_nonce` - check_ajax_referer() with no query arg reads
 * `_ajax_nonce` then `_wpnonce` - plus the `manage_options`
 * capability):
 * - traydigita_admin_toggle_module: POST module id, enabled 0/1.
 * - traydigita_admin_save: POST module id, fields either nested
 *   under `data` or flat; see saveModule().
 * - traydigita_admin_modules_view: POST view ('cards' or 'list');
 *   saves the current user's module-list view preference as user
 *   meta (see MODULES_VIEW_META). The modules page reads it back
 *   on render and passes it to the JS as data-modules-view.
 * - traydigita_admin_modules_list: returns the module list JSON
 *   (ModuleRegistry::moduleListData()): one entry per module -
 *   every existing field plus `category` / `category_label` -
 *   and a top-level `categories` list (slug, label, icon, count,
 *   custom). The modules page renders the same data into its
 *   data-modules / data-categories attributes on first load; the
 *   JS re-fetches this endpoint after a category change instead
 *   of reloading the page.
 * - traydigita_admin_module_category_create: POST slug (optional,
 *   derived from the label when empty), label, icon (a known
 *   Material icon slug, or empty). Creates an admin-managed
 *   custom category in the `traydigita_module_categories`
 *   option; slugs colliding with a built-in category are
 *   rejected.
 * - traydigita_admin_module_category_rename: POST slug, label,
 *   icon. Renames / re-icons a custom category; the slug is
 *   immutable and built-in categories cannot be renamed.
 * - traydigita_admin_module_category_delete: POST slug. Deletes
 *   a custom category and moves its modules back to their base
 *   categories server-side, so no module is ever orphaned.
 * - traydigita_admin_module_assign_category: POST module (id),
 *   category (slug). Assigns one module to a category; the
 *   assignment is stored as an override in the
 *   `traydigita_module_category_overrides` option, or cleared
 *   when the target is the module's own base category.
 *
 * Sanitization contract: the dispatcher deep-sanitizes the incoming
 * data array (recursively sanitize_text_field() on strings, keys via
 * sanitize_key(), non-string scalars kept as-is) and rejects unknown
 * module ids with a 400. The module itself must then sanitize every
 * field for its own type (absint() for ids, esc_url_raw() for URLs,
 * allowlists for enums) inside saveSettings() before storing
 * anything. The dispatcher never returns option values, so secrets
 * such as an SMTP password can never leak through these endpoints.
 */
final class AdminAjax
{
    /**
     * Nonce action shared by every admin endpoint and rendered as a
     * data-nonce attribute on the shell root element.
     */
    public const string NONCE_ACTION = 'traydigita-admin';

    /**
     * User meta key holding the current user's module-list view
     * preference ('cards' or 'list'). A UI preference belongs to
     * the user, not to the site, so it survives reloads and
     * logins without touching any option.
     */
    public const string MODULES_VIEW_META = 'traydigita_modules_view';

    /**
     * Allowed values for the module-list view preference.
     *
     * @var array<int, string>
     */
    public const array MODULES_VIEWS = ['cards', 'list'];

    /**
     * Register the AJAX actions (authenticated admin only).
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('wp_ajax_traydigita_admin_toggle_module', [self::class, 'toggleModule']);
        add_action('wp_ajax_traydigita_admin_save', [self::class, 'saveModule']);
        add_action('wp_ajax_traydigita_admin_modules_view', [self::class, 'saveModulesView']);
        add_action('wp_ajax_traydigita_admin_modules_list', [self::class, 'listModules']);
        add_action('wp_ajax_traydigita_admin_module_category_create', [self::class, 'createModuleCategory']);
        add_action('wp_ajax_traydigita_admin_module_category_rename', [self::class, 'renameModuleCategory']);
        add_action('wp_ajax_traydigita_admin_module_category_delete', [self::class, 'deleteModuleCategory']);
        add_action('wp_ajax_traydigita_admin_module_assign_category', [self::class, 'assignModuleCategory']);
    }

    /**
     * Verify the nonce, then the capability.
     *
     * check_ajax_referer() dies with -1 on a bad nonce; a missing
     * capability is answered with a JSON 403 instead.
     *
     * @return bool True when the request is authorized.
     */
    private static function authorize(): bool
    {
        check_ajax_referer(self::NONCE_ACTION);
        return current_user_can('manage_options');
    }

    /**
     * Read a POST field as a sanitized string.
     *
     * @param string $key Field name.
     * @return string
     */
    private static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return sanitize_text_field(wp_unslash($value));
    }

    /**
     * Recursively deep-sanitize a data array.
     *
     * String values are passed through sanitize_text_field();
     * integer, float and boolean values are kept as-is (the module
     * applies absint() and friends for its own fields); array keys
     * are sanitized with sanitize_key(); anything else (objects,
     * resources) is dropped. The result is safe to hand to a
     * module's saveSettings(), which must still sanitize per field.
     *
     * @param mixed $value Raw input.
     * @return mixed Sanitized value, or null when the value is unusable.
     */
    private static function deepSanitize(mixed $value): mixed
    {
        if (is_string($value)) {
            return sanitize_text_field(wp_unslash($value));
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $cleanKey = sanitize_key((string) $key);
                if ($cleanKey === '') {
                    continue;
                }
                $cleanItem = self::deepSanitize($item);
                if ($cleanItem !== null) {
                    $clean[$cleanKey] = $cleanItem;
                }
            }
            return $clean;
        }
        if (is_numeric($value) || is_bool($value)) {
            return $value;
        }
        return null;
    }

    /**
     * Resolve and validate the posted module id against the registry.
     *
     * Sends a JSON 400 and stops when the id is missing or unknown.
     *
     * @return class-string<ModuleInterface>|null The module class, or null after answering.
     */
    private static function resolveModule(): ?string
    {
        $id = sanitize_key(self::postString('module'));
        if ($id === '') {
            wp_send_json_error(
                ['message' => esc_html__('Unknown module.', 'traydigita-core')],
                400
            );
        }
        $class = ModuleRegistry::get($id);
        if ($class === null) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown module.', 'traydigita-core')],
                400
            );
        }
        return $class;
    }

    /**
     * Enable or disable one module.
     *
     * POST: module (id), enabled ('1' or '0').
     *
     * @return void
     */
    public static function toggleModule(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $class = self::resolveModule();
        if ($class === null) {
            return;
        }
        if (ModuleRegistry::isCore($class::id())) {
            wp_send_json_error(
                ['message' => esc_html__(
                    'Core modules cannot be disabled.',
                    'traydigita-core'
                )],
                400
            );
        }
        $enabled = self::postString('enabled') === '1';
        if (!ModuleRegistry::setEnabled($class::id(), $enabled)) {
            wp_send_json_error(
                ['message' => esc_html__('Could not update the module.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'message' => $enabled
                ? esc_html__('Module enabled', 'traydigita-core')
                : esc_html__('Module disabled', 'traydigita-core'),
            'enabled' => $enabled,
            'module' => $class::id(),
        ]);
    }

    /**
     * Save the current user's module-list view preference.
     *
     * POST: view ('cards' or 'list'). The value is validated
     * against the MODULES_VIEWS allowlist and stored as user meta
     * for the current user only, so the choice survives reloads
     * and logins. Unknown values are rejected with a 400.
     *
     * @return void
     */
    public static function saveModulesView(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $view = self::postString('view');
        if (!in_array($view, self::MODULES_VIEWS, true)) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown view.', 'traydigita-core')],
                400
            );
        }
        update_user_meta(get_current_user_id(), self::MODULES_VIEW_META, $view);
        wp_send_json_success([
            'message' => esc_html__('View saved.', 'traydigita-core'),
            'view' => $view,
        ]);
    }

    /**
     * Return the module list JSON (modules + categories).
     *
     * No POST fields. The response is
     * ModuleRegistry::moduleListData(): `modules` (one entry per
     * module, every existing field plus `category` and
     * `category_label`) and top-level `categories` (slug, label,
     * icon, count, custom). The modules page renders the same
     * data on first load; the JS calls this after a category
     * create / rename / delete / assign so the filter UI and
     * the cards refresh without a page reload.
     *
     * @return void
     */
    public static function listModules(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        wp_send_json_success(ModuleRegistry::moduleListData());
    }

    /**
     * Create an admin-managed custom category.
     *
     * POST: slug (optional - derived from the label when empty),
     * label (required), icon (a known Material icon slug, or
     * empty for the default 'extension' icon). Slugs colliding
     * with a built-in category slug or an existing custom slug
     * are rejected with a 400. On success the response carries
     * the new category and the fresh categories list.
     *
     * @return void
     */
    public static function createModuleCategory(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $label = self::postString('label');
        if ($label === '') {
            wp_send_json_error(
                ['message' => esc_html__('Category label is required.', 'traydigita-core')],
                400
            );
        }
        $slug = sanitize_key(self::postString('slug'));
        if ($slug === '') {
            $slug = sanitize_key(str_replace(' ', '-', $label));
        }
        if ($slug === '') {
            wp_send_json_error(
                ['message' => esc_html__('Category slug is required.', 'traydigita-core')],
                400
            );
        }
        $icon = sanitize_key(self::postString('icon'));
        if (!ModuleRegistry::isValidCategoryIcon($icon)) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown icon.', 'traydigita-core')],
                400
            );
        }
        $defaults = ModuleRegistry::defaultCategories();
        if (isset($defaults[$slug])) {
            wp_send_json_error(
                ['message' => esc_html__('A built-in category already uses that slug.', 'traydigita-core')],
                400
            );
        }
        if (!ModuleRegistry::createCustomCategory($slug, $label, $icon)) {
            wp_send_json_error(
                ['message' => esc_html__('That category already exists.', 'traydigita-core')],
                400
            );
        }
        $created = ModuleRegistry::customCategories()[$slug];
        wp_send_json_success([
            'message' => esc_html__('Category created.', 'traydigita-core'),
            'category' => [
                'slug' => $slug,
                'label' => $created['label'],
                'icon' => $created['icon'],
                'count' => 0,
                'custom' => true,
            ],
            'categories' => ModuleRegistry::categories(),
        ]);
    }

    /**
     * Rename (relabel / re-icon) a custom category.
     *
     * POST: slug, label (required), icon (a known Material icon
     * slug, or empty). The slug itself is immutable, and
     * built-in categories cannot be renamed - their labels live
     * in ModuleRegistry::defaultCategories(). On success the
     * response carries the fresh categories list.
     *
     * @return void
     */
    public static function renameModuleCategory(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $slug = sanitize_key(self::postString('slug'));
        $label = self::postString('label');
        $defaults = ModuleRegistry::defaultCategories();
        if (isset($defaults[$slug])) {
            wp_send_json_error(
                ['message' => esc_html__('Built-in categories cannot be renamed.', 'traydigita-core')],
                400
            );
        }
        if (!isset(ModuleRegistry::customCategories()[$slug])) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown category.', 'traydigita-core')],
                400
            );
        }
        if ($label === '') {
            wp_send_json_error(
                ['message' => esc_html__('Category label is required.', 'traydigita-core')],
                400
            );
        }
        $icon = sanitize_key(self::postString('icon'));
        if (!ModuleRegistry::isValidCategoryIcon($icon)) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown icon.', 'traydigita-core')],
                400
            );
        }
        if (!ModuleRegistry::renameCustomCategory($slug, $label, $icon)) {
            wp_send_json_error(
                ['message' => esc_html__('Could not save the category.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'message' => esc_html__('Category renamed.', 'traydigita-core'),
            'categories' => ModuleRegistry::categories(),
        ]);
    }

    /**
     * Delete a custom category.
     *
     * POST: slug. Only admin-created custom categories can be
     * deleted; built-in categories are rejected with a 400.
     * ModuleRegistry::deleteCustomCategory() removes the stored
     * overrides pointing at the deleted category, so every
     * affected module falls back to its base category
     * server-side - the response reports how many modules
     * moved. The response also carries the fresh categories
     * list.
     *
     * @return void
     */
    public static function deleteModuleCategory(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $slug = sanitize_key(self::postString('slug'));
        $defaults = ModuleRegistry::defaultCategories();
        if (isset($defaults[$slug])) {
            wp_send_json_error(
                ['message' => esc_html__('Built-in categories cannot be deleted.', 'traydigita-core')],
                400
            );
        }
        if (!isset(ModuleRegistry::customCategories()[$slug])) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown category.', 'traydigita-core')],
                400
            );
        }
        $moved = ModuleRegistry::deleteCustomCategory($slug);
        if ($moved < 0) {
            wp_send_json_error(
                ['message' => esc_html__('Could not delete the category.', 'traydigita-core')],
                500
            );
        }
        wp_send_json_success([
            'message' => esc_html__('Category deleted.', 'traydigita-core'),
            'moved' => $moved,
            'categories' => ModuleRegistry::categories(),
        ]);
    }

    /**
     * Assign one module to a category.
     *
     * POST: module (id), category (slug). The category must
     * exist in the final list (built-in, custom, or
     * filter-added); assigning the module's own base category
     * clears any stored override instead of writing a
     * redundant one. On success the response carries the
     * module's resolved category and the fresh categories list.
     *
     * @return void
     */
    public static function assignModuleCategory(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $class = self::resolveModule();
        if ($class === null) {
            return;
        }
        $slug = sanitize_key(self::postString('category'));
        if (!ModuleRegistry::assignModuleToCategory($class::id(), $slug)) {
            wp_send_json_error(
                ['message' => esc_html__('Unknown category.', 'traydigita-core')],
                400
            );
        }
        wp_send_json_success([
            'message' => esc_html__('Module assigned to category.', 'traydigita-core'),
            'module' => $class::id(),
            'category' => ModuleRegistry::categoryOf($class),
            'category_label' => ModuleRegistry::categoryLabel(ModuleRegistry::categoryOf($class)),
            'categories' => ModuleRegistry::categories(),
        ]);
    }

    /**
     * Save one module's settings.
     *
     * POST: module (id), plus the fields either as a nested `data`
     * array (data[field]=...) or as flat top-level fields
     * (field=...). Both shapes are accepted because the shipped
     * module forms use flat field names; when both are present the
     * nested `data` array wins for a given key. The fields are
     * deep-sanitized here; per-field sanitization and storage are
     * the module's job inside saveSettings(). Only the module's own
     * returned message (plus its optional safe `data` and field
     * `errors`) is sent back - never raw option values.
     *
     * @return void
     */
    public static function saveModule(): void
    {
        if (!self::authorize()) {
            wp_send_json_error(
                ['message' => esc_html__('You are not allowed to do that.', 'traydigita-core')],
                403
            );
        }
        $class = self::resolveModule();
        if ($class === null) {
            return;
        }
        $data = [];
        $nested = $_POST['data'] ?? [];
        if (is_array($nested)) {
            $sanitized = self::deepSanitize($nested);
            if (is_array($sanitized)) {
                $data = $sanitized;
            }
        }
        $reserved = ['action', 'module', '_ajax_nonce', '_wpnonce', '_wp_http_referer'];
        foreach ($_POST as $key => $value) {
            $field = sanitize_key((string) $key);
            if ($field === '' || in_array($field, $reserved, true)) {
                continue;
            }
            if ($field === 'data') {
                continue;
            }
            if (array_key_exists($field, $data)) {
                continue;
            }
            $clean = self::deepSanitize($value);
            if ($clean !== null) {
                $data[$field] = $clean;
            }
        }
        $result = $class::saveSettings($data);
        $success = !empty($result['success']);
        if (class_exists(ActivityLogModule::class)) {
            ActivityLogModule::record(
                $class::id(),
                $success ? 'settings-saved' : 'settings-save-failed',
                sprintf("Settings %s for module '%s'.", $success ? 'saved' : 'could not be saved', $class::id())
            );
        }
        $message = isset($result['message']) && is_string($result['message']) && $result['message'] !== ''
            ? $result['message']
            : ($success
                ? esc_html__('Settings saved.', 'traydigita-core')
                : esc_html__('Could not save the settings.', 'traydigita-core'));
        $payload = ['message' => $message];
        if (isset($result['data']) && is_array($result['data'])) {
            $payload['data'] = $result['data'];
        }
        if (isset($result['errors']) && is_array($result['errors'])) {
            $errors = [];
            foreach ($result['errors'] as $field => $error) {
                if (is_string($error) && $error !== '') {
                    $errors[sanitize_key((string) $field)] = $error;
                }
            }
            if ($errors !== []) {
                $payload['errors'] = $errors;
            }
        }
        if ($success) {
            wp_send_json_success($payload);
        }
        wp_send_json_error($payload, 400);
    }
}
