<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Jobs\PrepareStockImport;
use App\Domain\Stocks\Models\StockImport;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;

final class QueueStockImport
{
    public function __invoke(StockImport $import): void
    {
        if ($import->status->isTerminal()) {
            return;
        }

        $queue = config('stocks.import.queue');

        $import->forceFill([
            'status' => StockImportStatus::Queued,
            'queued_at' => Date::now(),
            'processed_rows' => 0,
            'failure_reason' => null,
            'batch_id' => null,
        ])->save();

        PrepareStockImport::dispatch($import->id)->onQueue($queue);

        Log::info('Stock import dispatched for processing.', [
            'import_id' => $import->id,
            'queue' => $queue,
        ]);
    }
}
