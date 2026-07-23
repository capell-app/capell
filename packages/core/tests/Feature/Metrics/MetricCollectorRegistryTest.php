<?php

declare(strict_types=1);

use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Contracts\Metrics\MetricScopeAuthorizer;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricReadContextData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricReaderType;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Support\Metrics\DenyMetricScopeAuthorizer;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Carbon\CarbonImmutable;

it('registers an owned collector idempotently and resolves its definitions', function (): void {
    $registry = resolve(MetricCollectorRegistry::class);

    $surface = resolve(PackageSurfaceRegistrar::class);
    $surface->metricCollector(RegistryTestMetricCollector::class);

    $registry->register(RegistryTestMetricCollector::class);

    expect($surface)->toBeInstanceOf(PackageSurfaceRegistrar::class)
        ->and($registry->collectors())->toHaveCount(1)
        ->and($registry->definitions()->keys()->all())->toBe([
            'capell-app/test:registry:requests',
        ]);
});

it('rejects classes outside the collector contract', function (): void {
    resolve(MetricCollectorRegistry::class)->register(stdClass::class);
})->throws(InvalidArgumentException::class);

it('does not let another class replace an owned collector identity', function (): void {
    $registry = resolve(MetricCollectorRegistry::class);
    $registry->register(RegistryTestMetricCollector::class);

    $registry->register(ConflictingRegistryTestMetricCollector::class);
})->throws(InvalidArgumentException::class, 'already owned');

it('binds a fail-closed metric scope authorizer by default', function (): void {
    $definition = registryTestDefinition();
    $scope = MetricScopeData::global('UTC');
    $context = new MetricReadContextData(
        MetricReaderType::System,
        $scope,
        CarbonImmutable::now('UTC'),
        'registry test',
        'operator:1',
    );
    $authorizer = resolve(MetricScopeAuthorizer::class);

    expect($authorizer)->toBeInstanceOf(DenyMetricScopeAuthorizer::class)
        ->and($authorizer->canRead($definition, $context))->toBeFalse();
});

final class RegistryTestMetricCollector implements CollectsDailyMetrics
{
    public function definitions(): array
    {
        return [registryTestDefinition()];
    }

    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        throw new LogicException('Collection is not used by the registry test.');
    }
}

final class ConflictingRegistryTestMetricCollector implements CollectsDailyMetrics
{
    public function definitions(): array
    {
        return [registryTestDefinition()];
    }

    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        throw new LogicException('Collection is not used by the registry test.');
    }
}

function registryTestDefinition(): MetricDefinitionData
{
    return new MetricDefinitionData(
        identity: new MetricIdentityData('capell-app/test', 'registry', 'requests'),
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
