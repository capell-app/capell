<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricCollectionStatus: string
{
    case Complete = 'complete';
    case Failed = 'failed';
    case Unsupported = 'unsupported';
}
