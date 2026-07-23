<?php

declare(strict_types=1);

namespace Capell\Core\Support\Metrics;

use Capell\Core\Contracts\Metrics\MetricScopeAuthorizer;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricReadContextData;

final class DenyMetricScopeAuthorizer implements MetricScopeAuthorizer
{
    public function canRead(MetricDefinitionData $definition, MetricReadContextData $context): bool
    {
        return false;
    }
}
