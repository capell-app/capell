<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricScopeData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Models\MetricEvent;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class StoreMetricEventAction
{
    public function execute(
        MetricDefinitionData $definition,
        int $value,
        int $weight,
        MetricScopeData $scope,
        CarbonImmutable $occurredAt,
    ): MetricEvent {
        if ($definition->status !== MetricDefinitionStatus::Active
            || $definition->semantics->semantic !== MetricSemantic::Event
            || $definition->semantics->aggregation !== MetricAggregation::Sum
            || $definition->representation->unit !== MetricUnitEnum::Count
            || $definition->representation->valueType !== MetricValueType::Integer) {
            throw new InvalidArgumentException('Metric events require an active summed integer count definition.');
        }

        if ($definition->scopeType !== $scope->type) {
            throw new InvalidArgumentException('Metric event scope must match its definition.');
        }

        if ($value < 1 || $weight < 1) {
            throw new InvalidArgumentException('Metric event value and weight must be positive integers.');
        }

        if ($occurredAt->utcOffset() !== 0) {
            throw new InvalidArgumentException('Metric event occurrence must be UTC.');
        }

        $siteId = $scope->siteUuid === null
            ? null
            : Site::query()->where('uuid', $scope->siteUuid)->value('id');

        return MetricEvent::query()->create([
            'occurred_at' => $occurredAt,
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
            'value' => $value,
            'weight' => $weight,
        ]);
    }
}
