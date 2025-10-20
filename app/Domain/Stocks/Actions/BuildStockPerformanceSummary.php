<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Enums\StockPerformancePeriod;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\Support\PriceChangeCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class BuildStockPerformanceSummary
{
    public function __construct(private PriceChangeCalculator $calculator) {}

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

        return Cache::remember($cacheKey, $ttl, function () use ($company, $asOf, $periods): array {
            $latestPrice = $this->resolveLatestPrice($company, $asOf);

            if ($latestPrice === null) {
                return [
                    'periods' => array_map(
                        fn (StockPerformancePeriod $period): array => $this->emptyPeriod($period),
                        $periods,
                    ),
                ];
            }

            /** @var CarbonImmutable $resolvedAsOf */
            $resolvedAsOf = $latestPrice->traded_on;

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
        });
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
     * @return array<string, mixed>
     */
    private function emptyPeriod(StockPerformancePeriod $period): array
    {
        return [
            'period' => $period->value,
            'change' => null,
            'formatted' => 'none',
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
        $targetDate = $this->computeTargetDate($period, $asOf);
        $baselinePrice = $this->resolveBaselinePrice($company, $period, $targetDate, $asOf);

        if ($baselinePrice === null || $baselinePrice->traded_on->equalTo($latestPrice->traded_on)) {
            return $this->emptyPeriod($period);
        }

        $percentage = $this->calculator->percentage($baselinePrice->price, $latestPrice->price);

        return [
            'period' => $period->value,
            'change' => $percentage,
            'formatted' => $this->calculator->formatted($percentage),
        ];
    }

    private function computeTargetDate(StockPerformancePeriod $period, CarbonImmutable $asOf): ?CarbonImmutable
    {
        return match ($period) {
            StockPerformancePeriod::OneDay => $asOf->subDay(),
            StockPerformancePeriod::OneMonth => $asOf->subMonth(),
            StockPerformancePeriod::ThreeMonths => $asOf->subMonths(3),
            StockPerformancePeriod::SixMonths => $asOf->subMonths(6),
            StockPerformancePeriod::YearToDate => $asOf->startOfYear(),
            StockPerformancePeriod::OneYear => $asOf->subYear(),
            StockPerformancePeriod::ThreeYears => $asOf->subYears(3),
            StockPerformancePeriod::FiveYears => $asOf->subYears(5),
            StockPerformancePeriod::TenYears => $asOf->subYears(10),
            StockPerformancePeriod::Max => null,
        };
    }

    private function resolveBaselinePrice(
        Company $company,
        StockPerformancePeriod $period,
        ?CarbonImmutable $targetDate,
        CarbonImmutable $asOf,
    ): ?StockPrice {
        if ($period === StockPerformancePeriod::Max) {
            return $company->stockPrices()
                ->orderBy('traded_on')
                ->first();
        }

        if ($targetDate === null) {
            return null;
        }

        if ($period === StockPerformancePeriod::YearToDate) {
            return $company->stockPrices()
                ->whereBetween('traded_on', [$targetDate->toDateString(), $asOf->toDateString()])
                ->orderBy('traded_on')
                ->first();
        }

        $nextTradingDay = $company->stockPrices()
            ->whereDate('traded_on', '>=', $targetDate)
            ->orderBy('traded_on')
            ->first();

        if ($nextTradingDay !== null) {
            return $nextTradingDay;
        }

        return $company->stockPrices()
            ->whereDate('traded_on', '<=', $targetDate)
            ->orderByDesc('traded_on')
            ->first();
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
