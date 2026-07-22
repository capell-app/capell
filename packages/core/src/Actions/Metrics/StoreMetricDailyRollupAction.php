<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Models\MetricCollectionRun;
use Capell\Core\Models\MetricDailyRollup;
use InvalidArgumentException;

final class StoreMetricDailyRollupAction
{
    public function execute(
        MetricCollectionRun $run,
        MetricDefinitionData $definition,
        string $day,
        MetricScopeData $scope,
        MetricPointState $state,
        ?MetricValueData $value,
        ?int $siteId = null,
    ): MetricDailyRollup {
        if (! $run->exists || $run->status !== MetricCollectionRunStatus::Started
            || $run->day->toDateString() !== $day
            || $run->owner_package !== $definition->identity->ownerPackage
            || $run->collector_key !== $definition->identity->collectorKey) {
            throw new InvalidArgumentException('Metric rollup identity must match its collection run.');
        }

        if ($definition->scopeType !== $scope->type) {
            throw new InvalidArgumentException('Metric rollup scope must match its definition.');
        }

        $this->assertStateValue($state, $value);

        if ($value !== null) {
            $value->assertMatches($definition->representation);
        }

        $storedValue = $value?->integer ?? $value?->decimal ?? $value?->minorUnits;

        return MetricDailyRollup::query()->create([
            'metric_collection_run_id' => $run->getKey(),
            'day' => $day,
            'owner_package' => $definition->identity->ownerPackage,
            'collector_key' => $definition->identity->collectorKey,
            'metric_key' => $definition->identity->metricKey,
            'definition_hash' => $definition->semanticHash(),
            'scope_key' => $scope->key(),
            'scope_type' => $scope->type,
            'site_id' => $siteId,
            'site_uuid' => $scope->siteUuid,
            'language' => $scope->language,
            'timezone' => $scope->timezone,
            'day_starts_at' => $scope->dayStartsAt,
            'unit' => $definition->representation->unit,
            'value_type' => $definition->representation->valueType,
            'value' => $storedValue === null ? null : (string) $storedValue,
            'scale' => $definition->representation->scale,
            'currency' => $definition->representation->currency ?? '',
            'point_state' => $state,
        ]);
    }

    private function assertStateValue(MetricPointState $state, ?MetricValueData $value): void
    {
        if (in_array($state, [MetricPointState::Missing, MetricPointState::Stale, MetricPointState::Unsupported], true)) {
            if ($value !== null) {
                throw new InvalidArgumentException('Non-value metric point states cannot persist a value.');
            }

            return;
        }

        if ($value === null) {
            throw new InvalidArgumentException('Present and zero metric point states require a value.');
        }

        $isZero = ($value->integer ?? $value->minorUnits) === 0
            || ($value->decimal !== null && preg_match('/\A0(?:\.0+)?\z/', $value->decimal) === 1);

        if (($state === MetricPointState::Zero) !== $isZero) {
            throw new InvalidArgumentException('Metric zero state must agree with its value.');
        }
    }
}
