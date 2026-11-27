<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Seo;

use TrayDigita\WP\Plugin\Core\Modules\SiteKit\SiteKitDetector;
use function delete_transient;
use function get_transient;
use function gmdate;
use function is_array;
use function is_numeric;
use function is_string;
use function rest_do_request;
use function set_transient;
use function strtotime;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SEO dashboard with Search Console data through Google Site Kit.
 *
 * This class only READS data Site Kit already owns: it calls Site
 * Kit's own documented REST datapoint
 * `google-site-kit/v1/modules/search-console/data/searchanalytics`
 * (GET, startDate/endDate/dimensions/limit) through
 * rest_do_request(), in the current admin user's request context,
 * so Site Kit's own permission checks still apply. It never reads
 * or stores Site Kit tokens, never calls Site Kit internals, and
 * never injects any tracking of its own - the theme ships no
 * analytics code at all.
 *
 * Data is cached for one hour in the `traydigita_seo_sc_data`
 * transient; the dashboard offers a refresh that clears the cache
 * and refetches. When Site Kit is not connected, or its
 * Search Console module is not active, the dashboard reports that
 * state instead of data - never empty or invented rows. All
 * response parsing is defensive: an unexpected shape yields an
 * error state, not a fatal.
 *
 * Note: Site Kit is not installed in the test WordPress install,
 * so the live round-trip against a real Site Kit could not be
 * verified here; the route name, parameters, and row shape follow
 * Site Kit's documented Search Console datapoint, and every
 * failure degrades to the honest "unavailable" state.
 */
final class SeoSearchConsole
{
    /**
     * Transient holding the cached dashboard data (1 hour).
     */
    public const string CACHE_KEY = 'traydigita_seo_sc_data';

    /**
     * Cache lifetime in seconds.
     */
    public const int CACHE_SECONDS = 3600;

    /**
     * How many rows to fetch per table (top queries, top pages).
     */
    public const int ROW_LIMIT = 10;

    /**
     * Dashboard state: Site Kit is not connected.
     */
    public const string REASON_NOT_CONNECTED = 'not-connected';

    /**
     * Dashboard state: Site Kit is connected but Search Console is not active.
     */
    public const string REASON_NO_MODULE = 'no-module';

    /**
     * Dashboard state: the Site Kit request failed or returned unusable data.
     */
    public const string REASON_ERROR = 'error';

    /**
     * Dashboard state: data is available.
     */
    public const string REASON_READY = 'ready';

    /**
     * Fetch the dashboard data (cached for one hour unless refreshed).
     *
     * Returns the state plus, when ready, the top queries and top
     * pages (each row: label, clicks, impressions, position) and
     * the totals across the fetched queries. Totals are sums of
     * the fetched rows only, never the whole property.
     *
     * @param bool $refresh True to ignore the cache and refetch from Site Kit.
     * @return array{available: bool, reason: string, queries: array<int, array{label: string, clicks: int, impressions: int, position: float}>, pages: array<int, array{label: string, clicks: int, impressions: int, position: float}>, total_clicks: int, total_impressions: int, start_date: string, end_date: string, cached: bool}
     */
    public static function getData(bool $refresh = false): array
    {
        $endDate = gmdate('Y-m-d', (int) strtotime('-2 days'));
        $startDate = gmdate('Y-m-d', (int) strtotime('-30 days'));
        $empty = [
            'available' => false,
            'reason' => self::REASON_NOT_CONNECTED,
            'queries' => [],
            'pages' => [],
            'total_clicks' => 0,
            'total_impressions' => 0,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cached' => false,
        ];
        if (!SiteKitDetector::isConnected()) {
            return $empty;
        }
        $modules = SiteKitDetector::getActiveModules();
        if (!isset($modules['search-console'])) {
            $empty['reason'] = self::REASON_NO_MODULE;
            return $empty;
        }
        if (!$refresh) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['queries'], $cached['pages'])) {
                /** @var array{available: bool, reason: string, queries: array<int, array{label: string, clicks: int, impressions: int, position: float}>, pages: array<int, array{label: string, clicks: int, impressions: int, position: float}>, total_clicks: int, total_impressions: int, start_date: string, end_date: string, cached: bool} $cached */
                $cached['cached'] = true;
                $cached['available'] = true;
                $cached['reason'] = self::REASON_READY;
                return $cached;
            }
        }
        $queries = self::fetchRows(['query'], $startDate, $endDate);
        $pages = self::fetchRows(['page'], $startDate, $endDate);
        if ($queries === null || $pages === null) {
            $empty['reason'] = self::REASON_ERROR;
            return $empty;
        }
        $totalClicks = 0;
        $totalImpressions = 0;
        foreach ($queries as $row) {
            $totalClicks += $row['clicks'];
            $totalImpressions += $row['impressions'];
        }
        $data = [
            'available' => true,
            'reason' => self::REASON_READY,
            'queries' => $queries,
            'pages' => $pages,
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'cached' => false,
        ];
        set_transient(self::CACHE_KEY, $data, self::CACHE_SECONDS);
        return $data;
    }

    /**
     * Clear the cached dashboard data so the next read refetches from Site Kit.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    /**
     * Fetch one Search Console table from Site Kit's REST datapoint.
     *
     * Sends startDate, endDate, one dimension, and a row limit.
     * Site Kit's default ordering for this datapoint is clicks
     * descending, which is the ordering the dashboard shows.
     * Returns null when the request fails, is denied, or returns
     * an unexpected shape.
     *
     * @param array<int, string> $dimensions Single dimension, 'query' or 'page'.
     * @param string $startDate Start date (Y-m-d).
     * @param string $endDate End date (Y-m-d).
     * @return array<int, array{label: string, clicks: int, impressions: int, position: float}>|null
     */
    private static function fetchRows(array $dimensions, string $startDate, string $endDate): ?array
    {
        $request = new WP_REST_Request(
            'GET',
            '/google-site-kit/v1/modules/search-console/data/searchanalytics'
        );
        $request->set_param('startDate', $startDate);
        $request->set_param('endDate', $endDate);
        $request->set_param('dimensions', $dimensions);
        $request->set_param('limit', self::ROW_LIMIT);
        $response = rest_do_request($request);
        if (!$response instanceof WP_REST_Response) {
            return null;
        }
        if ($response->get_status() < 200 || $response->get_status() >= 300) {
            return null;
        }
        $data = $response->get_data();
        if (!is_array($data)) {
            return null;
        }
        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['keys']) || !is_array($row['keys'])) {
                continue;
            }
            $label = $row['keys'][0] ?? '';
            if (!is_string($label) || $label === '') {
                continue;
            }
            $rows[] = [
                'label' => $label,
                'clicks' => isset($row['clicks']) && is_numeric($row['clicks']) ? (int) $row['clicks'] : 0,
                'impressions' => isset($row['impressions']) && is_numeric($row['impressions']) ? (int) $row['impressions'] : 0,
                'position' => isset($row['position']) && is_numeric($row['position']) ? (float) $row['position'] : 0.0,
            ];
        }
        return $rows;
    }
}
