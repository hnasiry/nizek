<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'symbol',
        'slug',
    ];

    public function stockImports(): HasMany
    {
        return $this->hasMany(StockImport::class);
    }

    public function stockPrices(): HasMany
    {
        return $this->hasMany(StockPrice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
