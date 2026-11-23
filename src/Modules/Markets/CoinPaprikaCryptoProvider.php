<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use function __;
use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function rawurlencode;
use function strtoupper;
use function trim;

/**
 * CoinPaprika crypto provider.
 *
 * Uses the free public tier (no API key, 20,000 calls a month for
 * personal use). One request per coin (`/v1/tickers/{coin_id}`),
 * with the `quotes` parameter for the fiat currency — verified live
 * 2026-11-21: USD and IDR quotes are both returned.
 *
 * CoinPaprika paid plans exist, but their Pro authentication (host /
 * header) could not be verified from the public docs, so this
 * provider is public-only: requiresKey() is false and no key field
 * is offered. Do not invent a Pro host or header.
 *
 * Coin ids are CoinGecko ids; the built-in map below translates the
 * common ones to CoinPaprika ids (btc-bitcoin, eth-ethereum…).
 * Unmapped ids are skipped, never guessed.
 *
 * @see https://docs.coinpaprika.com/
 */
final class CoinPaprikaCryptoProvider extends AbstractCryptoPriceProvider
{
    /**
     * Public endpoint host.
     */
    public const string HOST = 'api.coinpaprika.com';

    /**
     * CoinGecko id => CoinPaprika id for the common coins.
     *
     * @var array<string, string>
     */
    public const array COIN_MAP = [
        'bitcoin' => 'btc-bitcoin',
        'ethereum' => 'eth-ethereum',
        'solana' => 'sol-solana',
        'tether' => 'usdt-tether',
        'binancecoin' => 'bnb-binance-coin',
        'ripple' => 'xrp-xrp',
        'cardano' => 'ada-cardano',
        'dogecoin' => 'doge-dogecoin',
        'tron' => 'trx-tron',
        'chainlink' => 'link-chainlink',
        'avalanche-2' => 'avax-avalanche',
        'polkadot' => 'dot-polkadot',
        'litecoin' => 'ltc-litecoin',
        'bitcoin-cash' => 'bch-bitcoin-cash',
        'uniswap' => 'uni-uniswap',
        'cosmos' => 'atom-cosmos',
        'stellar' => 'xlm-stellar',
        'ethereum-classic' => 'etc-ethereum-classic',
        'filecoin' => 'fil-filecoin',
        'aptos' => 'apt-aptos',
        'arbitrum' => 'arb-arbitrum',
    ];

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'coinpaprika';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('CoinPaprika', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('Free public tier, no API key needed. Paid plans exist, but the theme uses the keyless tier only.', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), ['USD', 'IDR'], true);
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
        $currency = strtoupper(trim($currency));
        if (!$this->supportsCurrency($currency)) {
            $currency = 'USD';
        }
        $prices = [];
        foreach ($ids as $id) {
            $paprikaId = self::COIN_MAP[$id] ?? null;
            if ($paprikaId === null) {
                continue;
            }
            $url = 'https://' . self::HOST . '/v1/tickers/' . rawurlencode($paprikaId)
                . '?quotes=' . rawurlencode($currency);
            $data = $this->fetchJson($url, self::HOST);
            if ($data === null) {
                continue;
            }
            $quote = $data['quotes'][$currency] ?? null;
            if (!is_array($quote)) {
                continue;
            }
            $price = $this->numericValue($quote['price'] ?? null);
            if ($price === null) {
                continue;
            }
            $prices[$id] = [
                'price' => $price,
                'change_24h' => $this->numericValue($quote['percent_change_24h'] ?? null),
            ];
        }
        return $prices;
    }
}
