<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Stocks\Actions\BuildStockPerformanceSummary;
use App\Domain\Stocks\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Stocks\CompanyStockPerformanceRequest;
use Illuminate\Http\JsonResponse;

final class CompanyStockPerformanceController extends Controller
{
    public function __construct(private BuildStockPerformanceSummary $builder) {}

    public function __invoke(CompanyStockPerformanceRequest $request, Company $company): JsonResponse
    {
        $payload = $this->builder->handle(
            $company,
            $request->asOf(),
            $request->periods(),
        );

        return response()->json([
            'data' => $payload,
        ]);
    }
}
