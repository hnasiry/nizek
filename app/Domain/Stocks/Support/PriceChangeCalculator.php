<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Support;

use App\Domain\Stocks\ValueObjects\Price;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PriceChangeCalculator
{
    public function __construct(
        private int $scale = 12,
        private int $zeroScale = Price::SCALE,
    ) {}

    public function percentage(?Price $start, ?Price $end, int $precision = 4): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        if ($start->isZero($this->zeroScale)) {
            return null;
        }

        $change = BigDecimal::of($end->value())
            ->dividedBy($start->value(), $this->scale, RoundingMode::HALF_UP)
            ->minus(BigDecimal::one())
            ->toScale($precision, RoundingMode::HALF_UP);

        return (string) $change;
    }

    public function formatted(?string $percentage, int $precision = 2): string
    {
        if ($percentage === null) {
            return 'none';
        }

        $value = BigDecimal::of($percentage)
            ->multipliedBy('100');

        $rounded = $value->toScale($precision, RoundingMode::HALF_UP);

        return $rounded.'%';
    }
}
