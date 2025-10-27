<?php

declare(strict_types=1);

use App\Domain\Stocks\Enums\StockPerformancePeriod;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\Support\StockPriceBaselineResolver;
use Carbon\CarbonImmutable;

it('returns the oldest trade for the max period', function (): void {
    $company = Company::factory()->create();

    $oldest = createStockPrice($company, '2021-01-05', '50.000000');
    createStockPrice($company, '2022-06-10', '75.000000');
    $latest = createStockPrice($company, '2024-04-01', '120.000000');

    $resolver = new StockPriceBaselineResolver();

    $baseline = $resolver->resolve($company, StockPerformancePeriod::Max, $latest->traded_on);

    expect($baseline)->not->toBeNull()
        ->and($baseline?->is($oldest))->toBeTrue();
});

it('returns the first trade within the year to date range', function (): void {
    $company = Company::factory()->create();

    createStockPrice($company, '2023-12-15', '65.000000');
    $firstOfYear = createStockPrice($company, '2024-01-03', '70.000000');
    createStockPrice($company, '2024-02-01', '75.000000');
    $latest = createStockPrice($company, '2024-04-15', '80.000000');

    $resolver = new StockPriceBaselineResolver();

    $baseline = $resolver->resolve(
        $company,
        StockPerformancePeriod::YearToDate,
        CarbonImmutable::parse('2024-04-15'),
    );

    expect($baseline)->not->toBeNull()
        ->and($baseline?->is($firstOfYear))->toBeTrue();
});

it('prefers the first trade on or after the target date', function (): void {
    $company = Company::factory()->create();

    createStockPrice($company, '2024-03-30', '70.000000');
    $expected = createStockPrice($company, '2024-04-12', '72.000000');
    createStockPrice($company, '2024-05-10', '75.000000');

    $resolver = new StockPriceBaselineResolver();

    $baseline = $resolver->resolve(
        $company,
        StockPerformancePeriod::OneMonth,
        CarbonImmutable::parse('2024-05-10'),
    );

    expect($baseline)->not->toBeNull()
        ->and($baseline?->is($expected))->toBeTrue();
});

it('falls back to the most recent trade before the target date when needed', function (): void {
    $company = Company::factory()->create();

    $expected = createStockPrice($company, '2023-10-10', '60.000000');

    $resolver = new StockPriceBaselineResolver();

    $baseline = $resolver->resolve(
        $company,
        StockPerformancePeriod::OneMonth,
        CarbonImmutable::parse('2023-11-15'),
    );

    expect($baseline)->not->toBeNull()
        ->and($baseline?->is($expected))->toBeTrue();
});

it('returns null when there is no data to compare against', function (): void {
    $company = Company::factory()->create();
    $resolver = new StockPriceBaselineResolver();

    $baseline = $resolver->resolve(
        $company,
        StockPerformancePeriod::OneMonth,
        CarbonImmutable::parse('2024-02-20'),
    );

    expect($baseline)->toBeNull();
});

function createStockPrice(Company $company, string $date, string $price): StockPrice
{
    return StockPrice::factory()
        ->for($company, 'company')
        ->create([
            'traded_on' => $date,
            'price' => $price,
        ]);
}
