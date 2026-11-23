<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Widgets;

use TrayDigita\WP\Plugin\Core\Modules\Markets\MarketsModule;
use TrayDigita\WP\Plugin\Core\Market\ManualSnapshotProvider;
use TrayDigita\WP\Plugin\Core\Market\MarketDataProvider;
use TrayDigita\WP\Plugin\Core\Market\MarketQuote;
use function __;
use function abs;
use function apply_filters;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function class_exists;
use function explode;
use function get_option;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function is_subclass_of;
use function md5;
use function number_format_i18n;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function serialize;
use function strtoupper;
use function time;
use function trim;

/**
 * Shared base for the gold, stock, and crypto market widgets.
 *
 * Data flow:
 * - Providers are resolved through the `traydigita:core:market_provider`
 *   filter (child themes / plugins may plug in an API provider) and
 *   fall back to the manual snapshot provider.
 * - Quotes are cached in a transient (TTL 5 minutes). widget() only
 *   ever reads the transient — it never performs a network request.
 * - The cache is primed when the widget instance is saved (admin
 *   context, network allowed) and by the hourly WP-Cron event
 *   `traydigita_market_refresh` (registered in Sidebars).
 * - A failed fetch keeps the previous snapshot; an empty snapshot
 *   renders an honest "not configured yet" state, never a price.
 * - A failed fetch also sets a 60-second `_failed` marker transient
 *   for the cache key; while it is live, fetchQuotes() returns the
 *   failure result without hitting the provider again, so a down or
 *   rate-limiting provider is not hammered on every prime-on-miss
 *   render. Cron priming bypasses the marker.
 */
abstract class MarketWidgetBase extends WidgetBase
{
    /**
     * Transient TTL in seconds (5 minutes).
     */
    public const int CACHE_TTL = 300;

    /**
     * Failure-marker TTL in seconds (60 seconds).
     *
     * After a failed provider fetch, a `_failed` marker transient is
     * set for the cache key; while it is live, fetchQuotes() returns
     * the failure result without hitting the provider again.
     */
    public const int FAILED_TTL = 60;

    /**
     * Market kind: "gold", "stock", or "crypto".
     *
     * @return string
     */
    abstract protected function getMarketKind(): string;

    /**
     * Default symbols for this market (labels the widget tracks when
     * the owner has not configured anything else).
     *
     * @return string[]
     */
    abstract protected function getDefaultSymbols(): array;

    /**
     * Sanitize an instance without forcing a live fetch.
     *
     * WidgetBase::sanitizeInstance() routes through update(), which
     * primes the cache — a network call. That is correct on admin
     * save, but widget() calls sanitizeInstance() on every frontend
     * render, so routing through update() fetched live prices on
     * every page load and defeated the transient cache entirely
     * (a Yahoo-backed render took ~20 seconds; CoinGecko's free
     * tier would rate-limit under real traffic). Render paths prime
     * only on a cache miss: the first render after the TTL expires
     * refetches once, later renders read the transient. Forcing a
     * refresh stays with update() (admin save), primeAllInstances()
     * (WP-Cron), and the admin preview (which primes explicitly).
     *
     * @param mixed $instance Raw instance data.
     * @return array<string, mixed>
     */
    public function sanitizeInstance(mixed $instance): array
    {
        if (!is_array($instance)) {
            return [];
        }
        $sanitized = $this->sanitizeMarketInstance($instance);
        if ($this->getCachedQuotes($sanitized) === []) {
            $this->primeCache($sanitized);
        }
        return $sanitized;
    }

    /**
     * Whether this market kind is currently visible.
     *
     * The Markets module gates each kind: a disabled kind renders
     * nothing, and a disabled module removes the widgets entirely
     * (see Sidebars::MODULE_WIDGETS). The class_exists() guard keeps
     * the pre-module behaviour when the module class is absent.
     *
     * @return bool
     */
    protected function isKindVisible(): bool
    {
        if (!class_exists(MarketsModule::class)) {
            return true;
        }
        return MarketsModule::isKindEnabled($this->getMarketKind());
    }

    /**
     * Format a money amount for the market tables.
     *
     * IDR → "Rp1.234"; USD → "$1,234.56"; USDT → "1,234.56 USDT";
     * anything else → "1,234.56 CODE". A null amount yields an
     * empty string (the partial renders "Unavailable" instead).
     *
     * @param float|null $amount Amount in the given currency.
     * @param string $currency Currency code.
     * @return string
     */
    public static function formatMoney(?float $amount, string $currency): string
    {
        if ($amount === null) {
            return '';
        }
        $currency = strtoupper(trim($currency));
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);
        if ($currency === 'IDR') {
            return $sign . 'Rp' . number_format_i18n($abs, 0);
        }
        if ($currency === 'USD') {
            return $sign . '$' . number_format_i18n($abs, 2);
        }
        if ($currency === 'USDT') {
            return $sign . number_format_i18n($abs, 2) . ' USDT';
        }
        return $sign . number_format_i18n($abs, 2) . ' ' . $currency;
    }

    /**
     * @inheritDoc
     */
    public function widget($args, $instance): void
    {
        if (!$this->isKindVisible()) {
            return;
        }
        parent::widget($args, $instance);
    }

    /**
     * @inheritDoc
     */
    public function renderContent(array $instance): string
    {
        if (!$this->isKindVisible()) {
            return '';
        }
        return parent::renderContent($instance);
    }

    /**
     * Default provider for this market (used when the filter returns
     * nothing usable). The base implementation returns the manual
     * snapshot provider; the crypto widget overrides it to try
     * CoinGecko first.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return MarketDataProvider
     */
    protected function defaultProvider(array $instance): MarketDataProvider
    {
        return new ManualSnapshotProvider(
            ManualSnapshotProvider::parseLines((string) ($instance['snapshots'] ?? '')),
            time()
        );
    }

    /**
     * Resolve the provider for an instance.
     *
     * Filter: `traydigita:core:market_provider` receives null, the
     * market kind, and the instance, and may return a
     * MarketDataProvider. Anything else is ignored and the default
     * provider is used.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return MarketDataProvider
     */
    protected function resolveProvider(array $instance): MarketDataProvider
    {
        $provider = apply_filters(
            'traydigita:core:market_provider',
            null,
            $this->getMarketKind(),
            $instance
        );
        if ($provider instanceof MarketDataProvider) {
            return $provider;
        }
        return $this->defaultProvider($instance);
    }

    /**
     * Symbols this instance tracks.
     *
     * Stored as a newline-separated string in the instance; each line
     * may be "symbol" or "symbol|Label" (the label part is ignored
     * here — providers carry their own labels).
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return string[]
     */
    protected function getSymbols(array $instance): array
    {
        $raw = $instance['symbols'] ?? '';
        if (is_array($raw)) {
            $lines = array_map('strval', $raw);
        } else {
            $lines = explode("\n", (string) $raw);
        }
        $symbols = [];
        foreach ($lines as $line) {
            $parts = explode('|', trim($line));
            $symbol = trim($parts[0]);
            if ($symbol !== '') {
                $symbols[] = $symbol;
            }
        }
        if ($symbols !== []) {
            return array_values(array_unique($symbols));
        }
        return $this->getDefaultSymbols();
    }

    /**
     * Transient key for an instance's quotes.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return string
     */
    protected function quotesCacheKey(array $instance): string
    {
        return 'market_' . $this->getMarketKind() . '_'
            . md5(serialize([$this->getSymbols($instance), $instance['snapshots'] ?? '']));
    }

    /**
     * Transient key for an instance's failure marker.
     *
     * The marker lives alongside the quotes cache key with a
     * `_failed` suffix and a 60 second TTL (see FAILED_TTL).
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return string
     */
    protected function quotesFailedKey(array $instance): string
    {
        return $this->quotesCacheKey($instance) . '_failed';
    }

    /**
     * Read cached quotes (render path: no network, no provider call).
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return array<int, array<string, mixed>> Quote arrays, or [].
     */
    protected function getCachedQuotes(array $instance): array
    {
        $cached = $this->getCached($this->quotesCacheKey($instance));
        if (!is_array($cached)) {
            return [];
        }
        $quotes = [];
        foreach ($cached as $row) {
            if (!is_array($row) || ($row['label'] ?? '') === '') {
                continue;
            }
            $quotes[] = MarketQuote::fromArray($row)->toArray();
        }
        return $quotes;
    }

    /**
     * Fetch fresh quotes through the provider and store them.
     *
     * A failed or empty fetch keeps the previous snapshot untouched
     * and sets a 60-second `_failed` marker for the cache key; while
     * the marker is live, a later fetch returns the failure result
     * without hitting the provider again. A successful fetch deletes
     * the marker. When the primary provider returns nothing, the
     * manual snapshot provider is tried as a fallback.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @param bool $force Bypass the failure marker and fetch for real
     *                    (used by the WP-Cron prime path).
     * @return array<int, array<string, mixed>> Fresh quote arrays, or [].
     */
    protected function fetchQuotes(array $instance, bool $force = false): array
    {
        $failedKey = $this->quotesFailedKey($instance);
        if (!$force && $this->getCached($failedKey) !== false) {
            // A recent fetch already failed: back off instead of
            // hitting the provider again until the marker expires.
            return [];
        }
        $symbols = $this->getSymbols($instance);
        $provider = $this->resolveProvider($instance);
        $quotes = $this->providerQuotes($provider, $symbols);
        if ($quotes === [] && !$provider instanceof ManualSnapshotProvider) {
            $fallback = $this->fallbackSnapshotProvider($instance);
            $quotes = $this->providerQuotes($fallback, $symbols);
        }
        if ($quotes !== []) {
            // A successful fetch clears any failure marker for this key.
            $this->deleteCached($failedKey);
            // The Markets module may override the TTL from its
            // cache-lifetime setting (default 5 minutes).
            $ttl = apply_filters(
                'traydigita:core:market_cache_ttl',
                self::CACHE_TTL,
                $this->getMarketKind()
            );
            $this->setCached(
                $this->quotesCacheKey($instance),
                array_map(static fn(MarketQuote $quote): array => $quote->toArray(), $quotes),
                is_numeric($ttl) ? (int) $ttl : self::CACHE_TTL
            );
        } else {
            $this->setCached($failedKey, 1, self::FAILED_TTL);
        }
        return array_map(static fn(MarketQuote $quote): array => $quote->toArray(), $quotes);
    }

    /**
     * Manual snapshot provider used when a live provider returns nothing.
     *
     * Prefers the central snapshots from the Markets module settings
     * when they exist for this kind, and falls back to the widget
     * instance's own snapshot field (the pre-module behaviour).
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return ManualSnapshotProvider
     */
    private function fallbackSnapshotProvider(array $instance): ManualSnapshotProvider
    {
        if (class_exists(MarketsModule::class)
            && MarketsModule::isKindEnabled($this->getMarketKind())
        ) {
            $rows = MarketsModule::moduleSnapshotRows($this->getMarketKind());
            if ($rows !== []) {
                return new ManualSnapshotProvider($rows, MarketsModule::snapshotUpdatedAt());
            }
        }
        return new ManualSnapshotProvider(
            ManualSnapshotProvider::parseLines((string) ($instance['snapshots'] ?? '')),
            time()
        );
    }

    /**
     * Call a provider safely: any exception yields an empty list.
     *
     * @param MarketDataProvider $provider Provider to call.
     * @param string[] $symbols Symbols to request.
     * @return MarketQuote[]
     */
    private function providerQuotes(MarketDataProvider $provider, array $symbols): array
    {
        try {
            $quotes = $provider->getQuotes($symbols);
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_filter($quotes, static fn($quote) => $quote instanceof MarketQuote));
    }

    /**
     * Market widget classes registered through the filter below.
     *
     * Core ships no concrete market widgets: the Gold, Stock, and
     * Crypto widgets live in the theme, which registers its widget
     * classes here through the `traydigita:core:market_widgets`
     * filter. The filter receives an empty array and must return a
     * `kind => class-string` map (for example, the gold kind mapped
     * to the theme's gold widget class).
     *
     * A registered class must extend this base class: the
     * snapshot/prime/clear paths below call protected base methods
     * (marketWidgetData, quotesCacheKey, deleteCached), which PHP
     * only allows from an ancestor scope. A class that does not
     * extend this base — for example a theme widget built on the
     * theme's own copy of the base — is ignored here; the theme
     * drives those through its own copy instead. A registered class
     * must also be constructible with no arguments: snapshotForKind()
     * builds it with `new $class()`, and a class that needs
     * constructor arguments is silently skipped. With no
     * registration this returns an empty map and snapshotForKind()
     * / primeAllInstances() / clearAllCaches() stay no-ops — the
     * previous behaviour, now honest instead of naming classes
     * that do not exist in Core.
     *
     * @return array<string, class-string>
     */
    private static function marketWidgetClasses(): array
    {
        $classes = apply_filters('traydigita:core:market_widgets', []);
        if (!is_array($classes)) {
            return [];
        }
        $valid = [];
        foreach ($classes as $kind => $class) {
            if (!is_string($kind) || $kind === '' || !is_string($class) || $class === '') {
                continue;
            }
            if (!class_exists($class) || !is_subclass_of($class, self::class, true)) {
                continue;
            }
            $valid[$kind] = $class;
        }
        return $valid;
    }

    /**
     * Cached snapshot for the first saved instance of a market kind.
     *
     * The market strip (templates/sections/market-strip.php) reads
     * through this method: market transients are keyed per widget
     * instance (see quotesCacheKey), so no stable transient name
     * exists for the strip to read directly. This is render-path
     * safe: it only reads the transient cache and never performs a
     * network request (instances are sanitized with
     * sanitizeMarketInstance, not update(), so no priming happens).
     *
     * The widget class for the kind comes from
     * marketWidgetClasses() (filter `traydigita:core:market_widgets`).
     *
     * @param string $kind Market kind: "gold", "stock", or "crypto".
     * @return array{title: string, quotes: array<int, array<string, mixed>>, source: string, updated_at: int|null, currency: string, kind: string}|null Snapshot with cached quotes, or null when no instance has cached quotes.
     */
    public static function snapshotForKind(string $kind): ?array
    {
        $class = self::marketWidgetClasses()[$kind] ?? null;
        if ($class === null) {
            return null;
        }
        try {
            $widget = new $class();
        } catch (\Throwable) {
            return null;
        }
        $instances = get_option('widget_' . $widget->id_base, []);
        if (!is_array($instances)) {
            return null;
        }
        foreach ($instances as $instance) {
            if (!is_array($instance)) {
                continue;
            }
            $data = $widget->marketWidgetData($widget->sanitizeMarketInstance($instance));
            if ($data['quotes'] === []) {
                continue;
            }
            return $data;
        }
        return null;
    }

    /**
     * Prime the cache for an instance (admin save / cron / preview).
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @param bool $force Bypass the failure marker and fetch for real
     *                    (used by the WP-Cron prime path).
     * @return void
     */
    public function primeCache(array $instance, bool $force = false): void
    {
        $this->fetchQuotes($instance, $force);
    }

    /**
     * Prime the cache for every saved instance of every market widget.
     *
     * Called by the hourly `traydigita_market_refresh` cron event.
     * Cron priming forces a real fetch: it bypasses the 60-second
     * failure marker so a provider that recovered is picked up on
     * the next scheduled refresh instead of waiting out the backoff.
     * Widget classes come from marketWidgetClasses() (filter
     * `traydigita:core:market_widgets`); with no registration this
     * is a no-op.
     *
     * @return void
     */
    public static function primeAllInstances(): void
    {
        foreach (self::marketWidgetClasses() as $class) {
            try {
                $widget = new $class();
            } catch (\Throwable) {
                continue;
            }
            $instances = get_option('widget_' . $widget->id_base, []);
            if (!is_array($instances)) {
                continue;
            }
            foreach ($instances as $instance) {
                if (!is_array($instance)) {
                    continue;
                }
                $widget->primeCache($widget->sanitizeInstance($instance), true);
            }
        }
    }

    /**
     * Delete the cached quotes of every saved market widget instance.
     *
     * Used when the Markets module switches providers, so a stale
     * transient from the previous provider is never served under the
     * new one: the next prime (or the honest empty state, when the
     * new provider fails) takes over at once instead of up to one
     * TTL later. No raw SQL — the keys are recomputed per instance.
     * The 60-second failure markers are deleted alongside the quotes
     * caches, so a provider switch is never blocked by a stale
     * backoff from the previous provider. Widget classes come from
     * marketWidgetClasses() (filter
     * `traydigita:core:market_widgets`); with no registration this
     * is a no-op.
     *
     * @return void
     */
    public static function clearAllCaches(): void
    {
        foreach (self::marketWidgetClasses() as $class) {
            try {
                $widget = new $class();
            } catch (\Throwable) {
                continue;
            }
            $instances = get_option('widget_' . $widget->id_base, []);
            if (!is_array($instances)) {
                continue;
            }
            foreach ($instances as $instance) {
                if (!is_array($instance)) {
                    continue;
                }
                $sanitized = $widget->sanitizeInstance($instance);
                $widget->deleteCached($widget->quotesCacheKey($sanitized));
                $widget->deleteCached($widget->quotesFailedKey($sanitized));
            }
        }
    }

    /**
     * Shared widget data: cached quotes plus source/timestamp metadata.
     *
     * @param array<string, mixed> $instance Sanitized widget instance.
     * @return array{title: string, quotes: array<int, array<string, mixed>>, source: string, updated_at: int|null, currency: string, kind: string}
     */
    protected function marketWidgetData(array $instance): array
    {
        $quotes = $this->getCachedQuotes($instance);
        $updatedAt = null;
        $source = '';
        foreach ($quotes as $quote) {
            if (($quote['updated_at'] ?? null) !== null) {
                $updatedAt = $updatedAt === null
                    ? (int) $quote['updated_at']
                    : max($updatedAt, (int) $quote['updated_at']);
            }
            if ($source === '' && ($quote['source'] ?? '') !== '') {
                $source = (string) $quote['source'];
            }
        }
        if ($source === '') {
            $source = $this->resolveProvider($instance)->getSourceLabel();
        }
        return [
            'title' => (string) ($instance['title'] ?? ''),
            'quotes' => $quotes,
            'source' => $source,
            'updated_at' => $updatedAt,
            'currency' => (string) ($instance['currency'] ?? 'IDR'),
            'kind' => $this->getMarketKind(),
        ];
    }

    /**
     * Shared form fields for market widgets.
     *
     * @param array<string, mixed> $instance Widget instance.
     * @param string $symbolsLabel Label for the symbols field.
     * @param string $symbolsDescription Help text for the symbols field.
     * @return void
     */
    protected function marketFormFields(
        array $instance,
        string $symbolsLabel,
        string $symbolsDescription
    ): void {
        $this->textField($instance, 'title', __('Title:', 'traydigita-core'));
        $this->textareaField(
            $instance,
            'symbols',
            $symbolsLabel,
            $symbolsDescription,
            '',
            3
        );
        $this->textareaField(
            $instance,
            'snapshots',
            __('Manual price snapshot (one per line):', 'traydigita-core'),
            __('Format: Label|price|change|change_percent|buyback. Used when no API provider is configured.', 'traydigita-core'),
            '',
            4
        );
        $this->textField($instance, 'currency', __('Currency:', 'traydigita-core'), 'IDR');
    }

    /**
     * Shared sanitization for market widget instances.
     *
     * @param array<string, mixed> $newInstance Raw submitted instance.
     * @return array<string, mixed>
     */
    protected function sanitizeMarketInstance(array $newInstance): array
    {
        $symbols = [];
        $rawSymbols = (string) ($newInstance['symbols'] ?? '');
        foreach (explode("\n", $rawSymbols) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Allow "id|Label" lines; the symbol is the part before "|".
            $parts = explode('|', $line);
            $symbol = trim($parts[0]);
            if ($symbol !== '') {
                $symbols[] = $symbol;
            }
        }
        return [
            'title' => sanitize_text_field($newInstance['title'] ?? ''),
            'symbols' => implode("\n", array_values(array_unique($symbols))),
            'snapshots' => sanitize_textarea_field($newInstance['snapshots'] ?? ''),
            'currency' => sanitize_key($newInstance['currency'] ?? 'IDR') ?: 'IDR',
        ];
    }

    /**
     * Prime the cache on save so the widget renders from cache at once.
     *
     * @param array<string, mixed> $newInstance Raw submitted instance.
     * @param array<string, mixed> $oldInstance Previous instance.
     * @return array<string, mixed>
     */
    public function update($newInstance, $oldInstance): array
    {
        if (!is_array($newInstance)) {
            return [];
        }
        $instance = $this->sanitizeMarketInstance($newInstance);
        $this->primeCache($instance);
        return $instance;
    }
}
