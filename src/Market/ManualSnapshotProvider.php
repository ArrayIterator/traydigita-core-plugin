<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Market;

use function array_map;
use function explode;
use function in_array;
use function is_numeric;
use function mb_strtolower;
use function time;
use function trim;

/**
 * Manual snapshot provider: the working default for every market widget.
 *
 * Quotes are entered by the site owner in the widget form (or supplied
 * by the Customizer) as snapshot rows. No network request is ever made,
 * so this provider always works and never invents a price - a symbol
 * without a snapshot simply has no quote.
 *
 * Snapshot row shape (also used by the widget form textarea):
 *   label|price|change|change_percent|buyback
 * Only the label and price are required; the rest may be empty.
 */
final class ManualSnapshotProvider implements MarketDataProvider
{
    /**
     * @var array<int, array{label: string, price: float|null, change: float|null,
     *     change_percent: float|null, buyback: float|null, updated_at: int|null}>
     */
    private array $rows;

    /**
     * @param array<int, array<string, mixed>> $rows Snapshot rows.
     * @param int|null $updatedAt Fallback snapshot timestamp (defaults to now).
     */
    public function __construct(array $rows, ?int $updatedAt = null)
    {
        $updatedAt ??= time();
        $this->rows = [];
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $this->rows[] = [
                'label' => $label,
                'price' => isset($row['price']) && is_numeric($row['price']) ? (float) $row['price'] : null,
                'change' => isset($row['change']) && is_numeric($row['change']) ? (float) $row['change'] : null,
                'change_percent' => isset($row['change_percent']) && is_numeric($row['change_percent'])
                    ? (float) $row['change_percent']
                    : null,
                'buyback' => isset($row['buyback']) && is_numeric($row['buyback'])
                    ? (float) $row['buyback']
                    : null,
                'updated_at' => isset($row['updated_at']) && is_numeric($row['updated_at'])
                    ? (int) $row['updated_at']
                    : $updatedAt,
            ];
        }
    }

    /**
     * Parse snapshot rows from widget-form textarea lines.
     *
     * Each line is `label|price|change|change_percent|buyback`.
     *
     * @param string $text Raw textarea content.
     * @return array<int, array<string, mixed>>
     */
    public static function parseLines(string $text): array
    {
        $rows = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $rows[] = [
                'label' => $parts[0] ?? '',
                'price' => $parts[1] ?? null,
                'change' => $parts[2] ?? null,
                'change_percent' => $parts[3] ?? null,
                'buyback' => $parts[4] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function getQuotes(array $symbols): array
    {
        $wanted = array_map(
            static fn(string $symbol): string => mb_strtolower(trim($symbol)),
            $symbols
        );
        $quotes = [];
        foreach ($this->rows as $row) {
            if ($wanted !== [] && !in_array(mb_strtolower($row['label']), $wanted, true)) {
                continue;
            }
            $quotes[] = new MarketQuote(
                $row['label'],
                $row['label'],
                $row['price'],
                $row['change'],
                $row['change_percent'],
                $row['buyback'],
                $row['updated_at'],
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
        return 'Manual snapshot';
    }
}
