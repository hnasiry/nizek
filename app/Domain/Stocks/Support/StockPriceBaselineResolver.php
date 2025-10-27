<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Support;

use App\Domain\Stocks\Enums\StockPerformancePeriod;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use Carbon\CarbonImmutable;

final class StockPriceBaselineResolver
{
    public function resolve(
        Company $company,
        StockPerformancePeriod $period,
        CarbonImmutable $asOf,
    ): ?StockPrice {
        return match ($period) {
            StockPerformancePeriod::Max => $this->oldestPrice($company),
            StockPerformancePeriod::YearToDate => $this->firstPriceInRange(
                $company,
                $asOf->startOfYear(),
                $asOf,
            ),
            default => $this->baselineForRollingPeriod($company, $period, $asOf),
        };
    }

    private function baselineForRollingPeriod(
        Company $company,
        StockPerformancePeriod $period,
        CarbonImmutable $asOf,
    ): ?StockPrice {
        $targetDate = $this->targetDateFor($period, $asOf);

        if ($targetDate === null) {
            return null;
        }

        return $this->nextTradingDay($company, $targetDate)
            ?? $this->previousTradingDay($company, $targetDate);
    }

    private function targetDateFor(
        StockPerformancePeriod $period,
        CarbonImmutable $asOf,
    ): ?CarbonImmutable {
        return match ($period) {
            StockPerformancePeriod::OneDay => $asOf->subDay(),
            StockPerformancePeriod::OneMonth => $asOf->subMonth(),
            StockPerformancePeriod::ThreeMonths => $asOf->subMonths(3),
            StockPerformancePeriod::SixMonths => $asOf->subMonths(6),
            StockPerformancePeriod::OneYear => $asOf->subYear(),
            StockPerformancePeriod::ThreeYears => $asOf->subYears(3),
            StockPerformancePeriod::FiveYears => $asOf->subYears(5),
            StockPerformancePeriod::TenYears => $asOf->subYears(10),
            default => null,
        };
    }

    private function oldestPrice(Company $company): ?StockPrice
    {
        return $company->stockPrices()
            ->orderBy('traded_on')
            ->first();
    }

    private function firstPriceInRange(
        Company $company,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): ?StockPrice {
        return $company->stockPrices()
            ->whereBetween('traded_on', [$start->toDateString(), $end->toDateString()])
            ->orderBy('traded_on')
            ->first();
    }

    private function nextTradingDay(Company $company, CarbonImmutable $target): ?StockPrice
    {
        return $company->stockPrices()
            ->whereDate('traded_on', '>=', $target)
            ->orderBy('traded_on')
            ->first();
    }

    private function previousTradingDay(Company $company, CarbonImmutable $target): ?StockPrice
    {
        return $company->stockPrices()
            ->whereDate('traded_on', '<=', $target)
            ->orderByDesc('traded_on')
            ->first();
    }
}
