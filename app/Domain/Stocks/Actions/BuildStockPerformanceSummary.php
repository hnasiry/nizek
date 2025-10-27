<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Enums\StockPerformancePeriod;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\Support\PriceChangeCalculator;
use App\Domain\Stocks\Support\StockPriceBaselineResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class BuildStockPerformanceSummary
{
    public function __construct(
        private PriceChangeCalculator $calculator,
        private StockPriceBaselineResolver $baselineResolver,
    ) {}

    /**
     * @param  list<StockPerformancePeriod>|null  $periods
     * @return array<string, mixed>
     */
    public function handle(
        Company $company,
        ?CarbonImmutable $asOf = null,
        ?array $periods = null,
    ): array {
        $periods ??= StockPerformancePeriod::cases();

        $cacheKey = $this->makeCacheKey($company, $asOf, $periods);
        $ttl = (int) config('stocks.reporting.cache_ttl', 300);

        return Cache::remember(
            $cacheKey,
            $ttl,
            fn (): array => $this->buildSummary($company, $asOf, $periods),
        );
    }

    private function resolveLatestPrice(Company $company, ?CarbonImmutable $asOf): ?StockPrice
    {
        $query = $company->stockPrices()->orderByDesc('traded_on');

        if ($asOf !== null) {
            $query->whereDate('traded_on', '<=', $asOf);
        }

        return $query->first();
    }

    /**
     * @param  list<StockPerformancePeriod>  $periods
     * @return array<string, mixed>
     */
    private function buildSummary(
        Company $company,
        ?CarbonImmutable $asOf,
        array $periods,
    ): array {
        $latestPrice = $this->resolveLatestPrice($company, $asOf);

        if ($latestPrice === null) {
            return $this->emptySummary($periods);
        }

        /** @var CarbonImmutable $resolvedAsOf */
        $resolvedAsOf = $latestPrice->traded_on;

        return $this->summaryForLatestPrice($company, $periods, $resolvedAsOf, $latestPrice);
    }

    /**
     * @param  list<StockPerformancePeriod>  $periods
     * @return array<string, mixed>
     */
    private function emptySummary(array $periods): array
    {
        return [
            'periods' => array_map(
                fn (StockPerformancePeriod $period): array => $this->summarizePeriod($period, null),
                $periods,
            ),
        ];
    }

    /**
     * @param  list<StockPerformancePeriod>  $periods
     * @return array<string, mixed>
     */
    private function summaryForLatestPrice(
        Company $company,
        array $periods,
        CarbonImmutable $resolvedAsOf,
        StockPrice $latestPrice,
    ): array {
        return [
            'periods' => array_map(
                fn (StockPerformancePeriod $period): array => $this->buildPeriodEntry(
                    $company,
                    $period,
                    $resolvedAsOf,
                    $latestPrice,
                ),
                $periods,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPeriodEntry(
        Company $company,
        StockPerformancePeriod $period,
        CarbonImmutable $asOf,
        StockPrice $latestPrice,
    ): array {
        $baselinePrice = $this->baselineResolver->resolve($company, $period, $asOf);

        if ($baselinePrice === null || $baselinePrice->traded_on->equalTo($latestPrice->traded_on)) {
            return $this->summarizePeriod($period, null);
        }

        $change = $this->calculator->percentage(
            $baselinePrice->price,
            $latestPrice->price,
        );

        return $this->summarizePeriod($period, $change);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizePeriod(StockPerformancePeriod $period, ?string $change): array
    {
        return [
            'period' => $period->value,
            'change' => $change,
            'formatted' => $this->calculator->formatted($change),
        ];
    }

    /**
     * @param  list<StockPerformancePeriod>  $periods
     */
    private function makeCacheKey(Company $company, ?CarbonImmutable $asOf, array $periods): string
    {
        $periodValues = array_map(static fn (StockPerformancePeriod $period): string => $period->value, $periods);
        sort($periodValues);

        $asOfSegment = $asOf?->toDateString() ?? 'latest';
        $updatedAt = $company->updated_at?->timestamp;
        $updatedAtSegment = $updatedAt !== null ? (string) $updatedAt : 'na';

        return sprintf(
            'stock-performance:%d:%s:%s:%s',
            $company->id,
            $updatedAtSegment,
            $asOfSegment,
            hash('sha256', implode('-', $periodValues)),
        );
    }
}
