<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use Throwable;

final class StockImportRowSanitizer
{
    /**
     * @param  LazyCollection<int, array<string, mixed>>  $chunk
     * @return LazyCollection<int, array<string, string>>
     */
    public function sanitize(LazyCollection $chunk): LazyCollection
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
        } catch (Throwable $exception) {
            return null;
        }
    }

    private function parsePrice(mixed $value): ?float
    {
        $price = filter_var($value, FILTER_VALIDATE_FLOAT);

        return $price !== false ? $price : null;
    }
}
