<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use function __;
use function array_filter;
use function array_flip;
use function array_map;
use function array_values;
use function implode;
use function is_array;
use function is_string;
use function rawurlencode;
use function strtoupper;
use function trim;

/**
 * Binance crypto provider.
 *
 * Uses the public market-data endpoint (no API key, weight-based
 * rate limits). Prices are USDT pairs, so the display currency is
 * always USDT - never USD or IDR. There is no Pro tier for public
 * market data: Binance API keys are for trading endpoints only, so
 * this provider has no key field.
 *
 * Note: api.binance.com returned HTTP 451 ("restricted location")
 * from the build sandbox on 2026-11-21, so live prices could not be
 * verified from there. The endpoint contract itself
 * (`/api/v3/ticker/24hr?symbols=[...]`) is the documented public
 * one; on a host where Binance is reachable it works, and on a
 * blocked host the widget shows an honest empty state.
 *
 * Coin ids are CoinGecko ids; the built-in map below translates the
 * common ones to USDT symbols. Unmapped ids are skipped, never guessed.
 *
 * @see https://developers.binance.com/docs/binance-spot-api-docs/rest-api/public-api-endpoints
 */
final class BinanceCryptoProvider extends AbstractCryptoPriceProvider
{
    /**
     * Public endpoint host.
     */
    public const string HOST = 'api.binance.com';

    /**
     * CoinGecko id => Binance USDT symbol for the common coins.
     *
     * @var array<string, string>
     */
    public const array SYMBOL_MAP = [
        'bitcoin' => 'BTCUSDT',
        'ethereum' => 'ETHUSDT',
        'solana' => 'SOLUSDT',
        'binancecoin' => 'BNBUSDT',
        'ripple' => 'XRPUSDT',
        'cardano' => 'ADAUSDT',
        'dogecoin' => 'DOGEUSDT',
        'tron' => 'TRXUSDT',
        'chainlink' => 'LINKUSDT',
        'avalanche-2' => 'AVAXUSDT',
        'polkadot' => 'DOTUSDT',
        'litecoin' => 'LTCUSDT',
        'bitcoin-cash' => 'BCHUSDT',
        'uniswap' => 'UNIUSDT',
        'cosmos' => 'ATOMUSDT',
        'stellar' => 'XLMUSDT',
        'ethereum-classic' => 'ETCUSDT',
        'filecoin' => 'FILUSDT',
        'aptos' => 'APTUSDT',
        'arbitrum' => 'ARBUSDT',
    ];

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'binance';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('Binance', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('Public market data, no API key. Prices are USDT pairs, so the display currency is always USDT — never USD or IDR. There is no Pro tier for public market data.', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function supportsCurrency(string $currency): bool
    {
        return strtoupper(trim($currency)) === 'USDT';
    }

    /**
     * @inheritDoc
     */
    public function requiresKey(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function getSettings(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function fetchPrices(array $coinIds, string $currency): array
    {
        $ids = array_values(array_filter(array_map(
            static fn(string $id): string => trim($id),
            $coinIds
        )));
        if ($ids === []) {
            return [];
        }
        $symbols = [];
        foreach ($ids as $id) {
            $symbol = self::SYMBOL_MAP[$id] ?? null;
            if ($symbol !== null) {
                $symbols[$id] = $symbol;
            }
        }
        if ($symbols === []) {
            return [];
        }
        $url = 'https://' . self::HOST . '/api/v3/ticker/24hr?symbols='
            . rawurlencode('["' . implode('","', array_values($symbols)) . '"]');
        $data = $this->fetchJson($url, self::HOST);
        if ($data === null || isset($data['code'])) {
            return [];
        }
        $bySymbol = array_flip($symbols);
        $prices = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = isset($row['symbol']) && is_string($row['symbol']) ? $row['symbol'] : '';
            $id = $bySymbol[$symbol] ?? null;
            if ($id === null) {
                continue;
            }
            $price = $this->numericValue($row['lastPrice'] ?? null);
            if ($price === null) {
                continue;
            }
            $prices[$id] = [
                'price' => $price,
                'change_24h' => $this->numericValue($row['priceChangePercent'] ?? null),
            ];
        }
        return $prices;
    }
}
