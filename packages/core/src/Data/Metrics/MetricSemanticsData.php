<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricAggregation;
use Capell\Core\Enums\Metrics\MetricBackfillPolicy;
use Capell\Core\Enums\Metrics\MetricGapPolicy;
use Capell\Core\Enums\Metrics\MetricSemantic;
use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricSemanticsData extends Data
{
    public function __construct(
        public readonly MetricSemantic $semantic,
        public readonly MetricAggregation $aggregation,
        public readonly MetricGapPolicy $gapPolicy,
        public readonly MetricBackfillPolicy $backfillPolicy,
    ) {}

    public function assertCompatibleWith(MetricRepresentationData $representation): void
    {
        throw_if($this->aggregation === MetricAggregation::Average && $representation->valueType !== MetricValueType::Decimal, InvalidArgumentException::class, 'Average aggregation requires a fixed-decimal representation.');

        throw_if($this->semantic === MetricSemantic::Event
            && ($representation->unit !== MetricUnitEnum::Count
                || $representation->valueType !== MetricValueType::Integer
                || $this->aggregation !== MetricAggregation::Sum), InvalidArgumentException::class, 'Event metrics must be summed integer counts.');

        throw_if($this->semantic === MetricSemantic::Ratio
            && ($representation->unit !== MetricUnitEnum::Percentage
                || $representation->valueType !== MetricValueType::Decimal
                || $this->aggregation !== MetricAggregation::Average), InvalidArgumentException::class, 'Ratio metrics must be averaged fixed-decimal percentages.');

        throw_if($this->semantic === MetricSemantic::Counter && $this->aggregation !== MetricAggregation::Sum, InvalidArgumentException::class, 'Counter metrics must use sum aggregation.');

        throw_if($this->semantic === MetricSemantic::Gauge && $this->aggregation === MetricAggregation::Sum, InvalidArgumentException::class, 'Gauge metrics cannot use sum aggregation.');
    }

    /** @return array{semantic: string, aggregation: string, gap_policy: string, backfill_policy: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'semantic' => $this->semantic->value,
            'aggregation' => $this->aggregation->value,
            'gap_policy' => $this->gapPolicy->value,
            'backfill_policy' => $this->backfillPolicy->value,
        ];
    }
}
