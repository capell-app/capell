<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Contracts\Metrics\MetricScopeAuthorizer;
use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Data\Metrics\MetricPointData;
use Capell\Core\Data\Metrics\MetricReadContextData;
use Capell\Core\Data\Metrics\MetricSeriesData;
use Capell\Core\Data\Metrics\MetricValueData;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Models\MetricDailyRollup;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final class ReadMetricSeriesAction
{
    public function execute(
        MetricDefinitionData $definition,
        CarbonImmutable $from,
        CarbonImmutable $to,
        MetricReadContextData $context,
        MetricScopeAuthorizer $authorizer,
    ): MetricSeriesData {
        throw_if($from->isAfter($to), InvalidArgumentException::class, 'Metric series start must not follow its end.');
        throw_if($definition->scopeType !== $context->scope->type, InvalidArgumentException::class, 'Metric series scope must match its definition.');

        if (! $authorizer->canRead($definition, $context)) {
            throw new AuthorizationException('Metric series access is not authorized.');
        }

        $identity = $definition->identity;

        $rollups = MetricDailyRollup::query()
            ->where('owner_package', $identity->ownerPackage)
            ->where('collector_key', $identity->collectorKey)
            ->where('metric_key', $identity->metricKey)
            ->where('scope_key', $context->scope->key())
            ->whereDate('day', '>=', $from->toDateString())
            ->whereDate('day', '<=', $to->toDateString())
            ->orderBy('day')
            ->get();

        $points = array_values($rollups->map(function (MetricDailyRollup $rollup) use ($definition): MetricPointData {
            throw_if($rollup->definition_hash !== $definition->semanticHash(), InvalidArgumentException::class, 'Metric series contains a definition that does not match the requested metric.');

            return new MetricPointData(
                day: $rollup->day,
                state: $rollup->point_state,
                value: $this->value($rollup),
            );
        })->all());

        return new MetricSeriesData(
            identity: $identity,
            representation: $definition->representation,
            scope: $context->scope,
            points: $points,
        );
    }

    private function value(MetricDailyRollup $rollup): ?MetricValueData
    {
        if ($rollup->value === null) {
            return null;
        }

        return match ($rollup->value_type) {
            MetricValueType::Integer => MetricValueData::integer((int) $rollup->value),
            MetricValueType::Decimal => MetricValueData::decimal($rollup->value, $rollup->scale ?? 0),
            MetricValueType::MinorCurrencyUnit => MetricValueData::money(
                (int) $rollup->value,
                $rollup->currency,
                $rollup->scale ?? 0,
            ),
        };
    }
}
