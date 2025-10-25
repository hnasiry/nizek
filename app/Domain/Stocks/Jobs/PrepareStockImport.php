<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Jobs;

use App\Domain\Stocks\Actions\MarkStockImportBatchCompleted;
use App\Domain\Stocks\Actions\MarkStockImportBatchFailed;
use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\StockImport;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
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

        $totalRows = 0;

        $failureHandler = new MarkStockImportBatchFailed($import->id);
        $completionHandler = new MarkStockImportBatchCompleted($import->id);
        $batch = null;

        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($import->disk);
        $reader = null;
        $temporaryHandle = null;

        try {
            [$readerPath, $temporaryHandle] = $this->prepareReaderPath($storage, $import->stored_path);

            $reader = SimpleExcelReader::create($readerPath)
                ->trimHeaderRow()
                ->headersToSnakeCase();

            $chunkSize = max(1, config('stocks.import.chunk_size'));

            foreach ($reader->getRows()->chunk($chunkSize) as $chunk) {
                $sanitized = $this->sanitizeChunk($chunk);
                $rows = $sanitized->all();

                if ($rows === []) {
                    continue;
                }

                $totalRows += count($rows);

                $job = new ProcessStockImportChunk(
                    importId: $import->id,
                    companyId: $import->company_id,
                    rows: $rows
                );

                try {
                    if ($batch === null) {
                        $batch = Bus::batch([$job])
                            ->name(sprintf('stock-import:%s', $import->id))
                            ->then($completionHandler)
                            ->catch($failureHandler)
                            ->onQueue(config('stocks.import.queue'))
                            ->dispatch();
                    } else {
                        $batch = $batch->add([$job]);
                    }
                } catch (Throwable $exception) {
                    $failureHandler->dispatchFailed($exception, 'Unable to dispatch stock import batch.');

                    throw $exception;
                }
            }

            $reader->close();
        } catch (Throwable $exception) {
            $failureHandler->dispatchFailed($exception, 'Failed to read stock import file.');

            throw $exception;
        } finally {
            $reader?->close();

            if (is_resource($temporaryHandle)) {
                fclose($temporaryHandle);
            }
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
            'price' => number_format($price, 6, '.', ''),
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

    /** @return array{0:string,1:resource|null} */
    private function prepareReaderPath(FilesystemAdapter $storage, string $storedPath): array
    {
        if ($storage->getAdapter() instanceof LocalFilesystemAdapter) {
            $localPath = $storage->path($storedPath);

            if (! is_string($localPath) || $localPath === '') {
                throw new RuntimeException('Unable to resolve local path for stock import file.');
            }

            return [$localPath, null];
        }

        $temporaryHandle = tmpfile();

        if ($temporaryHandle === false) {
            throw new RuntimeException('Unable to create temporary file for stock import.');
        }

        $meta = stream_get_meta_data($temporaryHandle);
        $temporaryPath = $meta['uri'] ?? null;

        if (! is_string($temporaryPath) || $temporaryPath === '') {
            fclose($temporaryHandle);

            throw new RuntimeException('Unable to discover temporary file path for stock import.');
        }

        $sourceStream = $storage->readStream($storedPath);

        if (! is_resource($sourceStream)) {
            fclose($temporaryHandle);

            throw new RuntimeException('Unable to read stock import stream from storage.');
        }

        try {
            if (stream_copy_to_stream($sourceStream, $temporaryHandle) === false) {
                throw new RuntimeException('Unable to copy stock import stream to temporary file.');
            }
        } finally {
            fclose($sourceStream);
        }

        return [$temporaryPath, $temporaryHandle];
    }
}
