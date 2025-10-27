<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Actions;

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\StockImport;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Date;

final class MarkStockImportBatchCompleted
{
    public function __construct(
        private readonly string $importId
    ) {}

    public function __invoke(Batch $batch): void
    {
        /** @var StockImport|null $import */
        $import = StockImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $this->complete($import);
    }

    /**
     * @param  array<string, mixed>  $extraAttributes
     */
    public function completeWithoutBatch(StockImport $import, array $extraAttributes = []): void
    {
        $this->complete($import, $extraAttributes);
    }

    /**
     * @param  array<string, mixed>  $extraAttributes
     */
    private function complete(StockImport $import, array $extraAttributes = []): void
    {
        $import->forceFill(array_merge([
            'status' => StockImportStatus::Completed,
            'completed_at' => Date::now(),
        ], $extraAttributes))->save();
    }
}
