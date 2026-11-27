<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Security;

use function __;
use function sprintf;

/**
 * Security checks for the Security Monitor module.
 *
 * Pure logic, no WordPress calls: every check runs over an
 * environment array built by `SecurityModule::collectEnvironment()`,
 * so the checks are testable without a WordPress install and the
 * harness in `/tmp` can feed them arbitrary inputs.
 *
 * Environment shape (all keys expected; missing keys fall back to
 * the safest "unknown" value):
 *
 * - `disallow_file_edit` (bool|null): value of DISALLOW_FILE_EDIT,
 *   or null when the constant is not defined.
 * - `disallow_file_mods` (bool|null): value of DISALLOW_FILE_MODS,
 *   or null when the constant is not defined.
 * - `files` (array<string, int|null>): permission bits from
 *   `fileperms()` keyed by `wp-config.php`, `.htaccess`, and
 *   `uploads`; null when the path does not exist or cannot be read.
 * - `admin_user_exists` (bool): whether a user named `admin` exists.
 * - `wp_debug` (bool): value of WP_DEBUG (false when undefined).
 * - `wp_debug_display` (bool|null): value of WP_DEBUG_DISPLAY, or
 *   null when the constant is not defined. WordPress defaults it
 *   to true when WP_DEBUG is on, and `run()` applies that default.
 * - `display_errors` (bool): the PHP `display_errors` ini setting.
 * - `xmlrpc_file_exists` (bool): whether `xmlrpc.php` is present.
 * - `xmlrpc_enabled` (bool): `apply_filters('xmlrpc_enabled', true)`.
 * - `version_disclosed` (bool): whether the WordPress version
 *   string appears in the generator output (feed / `wp_head`).
 *
 * Every check returns:
 *
 * - `id` (string): stable machine id.
 * - `title` (string): short check name.
 * - `status` (string): `pass`, `warning`, or `fail`.
 * - `explanation` (string): plain-language description of the
 *   finding and why it matters.
 * - `fix` (string): the recommended fix, as plain text.
 * - `code` (string, optional): exact code to paste, shown as a
 *   copyable block. Only present where a snippet is the fix.
 * - `details` (array, optional): per-item breakdown, used by the
 *   file-permissions check.
 *
 * Checks only: no check ever writes anything. In particular, no
 * fix is offered as a button. Toggling a `wp-config.php` constant,
 * deleting the `admin` user, changing file ownership/permissions,
 * or disabling XML-RPC are all one-way doors for some sites (or
 * edits the web server may not own), so every fix is a manual
 * step with the exact code or commands shown instead.
 */
final class Checks
{
    /**
     * Check ids in display order.
     *
     * @var array<int, string>
     */
    public const array CHECK_IDS = [
        'file-edit',
        'file-permissions',
        'admin-user',
        'debug-display',
        'xml-rpc',
        'version-disclosure',
    ];

    /**
     * Exact `wp-config.php` lines that lock the file editor and
     * file modifications. Shown as copyable code; never written.
     */
    public const string WP_CONFIG_SNIPPET = "define( 'DISALLOW_FILE_EDIT', true );\n"
        . "define( 'DISALLOW_FILE_MODS', true );";

    /**
     * Code that disables XML-RPC (child theme `functions.php` or a
     * code-snippets plugin).
     */
    public const string XMLRPC_SNIPPET = "add_filter( 'xmlrpc_enabled', '__return_false' );";

    /**
     * Code that hides the WordPress version from feeds and headers.
     */
    public const string GENERATOR_SNIPPET = "remove_action( 'wp_head', 'wp_generator' );\n"
        . "add_filter( 'the_generator', '__return_empty_string' );";

    /**
     * Run every check over the given environment.
     *
     * @param array<string, mixed> $env Environment as described in the class docblock.
     * @return array<int, array<string, mixed>> Check results in display order.
     */
    public static function run(array $env): array
    {
        return [
            self::checkFileEdit($env),
            self::checkFilePermissions($env),
            self::checkAdminUser($env),
            self::checkDebugDisplay($env),
            self::checkXmlRpc($env),
            self::checkVersionDisclosure($env),
        ];
    }

    /**
     * Summarize a set of check results by status.
     *
     * @param array<int, array<string, mixed>> $results Results from `run()`.
     * @return array{pass: int, warning: int, fail: int}
     */
    public static function summarize(array $results): array
    {
        $summary = ['pass' => 0, 'warning' => 0, 'fail' => 0];
        foreach ($results as $result) {
            $status = isset($result['status']) && is_string($result['status']) ? $result['status'] : 'warning';
            if ($status === 'pass' || $status === 'warning' || $status === 'fail') {
                $summary[$status]++;
            } else {
                $summary['warning']++;
            }
        }
        return $summary;
    }

    /**
     * Check whether DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS are set.
     *
     * Both true is a pass. Anything else is a warning (not a fail):
     * the file editor is a legitimate workflow on some sites, and
     * DISALLOW_FILE_MODS also blocks plugin and theme installs and
     * updates, so switching it on has a real cost the owner must
     * choose deliberately.
     *
     * @param array<string, mixed> $env Environment.
     * @return array<string, mixed>
     */
    private static function checkFileEdit(array $env): array
    {
        $edit = $env['disallow_file_edit'] ?? null;
        $mods = $env['disallow_file_mods'] ?? null;
        $editOn = $edit === true;
        $modsOn = $mods === true;

        $fix = __(
            'Add these two lines to wp-config.php, above the line that says "That\'s all, stop editing!". '
            . 'The theme never edits wp-config.php for you: a single typo there can white-screen the whole site, '
            . 'so this change must be made by hand and checked afterwards.',
            'traydigita-core'
        );

        if ($editOn && $modsOn) {
            return [
                'id' => 'file-edit',
                'title' => __('File editing locked', 'traydigita-core'),
                'status' => 'pass',
                'explanation' => __(
                    'DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS are both set. '
                    . 'Even an administrator account that is taken over cannot edit plugin or theme files '
                    . 'from the dashboard, and cannot install or update plugins or themes from the dashboard either.',
                    'traydigita-core'
                ),
                'fix' => __('No action needed.', 'traydigita-core'),
            ];
        }

        if ($editOn || $modsOn) {
            $which = $editOn
                ? __('DISALLOW_FILE_EDIT is set, but DISALLOW_FILE_MODS is not.', 'traydigita-core')
                : __('DISALLOW_FILE_MODS is set, but DISALLOW_FILE_EDIT is not.', 'traydigita-core');
            return [
                'id' => 'file-edit',
                'title' => __('File editing partly locked', 'traydigita-core'),
                'status' => 'warning',
                'explanation' => $which . ' ' . __(
                    'The dashboard file editor is only half locked: an attacker with an administrator account '
                    . 'can still use whichever half is open.',
                    'traydigita-core'
                ),
                'fix' => $fix,
                'code' => self::WP_CONFIG_SNIPPET,
            ];
        }

        return [
            'id' => 'file-edit',
            'title' => __('File editing not locked', 'traydigita-core'),
            'status' => 'warning',
            'explanation' => __(
                'Neither DISALLOW_FILE_EDIT nor DISALLOW_FILE_MODS is defined in wp-config.php, '
                . 'so any administrator account can edit plugin and theme files from the dashboard. '
                . 'That turns a stolen administrator password into full code execution on the server. '
                . 'Note that setting DISALLOW_FILE_MODS also disables plugin and theme installs and updates '
                . 'from the dashboard — updates then have to be done another way (for example WP-CLI or FTP).',
                'traydigita-core'
            ),
            'fix' => $fix,
            'code' => self::WP_CONFIG_SNIPPET,
        ];
    }

    /**
     * Audit file permissions for wp-config.php, .htaccess, and the
     * uploads directory.
     *
     * A path that is world-writable (other-write bit set) is a
     * fail: any user on the server could change it. Group-writable
     * is a warning. A missing path is a warning too - it cannot be
     * audited (`.htaccess` legitimately does not exist on nginx
     * servers, and the uploads directory may use a custom path).
     * The overall status is the worst of the three.
     *
     * @param array<string, mixed> $env Environment.
     * @return array<string, mixed>
     */
    private static function checkFilePermissions(array $env): array
    {
        $files = isset($env['files']) && is_array($env['files']) ? $env['files'] : [];
        $labels = [
            'wp-config.php' => __('wp-config.php (database password and secret keys)', 'traydigita-core'),
            '.htaccess' => __('.htaccess (server rules)', 'traydigita-core'),
            'uploads' => __('Uploads directory', 'traydigita-core'),
        ];
        $details = [];
        $worst = 'pass';
        foreach ($labels as $key => $label) {
            $perms = $files[$key] ?? null;
            if (!is_int($perms)) {
                $details[] = [
                    'label' => $label,
                    'perms' => '',
                    'status' => 'warning',
                    'note' => __('Not found — could not be checked.', 'traydigita-core'),
                ];
                $worst = self::worstStatus($worst, 'warning');
                continue;
            }
            $octal = sprintf('%04o', $perms & 0777);
            if (($perms & 0002) !== 0) {
                $details[] = [
                    'label' => $label,
                    'perms' => $octal,
                    'status' => 'fail',
                    'note' => __('World-writable: any user on this server can change it.', 'traydigita-core'),
                ];
                $worst = self::worstStatus($worst, 'fail');
            } elseif (($perms & 0020) !== 0) {
                $details[] = [
                    'label' => $label,
                    'perms' => $octal,
                    'status' => 'warning',
                    'note' => __('Group-writable: anyone in the file’s group can change it.', 'traydigita-core'),
                ];
                $worst = self::worstStatus($worst, 'warning');
            } else {
                $details[] = [
                    'label' => $label,
                    'perms' => $octal,
                    'status' => 'pass',
                    'note' => __('Not writable by group or others.', 'traydigita-core'),
                ];
            }
        }

        if ($worst === 'pass') {
            $explanation = __(
                'wp-config.php, .htaccess, and the uploads directory are not writable by group or others.',
                'traydigita-core'
            );
            $fix = __('No action needed.', 'traydigita-core');
        } elseif ($worst === 'fail') {
            $explanation = __(
                'At least one checked path is world-writable. Any other user account on this server '
                . 'could change it — for wp-config.php that means reading the database password, '
                . 'and for the uploads directory it means planting files.',
                'traydigita-core'
            );
            $fix = '';
        } else {
            $explanation = __(
                'At least one checked path is group-writable or could not be found. '
                . 'Group-writable files can be changed by any account in the file’s group.',
                'traydigita-core'
            );
            $fix = '';
        }
        if ($fix === '') {
            $fix = __(
                'Fix the permissions over FTP, SSH, or the hosting file manager — the web server may not '
                . 'own these files, so the theme does not change permissions itself. Recommended: '
                . 'wp-config.php 0600 (or 0640), .htaccess 0644, uploads directory 0755. '
                . 'After changing wp-config.php, reload the site to confirm it still works.',
                'traydigita-core'
            );
        }

        return [
            'id' => 'file-permissions',
            'title' => __('File permissions', 'traydigita-core'),
            'status' => $worst,
            'explanation' => $explanation,
            'fix' => $fix,
            'details' => $details,
        ];
    }

    /**
     * Check whether a user named `admin` exists.
     *
     * `admin` is the first username every brute-force attack tries.
     * Existing is a fail; the fix is manual (create a new
     * administrator, move the content over, then delete `admin`) -
     * deleting a user is destructive, so no button is offered.
     *
     * @param array<string, mixed> $env Environment.
     * @return array<string, mixed>
     */
    private static function checkAdminUser(array $env): array
    {
        if (!empty($env['admin_user_exists'])) {
            return [
                'id' => 'admin-user',
                'title' => __('Default admin username', 'traydigita-core'),
                'status' => 'fail',
                'explanation' => __(
                    'A user named "admin" exists. It is the first username every automated login attack tries, '
                    . 'so an attacker only needs to guess the password — half the login is already known.',
                    'traydigita-core'
                ),
                'fix' => __(
                    'Create a new administrator account with a different username and a strong password, '
                    . 'log in as the new account, reassign the "admin" user’s posts to it, '
                    . 'then delete the "admin" user. Do not just rename it in the database — '
                    . 'use Users → Add New in wp-admin so posts and capabilities move correctly.',
                    'traydigita-core'
                ),
            ];
        }
        return [
            'id' => 'admin-user',
            'title' => __('Default admin username', 'traydigita-core'),
            'status' => 'pass',
            'explanation' => __(
                'No user named "admin" exists, so automated attacks cannot rely on that username.',
                'traydigita-core'
            ),
            'fix' => __('No action needed.', 'traydigita-core'),
        ];
    }

    /**
     * Check whether PHP errors are displayed to visitors.
     *
     * WordPress defaults WP_DEBUG_DISPLAY to true when WP_DEBUG is
     * on and the constant is not defined, so an undefined
     * WP_DEBUG_DISPLAY counts as "on" whenever WP_DEBUG is on.
     * Displaying errors is a fail: error messages can leak file
     * paths, plugin names, and database details to any visitor.
     * WP_DEBUG on with display off is a warning: debug output is
     * hidden, but make sure logging does not fill the disk or leak
     * through a public log file.
     *
     * @param array<string, mixed> $env Environment.
     * @return array<string, mixed>
     */
    private static function checkDebugDisplay(array $env): array
    {
        $debug = !empty($env['wp_debug']);
        $displayConstant = $env['wp_debug_display'] ?? null;
        // WordPress default: WP_DEBUG_DISPLAY is true when WP_DEBUG
        // is on and the constant is not defined.
        $display = $displayConstant === null ? $debug : (bool) $displayConstant;
        $iniDisplay = !empty($env['display_errors']);

        if ($display || $iniDisplay) {
            return [
                'id' => 'debug-display',
                'title' => __('Debug display', 'traydigita-core'),
                'status' => 'fail',
                'explanation' => __(
                    'PHP errors are shown to visitors. Error messages can reveal file paths, '
                    . 'plugin and theme names, and database details — information an attacker uses '
                    . 'to pick the next attack. This must be off on a live site.',
                    'traydigita-core'
                ),
                'fix' => __(
                    'In wp-config.php set define( \'WP_DEBUG_DISPLAY\', false ); and, if debugging is '
                    . 'needed, define( \'WP_DEBUG_LOG\', true ); instead so errors go to '
                    . 'wp-content/debug.log rather than to the screen. Also ask the host to turn '
                    . 'display_errors off in PHP. The theme never edits wp-config.php for you.',
                    'traydigita-core'
                ),
            ];
        }

        if ($debug) {
            return [
                'id' => 'debug-display',
                'title' => __('Debug display', 'traydigita-core'),
                'status' => 'warning',
                'explanation' => __(
                    'WP_DEBUG is on, but errors are hidden from visitors. That is safe to display, '
                    . 'but check that WP_DEBUG_LOG (if on) writes to a log file visitors cannot open, '
                    . 'and that the log does not grow without limit.',
                    'traydigita-core'
                ),
                'fix' => __(
                    'On a live site WP_DEBUG should normally be off. If you keep it on for troubleshooting, '
                    . 'make sure wp-content/debug.log is not publicly readable and delete it when done.',
                    'traydigita-core'
                ),
            ];
        }

        return [
            'id' => 'debug-display',
            'title' => __('Debug display', 'traydigita-core'),
            'status' => 'pass',
            'explanation' => __(
                'WP_DEBUG is off and PHP errors are not displayed to visitors.',
                'traydigita-core'
            ),
            'fix' => __('No action needed.', 'traydigita-core'),
        ];
    }

    /**
     * Check the XML-RPC status.
     *
     * A missing `xmlrpc.php` or a disabled `xmlrpc_enabled` filter
     * is a pass. An enabled XML-RPC endpoint is a warning, not a
     * fail: it is a real brute-force and pingback-abuse vector, but
     * the WordPress mobile apps, Jetpack, and some publishing tools
     * need it, so disabling it is the site owner's call.
     *
     * @param array<string, mixed> $env Environment.
     * @return array<string, mixed>
     */
    private static function checkXmlRpc(array $env): array
    {
        if (empty($env['xmlrpc_file_exists'])) {
            return [
                'id' => 'xml-rpc',
                'title' => __('XML-RPC', 'traydigita-core'),
                'status' => 'pass',
                'explanation' => __(
                    'xmlrpc.php is not present, so the XML-RPC endpoint does not exist on this site.',
                    'traydigita-core'
                ),
                'fix' => __('No action needed.', 'traydigita-core'),
            ];
        }

        if (empty($env['xmlrpc_enabled'])) {
            return [
                'id' => 'xml-rpc',
                'title' => __('XML-RPC', 'traydigita-core'),
                'status' => 'pass',
                'explanation' => __(
                    'xmlrpc.php exists, but XML-RPC is disabled (the xmlrpc_enabled filter returns false), '
                    . 'so login attempts through it are rejected.',
                    'traydigita-core'
                ),
                'fix' => __('No action needed.', 'traydigita-core'),
            ];
        }

        return [
            'id' => 'xml-rpc',
            'title' => __('XML-RPC', 'traydigita-core'),
            'status' => 'warning',
            'explanation' => __(
                'XML-RPC is enabled. It lets remote tools publish to the site, but it is also a classic '
                . 'brute-force target: one request can test hundreds of passwords, and pingbacks can be '
                . 'abused for denial-of-service attacks. If no mobile app, Jetpack connection, or remote '
                . 'publishing tool needs it, turn it off.',
                'traydigita-core'
            ),
            'fix' => __(
                'Add the snippet below to a child theme’s functions.php or a code-snippets plugin. '
                . 'Do not delete xmlrpc.php by hand — WordPress updates restore it.',
                'traydigita-core'
            ),
            'code' => self::XMLRPC_SNIPPET,
        ];
    }

    /**
     * Check whether the WordPress version is disclosed in feeds
     * and page headers.
     *
     * A disclosed version is a warning, not a fail: hiding the
     * version is obscurity, not a fix - an outdated WordPress is
     * still vulnerable. The real fix is keeping WordPress updated;
     * hiding the version only makes mass scanning slightly harder.
     *
     * @param array<string, mixed> $env Environment.
     * @return array<string, mixed>
     */
    private static function checkVersionDisclosure(array $env): array
    {
        if (!empty($env['version_disclosed'])) {
            return [
                'id' => 'version-disclosure',
                'title' => __('WordPress version disclosure', 'traydigita-core'),
                'status' => 'warning',
                'explanation' => __(
                    'The WordPress version is shown in the site’s feeds and/or page headers '
                    . '(the generator tag). Attackers scan for that version to find sites running '
                    . 'a WordPress release with known vulnerabilities. Hiding it does not fix an '
                    . 'outdated install — keeping WordPress updated is the real fix.',
                    'traydigita-core'
                ),
                'fix' => __(
                    'Add the snippet below to a child theme’s functions.php or a code-snippets plugin, '
                    . 'and keep WordPress, plugins, and themes updated.',
                    'traydigita-core'
                ),
                'code' => self::GENERATOR_SNIPPET,
            ];
        }
        return [
            'id' => 'version-disclosure',
            'title' => __('WordPress version disclosure', 'traydigita-core'),
            'status' => 'pass',
            'explanation' => __(
                'The WordPress version is not shown in the site’s feeds or page headers.',
                'traydigita-core'
            ),
            'fix' => __('No action needed.', 'traydigita-core'),
        ];
    }

    /**
     * Return the worse of two statuses (fail > warning > pass).
     *
     * @param string $current Worst status so far.
     * @param string $candidate New status to compare.
     * @return string
     */
    private static function worstStatus(string $current, string $candidate): string
    {
        $rank = ['pass' => 0, 'warning' => 1, 'fail' => 2];
        $currentRank = $rank[$current] ?? 0;
        $candidateRank = $rank[$candidate] ?? 0;
        return $candidateRank > $currentRank ? $candidate : $current;
    }
}
