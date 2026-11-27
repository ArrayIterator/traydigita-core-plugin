<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Redirects;

use TrayDigita\WP\Plugin\Core\Admin\ModuleInterface;
use TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls;
use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use function __;
use function _n;
use function add_action;
use function checked;
use function count;
use function current_user_can;
use function date_i18n;
use function esc_attr;
use function esc_attr__;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function esc_url_raw;
use function get_option;
use function home_url;
use function is_404;
use function is_admin;
use function is_string;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function wp_nonce_field;
use function wp_parse_url;
use function wp_redirect;
use function wp_unslash;

/**
 * Redirect Manager module.
 *
 * Keeps old URLs working after a migration or a slug change, and
 * shows which missing URLs visitors actually hit so broken links
 * can be fixed instead of guessed at:
 *
 * - Redirect rules (from-path to target URL, status 301/302/307/
 *   308) with wildcard support: `/old/*` redirects to `/new/*`
 *   with the remainder carried over. Exact matches win over
 *   wildcards; among wildcards the longest prefix wins. Rules
 *   are matched on `template_redirect` (early priority) and
 *   never fire for admin, AJAX, REST, or login requests, and
 *   never redirect a URL to itself (loop protection).
 * - 404 monitor: missing paths are logged (path, hit count,
 *   last seen, referrer) with static-asset 404s (.css, .js,
 *   images, fonts) skipped, capped at 200 entries with the
 *   oldest dropped. Each logged 404 offers a one-click
 *   "create redirect" prefill.
 * - Import/export of the rules as JSON (merge by default,
 *   overwrite only with an explicit checkbox).
 *
 * Everything lives in the single option `traydigita_redirects`
 * (autoload `no`), managed through RedirectStore. The settings
 * form is rendered inline here (no template partial), following
 * SeoModule; the rule list is managed through the module's own
 * AJAX endpoints (RedirectsAjax) with the shared
 * `traydigita-admin` nonce and the `manage_options` capability.
 */
final class RedirectsModule implements ModuleInterface
{
    /**
     * Option name is owned by RedirectStore.
     */
    public const string OPTION = RedirectStore::OPTION;

    /**
     * Nonce action for the settings form.
     */
    public const string SETTINGS_NONCE_ACTION = 'traydigita_redirects_save';

    /**
     * Module id.
     *
     * @return string
     */
    public static function id(): string
    {
        return 'redirects';
    }

    /**
     * Default category slug for the module list.
     *
     * Optional: ModuleRegistry::categoryOf() falls back to the
     * 'administration' category when a module does not declare
     * one, so a card is never uncategorised.
     *
     * @return string
     */
    public static function category(): string
    {
        return 'seo-discovery';
    }

    /**
     * Module display name.
     *
     * @return string
     */
    public static function name(): string
    {
        return __('Redirect Manager', 'traydigita-core');
    }

    /**
     * Module description.
     *
     * @return string
     */
    public static function description(): string
    {
        return __('Keep old URLs working after migrations and slug changes: redirect rules with wildcard support, a 404 monitor that shows which missing URLs visitors actually hit, and JSON import/export of the rules.', 'traydigita-core');
    }

    /**
     * Module icon (dashicons slug, without the `dashicons-` prefix).
     *
     * @return string
     */
    public static function icon(): string
    {
        return 'randomize';
    }

    /**
     * Module version.
     *
     * @return string
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Short feature list shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::features()
     * when present; a module without it falls back to
     * its description in the popup.
     *
     * @return array<int, string>
     */
    public static function features(): array
    {
        return [
            __('Redirect rules with wildcard support.', 'traydigita-core'),
            __('404 monitor showing which missing URLs visitors actually hit.', 'traydigita-core'),
            __('JSON import/export of rules.', 'traydigita-core'),
        ];
    }

    /**
     * Changelog shown in the module info popup.
     *
     * Optional: resolved by ModuleRegistry::changelog()
     * when present; a module without it shows a single
     * entry built from version(). Only recorded history
     * belongs here - never invent earlier versions.
     *
     * @return array<int, array{version: string, changes: array<int, string>}>
     */
    public static function changelog(): array
    {
        return [
            [
                'version' => '1.0.0',
                'changes' => [
                    __('Initial release in TrayDigita Core.', 'traydigita-core'),
                ],
            ],
        ];
    }

    /**
     * Admin settings page slug.
     *
     * @return string
     */
    public static function settingsPageSlug(): string
    {
        return 'traydigita-redirects';
    }

    /**
     * Whether the module can run (no hard dependencies).
     *
     * @return bool Always true.
     */
    public static function isAvailable(): bool
    {
        return true;
    }

    /**
     * Whether the module is configured enough to do its job.
     *
     * Configured means 404 logging is on or at least one rule
     * exists; with logging off and no rules the module does
     * nothing on the frontend.
     *
     * @return bool
     */
    public static function isConfigured(): bool
    {
        $store = RedirectStore::get();
        return $store['log_404s'] || count($store['rules']) > 0;
    }

    /**
     * Whether this is a core module.
     *
     * False: the module is optional and toggleable.
     *
     * @return bool
     */
    public static function isCore(): bool
    {
        return false;
    }

    /**
     * Render the settings page inside the admin shell content area.
     *
     * Sections: an add/edit rule form, the rule list (each row
     * with edit and delete actions), the 404-logging toggle
     * (saved through the generic dispatcher), the top-404 table
     * with one-click "create redirect" prefills, and import/
     * export. The rule list, the log, and import/export are
     * handled by resources/js/modules/admin-redirects.ts through
     * the module's own AJAX endpoints; the settings form is
     * submitted by the shell's generic handler.
     *
     * @return void
     */
    public static function renderSettingsPage(): void
    {
        $store = RedirectStore::get();
        $rules = $store['rules'];
        $top404s = RedirectStore::top404s($store, 20);
        $ruleCount = count($rules);
        $logCount = count($store['log']);
        $dateFormat = (string) get_option('date_format', 'Y-m-d') . ' ' . (string) get_option('time_format', 'H:i');
        ?>
        <div
            class="traydigita-module-settings redirects-settings"
            data-redirects-admin
            data-msg-rule-saved="<?php echo esc_attr__('Redirect saved.', 'traydigita-core'); ?>"
            data-msg-rule-deleted="<?php echo esc_attr__('Redirect deleted.', 'traydigita-core'); ?>"
            data-msg-rule-failed="<?php echo esc_attr__('Could not save the redirect. Check the source and target and try again.', 'traydigita-core'); ?>"
            data-msg-delete-failed="<?php echo esc_attr__('Could not delete the redirect.', 'traydigita-core'); ?>"
            data-msg-confirm-delete="<?php echo esc_attr__('Delete this redirect?', 'traydigita-core'); ?>"
            data-msg-confirm-clear-log="<?php echo esc_attr__('Clear the whole 404 log? This cannot be undone.', 'traydigita-core'); ?>"
            data-msg-log-cleared="<?php echo esc_attr__('404 log cleared.', 'traydigita-core'); ?>"
            data-msg-clear-failed="<?php echo esc_attr__('Could not clear the 404 log.', 'traydigita-core'); ?>"
            data-msg-export-failed="<?php echo esc_attr__('Could not export the redirects.', 'traydigita-core'); ?>"
            data-msg-import-done="<?php echo esc_attr__('Import finished.', 'traydigita-core'); ?>"
            data-msg-import-failed="<?php echo esc_attr__('Could not import the file. It must be a TrayDigita redirect export in JSON format.', 'traydigita-core'); ?>"
            data-msg-import-read-failed="<?php echo esc_attr__('Could not read that file.', 'traydigita-core'); ?>"
            data-msg-no-rules="<?php echo esc_attr__('No redirects yet. Add one below.', 'traydigita-core'); ?>"
            data-msg-no-404s="<?php echo esc_attr__('No 404s logged yet.', 'traydigita-core'); ?>"
            data-msg-edit="<?php echo esc_attr__('Edit', 'traydigita-core'); ?>"
            data-msg-delete="<?php echo esc_attr__('Delete', 'traydigita-core'); ?>"
            data-msg-add-redirect="<?php echo esc_attr__('Add redirect', 'traydigita-core'); ?>"
            data-msg-save-redirect="<?php echo esc_attr__('Save changes', 'traydigita-core'); ?>"
            data-msg-cancel-edit="<?php echo esc_attr__('Cancel', 'traydigita-core'); ?>"
            data-msg-imported="<?php echo esc_attr__('imported', 'traydigita-core'); ?>"
            data-msg-skipped="<?php echo esc_attr__('skipped', 'traydigita-core'); ?>"
            data-msg-duplicates="<?php echo esc_attr__('already existed', 'traydigita-core'); ?>"
        >
            <?php
            SettingsControls::intro(
                __('Redirects run before the page loads, so a moved article or a renamed category '
                    . 'keeps working for visitors and search engines.', 'traydigita-core'),
                __('Redirects never apply to the admin area, AJAX, REST, or the login page, and a redirect that points at the page it came from is ignored instead of looping.', 'traydigita-core')
            );
            ?>

            <p class="redirects-stats">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: number of redirect rules, 2: number of logged 404 paths. */
                        __('%1$s, %2$s.', 'traydigita-core'),
                        sprintf(
                            /* translators: %d is the number of redirect rules. */
                            _n('%d redirect rule', '%d redirect rules', $ruleCount, 'traydigita-core'),
                            $ruleCount
                        ),
                        sprintf(
                            /* translators: %d is the number of logged 404 paths. */
                            _n('%d logged 404 path', '%d logged 404 paths', $logCount, 'traydigita-core'),
                            $logCount
                        )
                    )
                );
                ?>
            </p>

            <p class="redirects-status" data-redirects-status role="status" aria-live="polite"></p>

            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Add redirect', 'traydigita-core'); ?></h2>
            <form class="redirects-rule-form" data-redirects-rule-form method="post" action="">
                <input type="hidden" name="edit_index" value="" data-redirects-edit-index>
                <p class="redirects-field">
                    <label for="redirects-from"><?php esc_html_e('Source path', 'traydigita-core'); ?></label>
                    <input type="text" id="redirects-from" name="from" value="" placeholder="/old-article" data-redirects-from required>
                    <span class="redirects-field-help">
                        <?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('A site path starting with /. End with /* to match everything '
                                    . 'below it, e.g. /old-category/*.', 'traydigita-core'),
                                __('The part after the prefix is carried into the target.', 'traydigita-core')
                            );
                        ?>
                    </span>
                </p>
                <p class="redirects-field">
                    <label for="redirects-to"><?php esc_html_e('Target', 'traydigita-core'); ?></label>
                    <input type="text" id="redirects-to" name="to" value="" placeholder="/new-article" data-redirects-to required>
                    <span class="redirects-field-help">
                        <?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('A site path starting with /, or a full URL such as '
                                    . 'https://example.com/new-article.', 'traydigita-core'),
                                __('With a wildcard source, use * in the target to place the captured part, e.g. /new-category/*. Without a * the remainder is appended to the target.', 'traydigita-core')
                            );
                        ?>
                    </span>
                </p>
                <p class="redirects-field">
                    <label for="redirects-status"><?php esc_html_e('Status code', 'traydigita-core'); ?></label>
                    <select id="redirects-status" name="status" data-redirects-status-select>
                        <option value="301" selected><?php esc_html_e('301 — Permanent (moved for good)', 'traydigita-core'); ?></option>
                        <option value="302"><?php esc_html_e('302 — Found (temporary)', 'traydigita-core'); ?></option>
                        <option value="307"><?php esc_html_e('307 — Temporary redirect (keeps the request method)', 'traydigita-core'); ?></option>
                        <option value="308"><?php esc_html_e('308 — Permanent redirect (keeps the request method)', 'traydigita-core'); ?></option>
                    </select>
                    <span class="redirects-field-help">
                        <?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('Use 301 for a permanent move (search engines pass the '
                                    . 'old ranking along).', 'traydigita-core'),
                                __('Use 302 or 307 for something temporary, and 308 when a permanent move must keep POST requests as POSTs.', 'traydigita-core')
                            );
                        ?>
                    </span>
                </p>
                <p class="redirects-form-actions">
                    <button type="submit" class="traydigita-admin-button traydigita-admin-button-primary" data-redirects-submit>
                        <?php esc_html_e('Add redirect', 'traydigita-core'); ?>
                    </button>
                    <button type="button" class="traydigita-admin-button" data-redirects-cancel-edit hidden>
                        <?php esc_html_e('Cancel', 'traydigita-core'); ?>
                    </button>
                </p>
            </form>

            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Redirect rules', 'traydigita-core'); ?></h2>
            <table class="widefat fixed striped redirects-rules-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Source', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Target', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody data-redirects-rules-body>
                    <?php if ($rules === []) : ?>
                        <tr data-redirects-empty-row>
                            <td colspan="4"><?php esc_html_e('No redirects yet. Add one above.', 'traydigita-core'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rules as $index => $rule) : ?>
                            <tr>
                                <td><code><?php echo esc_html($rule['from']); ?></code></td>
                                <td><code><?php echo esc_html($rule['to']); ?></code></td>
                                <td><code><?php echo esc_html((string) $rule['status']); ?></code></td>
                                <td>
                                    <button type="button" class="traydigita-admin-button traydigita-admin-button-small" data-redirects-edit="<?php echo esc_attr((string) $index); ?>">
                                        <?php esc_html_e('Edit', 'traydigita-core'); ?>
                                    </button>
                                    <button type="button" class="traydigita-admin-button traydigita-admin-button-small" data-redirects-delete="<?php echo esc_attr((string) $index); ?>">
                                        <?php esc_html_e('Delete', 'traydigita-core'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Settings', 'traydigita-core'); ?></h2>
            <form class="redirects-settings-form" data-traydigita-module-form data-traydigita-module="redirects" data-module="redirects" method="post" action="">
                <?php wp_nonce_field(self::SETTINGS_NONCE_ACTION); ?>
                <p class="redirects-field">
                    <label class="traydigita-ui-toggle" for="redirects-log-404s"><input class="traydigita-ui-toggle-input" type="checkbox" id="redirects-log-404s" name="log_404s" value="1" <?php checked($store['log_404s']); ?>><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Log 404 (page not found) hits', 'traydigita-core'); ?></span></label>
                    <span class="redirects-field-help">
                        <?php
                            \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                                __('Records the missing path, how often it was hit, when it was '
                                    . 'last seen, and the referrer.', 'traydigita-core'),
                                __('Missing static files (stylesheets, scripts, images, fonts) are never logged, and the log keeps at most 200 paths.', 'traydigita-core')
                            );
                        ?>
                    </span>
                </p>
                <?php SettingsControls::saveBar(); ?>
            </form>

            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Most-hit 404s', 'traydigita-core'); ?></h2>
            <p class="redirects-field-help">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('The missing URLs visitors hit most often.', 'traydigita-core'),
                        __('Create a redirect for one to send its traffic (and its search ranking) to the right page.', 'traydigita-core')
                    );
                ?>
            </p>
            <table class="widefat fixed striped redirects-404-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Missing path', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Hits', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Last seen', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Referrer', 'traydigita-core'); ?></th>
                        <th scope="col"><?php esc_html_e('Actions', 'traydigita-core'); ?></th>
                    </tr>
                </thead>
                <tbody data-redirects-404-body>
                    <?php if ($top404s === []) : ?>
                        <tr data-redirects-404-empty-row>
                            <td colspan="5"><?php esc_html_e('No 404s logged yet.', 'traydigita-core'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($top404s as $entry) : ?>
                            <tr>
                                <td><code><?php echo esc_html($entry['path']); ?></code></td>
                                <td><?php echo esc_html((string) $entry['count']); ?></td>
                                <td><?php echo esc_html(date_i18n($dateFormat, $entry['last_seen'])); ?></td>
                                <td>
                                    <?php if ($entry['referrer'] !== '') : ?>
                                        <a href="<?php echo esc_url($entry['referrer']); ?>"><?php echo esc_html(self::shortenUrl($entry['referrer'])); ?></a>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="traydigita-admin-button traydigita-admin-button-small" data-redirects-prefill="<?php echo esc_attr($entry['path']); ?>">
                                        <?php esc_html_e('Create redirect', 'traydigita-core'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="redirects-form-actions">
                <button type="button" class="traydigita-admin-button" data-redirects-clear-log>
                    <?php esc_html_e('Clear 404 log', 'traydigita-core'); ?>
                </button>
            </p>

            <h2 class="traydigita-ui-section-title"><?php esc_html_e('Import / export', 'traydigita-core'); ?></h2>
            <p class="redirects-field-help">
                <?php
                    \TrayDigita\WP\Plugin\Core\Admin\Settings\SettingsControls::helpText(
                        __('Export downloads the redirect rules as a JSON file.', 'traydigita-core'),
                        __('Import reads such a file back: by default it merges (rules for a source path that already exists are skipped); tick the box to replace all rules instead.', 'traydigita-core')
                    );
                ?>
            </p>
            <p class="redirects-form-actions">
                <button type="button" class="traydigita-admin-button" data-redirects-export>
                    <?php esc_html_e('Export redirects (JSON)', 'traydigita-core'); ?>
                </button>
            </p>
            <p class="redirects-field">
                <label for="redirects-import-file"><?php esc_html_e('Import file', 'traydigita-core'); ?></label>
                <input type="file" id="redirects-import-file" accept="application/json,.json" data-redirects-import-file>
            </p>
            <p class="redirects-field">
                <label class="traydigita-ui-toggle" for="redirects-import-overwrite"><input class="traydigita-ui-toggle-input" type="checkbox" id="redirects-import-overwrite" data-redirects-import-overwrite><span class="traydigita-ui-toggle-track" aria-hidden="true"><span class="traydigita-ui-toggle-thumb" aria-hidden="true"></span></span><span class="traydigita-ui-toggle-label"><?php esc_html_e('Replace all existing rules with the imported ones', 'traydigita-core'); ?></span></label>
            </p>
            <p class="redirects-form-actions">
                <button type="button" class="traydigita-admin-button" data-redirects-import>
                    <?php esc_html_e('Import redirects', 'traydigita-core'); ?>
                </button>
            </p>
        </div>
        <?php
    }

    /**
     * Validate and save settings from a submitted form.
     *
     * Expected flat key in `$data`: `log_404s` (`1` when checked;
     * an absent key means the checkbox was unchecked). Rules and
     * the 404 log are managed through RedirectsAjax and are
     * preserved untouched here.
     *
     * @param array<string, mixed> $data Sanitized form data.
     * @return array{success: bool, message: string}
     */
    public static function saveSettings(array $data): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => __('You are not allowed to change redirect settings.', 'traydigita-core'),
            ];
        }
        $store = RedirectStore::get();
        $store['log_404s'] = !empty($data['log_404s']);
        RedirectStore::save($store);
        return [
            'success' => true,
            'message' => __('Redirect settings saved.', 'traydigita-core'),
        ];
    }

    /**
     * Register the frontend hooks. Called only when the module is enabled.
     *
     * Guards with ModuleRegistry::isEnabled() so a direct call is
     * safe too. Redirects run on `template_redirect` at priority
     * 1 (before anything that renders), the 404 log at priority
     * 20 (after the redirect had its chance, so a redirected URL
     * is never logged as a 404).
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!ModuleRegistry::isEnabled(self::id())) {
            return;
        }
        RedirectsAjax::register();
        add_action('template_redirect', [self::class, 'maybeRedirect'], 1);
        add_action('template_redirect', [self::class, 'maybeLog404'], 20);
    }

    /**
     * Redirect the request when a rule matches.
     *
     * Hooked on `template_redirect` at priority 1. Skipped in
     * wp-admin, during AJAX requests, for REST requests, and on
     * wp-login.php, and for protected paths (see
     * RedirectStore::shouldSkipPath()). When the target resolves
     * to the requested URL itself the redirect is skipped
     * (loop protection) instead of bouncing the visitor. The
     * request query string is carried over when the target has
     * no query string of its own.
     *
     * @return void
     */
    public static function maybeRedirect(): void
    {
        if (is_admin() || self::isAjaxRequest() || self::isRestRequest() || self::isLoginPage()) {
            return;
        }
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = RedirectStore::normalizePath((string) wp_parse_url($requestUri, PHP_URL_PATH));
        if (RedirectStore::shouldSkipPath($path)) {
            return;
        }
        $store = RedirectStore::get();
        if ($store['rules'] === []) {
            return;
        }
        $matched = RedirectStore::match($store['rules'], $path);
        if ($matched === null) {
            return;
        }
        $homeHost = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (RedirectStore::isLoop($path, $matched['target'], $homeHost)) {
            return;
        }
        $target = $matched['target'];
        if (!str_starts_with($target, 'http://') && !str_starts_with($target, 'https://')) {
            $requestQuery = wp_parse_url($requestUri, PHP_URL_QUERY);
            if (is_string($requestQuery) && $requestQuery !== '' && !str_contains($target, '?')) {
                $target .= '?' . $requestQuery;
            }
            $target = home_url($target);
        }
        wp_redirect(esc_url_raw($target), $matched['rule']['status']);
        exit;
    }

    /**
     * Log a 404 hit.
     *
     * Hooked on `template_redirect` at priority 20, so a request
     * a redirect already handled never reaches the log. Only
     * logs when 404 logging is enabled, the path is not a
     * protected path, and the path is not a static asset. One
     * option read and one option write per logged 404.
     *
     * @return void
     */
    public static function maybeLog404(): void
    {
        if (!is_404()) {
            return;
        }
        $store = RedirectStore::get();
        if (!$store['log_404s']) {
            return;
        }
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        $path = RedirectStore::normalizePath((string) wp_parse_url($requestUri, PHP_URL_PATH));
        if (RedirectStore::shouldSkipPath($path) || RedirectStore::isStaticAsset($path)) {
            return;
        }
        $referrer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        $store = RedirectStore::record404($store, $path, $referrer, time());
        RedirectStore::save($store);
    }

    /**
     * Read the stored settings merged over the defaults.
     *
     * @return array{log_404s: bool}
     */
    public static function getSettings(): array
    {
        $store = RedirectStore::get();
        return [
            'log_404s' => (bool) ($store['log_404s'] ?? true),
        ];
    }

    /**
     * Default settings.
     *
     * 404 logging is on: the log is the module's main reason to
     * exist for a site that has not set up any rules yet.
     *
     * @return array{log_404s: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'log_404s' => true,
        ];
    }

    /**
     * Shorten a referrer URL for display (host + start of path).
     *
     * @param string $url Full referrer URL.
     * @return string Shortened display text.
     */
    private static function shortenUrl(string $url): string
    {
        if (strlen($url) <= 60) {
            return $url;
        }
        return substr($url, 0, 57) . '…';
    }

    /**
     * Whether the current request is an AJAX request.
     *
     * @return bool
     */
    private static function isAjaxRequest(): bool
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    /**
     * Whether the current request is a REST request.
     *
     * @return bool
     */
    private static function isRestRequest(): bool
    {
        return defined('REST_REQUEST') && REST_REQUEST;
    }

    /**
     * Whether the current request is wp-login.php.
     *
     * @return bool
     */
    private static function isLoginPage(): bool
    {
        return isset($GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php';
    }
}
