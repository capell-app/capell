<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricCollectionRunStatus: string
{
    case Started = 'started';
    case Completed = 'completed';
    case Failed = 'failed';
    case Unsupported = 'unsupported';
}
