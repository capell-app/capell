<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Metrics;

use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;

interface CollectsDailyMetrics
{
    /** @return list<MetricDefinitionData> */
    public function definitions(): array;

    /**
     * @param  list<MetricScopeData>  $scopes
     */
    public function collect(string $day, array $scopes): MetricCollectionResultData;
}
