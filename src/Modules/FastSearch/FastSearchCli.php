<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\FastSearch;

use function sprintf;

/**
 * WP-CLI commands for the Fast Search indexes (`wp td-search`).
 *
 * Registered from `FastSearchModule::boot()` only when WP-CLI is
 * running (`defined('WP_CLI') && WP_CLI`), so the command class
 * is never loaded on a web request. WP-CLI is the recommended way
 * to install the indexes on a large site: unlike the admin-page
 * build, a CLI run is not subject to HTTP timeouts.
 *
 * Commands:
 * - `wp td-search status`: table of all five indexes with their
 *   installed/missing state.
 * - `wp td-search install [--optimize]`: install every missing
 *   index, then verify; with `--optimize`, run OPTIMIZE TABLE on
 *   the posts and terms tables afterwards.
 *
 * The class talks to WP_CLI only through its public API, guarded
 * by class_exists() checks in the methods that use it, so the
 * file can be linted and loaded without WP-CLI present.
 */
final class FastSearchCli
{
    /**
     * Check the status of all required search indexes.
     *
     * ## EXAMPLES
     *
     *     wp td-search status
     *
     * @return void
     */
    public function status(): void
    {
        $definitions = IndexManager::definitions();
        $statuses = IndexManager::checkStatus();
        $rows = [];
        foreach ($definitions as $key => $definition) {
            $rows[] = [
                'Table' => $definition['table'],
                'Index' => $definition['name'],
                'Type' => $definition['type'],
                'Columns' => $definition['columns'],
                'Status' => !empty($statuses[$key]) ? 'Installed' : 'Missing',
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['Table', 'Index', 'Type', 'Columns', 'Status']);
    }

    /**
     * Safely install missing FULLTEXT and composite indexes via CLI.
     *
     * ## OPTIONS
     *
     * [--optimize]
     * : Run OPTIMIZE TABLE after installing indexes.
     *
     * ## EXAMPLES
     *
     *     wp td-search install
     *     wp td-search install --optimize
     *
     * @param array<int, string> $args Positional arguments (unused).
     * @param array<string, mixed> $assocArgs Associative arguments.
     * @return void
     */
    public function install(array $args, array $assocArgs): void
    {
        global $wpdb;
        $definitions = IndexManager::definitions();
        $statuses = IndexManager::checkStatus();
        foreach ($definitions as $key => $definition) {
            if (!empty($statuses[$key])) {
                \WP_CLI::log(
                    sprintf('Skipping %s on %s (already exists).', $definition['name'], $definition['table'])
                );
                continue;
            }
            \WP_CLI::log(
                sprintf('Creating %s on %s...', $definition['name'], $definition['table'])
            );
            $result = IndexManager::installIndex($key);
            if (!$result['success']) {
                \WP_CLI::warning(
                    sprintf('Failed creating %s: %s', $definition['name'], $result['message'])
                );
            }
        }
        IndexManager::checkStatus();
        if (isset($assocArgs['optimize'])) {
            \WP_CLI::log(
                sprintf('Running OPTIMIZE TABLE on %s and %s...', $wpdb->posts, $wpdb->terms)
            );
            $wpdb->query("OPTIMIZE TABLE `{$wpdb->posts}`, `{$wpdb->terms}`");
        }
        \WP_CLI::success('Index verification and installation finished.');
    }
}
