<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricValueType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case MinorCurrencyUnit = 'minor_currency_unit';
}
