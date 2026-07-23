<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricAggregation: string
{
    case Sum = 'sum';
    case Average = 'average';
    case Minimum = 'minimum';
    case Maximum = 'maximum';
    case Last = 'last';
}
