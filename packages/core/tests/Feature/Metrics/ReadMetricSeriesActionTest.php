<?php

declare(strict_types=1);

use Capell\Core\Actions\Metrics\ReadMetricSeriesAction;
use Capell\Core\Actions\Metrics\StoreMetricCollectionRunAction;
use Capell\Core\Actions\Metrics\StoreMetricDailyRollupAction;
use Capell\Core\Contracts\Metrics\MetricScopeAuthorizer;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricReadContextData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricReaderType;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Carbon\CarbonImmutable;

it('reads inclusive daily bounds when callers provide times', function (): void {
    $definition = readSeriesDefinition();
    $scope = MetricScopeData::global('UTC');
    $run = resolve(StoreMetricCollectionRunAction::class)->execute(
        day: '2026-07-20',
        ownerPackage: $definition->identity->ownerPackage,
        collectorKey: $definition->identity->collectorKey,
        definitionHash: $definition->semanticHash(),
        status: MetricCollectionRunStatus::Started,
        startedAt: CarbonImmutable::parse('2026-07-21 00:05:00', 'UTC'),
    );
    resolve(StoreMetricDailyRollupAction::class)->execute(
        run: $run,
        definition: $definition,
        day: '2026-07-20',
        scope: $scope,
        state: MetricPointState::Present,
        value: MetricValueData::integer(12),
    );

    $series = resolve(ReadMetricSeriesAction::class)->execute(
        definition: $definition,
        from: CarbonImmutable::parse('2026-07-20 18:00:00', 'UTC'),
        to: CarbonImmutable::parse('2026-07-20 20:00:00', 'UTC'),
        context: readSeriesContext($scope),
        authorizer: allowMetricReads(),
    );

    expect($series->points)->toHaveCount(1)
        ->and($series->latest())->toBe(12.0);
});

it('rejects a scope type that does not match the definition', function (): void {
    resolve(ReadMetricSeriesAction::class)->execute(
        definition: readSeriesDefinition(),
        from: CarbonImmutable::parse('2026-07-20', 'UTC'),
        to: CarbonImmutable::parse('2026-07-21', 'UTC'),
        context: readSeriesContext(MetricScopeData::site(
            '2ec4c4f6-8d8e-4f50-9ce6-30eb0d0e81cb',
            'UTC',
        )),
        authorizer: allowMetricReads(),
    );
})->throws(InvalidArgumentException::class, 'Metric series scope must match its definition.');

function readSeriesDefinition(): MetricDefinitionData
{
    return new MetricDefinitionData(
        identity: new MetricIdentityData('capell-app/test', 'series', 'requests'),
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

function readSeriesContext(MetricScopeData $scope): MetricReadContextData
{
    return new MetricReadContextData(
        MetricReaderType::System,
        $scope,
        CarbonImmutable::parse('2026-07-21', 'UTC'),
        'metrics test',
        'test:metrics',
    );
}

function allowMetricReads(): MetricScopeAuthorizer
{
    return new class implements MetricScopeAuthorizer
    {
        public function canRead(MetricDefinitionData $definition, MetricReadContextData $context): bool
        {
            return true;
        }
    };
}
