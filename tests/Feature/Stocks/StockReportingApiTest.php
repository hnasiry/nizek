<?php

declare(strict_types=1);

use App\Domain\Stocks\Enums\StockPerformancePeriod;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\ValueObjects\Price;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Spatie\SimpleExcel\SimpleExcelReader;

it('requires authentication for reporting endpoints', function (): void {
    $company = Company::factory()->create();

    $comparison = $this->getJson(
        sprintf('/api/companies/%d/stock-prices/comparison?from=2025-04-29&to=2025-04-30', $company->id),
    );

    $comparison->assertUnauthorized();

    $performance = $this->getJson(
        sprintf('/api/companies/%d/stock-prices/performance', $company->id),
    );

    $performance->assertUnauthorized();
});

it('returns stock price comparison between two dates', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    StockPrice::factory()->create([
        'company_id' => $company->id,
        'traded_on' => '2025-04-29',
        'price' => '163.7600',
    ]);

    StockPrice::factory()->create([
        'company_id' => $company->id,
        'traded_on' => '2025-04-30',
        'price' => '161.8400',
    ]);

    expect(StockPrice::query()->count())->toBe(2);

    $expectedPercentage = number_format((161.8400 / 163.7600) - 1, 6, '.', '');
    $expectedFormatted = number_format(((float) $expectedPercentage) * 100, 2, '.', '').'%';

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/comparison?from=2025-04-29&to=2025-04-30', $company->id),
    );

    $response->assertOk()
        ->assertJsonPath('data.change', $expectedPercentage)
        ->assertJsonPath('data.formatted', $expectedFormatted);
});

it('validates custom comparison request payload', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/comparison?from=2025-05-02&to=2025-05-01', $company->id),
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

it('handles missing price data gracefully in comparison response', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    StockPrice::factory()->create([
        'company_id' => $company->id,
        'traded_on' => '2025-04-30',
        'price' => '161.8400',
    ]);

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/comparison?from=2025-04-29&to=2025-04-30', $company->id),
    );

    $response->assertOk()
        ->assertJsonPath('data.change', null)
        ->assertJsonPath('data.formatted', 'none');
});

it('returns performance summary for the default periods', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $priceMap = [
        '2010-04-30' => '40.0000',
        '2015-04-30' => '55.0000',
        '2020-04-30' => '70.0000',
        '2022-04-30' => '80.0000',
        '2024-04-30' => '120.0000',
        '2024-10-30' => '130.0000',
        '2025-01-02' => '135.0000',
        '2025-01-30' => '120.0000',
        '2025-03-30' => '150.1000',
        '2025-04-29' => '160.0000',
        '2025-04-30' => '165.0000',
    ];

    foreach ($priceMap as $date => $price) {
        StockPrice::factory()->create([
            'company_id' => $company->id,
            'traded_on' => $date,
            'price' => $price,
        ]);
    }

    expect(StockPrice::query()->count())->toBe(count($priceMap));

    expect(
        StockPrice::query()
            ->where('company_id', $company->id)
            ->whereDate('traded_on', '2025-04-29')
            ->exists(),
    )->toBeTrue();

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/performance', $company->id),
    );

    $response->assertOk()
        ->assertJsonCount(count(StockPerformancePeriod::cases()), 'data.periods');

    $periods = $response->json('data.periods');

    expect($periods[0]['period'])->toBe(StockPerformancePeriod::OneDay->value)
        ->and($periods[0]['change'])->toBe(number_format((165.0000 / 160.0000) - 1, 6, '.', ''))
        ->and($periods[0]['formatted'])->toBe(number_format(((165.0000 / 160.0000) - 1) * 100, 2, '.', '').'%');

    expect($periods[4]['period'])->toBe(StockPerformancePeriod::YearToDate->value)
        ->and($periods[4]['change'])->toBe(number_format((165.0000 / 135.0000) - 1, 6, '.', ''))
        ->and($periods[4]['formatted'])->toBe(number_format(((165.0000 / 135.0000) - 1) * 100, 2, '.', '').'%');

    expect($periods[9]['period'])->toBe(StockPerformancePeriod::Max->value)
        ->and($periods[9]['change'])->toBe(number_format((165.0000 / 40.0000) - 1, 6, '.', ''))
        ->and($periods[9]['formatted'])->toBe(number_format(((165.0000 / 40.0000) - 1) * 100, 2, '.', '').'%');
});

it('can filter performance periods via query parameter', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    StockPrice::factory()->createMany([
        [
            'company_id' => $company->id,
            'traded_on' => '2025-04-29',
            'price' => '160.0000',
        ],
        [
            'company_id' => $company->id,
            'traded_on' => '2025-04-30',
            'price' => '165.0000',
        ],
    ]);

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/performance?periods[]=1D&periods[]=1M', $company->id),
    );

    $response->assertOk()
        ->assertJsonCount(2, 'data.periods')
        ->assertJsonPath('data.periods.0.period', '1D')
        ->assertJsonPath('data.periods.1.period', '1M');
});

it('marks periods without sufficient history', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    StockPrice::factory()->create([
        'company_id' => $company->id,
        'traded_on' => '2025-04-30',
        'price' => '165.0000',
    ]);

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/performance', $company->id),
    );

    $response->assertOk()
        ->assertJsonPath('data.periods.0.change', null)
        ->assertJsonPath('data.periods.0.formatted', 'none');
});

it('returns expected performance summary for dummy dataset', function (): void {
    Config::set('cache.default', 'array');
    Cache::clear();

    $user = User::factory()->create();
    $company = Company::factory()->create();

    $rows = SimpleExcelReader::create(base_path('tests/Fixtures/dummy_stock_prices.xlsx'))
        ->trimHeaderRow()
        ->headersToSnakeCase()
        ->getRows();

    foreach ($rows as $row) {
        $date = $row['date'];

        if ($date instanceof DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        if (empty($date)) {
            continue;
        }

        $priceValue = $row['stock_price'];

        if ($priceValue === null || $priceValue === '') {
            continue;
        }

        $price = (float) $priceValue;

        StockPrice::query()->create([
            'company_id' => $company->id,
            'traded_on' => $date,
            'price' => Price::fromString(number_format($price, 6, '.', '')),
        ]);
    }

    $response = $this->actingAs($user)->getJson(
        sprintf('/api/companies/%d/stock-prices/performance', $company->id),
    );

    $response->assertOk();

    $expected = [
        StockPerformancePeriod::OneDay->value => ['-0.011700', '-1.17%'],
        StockPerformancePeriod::OneMonth->value => ['0.068300', '6.83%'],
        StockPerformancePeriod::ThreeMonths->value => ['0.032900', '3.29%'],
        StockPerformancePeriod::SixMonths->value => ['0.283300', '28.33%'],
        StockPerformancePeriod::YearToDate->value => ['0.111500', '11.15%'],
        StockPerformancePeriod::OneYear->value => ['0.301200', '30.12%'],
        StockPerformancePeriod::ThreeYears->value => ['2.898100', '289.81%'],
        StockPerformancePeriod::FiveYears->value => ['4.566800', '456.68%'],
        StockPerformancePeriod::TenYears->value => ['9.840000', '984%'],
        StockPerformancePeriod::Max->value => ['24.509600', '2450.96%'],
    ];

    $periods = collect($response->json('data.periods'));

    foreach ($expected as $period => [$change, $formatted]) {
        $entry = $periods->firstWhere('period', $period);

        expect($entry)->not->toBeNull();

        $actualChange = (float) $entry['change'];
        $actualFormatted = (float) str_replace('%', '', $entry['formatted']);
        $expectedChange = (float) $change;
        $expectedFormatted = (float) $formatted;

        expect($actualChange)->toBeGreaterThan(-1000)
            ->and($actualChange)->toBeLessThan(1000)
            ->and($actualChange)->toEqualWithDelta($expectedChange, 0.0003);

        expect($actualFormatted)->toEqualWithDelta($expectedFormatted, 0.05);
        expect($entry['formatted'])->toContain('%');
    }
});
