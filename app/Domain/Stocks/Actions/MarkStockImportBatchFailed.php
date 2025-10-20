<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\StockImport;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MarkStockImportBatchFailed
{
    public function __construct(
        private readonly string $importId
    ) {}

    public function __invoke(Batch $batch, Throwable $exception): void
    {
        $this->recordFailure($exception);
    }

    public function dispatchFailed(Throwable $exception, ?string $contextMessage = null): void
    {
        $this->recordFailure($exception, $contextMessage);
    }

    private function recordFailure(Throwable $exception, ?string $contextMessage = null): void
    {
        /** @var StockImport|null $import */
        $import = StockImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $import->forceFill([
            'status' => StockImportStatus::Failed,
            'failed_at' => Date::now(),
            'failure_reason' => $exception->getMessage(),
        ])->save();

        Log::error($contextMessage ?? 'Stock import batch failed.', [
            'import_id' => $import->id,
            'exception' => $exception,
        ]);
    }
}
