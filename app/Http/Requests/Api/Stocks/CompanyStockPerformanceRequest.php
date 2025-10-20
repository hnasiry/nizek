<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Stocks;

use App\Domain\Stocks\Enums\StockPerformancePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CompanyStockPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'periods' => ['nullable', 'array'],
            'periods.*' => ['string', Rule::enum(StockPerformancePeriod::class)],
        ];
    }

    public function asOf(): ?CarbonImmutable
    {
        $validated = $this->validated();
        $value = $validated['as_of'] ?? null;

        return $value !== null
            ? CarbonImmutable::createFromFormat('Y-m-d', $value)
            : null;
    }

    /**
     * @return list<StockPerformancePeriod>
     */
    public function periods(): array
    {
        $validated = $this->validated();
        $periods = $validated['periods'] ?? null;

        if ($periods === null) {
            return StockPerformancePeriod::cases();
        }

        return array_map(
            static fn (string $period): StockPerformancePeriod => StockPerformancePeriod::from($period),
            $periods,
        );
    }

    protected function prepareForValidation(): void
    {
        $periods = $this->input('periods');

        if (is_string($periods)) {
            $this->merge([
                'periods' => array_values(array_filter(array_map('trim', explode(',', $periods)))),
            ]);
        }
    }
}
