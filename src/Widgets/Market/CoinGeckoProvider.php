<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Widgets\Market;

use function array_filter;
use function array_keys;
use function array_map;
use function implode;
use function is_array;
use function is_numeric;
use function is_wp_error;
use function rawurlencode;
use function time;
use function trim;
use function wp_parse_url;
use function wp_remote_get;
use function wp_remote_retrieve_body;

/**
 * CoinGecko provider for the crypto widget.
 *
 * Uses the public simple-price endpoint (no API key). The endpoint is
 * allowlisted by host, called with an 8 second timeout, and only ever
 * invoked from admin save, WP-Cron refresh, or the admin preview —
 * never from a frontend render path. On any failure it returns an
 * empty list so the widget falls back to the manual snapshot provider
 * instead of showing a fabricated price.
 *
 * @see https://www.coingecko.com/en/api/documentation
 */
final class CoinGeckoProvider implements MarketDataProvider
{
    /**
     * Only this host may be fetched.
     */
    public const string ALLOWED_HOST = 'api.coingecko.com';

    /**
     * Request timeout in seconds.
     */
    public const int TIMEOUT = 8;

    /**
     * @var array<string, string> CoinGecko id => display label.
     */
    private array $labels;

    /**
     * @var string Fiat currency for prices, e.g. "idr".
     */
    private string $currency;

    /**
     * @param array<string, string> $labels CoinGecko id => display label.
     * @param string $currency Fiat currency code (lowercase, e.g. "idr").
     */
    public function __construct(array $labels, string $currency = 'idr')
    {
        $this->labels = $labels;
        $this->currency = trim($currency) !== '' ? trim($currency) : 'idr';
    }

    /**
     * @inheritDoc
     */
    public function getQuotes(array $symbols): array
    {
        $ids = array_filter(array_map('trim', $symbols));
        if ($ids === []) {
            $ids = array_keys($this->labels);
        }
        if ($ids === []) {
            return [];
        }
        $url = 'https://' . self::ALLOWED_HOST . '/api/v3/simple/price?ids='
            . rawurlencode(implode(',', $ids))
            . '&vs_currencies=' . rawurlencode($this->currency)
            . '&include_24hr_change=true';
        if (wp_parse_url($url, PHP_URL_HOST) !== self::ALLOWED_HOST) {
            return [];
        }
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [];
        }
        $now = time();
        $quotes = [];
        foreach ($ids as $id) {
            $row = $data[$id] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $price = $row[$this->currency] ?? null;
            if (!is_numeric($price)) {
                continue;
            }
            $changePercent = $row[$this->currency . '_24h_change'] ?? null;
            $quotes[] = new MarketQuote(
                $id,
                $this->labels[$id] ?? $id,
                (float) $price,
                null,
                is_numeric($changePercent) ? (float) $changePercent : null,
                null,
                $now,
                $this->getSourceLabel(),
            );
        }
        return $quotes;
    }

    /**
     * @inheritDoc
     */
    public function getSourceLabel(): string
    {
        return 'CoinGecko';
    }
}
