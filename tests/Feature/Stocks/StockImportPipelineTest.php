<?php

declare(strict_types=1);

use App\Domain\Stocks\Actions\QueueStockImport;
use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Jobs\PrepareStockImport;
use App\Domain\Stocks\Jobs\ProcessStockImportChunk;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use App\Domain\Stocks\Models\StockPrice;
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
        ['traded_on' => '2025-04-30', 'price' => '161.8400'],
        ['traded_on' => '2025-04-29', 'price' => '163.7600'],
    ];

    new ProcessStockImportChunk($import->id, $company->id, $rows)->handle();

    $import->refresh();

    expect($import->processed_rows)->toBe(2);

    $prices = StockPrice::query()
        ->where('company_id', $company->id)
        ->orderBy('traded_on', 'desc')
        ->pluck('price', 'traded_on');

    expect($prices->all())->toBe([
        '2025-04-30' => '161.8400',
        '2025-04-29' => '163.7600',
    ]);

    // Upsert updates existing values.
    $rows[0]['price'] = '200.0000';

    new ProcessStockImportChunk($import->id, $company->id, [$rows[0]])->handle();

    expect(StockPrice::query()->where('company_id', $company->id)->count())->toBe(2)
        ->and(StockPrice::query()->where('company_id', $company->id)->where('traded_on', '2025-04-30')->value('price'))
        ->toBe('200.0000');
});
