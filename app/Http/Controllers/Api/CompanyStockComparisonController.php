<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Stocks\Actions\ResolveStockPriceComparison;
use App\Domain\Stocks\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Stocks\CustomComparisonRequest;
use Illuminate\Http\JsonResponse;

final class CompanyStockComparisonController extends Controller
{
    public function __construct(private ResolveStockPriceComparison $resolver) {}

    public function __invoke(CustomComparisonRequest $request, Company $company): JsonResponse
    {
        $payload = $this->resolver->handle(
            $company,
            $request->fromDate(),
            $request->toDate(),
        );

        return response()->json([
            'data' => $payload,
        ]);
    }
}
