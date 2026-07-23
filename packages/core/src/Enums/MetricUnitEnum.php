<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum MetricUnitEnum: string
{
    case Count = 'count';
    case Decimal = 'decimal';
    case MinorCurrencyUnit = 'minor_currency_unit';
    case Percentage = 'percentage';
    case Milliseconds = 'milliseconds';
    case Bytes = 'bytes';
}
