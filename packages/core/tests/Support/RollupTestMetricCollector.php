<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Override;

final class RollupTestMetricCollector implements CollectsDailyMetrics
{
    #[Override]
    public function definitions(): array
    {
        return [rollupTestDefinition()];
    }

    #[Override]
    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        $definition = rollupTestDefinition();
        $scope = $scopes[0];
        $sample = new MetricSampleData(
            $definition->identity,
            $definition->semanticHash(),
            $day,
            $scope,
            $definition->representation,
            MetricValueData::integer(7),
        );

        return new MetricCollectionResultData(
            MetricCollectionStatus::Complete,
            $day,
            [$scope],
            [$sample],
            'fixture:' . $day,
            hash('sha256', '7'),
            null,
        );
    }
}
