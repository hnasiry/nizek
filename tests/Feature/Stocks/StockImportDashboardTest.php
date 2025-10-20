<?php

declare(strict_types=1);

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Jobs\PrepareStockImport;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

it('queues a stock import from the dashboard uploader', function (): void {
    Storage::fake(config('stocks.import.disk'));
    Queue::fake();

    $this->actingAs(User::factory()->create());

    $company = Company::factory()->create();

    $file = UploadedFile::fake()->create(
        name: 'prices.xlsx',
        kilobytes: 100,
        mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    Volt::test('dashboard.imports')
        ->set('companyId', $company->id)
        ->set('upload', $file)
        ->call('queueImport')
        ->assertDispatched('import-queued')
        ->assertSet('upload', null);

    /** @var StockImport|null $import */
    $import = StockImport::query()->first();

    expect($import)->not->toBeNull()
        ->and($import->company_id)->toBe($company->id)
        ->and($import->status)->toBe(StockImportStatus::Queued);

    Storage::disk(config('stocks.import.disk'))->assertExists($import->stored_path);

    Queue::assertPushed(PrepareStockImport::class, fn (PrepareStockImport $job): bool => $job->importId === $import->id);
});

it('prefills the first company option', function (): void {
    $this->actingAs(User::factory()->create());

    $acme = Company::factory()->create([
        'name' => 'Acme Corp',
        'symbol' => 'ACM',
        'slug' => 'acme-corp',
    ]);

    Company::factory()->create([
        'name' => 'Zen Holdings',
        'symbol' => 'ZEN',
        'slug' => 'zen-holdings',
    ]);

    Volt::test('dashboard.imports')
        ->assertSet('companyId', $acme->id);
});

it('validates the uploaded spreadsheet', function (): void {
    Storage::fake(config('stocks.import.disk'));
    Queue::fake();

    $this->actingAs(User::factory()->create());

    $company = Company::factory()->create();

    $invalidFile = UploadedFile::fake()->create(
        name: 'notes.txt',
        kilobytes: 10,
        mimeType: 'text/plain'
    );

    Volt::test('dashboard.imports')
        ->set('companyId', $company->id)
        ->set('upload', $invalidFile)
        ->call('queueImport')
        ->assertHasErrors(['upload' => ['mimes']]);

    expect(StockImport::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});
