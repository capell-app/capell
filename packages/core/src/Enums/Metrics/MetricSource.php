<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

enum MetricSource: string
{
    case Database = 'database';
    case EventStream = 'event_stream';
    case ExternalService = 'external_service';
    case Derived = 'derived';
}
