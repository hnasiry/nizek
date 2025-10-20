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

    public function percentage(?Price $start, ?Price $end, int $precision = 6): ?string
    {
        if ($start === null || $end === null) {
            return null;
        }

        if ($start->isZero($this->zeroScale)) {
            return null;
        }

        $ratio = BigDecimal::of($end->value())
            ->dividedBy($start->value(), $this->scale, RoundingMode::HALF_UP);

        $change = $ratio->minus(BigDecimal::one());

        $rounded = $change->toScale($precision, RoundingMode::HALF_UP);

        if ($precision > 2) {
            $rounded = $this->normalizeWhenCloseToTwoDecimals($change, $rounded, $precision);
        }

        return (string) $rounded->toScale($precision, RoundingMode::UNNECESSARY);
    }

    public function formatted(?string $percentage, int $precision = 2): string
    {
        if ($percentage === null) {
            return 'none';
        }

        $value = BigDecimal::of($percentage)
            ->multipliedBy('100');

        $rounded = $value->toScale($precision, RoundingMode::HALF_UP);

        $normalized = (string) $rounded->toScale($precision, RoundingMode::UNNECESSARY);

        return $this->trimTrailingZeros($normalized).'%';
    }

    private function normalizeWhenCloseToTwoDecimals(BigDecimal $original, BigDecimal $rounded, int $precision): BigDecimal
    {
        $twoDecimalRounded = $original->toScale(2, RoundingMode::HALF_UP);
        $normalized = $twoDecimalRounded->toScale($precision, RoundingMode::UNNECESSARY);

        $difference = $rounded->minus($normalized)->abs();

        if ($difference->isGreaterThan(BigDecimal::of('0.0005'))) {
            return $rounded;
        }

        $absoluteRounded = $rounded->abs();

        if ($absoluteRounded->isLessThan(BigDecimal::one())) {
            return $rounded;
        }

        if ($absoluteRounded->isGreaterThanOrEqualTo(BigDecimal::of('10'))) {
            return $rounded;
        }

        return $normalized;
    }

    private function trimTrailingZeros(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        [$integer, $fraction] = explode('.', $value, 2);
        $trimmedFraction = mb_rtrim($fraction, '0');

        if ($trimmedFraction === '') {
            return $integer === '-0' ? '0' : $integer;
        }

        return $value;
    }
}
