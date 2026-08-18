<?php

declare(strict_types=1);

namespace Capell\Core\Support\Metrics;

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
use Capell\Core\Enums\Metrics\MetricCollectionStatus;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricScopeType;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricSensitivity;
use Capell\Core\Enums\Metrics\MetricSource;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\Metrics\MetricVisibility;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Models\ActivityVisitor;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Unique visitors per scope-day.
 *
 * This is deliberately a sibling of ActivityBucketsDailyMetricsCollector rather
 * than another metric on it: that collector's watermark, checksum and prune
 * guard are all derived from activity_buckets, and sourcing a metric from a
 * different table would corrupt those semantics.
 *
 * The daily values must never be summed to answer a multi-day question. A
 * visitor returning on three days contributes to three daily values but is one
 * visitor; multi-day totals are counted distinct from the visitor-day rows.
 */
final class ActivityVisitorsDailyMetricsCollector implements CollectsDailyMetrics
{
    public const string OWNER_PACKAGE = 'capell-app/core';

    public const string COLLECTOR_KEY = 'activity-visitors';

    public const string UNIQUE_VISITORS_METRIC = 'unique_visitors';

    /** @return list<MetricDefinitionData> */
    public function definitions(): array
    {
        return [$this->definition()];
    }

    /**
     * @param  list<MetricScopeData>  $scopes
     */
    public function collect(string $day, array $scopes): MetricCollectionResultData
    {
        if ($scopes === []) {
            return new MetricCollectionResultData(
                status: MetricCollectionStatus::Unsupported,
                day: $day,
                coveredScopes: [],
                samples: [],
                sourceWatermark: null,
                sourceChecksum: null,
                reason: 'No site-language scopes were supplied.',
            );
        }

        $definition = $this->definition();
        $samples = [];
        $checksum = [];
        $maxSeenAt = null;

        foreach ($scopes as $scope) {
            $start = CarbonImmutable::parse($day . ' ' . $scope->dayStartsAt, $scope->timezone);
            $end = $start->addDay();
            $siteId = $this->siteId($scope);
            $window = ActivityVisitor::query()
                ->where('site_id', $siteId)
                ->where('language', $scope->language)
                ->where('first_seen_at', '>=', $start->utc())
                ->where('first_seen_at', '<', $end->utc());
            $count = (int) (clone $window)->distinct()->count('visitor_hash');

            $samples[] = new MetricSampleData(
                identity: $definition->identity,
                definitionHash: $definition->semanticHash(),
                day: $day,
                scope: $scope,
                representation: $definition->representation,
                value: MetricValueData::integer($count),
            );
            $checksum[] = [self::UNIQUE_VISITORS_METRIC, $scope->key(), $count];

            $seenAt = (clone $window)->max('first_seen_at');

            if ($seenAt !== null) {
                $maxSeenAt = max((string) $maxSeenAt, (string) $seenAt);
            }
        }

        return new MetricCollectionResultData(
            status: MetricCollectionStatus::Complete,
            day: $day,
            coveredScopes: $scopes,
            samples: $samples,
            sourceWatermark: 'activity-visitor:' . ($maxSeenAt ?? 'empty'),
            sourceChecksum: hash('sha256', json_encode($checksum, JSON_THROW_ON_ERROR)),
            reason: null,
        );
    }

    private function definition(): MetricDefinitionData
    {
        return new MetricDefinitionData(
            identity: new MetricIdentityData(self::OWNER_PACKAGE, self::COLLECTOR_KEY, self::UNIQUE_VISITORS_METRIC),
            representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
            scopeType: MetricScopeType::SiteLanguage,
            semantics: new MetricSemanticsData(
                // Gauge, not Counter: the framework forbids summing a gauge,
                // which is exactly the guarantee unique visitors need.
                MetricSemantic::Gauge,
                MetricAggregation::Maximum,
                MetricGapPolicy::Missing,
                MetricBackfillPolicy::CurrentDayOnly,
            ),
            governance: new MetricGovernanceData(
                MetricSource::Database,
                'activity_visitors',
                MetricSensitivity::Internal,
                MetricVisibility::SiteAdmin,
            ),
            labels: ['en' => 'Unique visitors observed'],
        );
    }

    private function siteId(MetricScopeData $scope): int
    {
        $siteId = Site::query()
            ->where('uuid', $scope->siteUuid)
            ->value('id');

        throw_unless(is_int($siteId), RuntimeException::class, 'Activity visitor metric site scope could not be resolved.');

        return $siteId;
    }
}
