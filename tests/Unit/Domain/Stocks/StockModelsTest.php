<?php

declare(strict_types=1);

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\ValueObjects\Price;
use Carbon\CarbonImmutable;

it('relates stock prices to company', function (): void {
    $company = Company::factory()->create();

    $price = StockPrice::factory()
        ->for($company, 'company')
        ->create([
            'traded_on' => CarbonImmutable::now()->toDateString(),
            'price' => '123.4500',
        ]);

    expect($price->company->is($company))->toBeTrue()
        ->and($company->stockPrices()->count())->toBe(1)
        ->and($price->price)->toBeInstanceOf(Price::class)
        ->and($price->price->value())->toBe('123.450000');
});

it('casts stock import timestamps and status', function (): void {
    $queuedAt = CarbonImmutable::now()->subMinutes(15)->setMicroseconds(0);

    $import = StockImport::factory()->create([
        'status' => StockImportStatus::Completed,
        'queued_at' => $queuedAt,
        'started_at' => $queuedAt->addMinute(),
        'completed_at' => $queuedAt->addMinutes(10),
    ]);

    expect($import->status)->toBe(StockImportStatus::Completed)
        ->and($import->queued_at)->toEqual($queuedAt)
        ->and($import->started_at)->toEqual($queuedAt->addMinute())
        ->and($import->completed_at)->toEqual($queuedAt->addMinutes(10));
});
