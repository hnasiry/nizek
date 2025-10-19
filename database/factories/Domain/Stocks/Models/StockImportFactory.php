<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Stocks\Models;

use App\Domain\Stocks\Enums\StockImportStatus;
use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockImport>
 */
final class StockImportFactory extends Factory
{
    protected $model = StockImport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $queuedAt = $this->faker->dateTimeBetween('-2 days', 'now');

        $totalRows = $this->faker->numberBetween(100, 5000);

        return [
            'id' => (string) Str::ulid(),
            'company_id' => Company::factory(),
            'original_filename' => $this->faker->unique()->lexify('prices-????.xlsx'),
            'stored_path' => 'imports/'.$this->faker->uuid().'.xlsx',
            'disk' => 'local',
            'status' => StockImportStatus::Pending,
            'total_rows' => $totalRows,
            'processed_rows' => $this->faker->numberBetween(0, $totalRows),
            'batch_id' => $this->faker->boolean(70) ? (string) Str::uuid() : null,
            'queued_at' => $queuedAt,
            'started_at' => $this->faker->boolean() ? $this->faker->dateTimeBetween($queuedAt, 'now') : null,
            'completed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ];
    }
}
