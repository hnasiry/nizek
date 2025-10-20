<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\Support\PriceChangeCalculator;
use Carbon\CarbonImmutable;

final class ResolveStockPriceComparison
{
    public function __construct(private PriceChangeCalculator $calculator) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Company $company, CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        $fromPrice = $this->findPrice($company, $fromDate);
        $toPrice = $this->findPrice($company, $toDate);

        $percentage = $this->calculator->percentage(
            $fromPrice?->price,
            $toPrice?->price,
        );

        return [
            'change' => $percentage,
            'formatted' => $this->calculator->formatted($percentage),
        ];
    }

    private function findPrice(Company $company, CarbonImmutable $date): ?StockPrice
    {
        return $company->stockPrices()
            ->whereDate('traded_on', '=', $date)
            ->first();
    }
}
