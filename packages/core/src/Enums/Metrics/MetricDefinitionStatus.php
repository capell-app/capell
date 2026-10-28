<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

/** @experimental */
enum MetricDefinitionStatus: string
{
    case Active = 'active';
    case Tombstoned = 'tombstoned';
}
