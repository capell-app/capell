<?php

declare(strict_types=1);

namespace Capell\Core\Support\Metrics;

use Capell\Core\Data\Metrics\MetricDefinitionData;
use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricDefinitionStatus;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<MetricDefinitionData> */
final class MetricEventRegistry extends AbstractKeyedRegistry
{
    public function register(MetricDefinitionData $definition): self
    {
        throw_if($definition->status !== MetricDefinitionStatus::Active
            || $definition->semantics->semantic !== MetricSemantic::Event
            || $definition->semantics->aggregation !== MetricAggregation::Sum
            || $definition->representation->unit !== MetricUnitEnum::Count
            || $definition->representation->valueType !== MetricValueType::Integer, InvalidArgumentException::class, 'Event metric registry accepts only active summed integer count events.');

        $metricKey = $definition->identity->metricKey;
        $existing = $this->getItem($metricKey);

        if ($existing !== null && $existing->semanticHash() !== $definition->semanticHash()) {
            throw new InvalidArgumentException(sprintf('Metric key [%s] is already registered with different semantics.', $metricKey));
        }

        $this->setItem($metricKey, $definition);

        return $this;
    }

    public function definition(string $metric): MetricDefinitionData
    {
        return $this->getItem($metric)
            ?? throw new InvalidArgumentException(sprintf('Metric [%s] is not registered.', $metric));
    }

    /** @return array<string, MetricDefinitionData> */
    public function definitions(): array
    {
        return $this->allItems();
    }
}
