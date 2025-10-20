<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Jobs;

use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use App\Domain\Stocks\Models\StockPrice;
use App\Domain\Stocks\ValueObjects\Price;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;

final class ProcessStockImportChunk implements ShouldQueue
{
    use Batchable;
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
        if ($this->batch()?->cancelled()) {
            return;
        }

        if ($this->rows === []) {
            return;
        }

        $payload = array_map(function (array $row): array {
            $price = Price::fromString($row['price']);

            return [
                'company_id' => $this->companyId,
                'traded_on' => $row['traded_on'],
                'price' => $price->toMinor(),
            ];
        }, $this->rows);

        StockPrice::query()->upsert(
            values: $payload,
            uniqueBy: ['company_id', 'traded_on'],
            update: ['price']
        );

        // Update company updated_at to invalidate cache key for stock performance summary
        Company::query()
            ->whereKey($this->companyId)
            ->update(['updated_at' => Date::now()]);

        StockImport::query()->whereKey($this->importId)->increment('processed_rows', count($payload));
    }
}
