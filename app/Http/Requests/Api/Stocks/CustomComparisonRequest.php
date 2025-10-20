<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Stocks;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class CustomComparisonRequest extends FormRequest
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
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function fromDate(): CarbonImmutable
    {
        $validated = $this->validated();

        return CarbonImmutable::createFromFormat('Y-m-d', $validated['from']);
    }

    public function toDate(): CarbonImmutable
    {
        $validated = $this->validated();

        return CarbonImmutable::createFromFormat('Y-m-d', $validated['to']);
    }
}
