<?php

declare(strict_types=1);

namespace App\Domain\Stocks\ValueObjects;

use InvalidArgumentException;

final class Price
{
    public const int SCALE = 6;

    private const int DEFAULT_ROUNDING_SCALE = self::SCALE;

    private function __construct(
        private readonly string $amount,
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

        $decimal = bcdiv((string) $value, self::powerOfTen($scale), $scale);

        return new self($decimal, $scale);
    }

    public function value(): string
    {
        return $this->amount;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function isZero(?int $scale = null): bool
    {
        $precision = $scale ?? $this->scale;

        return bccomp($this->amount, '0', $precision) === 0;
    }

    public function dividedBy(self $divisor, int $scale = 10): string
    {
        return bcdiv($this->amount, $divisor->amount, $scale);
    }

    public function toMinor(?int $scale = null): string
    {
        $precision = $scale ?? $this->scale;

        return bcmul($this->amount, self::powerOfTen($precision), 0);
    }

    public function formatted(int $precision = 2): string
    {
        return $this->round($precision);
    }

    public function round(int $precision = self::DEFAULT_ROUNDING_SCALE): string
    {
        return bcadd($this->amount, '0', $precision);
    }

    private static function normalize(string $value, int $scale): string
    {
        return bcadd($value, '0', $scale);
    }

    private static function powerOfTen(int $scale): string
    {
        return $scale === 0 ? '1' : '1'.str_repeat('0', $scale);
    }
}
