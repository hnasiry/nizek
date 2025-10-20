<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Casts;

use App\Domain\Stocks\ValueObjects\Price;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class PriceCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Price
    {
        if ($value === null) {
            return null;
        }

        return Price::fromMinor($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Price) {
            return $value->toMinor();
        }

        if (is_string($value) || is_numeric($value)) {
            return Price::fromString((string) $value)->toMinor();
        }

        throw new InvalidArgumentException(sprintf('Unable to cast %s to a stock price.', get_debug_type($value)));
    }
}
