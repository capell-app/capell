<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricPointState: string
{
    case Present = 'present';
    case Zero = 'zero';
    case Missing = 'missing';
    case Stale = 'stale';
    case Unsupported = 'unsupported';
}
