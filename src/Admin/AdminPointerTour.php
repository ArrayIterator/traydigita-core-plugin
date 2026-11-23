<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Admin;

use function __;
use function absint;
use function add_action;
use function admin_url;
use function check_admin_referer;
use function check_ajax_referer;
use function count;
use function current_user_can;
use function delete_user_meta;
use function esc_html__;
use function get_current_user_id;
use function get_user_meta;
use function is_string;
use function sanitize_key;
use function update_user_meta;
use function wp_die;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_nonce_url;
use function wp_safe_redirect;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Onboarding guided tour built on WordPress's native pointers.
 *
 * First-time administrators get the standard WordPress pointer
 * (wp_enqueue_script('wp-pointer') + wp_enqueue_style('wp-pointer'),
 * the jQuery pointer API) anchored to real UI elements — the
 * built-in WordPress look, no custom tour card or styling.
 *
 * Steps cover the module list, the General hub, the colour
 * palette in the Customizer, Mobile Bottom Navigation, Cookie
 * Consent, and finishing. Each step names the admin page it
 * lives on (`page`: a TrayDigita page slug) and a CSS selector
 * for its anchor element. The tour navigates between pages
 * itself: pressing Next on a step whose next step lives on
 * another page loads that page with ?traydigita_tour=N, where
 * the tour resumes at step N. A step whose anchor does not
 * exist on the current screen is skipped gracefully instead
 * of breaking the tour.
 *
 * Dismissal is stored per user (user meta
 * `traydigita_tour_dismissed`, the same key the previous custom
 * tour used, so existing dismissals carry over), so the tour
 * shows once. A "Replay tour" link on the Settings hub clears
 * the dismissal through a nonce-protected GET action and
 * redirects to step 1 on the Modules list.
 *
 * The Customizer steps anchor to the shortcut links on the hub
 * (which open the Customizer with the right section focused);
 * the tour itself does not run inside the Customizer.
 */
final class AdminPointerTour
{
    /**
     * User meta key storing the per-user dismissal.
     */
    public const string USER_META = 'traydigita_tour_dismissed';

    /**
     * AJAX action dismissing the tour for the current user.
     */
    public const string AJAX_DISMISS = 'traydigita_admin_dismiss_pointer_tour';

    /**
     * Query parameter carrying the 1-based step number the tour
     * resumes at (?traydigita_tour=N).
     */
    public const string QUERY_PARAM = 'traydigita_tour';

    /**
     * Query parameter triggering the replay action
     * (?traydigita_tour_replay=1, nonce-protected).
     */
    public const string REPLAY_PARAM = 'traydigita_tour_replay';

    /**
     * Nonce action for the replay GET action.
     */
    public const string REPLAY_NONCE_ACTION = 'traydigita_replay_pointer_tour';

    /**
     * Tour steps, in order.
     *
     * - id: stable step id.
     * - title / body: English source strings, translatable.
     * - page: TrayDigita page slug the step lives on.
     * - selector: CSS selector of the anchor element on that page.
     *
     * @return array<int, array{id: string, title: string, body: string, page: string, selector: string}>
     */
    public static function steps(): array
    {
        return [
            [
                'id' => 'modules',
                'title' => __('Modules: enable what you need', 'traydigita-core'),
                'body' => __('Every feature is a module. Enable only what your site needs — disabled modules register no hooks and load nothing.', 'traydigita-core'),
                'page' => AdminMenu::MENU_SLUG,
                'selector' => '[data-traydigita-module-list] .traydigita-ui-toolbar',
            ],
            [
                'id' => 'hub',
                'title' => __('Settings hub', 'traydigita-core'),
                'body' => __('Module settings live in the Settings hub, not in a long menu. Each active module links to its own settings page from there.', 'traydigita-core'),
                'page' => AdminMenu::MENU_SLUG,
                'selector' => 'a[href$="admin.php?page=' . AdminMenu::HUB_SLUG . '"]',
            ],
            [
                'id' => 'palette',
                'title' => __('Pick an admin palette', 'traydigita-core'),
                'body' => __('The admin is neutral by default. Open the Customizer to pick a palette — Koperasi Green, Slate, or another — and change the primary and accent colours.', 'traydigita-core'),
                'page' => AdminMenu::HUB_SLUG,
                'selector' => '[data-tour-anchor="admin-palette"]',
            ],
            [
                'id' => 'mobile-nav',
                'title' => __('Mobile bottom navigation', 'traydigita-core'),
                'body' => __('The mobile bottom navigation is configured in the Customizer too. Pick the items your readers reach most often.', 'traydigita-core'),
                'page' => AdminMenu::HUB_SLUG,
                'selector' => '[data-tour-anchor="mobile-nav"]',
            ],
            [
                'id' => 'cookie-consent',
                'title' => __('Cookie Consent', 'traydigita-core'),
                'body' => __('Cookie Consent is one of the active modules. Open its settings from the hub to set the banner text and behaviour.', 'traydigita-core'),
                'page' => AdminMenu::HUB_SLUG,
                'selector' => '[data-tour-anchor="module-cookie-consent"]',
            ],
            [
                'id' => 'finish',
                'title' => __('You are ready', 'traydigita-core'),
                'body' => __('That is the whole tour. You can replay it any time from the Settings hub.', 'traydigita-core'),
                'page' => AdminMenu::HUB_SLUG,
                'selector' => '.traydigita-admin-header',
            ],
        ];
    }

    /**
     * Register the tour hooks.
     *
     * Called from AdminMenu::init(): wp-pointer assets on
     * TrayDigita admin pages, the nonce-protected replay GET
     * action, and the dismiss AJAX endpoint. The dismiss
     * endpoint requires the `traydigita-admin` nonce (sent as
     * `_ajax_nonce`) plus the `manage_options` capability.
     *
     * @return void
     */
    public static function register(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_init', [self::class, 'maybeHandleReplay']);
        add_action('wp_ajax_' . self::AJAX_DISMISS, [self::class, 'dismiss']);
    }

    /**
     * Enqueue the WordPress pointer assets on TrayDigita pages
     * only.
     *
     * The pointer JavaScript itself ships inside the `admin`
     * Vite entry (resources/js/modules/admin-pointer-tour.ts);
     * this only loads WordPress's built-in wp-pointer script
     * (jQuery pointer widget) and stylesheet, which the entry
     * depends on.
     *
     * @param string $hookSuffix Current admin page hook suffix (unused; the page is detected from $_GET['page']).
     * @return void
     */
    public static function enqueue(string $hookSuffix): void
    {
        if (!self::isTraydigitaPage()) {
            return;
        }
        wp_enqueue_script('wp-pointer');
        wp_enqueue_style('wp-pointer');
    }

    /**
     * Whether the tour should auto-start for the current user.
     *
     * First visit only: the user meta key is empty until the tour
     * is dismissed or finished.
     *
     * @return bool
     */
    public static function shouldShow(): bool
    {
        if (!current_user_can(AdminMenu::CAPABILITY)) {
            return false;
        }
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return false;
        }
        return get_user_meta($userId, self::USER_META, true) === '';
    }

    /**
     * Requested tour step, 1-based, from ?traydigita_tour=N.
     *
     * Sanitized (integer, clamped to 1..step count); 0 when the
     * parameter is absent or invalid.
     *
     * @return int
     */
    public static function currentStep(): int
    {
        if (!isset($_GET[self::QUERY_PARAM]) || !is_string($_GET[self::QUERY_PARAM])) {
            return 0;
        }
        $step = absint(wp_unslash($_GET[self::QUERY_PARAM]));
        if ($step < 1) {
            return 0;
        }
        $total = count(self::steps());
        return $step > $total ? $total : $step;
    }

    /**
     * URL of a tour step's page, carrying the resume parameter.
     *
     * @param int $index 0-based step index.
     * @return string
     */
    public static function stepUrl(int $index): string
    {
        $steps = self::steps();
        $step = $steps[$index] ?? $steps[0];
        return admin_url(
            'admin.php?page=' . $step['page'] . '&' . self::QUERY_PARAM . '=' . ($index + 1)
        );
    }

    /**
     * URL of the "Replay tour" link on the Settings hub.
     *
     * A nonce-protected GET action: maybeHandleReplay() clears
     * the dismissal and redirects to step 1 on the Modules list.
     *
     * @return string
     */
    public static function replayUrl(): string
    {
        return wp_nonce_url(
            admin_url('admin.php?page=' . AdminMenu::MENU_SLUG . '&' . self::REPLAY_PARAM . '=1'),
            self::REPLAY_NONCE_ACTION
        );
    }

    /**
     * Tour payload for the shell's data-pointer-tour attribute.
     *
     * The JavaScript (resources/js/modules/admin-pointer-tour.ts)
     * reads this from the shell root element. `show` is true only
     * when the current user may see the tour and the step to show
     * lives on the page being rendered: an explicit
     * ?traydigita_tour=N request shows that step, otherwise the
     * tour auto-starts at step 1 on the first visit to the Modules
     * list only.
     *
     * @return array{show: bool, current: int, steps: array<int, array{id: string, title: string, content: string,
     *     target: string, url: string}>, dismissAction: string, labels: array{next: string, back: string, skip: string,
     *     finish: string, step: string}}
     */
    public static function tourData(): array
    {
        $steps = self::steps();
        $current = self::currentStep();
        $show = false;
        if ($current > 0) {
            // Explicit step request: show it only when the step
            // lives on the page being rendered.
            $show = $steps[$current - 1]['page'] === self::currentPageSlug();
        } elseif (self::shouldShow() && self::currentPageSlug() === AdminMenu::MENU_SLUG) {
            // First visit: auto-start at step 1 on the Modules
            // list only, so opening any other page never yanks
            // the admin away to the module list.
            $show = true;
            $current = 1;
        }
        if ($show && !current_user_can(AdminMenu::CAPABILITY)) {
            $show = false;
        }
        $dataSteps = [];
        foreach ($steps as $index => $step) {
            $dataSteps[] = [
                'id' => $step['id'],
                'title' => $step['title'],
                'content' => $step['body'],
                'target' => $step['selector'],
                'url' => self::stepUrl($index),
            ];
        }
        return [
            'show' => $show,
            'current' => $show ? $current : 0,
            'steps' => $dataSteps,
            'dismissAction' => self::AJAX_DISMISS,
            'labels' => [
                'next' => __('Next', 'traydigita-core'),
                'back' => __('Back', 'traydigita-core'),
                'skip' => __('Skip the tour', 'traydigita-core'),
                'finish' => __('Finish', 'traydigita-core'),
                'step' => __('Step %s of %s', 'traydigita-core'),
            ],
        ];
    }

    /**
     * Handle the "Replay tour" GET action.
     *
     * Runs on admin_init; only when ?traydigita_tour_replay=1 is
     * present. The nonce is verified (check_admin_referer() dies
     * on a bad nonce) and the capability is checked before the
     * dismissal is cleared; the admin is then redirected to step
     * 1 on the Modules list.
     *
     * @return void
     */
    public static function maybeHandleReplay(): void
    {
        if (!isset($_GET[self::REPLAY_PARAM])) {
            return;
        }
        check_admin_referer(self::REPLAY_NONCE_ACTION);
        if (!current_user_can(AdminMenu::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'traydigita-core'));
        }
        $userId = get_current_user_id();
        if ($userId > 0) {
            delete_user_meta($userId, self::USER_META);
        }
        wp_safe_redirect(
            admin_url('admin.php?page=' . AdminMenu::MENU_SLUG . '&' . self::QUERY_PARAM . '=1')
        );
        exit;
    }

    /**
     * Dismiss the tour for the current user (AJAX).
     *
     * @return void
     */
    public static function dismiss(): void
    {
        check_ajax_referer(AdminAjax::NONCE_ACTION);
        if (!current_user_can(AdminMenu::CAPABILITY)) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        $userId = get_current_user_id();
        if ($userId <= 0) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'traydigita-core')], 403);
        }
        update_user_meta($userId, self::USER_META, '1');
        wp_send_json_success(['dismissed' => true]);
    }

    /**
     * Current admin page slug from ?page=, or "".
     *
     * @return string
     */
    private static function currentPageSlug(): string
    {
        if (!isset($_GET['page']) || !is_string($_GET['page'])) {
            return '';
        }
        return sanitize_key(wp_unslash($_GET['page']));
    }

    /**
     * Whether the current admin screen is a TrayDigita page.
     *
     * Same rule as AdminMenu::printAdminPalette(): the Modules
     * list, the Settings hub, or a known module settings page.
     *
     * @return bool
     */
    private static function isTraydigitaPage(): bool
    {
        $page = self::currentPageSlug();
        if ($page === '') {
            return false;
        }
        return $page === AdminMenu::MENU_SLUG
            || $page === AdminMenu::HUB_SLUG
            || ModuleRegistry::getBySlug($page) !== null;
    }
}
