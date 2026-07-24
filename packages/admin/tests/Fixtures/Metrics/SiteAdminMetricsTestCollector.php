<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Metrics;

use Capell\Core\Contracts\Metrics\CollectsDailyMetrics;
use Capell\Core\Data\Metrics\MetricCollectionResultData;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricGovernanceData;
use Capell\Core\Data\Metrics\MetricIdentityData;
use Capell\Core\Data\Metrics\MetricRepresentationData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricSemanticsData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;

final class SiteAdminMetricsTestCollector implements CollectsDailyMetrics
{
    /** @return list<MetricDefinitionData> */
    public function definitions(): array
    {
        return [
            $this->definition('visible-count', 'Visible count', MetricVisibility::SiteAdmin, MetricScopeType::Global),
            $this->definition('visible-empty', 'Visible empty', MetricVisibility::SiteAdmin, MetricScopeType::Global),
            $this->definition('visible-percentage', 'Visible percentage', MetricVisibility::SiteAdmin, MetricScopeType::Global),
            $this->definition('operations-only', 'Operations only', MetricVisibility::PlatformOps, MetricScopeType::Global),
            $this->definition('site-scoped', 'Site scoped', MetricVisibility::SiteAdmin, MetricScopeType::Site),
            $this->definition(
                'inactive',
                'Inactive',
                MetricVisibility::SiteAdmin,
                MetricScopeType::Global,
                MetricDefinitionStatus::Tombstoned,
            ),
        ];
    }

    /** @param list<MetricScopeData> $scopes */
    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        return new MetricCollectionResultData(
            MetricCollectionStatus::Unsupported,
            $day,
            [],
            [],
            null,
            null,
            'Test collector does not collect.',
        );
    }

    private function definition(
        string $metric,
        string $label,
        MetricVisibility $visibility,
        MetricScopeType $scope,
        MetricDefinitionStatus $status = MetricDefinitionStatus::Active,
    ): MetricDefinitionData {
        return new MetricDefinitionData(
            identity: new MetricIdentityData('capell-app/admin-tests', 'site-admin-dashboard', $metric),
            representation: $metric === 'visible-percentage'
                ? new MetricRepresentationData(MetricUnitEnum::Percentage, MetricValueType::Decimal, scale: 1)
                : new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
            scopeType: $scope,
            semantics: new MetricSemanticsData(
                MetricSemantic::Gauge,
                MetricAggregation::Last,
                MetricGapPolicy::Missing,
                MetricBackfillPolicy::Supported,
            ),
            governance: new MetricGovernanceData(
                MetricSource::Database,
                'admin-tests',
                MetricSensitivity::Internal,
                $visibility,
            ),
            status: $status,
            labels: ['en' => $label],
            descriptions: ['en' => $label . ' description'],
        );
    }
}
