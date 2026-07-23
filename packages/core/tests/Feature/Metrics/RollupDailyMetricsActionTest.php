<?php

declare(strict_types=1);

use Capell\Core\Actions\Metrics\RollupDailyMetricsAction;
use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricSampleData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;

it('atomically stores a complete collector day and replaces an earlier snapshot', function (): void {
    $registry = resolve(MetricCollectorRegistry::class);
    $registry->register(RollupTestMetricCollector::class);
    $scope = MetricScopeData::global('UTC');
    $action = resolve(RollupDailyMetricsAction::class);

    expect($action->execute('2026-07-21', [$scope]))->toBe(1)
        ->and($action->execute('2026-07-21', [$scope]))->toBe(1)
        ->and(MetricDailyRollup::query()->count())->toBe(1);

    $rollup = MetricDailyRollup::query()->sole();

    expect($rollup->value)->toBe('7')
        ->and($rollup->point_state)->toBe(MetricPointState::Present)
        ->and(MetricCollectionRun::query()->where('status', MetricCollectionRunStatus::Completed)->count())->toBe(2);
});

final class RollupTestMetricCollector implements CollectsDailyMetrics
{
    public function definitions(): array
    {
        return [rollupTestDefinition()];
    }

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

function rollupTestDefinition(): MetricDefinitionData
{
    return new MetricDefinitionData(
        identity: new MetricIdentityData('capell-app/test', 'daily', 'requests'),
        representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
        scopeType: MetricScopeType::Global,
        semantics: new MetricSemanticsData(
            MetricSemantic::Counter,
            MetricAggregation::Sum,
            MetricGapPolicy::Missing,
            MetricBackfillPolicy::Supported,
        ),
        governance: new MetricGovernanceData(
            MetricSource::Database,
            'tests.requests',
            MetricSensitivity::Internal,
            MetricVisibility::PlatformOps,
        ),
    );
}
