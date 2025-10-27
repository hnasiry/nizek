<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Jobs;

use App\Domain\Stocks\Actions\MarkStockImportBatchCompleted;
use App\Domain\Stocks\Actions\MarkStockImportBatchFailed;
use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\StockImport;
use App\Domain\Stocks\Support\ResolvedStockImportFile;
use App\Domain\Stocks\Support\StockImportFileResolver;
use App\Domain\Stocks\Support\StockImportRowSanitizer;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use LogicException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Throwable;

final class PrepareStockImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private ?StockImport $import = null;

    private ?Batch $dispatchedBatch = null;

    private int $totalRows = 0;

    private ?MarkStockImportBatchCompleted $completionHandler = null;

    private ?MarkStockImportBatchFailed $failureHandler = null;

    private ?StockImportFileResolver $fileResolver = null;

    private ?StockImportRowSanitizer $rowSanitizer = null;

    public function __construct(
        public string $importId
    ) {
        $this->onQueue(config('stocks.import.queue'));
    }

    public function handle(StockImportFileResolver $fileResolver, StockImportRowSanitizer $rowSanitizer): void
    {
        $this->resetState();
        $this->fileResolver = $fileResolver;
        $this->rowSanitizer = $rowSanitizer;

        $this->import = $this->findImport();

        if ($this->import === null) {
            return;
        }

        $this->initializeHandlers();
        $this->markImportProcessing();

        try {
            $this->processImportFile();
        } catch (Throwable $exception) {
            $this->failureHandler?->dispatchFailed($exception, 'Failed to read stock import file.');

            throw $exception;
        }

        $this->finalizeImport();
    }

    private function findImport(): ?StockImport
    {
        /** @var StockImport|null $import */
        $import = StockImport::query()->find($this->importId);

        if ($import === null || $import->status->isTerminal()) {
            return null;
        }

        return $import;
    }

    private function markImportProcessing(): void
    {
        $this->import?->forceFill([
            'status' => StockImportStatus::Processing,
            'started_at' => Date::now(),
            'processed_rows' => 0,
            'failure_reason' => null,
        ])->save();
    }

    private function processImportFile(): void
    {
        if ($this->import === null || $this->fileResolver === null) {
            return;
        }

        $resolvedFile = $this->fileResolver->resolve($this->import->disk, $this->import->stored_path);

        try {
            $this->processResolvedFile($resolvedFile);
        } finally {
            $resolvedFile->release();
        }
    }

    private function processResolvedFile(ResolvedStockImportFile $resolvedFile): void
    {
        $reader = SimpleExcelReader::create($resolvedFile->path)
            ->trimHeaderRow()
            ->headersToSnakeCase();

        try {
            $this->processReaderRows($reader);
        } finally {
            $reader->close();
        }
    }

    private function processReaderRows(SimpleExcelReader $reader): void
    {
        if ($this->rowSanitizer === null) {
            throw new LogicException('Row sanitizer must be initialized before processing reader rows.');
        }

        $chunkSize = $this->chunkSize();

        foreach ($reader->getRows()->chunk($chunkSize) as $chunk) {
            $rows = $this->rowSanitizer->sanitize($chunk)->all();

            if ($rows === []) {
                continue;
            }

            $this->totalRows += count($rows);
            $this->queueSanitizedChunk($rows);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function queueSanitizedChunk(array $rows): void
    {
        if ($this->import === null) {
            return;
        }

        $job = $this->makeChunkJob($rows);

        try {
            $this->dispatchedBatch = $this->dispatchChunk(
                $this->dispatchedBatch,
                $job,
                (string) $this->import->id,
            );
        } catch (Throwable $exception) {
            $this->failureHandler?->dispatchFailed($exception, 'Unable to dispatch stock import batch.');

            throw $exception;
        }
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function makeChunkJob(array $rows): ProcessStockImportChunk
    {
        if ($this->import === null) {
            throw new LogicException('Stock import must be resolved before queueing chunks.');
        }

        return new ProcessStockImportChunk(
            importId: $this->import->id,
            companyId: $this->import->company_id,
            rows: $rows,
        );
    }

    private function chunkSize(): int
    {
        return max(1, (int) config('stocks.import.chunk_size'));
    }

    private function dispatchChunk(
        ?Batch $batch,
        ProcessStockImportChunk $job,
        string $importId
    ): Batch {
        if ($this->completionHandler === null || $this->failureHandler === null) {
            throw new LogicException('Batch handlers must be initialized before dispatching.');
        }

        if ($batch === null) {
            return Bus::batch([$job])
                ->name(sprintf('stock-import:%s', $importId))
                ->then($this->completionHandler)
                ->catch($this->failureHandler)
                ->onQueue(config('stocks.import.queue'))
                ->dispatch();
        }

        $batch->add([$job]);

        return $batch;
    }

    private function finalizeImport(): void
    {
        if ($this->import === null || $this->completionHandler === null) {
            return;
        }

        if ($this->totalRows === 0) {
            $this->completionHandler->completeWithoutBatch($this->import, [
                'total_rows' => 0,
                'processed_rows' => 0,
            ]);

            return;
        }

        $this->import->forceFill([
            'batch_id' => $this->dispatchedBatch?->id,
            'total_rows' => $this->totalRows,
        ])->save();
    }

    private function initializeHandlers(): void
    {
        if ($this->import === null) {
            return;
        }

        $this->completionHandler = new MarkStockImportBatchCompleted($this->import->id);
        $this->failureHandler = new MarkStockImportBatchFailed($this->import->id);
    }

    private function resetState(): void
    {
        $this->import = null;
        $this->dispatchedBatch = null;
        $this->totalRows = 0;
        $this->completionHandler = null;
        $this->failureHandler = null;
        $this->fileResolver = null;
        $this->rowSanitizer = null;
    }
}
