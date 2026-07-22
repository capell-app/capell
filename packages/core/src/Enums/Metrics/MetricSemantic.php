<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricSemantic: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Ratio = 'ratio';
    case Event = 'event';
}
