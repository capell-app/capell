<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Database;

enum DatabaseDateOperation: string
{
    case Year = 'year';
    case Month = 'month';
    case Day = 'day';
    case Hour = 'hour';
    case HourLabel = 'hour-label';
    case DayAbbreviation = 'day-abbreviation';
    case DayMonthLabel = 'day-month-label';
    case MonthYearLabel = 'month-year-label';
}
