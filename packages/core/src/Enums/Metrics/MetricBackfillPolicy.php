<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

/** @internal */
enum MetricBackfillPolicy: string
{
    case Supported = 'supported';
    case CurrentDayOnly = 'current_day_only';
    case Unsupported = 'unsupported';
}
