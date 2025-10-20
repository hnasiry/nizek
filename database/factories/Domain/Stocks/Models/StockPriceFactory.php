<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Stocks\Models;

use App\Domain\Stocks\Models\Company;
use App\Domain\Stocks\Models\StockPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockPrice>
 */
final class StockPriceFactory extends Factory
{
    protected $model = StockPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'traded_on' => $this->faker->dateTimeBetween('-3 years', 'now')->format('Y-m-d'),
            'price' => number_format($this->faker->randomFloat(6, 10, 250), 6, '.', ''),
        ];
    }
}
