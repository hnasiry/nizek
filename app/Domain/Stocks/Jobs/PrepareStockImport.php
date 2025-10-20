<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Jobs;

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\StockImport;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

final class PrepareStockImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $importId
    ) {
        $this->onQueue(config('stocks.import.queue'));
    }

    public function handle(): void
    {
        /** @var StockImport|null $import */
        $import = StockImport::query()->find($this->importId);

        if ($import === null || $import->status->isTerminal()) {
            return;
        }

        $import->forceFill([
            'status' => StockImportStatus::Processing,
            'started_at' => Date::now(),
            'processed_rows' => 0,
            'failure_reason' => null,
        ])->save();

        $jobs = [];
        $totalRows = 0;

        try {
            $path = Storage::disk($import->disk)->path($import->stored_path);

            $reader = SimpleExcelReader::create($path)
                ->trimHeaderRow()
                ->headersToSnakeCase();

            $chunkSize = max(1, config('stocks.import.chunk_size'));

            foreach ($reader->getRows()->chunk($chunkSize) as $chunk) {
                $sanitized = $this->sanitizeChunk($chunk);

                if ($sanitized->isEmpty()) {
                    continue;
                }

                $jobs[] = new ProcessStockImportChunk(
                    importId: $import->id,
                    companyId: $import->company_id,
                    rows: $sanitized->all()
                );

                $totalRows += $sanitized->count();
            }

            $reader->close();
        } catch (Throwable $exception) {
            $this->markFailed($import->id, $exception);

            Log::error('Failed to read stock import file.', [
                'import_id' => $import->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }

        if ($totalRows === 0) {
            $import->forceFill([
                'status' => StockImportStatus::Completed,
                'completed_at' => Date::now(),
                'total_rows' => 0,
                'processed_rows' => 0,
            ])->save();

            return;
        }

        $pendingBatch = Bus::batch($jobs)
            ->name(sprintf('stock-import:%s', $import->id))
            ->then(fn () => $this->markCompleted($import->id))
            ->catch(function (Throwable $exception) use ($import): void {
                $this->markFailed($import->id, $exception);

                Log::error('Stock import batch failed.', [
                    'import_id' => $import->id,
                    'exception' => $exception,
                ]);
            })
            ->onQueue(config('stocks.import.queue'));

        $batch = $pendingBatch->dispatch();

        $import->forceFill([
            'batch_id' => $batch->id,
            'total_rows' => $totalRows,
        ])->save();
    }

    private function sanitizeChunk(LazyCollection $chunk): LazyCollection
    {
        return $chunk
            ->map(fn (array $row): ?array => $this->sanitizeRow($row))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>|null
     */
    private function sanitizeRow(array $row): ?array
    {
        $dateValue = data_get($row, 'date');
        $priceValue = data_get($row, 'stock_price') ?? data_get($row, 'price');


        if (empty($dateValue) || empty($priceValue)) {
            return null;
        }

        $date = $this->parseDate($dateValue);
        if ($date === null) {
            return null;
        }

        $price = $this->parsePrice($priceValue);
        if ($price === null) {
            return null;
        }

        return [
            'traded_on' => $date->toDateString(),
            'price' => number_format($price, 4, '.', ''),
        ];
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Date::parse($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function parsePrice(mixed $value): ?float
    {
        $price = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $price !== false ? $price : null;
    }

    private function markCompleted(string $importId): void
    {
        /** @var StockImport|null $import */
        $import = StockImport::query()->find($importId);

        if ($import === null) {
            return;
        }

        $import->forceFill([
            'status' => StockImportStatus::Completed,
            'completed_at' => Date::now(),
        ])->save();
    }

    private function markFailed(string $importId, Throwable $exception): void
    {
        /** @var StockImport|null $import */
        $import = StockImport::query()->find($importId);

        if ($import === null) {
            return;
        }

        $import->forceFill([
            'status' => StockImportStatus::Failed,
            'failed_at' => Date::now(),
            'failure_reason' => $exception->getMessage(),
        ])->save();
    }
}
