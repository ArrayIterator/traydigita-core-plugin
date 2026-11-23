<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Markets;

use function __;
use function array_filter;
use function array_map;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function rawurlencode;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * CoinGecko crypto provider (default).
 *
 * Uses the public simple-price endpoint (no API key) by default. When
 * a Pro API key is set, the Pro endpoint is used instead, with the
 * `x-cg-pro-api-key` header. Supports USD and IDR. The public tier is
 * rate-limited, so results are cached by the widget layer (transient,
 * TTL from the Markets module settings) and only refreshed on save,
 * WP-Cron, or admin preview.
 *
 * @see https://www.coingecko.com/en/api/documentation
 */
final class CoinGeckoCryptoProvider extends AbstractCryptoPriceProvider
{
    /**
     * Free public endpoint host.
     */
    public const string PUBLIC_HOST = 'api.coingecko.com';

    /**
     * Pro endpoint host (used only when an API key is set).
     */
    public const string PRO_HOST = 'pro-api.coingecko.com';

    /**
     * @param string $apiKey Optional CoinGecko Pro API key (empty = public tier).
     */
    public function __construct(private readonly string $apiKey = '')
    {
    }

    /**
     * @inheritDoc
     */
    public function getId(): string
    {
        return 'coingecko';
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return __('CoinGecko', 'traydigita-core');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return __('Free public endpoint, no API key needed. With a Pro API key the Pro endpoint is used instead (higher rate limits).', 'traydigita-core');
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
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getSettings(): array
    {
        return [
            [
                'id' => 'api_key',
                'type' => 'password',
                'label' => __('CoinGecko Pro API key', 'traydigita-core'),
                'description' => __('Optional. Leave empty to use the free public endpoint. Only used when CoinGecko is the selected crypto provider.', 'traydigita-core'),
            ],
        ];
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
        $key = strtolower($currency);
        $host = $this->apiKey !== '' ? self::PRO_HOST : self::PUBLIC_HOST;
        $headers = $this->apiKey !== '' ? ['x-cg-pro-api-key' => $this->apiKey] : [];
        $url = 'https://' . $host . '/api/v3/simple/price?ids='
            . rawurlencode(implode(',', $ids))
            . '&vs_currencies=' . rawurlencode($key)
            . '&include_24hr_change=true';
        $data = $this->fetchJson($url, $host, $headers);
        if ($data === null) {
            return [];
        }
        $prices = [];
        foreach ($ids as $id) {
            $row = $data[$id] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $price = $this->numericValue($row[$key] ?? null);
            if ($price === null) {
                continue;
            }
            $prices[$id] = [
                'price' => $price,
                'change_24h' => $this->numericValue($row[$key . '_24h_change'] ?? null),
            ];
        }
        return $prices;
    }
}
