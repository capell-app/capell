<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum MetricUnitEnum: string
{
    case Count = 'count';
    case Amount = 'amount';
    case Percent = 'percent';
    case Milliseconds = 'milliseconds';
}
