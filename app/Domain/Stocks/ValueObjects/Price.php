<?php

declare(strict_types=1);

namespace App\Domain\Stocks\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class Price
{
    public const int SCALE = 6;

    private const int DEFAULT_ROUNDING_SCALE = self::SCALE;

    private function __construct(
        private readonly BigDecimal $amount,
        private readonly int $scale,
    ) {}

    public function __toString(): string
    {
        return $this->value();
    }

    public static function fromString(string $value, int $scale = self::SCALE): self
    {
        return new self(self::normalize($value, $scale), $scale);
    }

    public static function fromMinor(int|string $value, int $scale = self::SCALE): self
    {
        if (! preg_match('/^-?\d+$/', (string) $value)) {
            throw new InvalidArgumentException('Minor units must be an integer value.');
        }

        $decimal = BigDecimal::of((string) $value)
            ->dividedBy(self::powerOfTen($scale), $scale, RoundingMode::DOWN);

        return new self(self::normalize($decimal, $scale), $scale);
    }

    public function value(): string
    {
        return (string) $this->amount->toScale($this->scale, RoundingMode::UNNECESSARY);
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function isZero(?int $scale = null): bool
    {
        $precision = $scale ?? $this->scale;

        return $this->amount
            ->toScale($precision, RoundingMode::DOWN)
            ->isZero();
    }

    public function dividedBy(self $divisor, int $scale = 10): string
    {
        return (string) $this->amount
            ->dividedBy($divisor->amount, $scale, RoundingMode::DOWN);
    }

    public function toMinor(?int $scale = null): string
    {
        $precision = $scale ?? $this->scale;

        $scaled = $this->amount->toScale($precision, RoundingMode::DOWN);

        return (string) $scaled
            ->withPointMovedRight($precision)
            ->toScale(0, RoundingMode::DOWN);
    }

    public function formatted(int $precision = 2): string
    {
        return $this->round($precision);
    }

    public function round(int $precision = self::DEFAULT_ROUNDING_SCALE): string
    {
        return (string) $this->amount
            ->toScale($precision, RoundingMode::DOWN);
    }

    private static function normalize(BigDecimal|string $value, int $scale): BigDecimal
    {
        $decimal = $value instanceof BigDecimal ? $value : BigDecimal::of($value);

        return $decimal->toScale($scale, RoundingMode::DOWN);
    }

    private static function powerOfTen(int $scale): BigDecimal
    {
        if ($scale === 0) {
            return BigDecimal::one();
        }

        return BigDecimal::one()->withPointMovedRight($scale);
    }
}
