<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Models;

use App\Domain\Stocks\Casts\PriceCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockPrice extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'company_id',
        'traded_on',
        'price',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'traded_on' => 'immutable_date',
            'price' => PriceCast::class,
        ];
    }
}
