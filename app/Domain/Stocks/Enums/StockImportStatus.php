<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Enums;

enum StockImportStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed], true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'green',
            self::Failed => 'red',
            self::Processing => 'blue',
            self::Queued => 'amber',
            default => 'zinc',
        };
    }
}
