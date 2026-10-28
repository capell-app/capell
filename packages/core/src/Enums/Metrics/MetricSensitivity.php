<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Metrics;

/** @experimental */
enum MetricSensitivity: string
{
    case Public = 'public';
    case Internal = 'internal';
    case Confidential = 'confidential';
}
