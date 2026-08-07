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
use Capell\Core\Enums\ActivityBucketSubjectEnum;
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
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use RuntimeException;

final class ActivityBucketsDailyMetricsCollector implements CollectsDailyMetrics
{
    public const string OWNER_PACKAGE = 'capell-app/core';

    public const string COLLECTOR_KEY = 'activity';

    public const string PAGE_VIEWS_METRIC = 'page_views';

    public const string SEARCHES_METRIC = 'searches';

    /** @return list<MetricDefinitionData> */
    public function definitions(): array
    {
        return [
            $this->definition(self::PAGE_VIEWS_METRIC, 'Page views observed'),
            $this->definition(self::SEARCHES_METRIC, 'Searches observed'),
        ];
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

        $definitions = collect($this->definitions())->keyBy(
            static fn (MetricDefinitionData $definition): string => $definition->identity->metricKey,
        );
        $samples = [];
        $checksum = [];
        $maxBucket = null;

        foreach ($scopes as $scope) {
            $start = CarbonImmutable::parse($day . ' ' . $scope->dayStartsAt, $scope->timezone);
            $end = $start->addDay();
            $siteId = $this->siteId($scope);

            foreach ([
                self::PAGE_VIEWS_METRIC => ActivityBucketSubjectEnum::PageView,
                self::SEARCHES_METRIC => ActivityBucketSubjectEnum::SearchTerm,
            ] as $metricKey => $subjectType) {
                $count = (int) ActivityBucket::query()
                    ->where('site_id', $siteId)
                    ->where('language', $scope->language)
                    ->where('subject_type', $subjectType)
                    ->where('bucket_started_at', '>=', $start->utc())
                    ->where('bucket_started_at', '<', $end->utc())
                    ->sum('count');
                $definition = $definitions->get($metricKey);

                throw_unless($definition instanceof MetricDefinitionData, RuntimeException::class, 'Activity metric definition is missing.');

                $samples[] = new MetricSampleData(
                    identity: $definition->identity,
                    definitionHash: $definition->semanticHash(),
                    day: $day,
                    scope: $scope,
                    representation: $definition->representation,
                    value: MetricValueData::integer($count),
                );
                $checksum[] = [$metricKey, $scope->key(), $count];
            }

            $bucket = ActivityBucket::query()
                ->where('site_id', $siteId)
                ->where('language', $scope->language)
                ->where('bucket_started_at', '>=', $start->utc())
                ->where('bucket_started_at', '<', $end->utc())
                ->max('bucket_started_at');

            if ($bucket !== null) {
                $maxBucket = max((string) $maxBucket, (string) $bucket);
            }
        }

        return new MetricCollectionResultData(
            status: MetricCollectionStatus::Complete,
            day: $day,
            coveredScopes: $scopes,
            samples: $samples,
            sourceWatermark: 'activity-bucket:' . ($maxBucket ?? 'empty'),
            sourceChecksum: hash('sha256', json_encode($checksum, JSON_THROW_ON_ERROR)),
            reason: null,
        );
    }

    private function definition(string $metricKey, string $label): MetricDefinitionData
    {
        return new MetricDefinitionData(
            identity: new MetricIdentityData(self::OWNER_PACKAGE, self::COLLECTOR_KEY, $metricKey),
            representation: new MetricRepresentationData(MetricUnitEnum::Count, MetricValueType::Integer),
            scopeType: MetricScopeType::SiteLanguage,
            semantics: new MetricSemanticsData(
                MetricSemantic::Counter,
                MetricAggregation::Sum,
                MetricGapPolicy::Missing,
                MetricBackfillPolicy::CurrentDayOnly,
            ),
            governance: new MetricGovernanceData(
                MetricSource::Database,
                'activity_buckets',
                MetricSensitivity::Internal,
                MetricVisibility::SiteAdmin,
            ),
            labels: ['en' => $label],
        );
    }

    private function siteId(MetricScopeData $scope): int
    {
        $siteId = Site::query()
            ->where('uuid', $scope->siteUuid)
            ->value('id');

        throw_unless(is_int($siteId), RuntimeException::class, 'Activity metric site scope could not be resolved.');

        return $siteId;
    }
}
