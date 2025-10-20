<?php

declare(strict_types=1);

use App\Domain\Stocks\Actions\QueueStockImport;
use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Jobs\PrepareStockImport;
use App\Domain\Stocks\Jobs\ProcessStockImportChunk;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use App\Domain\Stocks\Models\StockPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

it('queues stock import for asynchronous processing', function (): void {
    Date::setTestNow(now());
    Queue::fake();

    $import = StockImport::factory()
        ->for(Company::factory(), 'company')
        ->create([
            'status' => StockImportStatus::Pending,
            'stored_path' => 'imports/prices.xlsx',
            'disk' => 'local',
        ]);

    app(QueueStockImport::class)->handle($import);

    $import->refresh();

    expect($import->status)->toBe(StockImportStatus::Queued)
        ->and($import->queued_at)->not->toBeNull()
        ->and($import->processed_rows)->toBe(0);

    Queue::assertPushedOn(config('stocks.import.queue'), PrepareStockImport::class);

    Date::setTestNow();
});

it('splits excel rows into chunk jobs', function (): void {
    Storage::fake('local');
    Bus::fake();

    $company = Company::factory()->create();
    $path = 'imports/chunk-test.xlsx';

    Storage::disk('local')->makeDirectory('imports');

    SimpleExcelWriter::create(Storage::disk('local')->path($path))
        ->addRows([
            ['date' => '2025-04-30', 'stock_price' => 161.84],
            ['date' => '2025-04-29', 'stock_price' => 163.76],
        ])
        ->close();

    $import = StockImport::factory()->create([
        'company_id' => $company->id,
        'status' => StockImportStatus::Queued,
        'stored_path' => $path,
        'disk' => 'local',
        'processed_rows' => 0,
    ]);

    new PrepareStockImport($import->id)->handle();

    Bus::assertBatched(function ($pendingBatch) {
        return $pendingBatch->jobs->count() === 1
            && $pendingBatch->jobs->first() instanceof ProcessStockImportChunk;
    });

    $import->refresh();

    expect($import->status)->toBe(StockImportStatus::Processing)
        ->and($import->total_rows)->toBe(2)
        ->and($import->batch_id)->not->toBeNull();
});

it('upserts chunk rows and advances counters', function (): void {
    Date::setTestNow(CarbonImmutable::parse('2025-01-01 00:00:00'));

    $company = Company::factory()->create();
    $import = StockImport::factory()->create([
        'company_id' => $company->id,
        'status' => StockImportStatus::Processing,
        'processed_rows' => 0,
        'total_rows' => 0,
        'disk' => 'local',
        'stored_path' => 'imports/irrelevant.xlsx',
    ]);

    $rows = [
        ['traded_on' => '2025-04-30', 'price' => '161.840000'],
        ['traded_on' => '2025-04-29', 'price' => '163.760000'],
    ];

    Date::setTestNow(CarbonImmutable::parse('2025-01-02 08:00:00'));

    new ProcessStockImportChunk($import->id, $company->id, $rows)->handle();

    $import->refresh();
    $company->refresh();
    $firstTouch = Date::now();

    expect($import->processed_rows)->toBe(2);
    expect($company->updated_at)->toEqual($firstTouch);

    $prices = StockPrice::query()
        ->where('company_id', $company->id)
        ->orderByDesc('traded_on')
        ->get()
        ->mapWithKeys(static fn (StockPrice $price): array => [
            $price->traded_on->format('Y-m-d') => $price->price?->value(),
        ]);

    expect($prices->all())->toBe([
        '2025-04-30' => '161.840000',
        '2025-04-29' => '163.760000',
    ]);

    // Upsert updates existing values.
    $rows[0]['price'] = '200.000000';

    Date::setTestNow(CarbonImmutable::parse('2025-01-03 09:30:00'));

    new ProcessStockImportChunk($import->id, $company->id, [$rows[0]])->handle();

    $company->refresh();
    $secondTouch = Date::now();

    $latest = StockPrice::query()
        ->where('company_id', $company->id)
        ->where('traded_on', '2025-04-30')
        ->first();

    expect(StockPrice::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and($latest?->price?->value())->toBe('200.000000')
        ->and($company->updated_at)->toEqual($secondTouch)
        ->and($secondTouch)->not->toEqual($firstTouch);

    Date::setTestNow();
});
