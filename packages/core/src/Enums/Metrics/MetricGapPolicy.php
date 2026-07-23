<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricGapPolicy: string
{
    case Missing = 'missing';
    case CarryForward = 'carry_forward';
}
