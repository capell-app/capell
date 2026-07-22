<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Metrics;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Carbon\CarbonImmutable;

interface CollectsDailyMetrics
{
    /**
     * Declare the metrics this collector produces, for discovery and labelling.
     *
     * @return list<MetricDefinitionData>
     */
    public function definitions(): array;

    /**
     * Produce one sample per (metric, scope) for the given day.
     *
     * @return iterable<MetricSampleData>
     */
    public function collect(CarbonImmutable $day): iterable;
}
