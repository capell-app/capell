<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Metrics;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricReadContextData;

interface MetricScopeAuthorizer
{
    public function canRead(MetricDefinitionData $definition, MetricReadContextData $context): bool;
}
