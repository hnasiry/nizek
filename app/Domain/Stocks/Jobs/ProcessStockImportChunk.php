<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Jobs;

use App\Domain\Stocks\Models\StockImport;
use App\Domain\Stocks\Models\StockPrice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;

final class ProcessStockImportChunk implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    public function __construct(
        public string $importId,
        public int $companyId,
        public array $rows
    ) {
        $this->onQueue(config('stocks.import.queue'));
    }

    public function handle(): void
    {
        if ($this->rows === []) {
            return;
        }

        $timestamp = Date::now()->toDateTimeString();

        $payload = array_map(function (array $row) use ($timestamp): array {
            return [
                'company_id' => $this->companyId,
                'stock_import_id' => $this->importId,
                'traded_on' => $row['traded_on'],
                'price' => $row['price'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }, $this->rows);

        StockPrice::query()->upsert(
            values: $payload,
            uniqueBy: ['company_id', 'traded_on'],
            update: ['price', 'stock_import_id', 'updated_at']
        );

        StockImport::query()->whereKey($this->importId)->increment('processed_rows', count($payload));
    }
}
