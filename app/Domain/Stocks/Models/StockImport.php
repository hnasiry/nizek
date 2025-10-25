<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Models;

use App\Domain\Stocks\Enums\StockImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StockImport extends Model
{
    use HasFactory;

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'company_id',
        'original_filename',
        'stored_path',
        'disk',
        'status',
        'total_rows',
        'processed_rows',
        'batch_id',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
        'failure_reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => StockImportStatus::Pending,
        'disk' => 'local',
    ];

    protected $keyType = 'string';

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockImportStatus::class,
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
