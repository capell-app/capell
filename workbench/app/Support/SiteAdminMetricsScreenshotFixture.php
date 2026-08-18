<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Actions\Metrics\StoreMetricCollectionRunAction;
use Capell\Core\Actions\Metrics\StoreMetricDailyRollupAction;
use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Support\Metrics\MetricCollectorRegistry;
use Carbon\CarbonImmutable;

final class SiteAdminMetricsScreenshotFixture implements CollectsDailyMetrics
{
    private const string OwnerPackage = 'capell-app/site-stats';

    private const string CollectorKey = 'content_totals';

    public static function initialize(): void
    {
        $registry = resolve(MetricCollectorRegistry::class);
        $registry->register(self::class);

        $definitions = collect($registry->definitions())->keyBy(
            static fn (MetricDefinitionData $definition): string => $definition->identity->metricKey,
        );
        $scope = MetricScopeData::global('UTC');
        $values = [
            'content.pages_total' => [38, 40, 42, 44, 45, 47, 49],
            'content.sites_total' => [4, 4, 5, 5, 6, 6, 6],
            'content.active_sites_total' => [3, 3, 4, 4, 5, 5, 5],
            'content.active_domains_total' => [5, 5, 6, 6, 7, 7, 8],
        ];
        $today = CarbonImmutable::parse('2026-08-17', 'UTC');

        foreach ($values as $metricKey => $trend) {
            $definition = $definitions->get($metricKey);

            if (! $definition instanceof MetricDefinitionData) {
                continue;
            }

            foreach ($trend as $offset => $value) {
                $day = $today->subDays(count($trend) - $offset - 1)->toDateString();
                $run = resolve(StoreMetricCollectionRunAction::class)->execute(
                    day: $day,
                    ownerPackage: self::OwnerPackage,
                    collectorKey: self::CollectorKey,
                    definitionHash: $definition->semanticHash(),
                    status: MetricCollectionRunStatus::Started,
                    startedAt: CarbonImmutable::parse($day . ' 00:05:00', 'UTC'),
                );

                resolve(StoreMetricDailyRollupAction::class)->execute(
                    run: $run,
                    definition: $definition,
                    day: $day,
                    scope: $scope,
                    state: MetricPointState::Present,
                    value: MetricValueData::integer($value),
                );
            }
        }
    }

    /** @return list<MetricDefinitionData> */
    public function definitions(): array
    {
        return [
            $this->definition('content.pages_total', 'Pages', 'Published and draft pages.'),
            $this->definition('content.sites_total', 'Sites', 'Sites managed by this installation.'),
            $this->definition('content.active_sites_total', 'Active sites', 'Sites currently serving content.'),
            $this->definition('content.active_domains_total', 'Active domains', 'Domains serving active sites.'),
        ];
    }

    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        return new MetricCollectionResultData(
            MetricCollectionStatus::Unsupported,
            $day,
            [],
            [],
            null,
            null,
            'Screenshot fixture does not collect live metrics.',
        );
    }

    private function definition(string $metricKey, string $label, string $description): MetricDefinitionData
    {
        return new MetricDefinitionData(
            identity: new MetricIdentityData(self::OwnerPackage, self::CollectorKey, $metricKey),
            representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
            scopeType: MetricScopeType::Global,
            semantics: new MetricSemanticsData(
                MetricSemantic::Gauge,
                MetricAggregation::Last,
                MetricGapPolicy::Missing,
                MetricBackfillPolicy::Supported,
            ),
            governance: new MetricGovernanceData(
                MetricSource::Database,
                'site-stats',
                MetricSensitivity::Internal,
                MetricVisibility::SiteAdmin,
            ),
            status: MetricDefinitionStatus::Active,
            labels: ['en' => $label],
            descriptions: ['en' => $description],
        );
    }
}
