<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use TrayDigita\WP\Plugin\Core\Market\MarketDataProvider;
use TrayDigita\WP\Plugin\Core\Market\MarketQuote;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function is_array;
use function is_numeric;
use function time;
use function trim;

/**
 * Adapts a CryptoPriceProvider to the widget layer's
 * MarketDataProvider contract.
 *
 * The widget layer calls getQuotes() with CoinGecko ids and the
 * instance's display labels; the adapter forwards the ids to the
 * crypto provider and converts the returned prices to MarketQuote
 * rows. A provider failure yields an empty list, so the widget
 * falls back to the manual snapshot (or an honest empty state) —
 * never a fabricated price.
 */
final class CryptoProviderAdapter implements MarketDataProvider
{
    /**
     * @param CryptoPriceProvider $provider Selected crypto provider.
     * @param array<string, string> $labels CoinGecko id => display label.
     * @param string $currency Display currency code.
     */
    public function __construct(
        private readonly CryptoPriceProvider $provider,
        private readonly array $labels,
        private readonly string $currency
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getQuotes(array $symbols): array
    {
        $ids = array_values(array_filter(array_map(
            static fn(string $id): string => trim($id),
            $symbols
        )));
        if ($ids === []) {
            $ids = array_keys($this->labels);
        }
        if ($ids === []) {
            return [];
        }
        $currency = $this->provider->supportsCurrency($this->currency)
            ? $this->currency
            : $this->fallbackCurrency();
        try {
            $prices = $this->provider->fetchPrices($ids, $currency);
        } catch (\Throwable) {
            return [];
        }
        $quotes = [];
        foreach ($ids as $id) {
            $row = $prices[$id] ?? null;
            if (!is_array($row) || !isset($row['price']) || !is_numeric($row['price'])) {
                continue;
            }
            $quotes[] = new MarketQuote(
                $id,
                $this->labels[$id] ?? $id,
                (float) $row['price'],
                null,
                isset($row['change_24h']) && is_numeric($row['change_24h'])
                    ? (float) $row['change_24h']
                    : null,
                null,
                time(),
                $this->provider->getLabel()
            );
        }
        return $quotes;
    }

    /**
     * Currency to use when the requested one is not supported.
     *
     * IDR first (Indodax only quotes IDR), then USD, then USDT
     * (Binance). The order matters: falling through to USDT for an
     * IDR-only provider would serve nothing at all.
     *
     * @return string
     */
    private function fallbackCurrency(): string
    {
        if ($this->provider->supportsCurrency('IDR')) {
            return 'IDR';
        }
        if ($this->provider->supportsCurrency('USD')) {
            return 'USD';
        }
        return 'USDT';
    }

    /**
     * @inheritDoc
     */
    public function getSourceLabel(): string
    {
        return $this->provider->getLabel();
    }
}
