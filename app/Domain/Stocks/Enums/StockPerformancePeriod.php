<?php

declare(strict_types=1);

namespace App\Domain\Stocks\Enums;

enum StockPerformancePeriod: string
{
    case OneDay = '1D';
    case OneMonth = '1M';
    case ThreeMonths = '3M';
    case SixMonths = '6M';
    case YearToDate = 'YTD';
    case OneYear = '1Y';
    case ThreeYears = '3Y';
    case FiveYears = '5Y';
    case TenYears = '10Y';
    case Max = 'MAX';
}
