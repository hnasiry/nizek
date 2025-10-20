<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Support;

use App\Domain\Stocks\ValueObjects\Price;

final class PriceChangeCalculator
{
    public function __construct(
        private int $scale = 12,
        private int $zeroScale = Price::SCALE,
    ) {}

    public function percentage(?Price $start, ?Price $end, int $precision = 6): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        if ($start->isZero($this->zeroScale)) {
            return null;
        }

        $ratio = $end->dividedBy($start, $this->scale);
        $change = bcsub($ratio, '1', $this->scale);

        return $this->round($change, $precision);
    }

    public function formatted(?string $percentage, int $precision = 2): string
    {
        if ($percentage === null) {
            return 'none';
        }

        $value = bcmul($percentage, '100', $this->scale);
        $rounded = $this->round($value, $precision);

        return $rounded.'%';
    }

    private function round(string $value, int $precision): string
    {
        $scale = max($precision + 2, $this->scale);
        $comparison = bccomp($value, '0', $scale);

        if ($comparison === 0) {
            return bcadd('0', '0', $precision);
        }

        $increment = $this->roundingIncrement($precision);

        $adjusted = $comparison > 0
            ? bcadd($value, $increment, $precision)
            : bcsub($value, $increment, $precision);

        if (bccomp($adjusted, '0', $precision) === 0) {
            return bcadd('0', '0', $precision);
        }

        return $adjusted;
    }

    private function roundingIncrement(int $precision): string
    {
        return sprintf('0.%s5', str_repeat('0', $precision));
    }
}
