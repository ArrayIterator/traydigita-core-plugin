<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Market;

/**
 * Immutable value object for one market quote row.
 *
 * Prices are stored as plain floats in the provider's currency; the
 * widget partial formats them for display (Indonesian number format).
 * A null price means "no data available" - the widget renders an
 * honest empty state for that row, never a zero or a guessed price.
 */
final class MarketQuote
{
    /**
     * @param string      $symbol        Provider symbol (brand, ticker, CoinGecko id).
     * @param string      $label         Human-readable label shown in the widget.
     * @param float|null  $price         Current price, or null when unknown.
     * @param float|null  $change        Absolute change since the previous snapshot.
     * @param float|null  $changePercent Percentage change since the previous snapshot.
     * @param float|null  $buyback       Buyback price (gold only), or null.
     * @param int|null    $updatedAt     Unix timestamp of the snapshot, or null.
     * @param string      $source        Source label for this quote.
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $label,
        public readonly ?float $price,
        public readonly ?float $change = null,
        public readonly ?float $changePercent = null,
        public readonly ?float $buyback = null,
        public readonly ?int $updatedAt = null,
        public readonly string $source = '',
    ) {
    }

    /**
     * Whether this quote carries a usable price.
     *
     * @return bool
     */
    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    /**
     * Whether the price moved up, down, or is unchanged/unknown.
     *
     * @return string One of "up", "down", "flat".
     */
    public function getDirection(): string
    {
        if ($this->change === null || $this->change === 0.0) {
            return 'flat';
        }
        return $this->change > 0 ? 'up' : 'down';
    }

    /**
     * Convert the quote to a plain array for caching and templates.
     *
     * @return array{
     *     symbol: string,
     *     label: string,
     *     price: float|null,
     *     change: float|null,
     *     change_percent: float|null,
     *     buyback: float|null,
     *     updated_at: int|null,
     *     source: string
     * }
     */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'label' => $this->label,
            'price' => $this->price,
            'change' => $this->change,
            'change_percent' => $this->changePercent,
            'buyback' => $this->buyback,
            'updated_at' => $this->updatedAt,
            'source' => $this->source,
        ];
    }

    /**
     * Rebuild a quote from a cached array produced by toArray().
     *
     * @param array<string, mixed> $data Cached quote data.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['symbol'] ?? ''),
            (string) ($data['label'] ?? ''),
            isset($data['price']) && is_numeric($data['price']) ? (float) $data['price'] : null,
            isset($data['change']) && is_numeric($data['change']) ? (float) $data['change'] : null,
            isset($data['change_percent']) && is_numeric($data['change_percent'])
                ? (float) $data['change_percent']
                : null,
            isset($data['buyback']) && is_numeric($data['buyback']) ? (float) $data['buyback'] : null,
            isset($data['updated_at']) && is_numeric($data['updated_at']) ? (int) $data['updated_at'] : null,
            (string) ($data['source'] ?? ''),
        );
    }
}
