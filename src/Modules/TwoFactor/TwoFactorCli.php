<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use TrayDigita\WP\Plugin\Core\Modules\Passkeys\PasskeysModule;
use WP_User;
use function __;
use function count;
use function ctype_digit;
use function date_i18n;
use function delete_user_meta;
use function get_option;
use function get_user_by;
use function sprintf;

/**
 * WP-CLI commands for the Two-Factor module (`wp traydigita-2fa`).
 *
 * Registered from `TwoFactorModule::boot()` only when WP-CLI is
 * running (`defined('WP_CLI') && WP_CLI`), so the command class
 * is never loaded on a web request. The class talks to WP_CLI
 * only through its public API with fully-qualified calls, so
 * the file can be linted and loaded without WP-CLI present.
 *
 * Commands:
 * - `wp traydigita-2fa status <user>`: show the user's 2FA
 *   state (methods, backup codes left, lockout).
 * - `wp traydigita-2fa disable <user> [--yes]`: remove every
 *   2FA method and trusted device for the user. This is the
 *   documented anti-lockout recovery: it needs shell access to
 *   the server, so it is not a backdoor. Prompts for
 *   confirmation unless `--yes` is given.
 * - `wp traydigita-2fa reset-lockout <user>`: clear the
 *   failed-attempt counter and lockout for the user.
 *
 * The `<user>` argument is a login, an id, or an email address.
 */
final class TwoFactorCli
{
    /**
     * Register the `traydigita-2fa` command.
     *
     * @return void
     */
    public static function register(): void
    {
        \WP_CLI::add_command('traydigita-2fa', self::class);
    }

    /**
     * Resolve a user by login, id, or email address.
     *
     * @param string $identifier Login, numeric id, or email.
     * @return WP_User|null
     */
    private static function resolveUser(string $identifier): ?WP_User
    {
        if (ctype_digit($identifier)) {
            $user = get_user_by('id', (int) $identifier);
            if ($user instanceof WP_User) {
                return $user;
            }
        }
        $user = get_user_by('login', $identifier);
        if ($user instanceof WP_User) {
            return $user;
        }
        $user = get_user_by('email', $identifier);
        return $user instanceof WP_User ? $user : null;
    }

    /**
     * Show a user's two-factor state.
     *
     * ## OPTIONS
     *
     * <user>
     * : User login, id, or email address.
     *
     * ## EXAMPLES
     *
     *     wp traydigita-2fa status admin
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, mixed> $assocArgs Associative arguments (unused).
     * @return void
     */
    public function status(array $args, array $assocArgs): void
    {
        $user = self::resolveUser((string) ($args[0] ?? ''));
        if (!$user instanceof WP_User) {
            // WP_CLI::error() exits with the default $exit = true;
            // the return only guards the non-exiting path.
            \WP_CLI::error(__('User not found.', 'traydigita-core'));
            return;
        }
        $userId = (int) $user->ID;
        \WP_CLI::log(sprintf(__('User: %s (#%d)', 'traydigita-core'), $user->user_login, $userId));
        \WP_CLI::log(sprintf(
            __('Authenticator app (TOTP): %s', 'traydigita-core'),
            TwoFactorModule::hasTotp($userId) ? __('enabled', 'traydigita-core') : __('not set up', 'traydigita-core')
        ));
        if (TwoFactorModule::hasTotp($userId)) {
            \WP_CLI::log(sprintf(
                /* translators: %d is the number of unused backup codes. */
                __('Backup codes left: %d', 'traydigita-core'),
                TwoFactorModule::remainingBackupCodes($userId)
            ));
        }
        $passkeys = PasskeysModule::getPasskeys($userId);
        \WP_CLI::log(sprintf(
            /* translators: %d is the number of registered passkeys. */
            __('Passkeys: %d', 'traydigita-core'),
            count($passkeys)
        ));
        foreach ($passkeys as $passkey) {
            \WP_CLI::log(sprintf(
                '  - %s (%s)',
                (string) ($passkey['label'] ?? ''),
                date_i18n(get_option('date_format', 'F j, Y'), (int) ($passkey['created'] ?? 0))
            ));
        }
        $devices = TwoFactorModule::getDevices($userId);
        \WP_CLI::log(sprintf(
            /* translators: %d is the number of trusted devices. */
            __('Trusted devices: %d', 'traydigita-core'),
            count($devices)
        ));
        \WP_CLI::log(sprintf(
            __('Locked out: %s', 'traydigita-core'),
            TwoFactorModule::isLockedOut($userId) ? __('yes', 'traydigita-core') : __('no', 'traydigita-core')
        ));
    }

    /**
     * Disable two-factor authentication for a user.
     *
     * Removes the authenticator app, backup codes, all passkeys,
     * and all trusted devices, and clears any lockout. The user
     * can enroll again from their profile page. This is the
     * documented recovery for a locked-out account - it requires
     * shell access to the server.
     *
     * ## OPTIONS
     *
     * <user>
     * : User login, id, or email address.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp traydigita-2fa disable admin
     *     wp traydigita-2fa disable admin --yes
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, mixed> $assocArgs Associative arguments.
     * @return void
     */
    public function disable(array $args, array $assocArgs): void
    {
        $user = self::resolveUser((string) ($args[0] ?? ''));
        if (!$user instanceof WP_User) {
            // WP_CLI::error() exits with the default $exit = true;
            // the return only guards the non-exiting path.
            \WP_CLI::error(__('User not found.', 'traydigita-core'));
            return;
        }
        $userId = (int) $user->ID;
        if (!isset($assocArgs['yes'])) {
            \WP_CLI::confirm(sprintf(
                __('Remove all two-factor methods for %s? They will be able to log in with their password only.', 'traydigita-core'),
                $user->user_login
            ));
        }
        TwoFactorModule::removeTotp($userId);
        PasskeysModule::setPasskeys($userId, []);
        TwoFactorModule::revokeAllDevices($userId);
        TwoFactorModule::clearAttempts($userId);
        delete_user_meta($userId, TwoFactorModule::META_ENROLLED_AT);
        TwoFactorModule::logEvent($userId, 'all', 'success', 'disabled_by_cli');
        \WP_CLI::success(sprintf(
            __('Two-factor authentication disabled for %s.', 'traydigita-core'),
            $user->user_login
        ));
    }

    /**
     * Clear the failed-attempt counter and lockout for a user.
     *
     * The user's methods are untouched - this only lifts a
     * temporary lockout so they can try again.
     *
     * ## OPTIONS
     *
     * <user>
     * : User login, id, or email address.
     *
     * ## EXAMPLES
     *
     *     wp traydigita-2fa reset-lockout admin
     *
     * @param array<int, string> $args Positional arguments.
     * @param array<string, mixed> $assocArgs Associative arguments (unused).
     * @return void
     */
    public function resetLockout(array $args, array $assocArgs): void
    {
        $user = self::resolveUser((string) ($args[0] ?? ''));
        if (!$user instanceof WP_User) {
            // WP_CLI::error() exits with the default $exit = true;
            // the return only guards the non-exiting path.
            \WP_CLI::error(__('User not found.', 'traydigita-core'));
            return;
        }
        TwoFactorModule::clearAttempts((int) $user->ID);
        \WP_CLI::success(sprintf(
            __('Lockout cleared for %s.', 'traydigita-core'),
            $user->user_login
        ));
    }
}
