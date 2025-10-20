<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\IssueTokenController;
use App\Http\Controllers\Api\CompanyStockComparisonController;
use App\Http\Controllers\Api\CompanyStockPerformanceController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', IssueTokenController::class)
    ->name('api.auth.login');

Route::middleware(['auth:sanctum'])->group(function (): void {
    Route::prefix('companies/{company}/stock-prices')->name('api.companies.stock-prices.')->group(function (): void {
        Route::get('comparison', CompanyStockComparisonController::class)
            ->name('comparison');

        Route::get('performance', CompanyStockPerformanceController::class)
            ->name('performance');
    });
});
