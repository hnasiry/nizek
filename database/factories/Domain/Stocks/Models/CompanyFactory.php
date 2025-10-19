<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Stocks\Models;

use App\Domain\Stocks\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'symbol' => Str::upper($this->faker->unique()->lexify('???')),
            'slug' => Str::slug($name.' '.$this->faker->unique()->randomNumber(3)),
        ];
    }
}
