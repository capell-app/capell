<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

/** @internal */
enum MetricReaderType: string
{
    case Anonymous = 'anonymous';
    case User = 'user';
    case System = 'system';
}
