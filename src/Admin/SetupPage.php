<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use TrayDigita\WP\Plugin\Core\Admin\ModuleRegistry;
use TrayDigita\WP\Plugin\Core\Modules\ActivityLog\ActivityLogModule;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaModule;
use TrayDigita\WP\Plugin\Core\Modules\Agenda\AgendaRenderer;
use TrayDigita\WP\Plugin\Core\Modules\Cache\CacheDetector;
use TrayDigita\WP\Plugin\Core\Modules\Cache\CacheModule;
use TrayDigita\WP\Plugin\Core\Modules\EPaper\EPaperFrontend;
use TrayDigita\WP\Plugin\Core\Modules\Installer\InstallerAjax;
use TrayDigita\WP\Plugin\Core\Modules\Installer\InstallerModule;
use TrayDigita\WP\Plugin\Core\Modules\Installer\PluginList;
use TrayDigita\WP\Plugin\Core\Modules\Push\PushModule;
use TrayDigita\WP\Plugin\Core\Modules\Pwa\PwaModule;
use function __;
use function absint;
use function add_query_arg;
use function admin_url;
use function array_slice;
use function ceil;
use function count;
use function esc_attr;
use function esc_html;
use function esc_html_e;
use function esc_url;
use function file_exists;
use function get_locale;
use function get_option;
use function implode;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function sprintf;
use function sanitize_key;
use function in_array;
use function wp_nonce_field;
use function wp_unslash;
use function _n;

/**
 * Setup page: the first-run preparation checklist.
 *
 * Renders the checklist inside the admin shell (the wiring builder
 * adds the page and sets $traydigita_setup_page; the thin template
 * templates/admin/setup-page.php calls render() here). Every status
 * is read live from the running site on each render — nothing is
 * cached and no status is hardcoded.
 *
 * Checklist items (see items()):
 * - stack: the required TrayDigita Stack plugin (install from the
 *   project's GitHub release, upload a release .zip, or activate).
 * - recommended: the fourteen installable curated plugins from
 *   PluginList. When the stack is active the item links to the
 *   Plugin Suggestions page; when it is inactive that page does
 *   not exist, so the item carries the per-plugin rows instead and
 *   the template renders them with the installer page's data
 *   attributes, so resources/js/modules/admin-installer.ts wires
 *   them (no dead links, no fake buttons).
 * - permalinks: the Agenda / E-Paper rewrite rules.
 * - translations: the locale .mo file (same check as
 *   SiteHealth::translationFileTest()).
 * - vite-manifest: dist/manifest.json (same check as
 *   SiteHealth::viteManifestTest()).
 * - push: the Push module (never critical).
 * - cache: a page-cache plugin (CacheDetector). 'done' when one
 *   is active; otherwise 'pending' with the server-aware
 *   recommendation (CacheModule::recommendedPlugin()) and a link
 *   to the cache group on the Plugin Suggestions page — or to
 *   the Plugins screen when the stack is inactive and that page
 *   does not exist yet.
 *
 * Item shape: each item is ['id', 'title', 'description',
 * 'status', 'detail', 'action'] where 'status' is one of 'done',
 * 'pending' or 'info' and 'action' is one of 'stack-install',
 * 'stack-activate', 'stack-upload', 'link-installer',
 * 'link-installer-cache', 'flush-permalinks', 'link-plugins',
 * 'link-push' or 'none'. No HTML lives in items() — the template owns the markup. Two
 * exceptions, both documented where they occur:
 * - The 'stack-install' action renders BOTH the GitHub install
 *   button and the zip-upload fallback form: the upload form is
 *   the fallback control of the install action, not a second item,
 *   so items() never returns 'stack-upload' on its own. The
 *   'stack-upload' value stays in the enum for the upload control
 *   itself (the admin-post action traydigita_setup_upload_stack).
 * - The 'recommended' item carries an extra 'plugins' key (the
 *   fourteen installable entries with their live statuses) exactly
 *   when the stack is inactive, and its action is 'none' then:
 *   there is no single control, only the per-plugin buttons.
 *
 * The upload form posts to admin-post.php and redirects back here
 * with ?setup-result=installed|error&setup-message=<code>. The
 * message is always a short code from the allowlist in
 * resultMessage() (see SetupAjax::uploadStack(), which must only
 * redirect with those codes); the human text is rendered from
 * that allowlist, never from raw request input.
 */
final class SetupPage
{
    /**
     * Admin page slug of the Setup page.
     */
    public const string SLUG = 'traydigita-setup';

    /**
     * Recommended plugins per page in the Setup grid.
     *
     * Fourteen curated plugins at six per page is three pages:
     * short enough to scan, long enough that paging is rare.
     */
    public const int RECOMMENDED_PER_PAGE = 6;

    /**
     * Return the checklist items as structured data.
     *
     * Every status is read live when this runs. The template
     * renders the right control per item action.
     *
     * @return array<int, array{id: string, title: string, description: string, status: string, detail: string, action: string, plugins?: array<int, array{slug: string, name: string, reason: string, status: string}>}>
     */
    public static function items(): array
    {
        return [
            self::stackItem(),
            self::recommendedItem(),
            self::permalinksItem(),
            self::translationsItem(),
            self::viteManifestItem(),
            self::pushItem(),
            self::activityLogItem(),
            self::cacheItem(),
        ];
    }

    /**
     * Render the checklist content inside the admin shell.
     *
     * Echoes ONLY the checklist content; the caller wraps it in
     * templates/admin/shell.php. Shows the upload-result notice
     * first (when the page was reached from the upload redirect),
     * then one card per checklist item.
     *
     * @return void
     */
    public static function render(): void
    {
        $items = self::items();
        $counts = ['done' => 0, 'pending' => 0, 'info' => 0];
        foreach ($items as $item) {
            if (isset($counts[$item['status']])) {
                $counts[$item['status']]++;
            }
        }
        ?>
        <div class="traydigita-module-settings setup-page" data-setup-admin
            data-msg-installing="<?php echo esc_attr(__('Installing…', 'traydigita-core')); ?>"
            data-msg-activating="<?php echo esc_attr(__('Activating…', 'traydigita-core')); ?>"
            data-msg-creating="<?php echo esc_attr(__('Creating…', 'traydigita-core')); ?>"
            data-msg-flushing="<?php echo esc_attr(__('Flushing…', 'traydigita-core')); ?>"
            data-msg-activate="<?php echo esc_attr(__('Activate', 'traydigita-core')); ?>"
            data-msg-installed="<?php echo esc_attr(__('Installed', 'traydigita-core')); ?>"
            data-msg-active="<?php echo esc_attr(__('Active', 'traydigita-core')); ?>"
            data-msg-done="<?php echo esc_attr(__('Done', 'traydigita-core')); ?>"
            data-msg-pending="<?php echo esc_attr(__('Pending', 'traydigita-core')); ?>"
            data-msg-error="<?php echo esc_attr(__('Something went wrong. Please try again.', 'traydigita-core')); ?>">
            <?php self::renderUploadResult(); ?>
            <p class="traydigita-module-intro">
                <?php esc_html_e('Work through this list once after installing the theme. Every status below is checked live — reload the page to re-check after installing or activating something.', 'traydigita-core'); ?>
            </p>
            <div class="setup-summary">
                <span class="traydigita-admin-badge traydigita-admin-badge-success">
                    <span class="material-icons" aria-hidden="true">check_circle</span>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d is the number of checklist items that are done. */
                            _n('%d done', '%d done', $counts['done'], 'traydigita-core'),
                            $counts['done']
                        )
                    );
                    ?>
                </span>
                <span class="traydigita-admin-badge traydigita-admin-badge-warning">
                    <span class="material-icons" aria-hidden="true">schedule</span>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d is the number of checklist items still pending. */
                            _n('%d pending', '%d pending', $counts['pending'], 'traydigita-core'),
                            $counts['pending']
                        )
                    );
                    ?>
                </span>
                <span class="traydigita-admin-badge traydigita-admin-badge-muted">
                    <span class="material-icons" aria-hidden="true">info</span>
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %d is the number of informational checklist items. */
                            _n('%d info', '%d info', $counts['info'], 'traydigita-core'),
                            $counts['info']
                        )
                    );
                    ?>
                </span>
                <button type="button" class="traydigita-admin-button" data-setup-recheck>
                    <span class="material-icons" aria-hidden="true">refresh</span>
                    <?php esc_html_e('Re-check statuses', 'traydigita-core'); ?>
                </button>
            </div>
            <div class="traydigita-setup-grid">
                <?php foreach ($items as $item) : ?>
                    <?php self::renderItem($item); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Live permalink signals for the Agenda / E-Paper rewrite rules.
     *
     * Two independent signals are checked, and the item is 'done'
     * only when both agree:
     * - the Agenda rewrite-flushed flag
     *   (AgendaModule::REWRITES_FLUSHED_OPTION), set by
     *   AgendaModule::boot() after flush_rewrite_rules();
     * - the stored rewrite rules themselves
     *   (get_option('rewrite_rules')) containing the Agenda rules
     *   (AgendaRenderer regexes) and the E-Paper rules
     *   (EPaperFrontend regexes).
     *
     * @return array{flag: bool, agenda: bool, epaper: bool, done: bool}
     */
    public static function permalinkSignals(): array
    {
        $rules = get_option('rewrite_rules');
        if (!is_array($rules)) {
            $rules = [];
        }
        $flag = (bool) get_option(AgendaModule::REWRITES_FLUSHED_OPTION, false);
        $agenda = isset($rules[AgendaRenderer::ARCHIVE_REGEX])
            || isset($rules[AgendaRenderer::SINGLE_REGEX])
            || isset($rules[AgendaRenderer::ICS_REGEX]);
        $epaper = isset($rules[EPaperFrontend::ARCHIVE_REGEX])
            || isset($rules[EPaperFrontend::ISSUE_REGEX]);
        return [
            'flag' => $flag,
            'agenda' => $agenda,
            'epaper' => $epaper,
            'done' => $flag && $agenda && $epaper,
        ];
    }

    /**
     * The permalinks checklist item (Agenda / E-Paper rewrite rules).
     *
     * 'done' only when the Agenda flag is set AND the stored rules
     * contain both the Agenda and the E-Paper rules. The detail
     * names each signal, and says when a module is disabled —
     * a disabled module registers no rules, so flushing alone
     * cannot make its rules appear.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    public static function permalinksItem(): array
    {
        $signals = self::permalinkSignals();
        $parts = [];
        $parts[] = $signals['flag']
            ? __('Rewrite flag is set.', 'traydigita-core')
            : __('Rewrite flag is not set.', 'traydigita-core');
        $parts[] = $signals['agenda']
            ? __('Agenda rules are present.', 'traydigita-core')
            : __('Agenda rules are missing.', 'traydigita-core');
        $parts[] = $signals['epaper']
            ? __('E-Paper rules are present.', 'traydigita-core')
            : __('E-Paper rules are missing.', 'traydigita-core');
        if (!ModuleRegistry::isEnabled('agenda')) {
            $parts[] = __('The Agenda module is disabled, so its rules cannot be registered.', 'traydigita-core');
        }
        if (!ModuleRegistry::isEnabled('epaper')) {
            $parts[] = __('The E-Paper module is disabled, so its rules cannot be registered.', 'traydigita-core');
        }
        return [
            'id' => 'permalinks',
            'title' => __('Permalinks', 'traydigita-core'),
            'description' => __(
                'The Agenda (/agenda/) and E-Paper (/epaper/) URLs work through rewrite rules. Flushing the rules once registers them.',
                'traydigita-core'
            ),
            'status' => $signals['done'] ? 'done' : 'pending',
            'detail' => implode(' ', $parts),
            'action' => $signals['done'] ? 'none' : 'flush-permalinks',
        ];
    }

    /**
     * The stack checklist item (required TrayDigita Stack plugin).
     *
     * Status comes from PluginList::status(), which has no stack
     * dependency of its own. Not installed: install from the
     * GitHub release, with the zip-upload form as fallback.
     * Installed but inactive: activate. Active: done.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    private static function stackItem(): array
    {
        $status = PluginList::status(PluginList::STACK_SLUG);
        if ($status === PluginList::STATUS_ACTIVE) {
            return [
                'id' => 'stack',
                'title' => __('TrayDigita Stack', 'traydigita-core'),
                'description' => __(
                    'The required companion plugin. It provides the image, popular-posts and messaging helpers the theme uses; without it, those helpers fall back to built-in behaviour.',
                    'traydigita-core'
                ),
                'status' => 'done',
                'detail' => __('Installed and active.', 'traydigita-core'),
                'action' => 'none',
            ];
        }
        if ($status === PluginList::STATUS_INSTALLED) {
            return [
                'id' => 'stack',
                'title' => __('TrayDigita Stack', 'traydigita-core'),
                'description' => __(
                    'The required companion plugin. It provides the image, popular-posts and messaging helpers the theme uses; without it, those helpers fall back to built-in behaviour.',
                    'traydigita-core'
                ),
                'status' => 'pending',
                'detail' => __('Installed but not active. Activate it to enable the helpers.', 'traydigita-core'),
                'action' => 'stack-activate',
            ];
        }
        return [
            'id' => 'stack',
            'title' => __('TrayDigita Stack', 'traydigita-core'),
            'description' => __(
                'The required companion plugin. It provides the image, popular-posts and messaging helpers the theme uses; without it, those helpers fall back to built-in behaviour.',
                'traydigita-core'
            ),
            'status' => 'pending',
            'detail' => __(
                'Not installed. Install it from the project’s GitHub release, or upload the release .zip file below.',
                'traydigita-core'
            ),
            'action' => 'stack-install',
        ];
    }

    /**
     * The recommended-plugins checklist item.
     *
     * Summary counts over the fourteen installable curated entries
     * (PluginList::all() filtered by installable — the stack entry
     * is excluded). 'done' only when all six are active; anything
     * less is 'pending' because there is still something to
     * install or activate.
     *
     * When the stack is active the item links to the Plugin
     * Suggestions page. When the stack is inactive that page does
     * not exist, so the item instead carries the 'plugins' extra
     * key and the template renders per-plugin Install / Activate
     * buttons with the installer page's data attributes.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string, plugins?: array<int, array{slug: string, name: string, reason: string, status: string}>}
     */
    private static function recommendedItem(): array
    {
        $statuses = PluginList::statuses();
        $installable = [];
        foreach (PluginList::all() as $plugin) {
            if ($plugin['installable']) {
                $installable[] = $plugin;
            }
        }
        $total = count($installable);
        $installed = 0;
        $active = 0;
        foreach ($installable as $plugin) {
            $status = $statuses[$plugin['slug']] ?? PluginList::STATUS_NOT_INSTALLED;
            if ($status !== PluginList::STATUS_NOT_INSTALLED) {
                $installed++;
            }
            if ($status === PluginList::STATUS_ACTIVE) {
                $active++;
            }
        }
        $stackActive = PluginList::status(PluginList::STACK_SLUG) === PluginList::STATUS_ACTIVE;
        $item = [
            'id' => 'recommended',
            'title' => __('Recommended plugins', 'traydigita-core'),
            'description' => __(
                'Plugins this news site benefits from — Google Site Kit first. All are optional; install only the ones you need.',
                'traydigita-core'
            ),
            'status' => $active === $total ? 'done' : 'pending',
            'detail' => sprintf(
                /* translators: 1: installed count, 2: total recommended plugins, 3: active count. */
                __('Installed: %1$d of %2$d recommended plugins; active: %3$d.', 'traydigita-core'),
                $installed,
                $total,
                $active
            ),
            'action' => $stackActive ? 'link-installer' : 'none',
        ];
        if (!$stackActive) {
            $plugins = [];
            foreach ($installable as $plugin) {
                $plugins[] = [
                    'slug' => $plugin['slug'],
                    'name' => $plugin['name'],
                    'reason' => $plugin['reason'],
                    'status' => $statuses[$plugin['slug']] ?? PluginList::STATUS_NOT_INSTALLED,
                ];
            }
            $item['plugins'] = $plugins;
        }
        return $item;
    }

    /**
     * The translations checklist item (locale .mo file).
     *
     * Same check as SiteHealth::translationFileTest(): the
     * languages/{locale}.mo file must exist. Missing is 'info',
     * not 'pending': the site still works, English strings are
     * used as fallback, and there is no in-page action that
     * could create the file.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    private static function translationsItem(): array
    {
        $locale = get_locale();
        $present = file_exists(\TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/languages/' . $locale . '.mo');
        if ($present) {
            $detail = sprintf(
                /* translators: %s is the site locale, e.g. id_ID. */
                __('Translation file found for %s.', 'traydigita-core'),
                $locale
            );
        } else {
            $detail = sprintf(
                /* translators: %s is the site locale, e.g. id_ID. */
                __('No translation file for %s. English strings are used as fallback.', 'traydigita-core'),
                $locale
            );
        }
        return [
            'id' => 'translations',
            'title' => __('Translations', 'traydigita-core'),
            'description' => __(
                'The Indonesian translation of the theme interface. It is generated from the translation files when the theme is built.',
                'traydigita-core'
            ),
            'status' => $present ? 'done' : 'info',
            'detail' => $detail,
            'action' => 'none',
        ];
    }

    /**
     * The Vite manifest checklist item (dist/manifest.json).
     *
     * Same check as SiteHealth::viteManifestTest(). Missing is
     * 'info', not 'pending': the production build regenerates
     * the manifest, and no in-page action can run that build.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    private static function viteManifestItem(): array
    {
        $present = file_exists(\TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/assets/dist/manifest.json');
        return [
            'id' => 'vite-manifest',
            'title' => __('Vite manifest', 'traydigita-core'),
            'description' => __(
                'The production asset manifest, built from the theme sources. Without it the theme cannot load its compiled styles and scripts.',
                'traydigita-core'
            ),
            'status' => $present ? 'done' : 'info',
            'detail' => $present
                ? __('Production manifest found (dist/manifest.json).', 'traydigita-core')
                : __('Production manifest missing. Run the asset build to regenerate it.', 'traydigita-core'),
            'action' => 'none',
        ];
    }

    /**
     * The push checklist item (Push module).
     *
     * Status via PwaModule::isPushConfigured(). Push is
     * optional, so an unconfigured push half is 'info', never
     * a failure. Links to the module's own settings page.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    private static function pushItem(): array
    {
        $active = PwaModule::isPushConfigured();
        return [
            'id' => 'push',
            'title' => __('Push notifications', 'traydigita-core'),
            'description' => __(
                'Manual push notifications to subscribers, from the Push module (Firebase Cloud Messaging). Optional: the site works fully without it.',
                'traydigita-core'
            ),
            'status' => $active ? 'done' : 'info',
            'detail' => $active
                ? __('Configured in the Push module.', 'traydigita-core')
                : __('Not configured yet. Push notifications are unavailable (optional).', 'traydigita-core'),
            'action' => 'link-push',
        ];
    }

    /**
     * The Activity Log checklist item.
     *
     * 'done' when the module is enabled and its table exists
     * (detail counts the logged entries). 'pending' when the
     * module is enabled but the table is missing — the create
     * action runs dbDelta immediately. 'info' when the module
     * is disabled: logging is optional, and the action links to
     * the module settings page instead of creating anything.
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    public static function activityLogItem(): array
    {
        $description = __(
            'A lightweight log of what happens across the theme — module toggles, settings saves, push and mail sends, logins — stored in its own table with automatic pruning.',
            'traydigita-core'
        );
        if (!ModuleRegistry::isEnabled(ActivityLogModule::id())) {
            return [
                'id' => 'activity-log',
                'title' => __('Activity Log', 'traydigita-core'),
                'description' => $description,
                'status' => 'info',
                'detail' => __('The Activity Log module is disabled. Enable it in the module list to start recording.', 'traydigita-core'),
                'action' => 'link-activity-log',
            ];
        }
        if (!ActivityLogModule::tableExists()) {
            return [
                'id' => 'activity-log',
                'title' => __('Activity Log', 'traydigita-core'),
                'description' => $description,
                'status' => 'pending',
                'detail' => __('The log table does not exist yet, so nothing is being recorded. Create it now — a missing table also heals itself the next time an event is logged.', 'traydigita-core'),
                'action' => 'create-activity-log-table',
            ];
        }
        $count = ActivityLogModule::countAll();
        return [
            'id' => 'activity-log',
            'title' => __('Activity Log', 'traydigita-core'),
            'description' => $description,
            'status' => 'done',
            'detail' => sprintf(
                /* translators: %d is the number of log entries. */
                _n('The log table is ready — %d entry logged.', 'The log table is ready — %d entries logged.', $count, 'traydigita-core'),
                $count
            ),
            'action' => 'link-activity-log',
        ];
    }

    /**
     * The cache-plugin checklist item.
     *
     * 'done' when a cache plugin is active (CacheDetector), with
     * the detail naming it. Otherwise 'pending' — a cache plugin
     * is optional, but the setup list is the place to surface the
     * recommendation — with the server-aware recommendation
     * (CacheModule::recommendedPlugin()) as the detail. The
     * action links to the cache group on the Plugin Suggestions
     * page when the stack is active; that page does not exist
     * without the stack, so then the action is the Plugins
     * screen instead (same fallback the push item uses).
     *
     * @return array{id: string, title: string, description: string, status: string, detail: string, action: string}
     */
    private static function cacheItem(): array
    {
        $description = __(
            'A page-cache plugin serves repeat visits from cache instead of rebuilding every page. Which plugin fits best depends on the web server this site runs on.',
            'traydigita-core'
        );
        $detected = CacheDetector::detect();
        if ($detected !== []) {
            $names = [];
            foreach ($detected as $plugin) {
                $names[] = $plugin['name'];
            }
            $detail = count($names) === 1
                ? sprintf(
                    /* translators: %s is the name of the active cache plugin. */
                    __('%s is active.', 'traydigita-core'),
                    $names[0]
                )
                : sprintf(
                    /* translators: %s is a comma-separated list of active cache plugin names. */
                    __('%s are active.', 'traydigita-core'),
                    implode(', ', $names)
                );
            return [
                'id' => 'cache',
                'title' => __('Cache plugin', 'traydigita-core'),
                'description' => $description,
                'status' => 'done',
                'detail' => $detail,
                'action' => 'none',
            ];
        }
        $recommended = CacheModule::recommendedPlugin();
        $stackActive = PluginList::status(PluginList::STACK_SLUG) === PluginList::STATUS_ACTIVE;
        return [
            'id' => 'cache',
            'title' => __('Cache plugin', 'traydigita-core'),
            'description' => $description,
            'status' => 'pending',
            'detail' => $recommended['note'],
            'action' => $stackActive ? 'link-installer-cache' : 'link-plugins',
        ];
    }

    /**
     * Render the upload-result notice after the admin-post redirect.
     *
     * Reads ?setup-result and ?setup-message, both sanitized with
     * sanitize_key(). The message is a short code looked up in
     * the allowlist in resultMessage(); an unknown code shows
     * nothing, and raw request input is never echoed.
     *
     * @return void
     */
    private static function renderUploadResult(): void
    {
        $result = isset($_GET['setup-result'])
            ? sanitize_key(wp_unslash($_GET['setup-result']))
            : '';
        if ($result !== 'installed' && $result !== 'error') {
            return;
        }
        $code = isset($_GET['setup-message'])
            ? sanitize_key(wp_unslash($_GET['setup-message']))
            : '';
        $message = self::resultMessage($code);
        if ($message === '') {
            return;
        }
        $class = $result === 'installed'
            ? 'traydigita-admin-notice traydigita-admin-notice-success'
            : 'traydigita-admin-notice traydigita-admin-notice-error';
        ?>
        <div class="<?php echo esc_attr($class); ?>">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * Human text for an upload-result code.
     *
     * Allowlist only: SetupAjax::uploadStack() redirects with one
     * of these codes, and anything else renders no notice at all.
     *
     * @param string $code Short result code from the redirect URL.
     * @return string Human-readable message, or '' for an unknown code.
     */
    private static function resultMessage(string $code): string
    {
        switch ($code) {
            case 'installed':
                return __('TrayDigita Stack was installed. Activate it when you are ready — nothing is activated automatically.', 'traydigita-core');
            case 'already-installed':
                return __('TrayDigita Stack is already installed.', 'traydigita-core');
            case 'no-file':
                return __('No file was uploaded. Choose the TrayDigita Stack .zip file and try again.', 'traydigita-core');
            case 'upload-error':
                return __('The file upload failed. Please try again.', 'traydigita-core');
            case 'not-zip':
                return __('Only .zip files can be uploaded.', 'traydigita-core');
            case 'too-large':
                return __('The uploaded file is too large. The limit is 20 MB.', 'traydigita-core');
            case 'filesystem':
                return __('WordPress cannot write plugin files directly on this site (direct filesystem access is unavailable). Check the file ownership and permissions, or install the plugin from the Plugins screen, and try again.', 'traydigita-core');
            case 'install-failed':
                return __('The plugin could not be installed from the uploaded file.', 'traydigita-core');
            default:
                return '';
        }
    }

    /**
     * Render one checklist item card.
     *
     * Same card language as the integrations hub: icon tile in
     * the head, one-line description, live detail line, and a
     * footer carrying the icon+text status pill and the item's
     * primary action. The recommended item spans the full grid
     * width when it carries its per-plugin cards (see
     * renderRecommendedPlugins()).
     *
     * @param array{id: string, title: string, description: string, status: string, detail: string, action: string, plugins?: array<int, array{slug: string, name: string, reason: string, status: string}>} $item Checklist item from items().
     * @return void
     */
    private static function renderItem(array $item): void
    {
        $badgeClass = $item['status'] === 'done'
            ? 'traydigita-admin-badge-success'
            : ($item['status'] === 'pending'
                ? 'traydigita-admin-badge-warning'
                : 'traydigita-admin-badge-muted');
        $badgeLabel = $item['status'] === 'done'
            ? __('Done', 'traydigita-core')
            : ($item['status'] === 'pending'
                ? __('Pending', 'traydigita-core')
                : __('Info', 'traydigita-core'));
        $cardClass = 'traydigita-admin-card setup-item';
        if (isset($item['plugins'])) {
            $cardClass .= ' setup-item--wide';
        }
        ?>
        <article class="<?php echo esc_attr($cardClass); ?>" data-setup-item="<?php echo esc_attr($item['id']); ?>">
            <div class="traydigita-admin-card-head">
                <span class="traydigita-admin-card-icon material-icons" aria-hidden="true">
                    <?php echo esc_html(self::itemIcon($item['id'])); ?>
                </span>
                <div class="traydigita-admin-card-titles">
                    <h2 class="traydigita-admin-card-name"><?php echo esc_html($item['title']); ?></h2>
                </div>
            </div>
            <div class="traydigita-admin-card-description-wrap"><p class="traydigita-admin-card-description"><?php echo esc_html($item['description']); ?></p></div>
            <p class="setup-item-detail" data-setup-detail><?php echo esc_html($item['detail']); ?></p>
            <div class="traydigita-admin-card-foot">
                <span class="traydigita-admin-badge <?php echo esc_attr($badgeClass); ?>" data-setup-badge>
                    <span class="material-icons" aria-hidden="true">
                        <?php echo esc_html(self::badgeIcon($item['status'])); ?>
                    </span>
                    <span data-badge-label><?php echo esc_html($badgeLabel); ?></span>
                </span>
                <div class="setup-item-actions">
                    <?php self::renderItemAction($item); ?>
                </div>
            </div>
            <?php self::renderItemExtras($item); ?>
            <?php if (in_array($item['action'], ['stack-install', 'stack-activate', 'flush-permalinks', 'create-activity-log-table'], true)) : ?>
                <p class="setup-item-status" data-setup-status role="status"></p>
            <?php endif; ?>
        </article>
        <?php
    }

    /**
     * Material Icons glyph for a checklist item.
     *
     * One real glyph per item, matching what the item is about:
     * the companion plugin, the curated plugins, the rewrite
     * rules, the translation file, the build manifest, push,
     * and the cache plugin.
     *
     * @param string $id Checklist item id.
     * @return string Material Icons ligature name.
     */
    private static function itemIcon(string $id): string
    {
        return match ($id) {
            'stack' => 'layers',
            'recommended' => 'extension',
            'permalinks' => 'link',
            'translations' => 'translate',
            'vite-manifest' => 'build',
            'push' => 'notifications',
            'activity-log' => 'history',
            'cache' => 'speed',
            default => 'checklist',
        };
    }

    /**
     * Material Icons glyph for a checklist status pill.
     *
     * The pill is icon + text, never colour alone, matching the
     * integrations hub: a check for done, a clock for pending,
     * an info mark for informational items.
     *
     * @param string $status Checklist item status ('done', 'pending' or 'info').
     * @return string Material Icons ligature name.
     */
    private static function badgeIcon(string $status): string
    {
        return match ($status) {
            'done' => 'check_circle',
            'pending' => 'schedule',
            default => 'info',
        };
    }

    /**
     * Render the primary action control for one checklist item.
     *
     * Only the control itself: the footer around it (status pill
     * + this control) is rendered by renderItem(). The four AJAX
     * actions (stack install / stack activate / flush / create
     * the activity-log table) get a live status line rendered by
     * renderItem(); the link actions are plain links; 'none'
     * renders nothing — except the recommended item, whose
     * per-plugin cards are rendered by renderItemExtras() instead.
     *
     * @param array{id: string, title: string, description: string, status: string, detail: string, action: string, plugins?: array<int, array{slug: string, name: string, reason: string, status: string}>} $item Checklist item from items().
     * @return void
     */
    private static function renderItemAction(array $item): void
    {
        switch ($item['action']) {
            case 'stack-install':
                ?>
                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary"
                    data-setup-install-stack>
                    <?php esc_html_e('Install from GitHub release', 'traydigita-core'); ?>
                </button>
                <?php
                break;
            case 'stack-activate':
                ?>
                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary"
                    data-setup-activate-stack>
                    <?php esc_html_e('Activate', 'traydigita-core'); ?>
                </button>
                <?php
                break;
            case 'flush-permalinks':
                ?>
                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary"
                    data-setup-flush>
                    <?php esc_html_e('Flush permalink rules', 'traydigita-core'); ?>
                </button>
                <?php
                break;
            case 'create-activity-log-table':
                ?>
                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary"
                    data-setup-create-activity-log>
                    <?php esc_html_e('Create the log table', 'traydigita-core'); ?>
                </button>
                <?php
                break;
            case 'link-activity-log':
                ?>
                <a class="traydigita-admin-button traydigita-admin-button-primary"
                    href="<?php echo esc_url(admin_url('admin.php?page=' . ActivityLogModule::settingsPageSlug())); ?>">
                    <?php esc_html_e('Open Activity Log', 'traydigita-core'); ?>
                </a>
                <?php
                break;
            case 'link-installer':
                ?>
                <a class="traydigita-admin-button traydigita-admin-button-primary"
                    href="<?php echo esc_url(admin_url('admin.php?page=' . InstallerModule::settingsPageSlug())); ?>">
                    <?php esc_html_e('Open Plugin Suggestions', 'traydigita-core'); ?>
                </a>
                <?php
                break;
            case 'link-installer-cache':
                ?>
                <a class="traydigita-admin-button traydigita-admin-button-primary"
                    href="<?php echo esc_url(CacheModule::installerCacheGroupUrl()); ?>">
                    <?php esc_html_e('See the recommended cache plugins', 'traydigita-core'); ?>
                </a>
                <?php
                break;
            case 'link-push':
                ?>
                <a class="traydigita-admin-button traydigita-admin-button-primary"
                    href="<?php echo esc_url(admin_url('admin.php?page=' . PushModule::settingsPageSlug())); ?>">
                    <?php esc_html_e('Open Push Notifications', 'traydigita-core'); ?>
                </a>
                <?php
                break;
            case 'link-plugins':
                ?>
                <a class="traydigita-admin-button traydigita-admin-button-primary"
                    href="<?php echo esc_url(admin_url('plugins.php')); ?>">
                    <?php esc_html_e('Open Plugins', 'traydigita-core'); ?>
                </a>
                <?php
                break;
            default:
                break;
        }
    }

    /**
     * Render the extra controls for one checklist item, below its footer.
     *
     * The stack-install item carries the zip-upload fallback form
     * (same admin-post endpoint and nonce as before); the
     * recommended item carries its per-plugin cards when the
     * stack is inactive (see renderRecommendedPlugins()). Every
     * other item has nothing extra.
     *
     * @param array{id: string, title: string, description: string, status: string, detail: string, action: string, plugins?: array<int, array{slug: string, name: string, reason: string, status: string}>} $item Checklist item from items().
     * @return void
     */
    private static function renderItemExtras(array $item): void
    {
        if ($item['action'] === 'stack-install') {
            ?>
            <form class="setup-upload-form" method="post"
                action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="traydigita_setup_upload_stack">
                <?php wp_nonce_field(InstallerAjax::NONCE_ACTION); ?>
                <span class="setup-upload-icon material-icons" aria-hidden="true">upload_file</span>
                <label class="setup-upload-field">
                    <span><?php esc_html_e('Or upload the release .zip file', 'traydigita-core'); ?></span>
                    <input type="file" name="stack_zip" accept=".zip,application/zip" required>
                </label>
                <button type="submit" class="traydigita-admin-button">
                    <?php esc_html_e('Upload and install', 'traydigita-core'); ?>
                </button>
            </form>
            <?php
            return;
        }
        if (isset($item['plugins'])) {
            self::renderRecommendedPlugins($item['plugins']);
        }
    }

    /**
     * Render the per-plugin cards for the recommended item (only
     * when the stack is inactive).
     *
     * One card per plugin in the same language as the
     * integrations hub — icon tile (a generic category glyph from
     * PluginList::iconFor(), never a brand logo), name, one-line
     * reason, icon+text status pill, and Install / Activate
     * buttons. Same markup and data attributes as the installer
     * page (InstallerModule::renderSettingsPage()): the
     * [data-installer-admin] root with the translatable strings,
     * [data-installer-plugin] cards with data-slug, and
     * [data-installer-install] / [data-installer-activate]
     * buttons — so resources/js/modules/admin-installer.ts wires
     * them with no extra JavaScript. The stack entry is not in
     * this list: it cannot be installed from wordpress.org.
     *
     * Paginated, six per page, with prev/next links back to this
     * page (`td_recommended_page` query arg) — the same pattern
     * as the Activity Log viewer. The cards are plain
     * server-rendered markup, so the Install / Activate buttons
     * keep working on every page: the installer JavaScript binds
     * one delegated click listener on the grid container.
     *
     * @param array<int, array{slug: string, name: string, reason: string, status: string}> $plugins Installable curated plugins with live statuses.
     * @return void
     */
    private static function renderRecommendedPlugins(array $plugins): void
    {
        $total = count($plugins);
        $perPage = self::RECOMMENDED_PER_PAGE;
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        if ($totalPages < 1) {
            $totalPages = 1;
        }
        $page = isset($_GET['td_recommended_page']) && is_numeric(wp_unslash($_GET['td_recommended_page']))
            ? absint(wp_unslash($_GET['td_recommended_page']))
            : 1;
        $page = max(1, min($page, $totalPages));
        $pagePlugins = array_slice($plugins, ($page - 1) * $perPage, $perPage);
        $baseUrl = admin_url('admin.php?page=' . self::SLUG);
        ?>
        <div class="setup-plugin-grid" data-installer-admin
            data-msg-installing="<?php echo esc_attr(__('Installing…', 'traydigita-core')); ?>"
            data-msg-activating="<?php echo esc_attr(__('Activating…', 'traydigita-core')); ?>"
            data-msg-activate="<?php echo esc_attr(__('Activate', 'traydigita-core')); ?>"
            data-msg-installed="<?php echo esc_attr(__('Installed', 'traydigita-core')); ?>"
            data-msg-active="<?php echo esc_attr(__('Active', 'traydigita-core')); ?>"
            data-msg-error="<?php echo esc_attr(__('Something went wrong. Please try again.', 'traydigita-core')); ?>">
            <?php foreach ($pagePlugins as $plugin) : ?>
                <?php
                $badgeClass = $plugin['status'] === PluginList::STATUS_ACTIVE
                    ? 'traydigita-admin-badge-success'
                    : ($plugin['status'] === PluginList::STATUS_INSTALLED
                        ? 'traydigita-admin-badge-warning'
                        : 'traydigita-admin-badge-muted');
                $badgeLabel = $plugin['status'] === PluginList::STATUS_ACTIVE
                    ? __('Active', 'traydigita-core')
                    : ($plugin['status'] === PluginList::STATUS_INSTALLED
                        ? __('Installed', 'traydigita-core')
                        : __('Not installed', 'traydigita-core'));
                $badgeIcon = $plugin['status'] === PluginList::STATUS_ACTIVE
                    ? 'check_circle'
                    : ($plugin['status'] === PluginList::STATUS_INSTALLED
                        ? 'download_done'
                        : 'download');
                ?>
                <article class="traydigita-admin-card setup-plugin-card installer-plugin" data-installer-plugin
                    data-slug="<?php echo esc_attr($plugin['slug']); ?>" data-installable="1">
                    <div class="traydigita-admin-card-head">
                        <span class="traydigita-admin-card-icon material-icons" aria-hidden="true">
                            <?php echo esc_html(PluginList::iconFor($plugin['slug'])); ?>
                        </span>
                        <div class="traydigita-admin-card-titles">
                            <h3 class="traydigita-admin-card-name"><?php echo esc_html($plugin['name']); ?></h3>
                        </div>
                    </div>
                    <div class="traydigita-admin-card-description-wrap"><p class="traydigita-admin-card-description"><?php echo esc_html($plugin['reason']); ?></p></div>
                    <div class="traydigita-admin-card-foot">
                        <span class="traydigita-admin-badge installer-status-badge <?php echo esc_attr($badgeClass); ?>"
                            data-installer-badge>
                            <span class="material-icons" aria-hidden="true">
                                <?php echo esc_html($badgeIcon); ?>
                            </span>
                            <span data-badge-label><?php echo esc_html($badgeLabel); ?></span>
                        </span>
                        <div class="installer-plugin-actions">
                            <?php if ($plugin['status'] === PluginList::STATUS_NOT_INSTALLED) : ?>
                                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary"
                                    data-installer-install>
                                    <?php esc_html_e('Install', 'traydigita-core'); ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($plugin['status'] === PluginList::STATUS_INSTALLED) : ?>
                                <button type="button" class="traydigita-admin-button traydigita-admin-button-primary"
                                    data-installer-activate>
                                    <?php esc_html_e('Activate', 'traydigita-core'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="installer-plugin-status" data-installer-status role="status"></p>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1) : ?>
            <nav class="setup-plugin-pagination" aria-label="<?php esc_attr_e('Recommended plugin pages', 'traydigita-core'); ?>">
                <span class="setup-plugin-page-info">
                    <?php
                    /* translators: 1: current page, 2: total pages, 3: total recommended plugins. */
                    echo esc_html(sprintf(__('Page %1$d of %2$d (%3$d plugins)', 'traydigita-core'), $page, $totalPages, $total));
                    ?>
                </span>
                <?php if ($page > 1) : ?>
                    <a class="traydigita-admin-button" href="<?php echo esc_url(add_query_arg(['td_recommended_page' => $page - 1], $baseUrl)); ?>"><?php esc_html_e('Previous', 'traydigita-core'); ?></a>
                <?php endif; ?>
                <?php if ($page < $totalPages) : ?>
                    <a class="traydigita-admin-button" href="<?php echo esc_url(add_query_arg(['td_recommended_page' => $page + 1], $baseUrl)); ?>"><?php esc_html_e('Next', 'traydigita-core'); ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
        <?php
    }
}
