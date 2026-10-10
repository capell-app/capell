<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use LogicException;
use Override;

final class ConflictingRegistryTestMetricCollector implements CollectsDailyMetrics
{
    #[Override]
    public function definitions(): array
    {
        return [registryTestDefinition()];
    }

    #[Override]
    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        throw new LogicException('Collection is not used by the registry test.');
    }
}
