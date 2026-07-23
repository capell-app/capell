<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricPointState;
use Capell\Core\Enums\Metrics\MetricValueType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricPointData extends Data
{
    public function __construct(
        public readonly CarbonImmutable $day,
        public readonly MetricPointState $state,
        public readonly ?MetricValueData $value,
    ) {
        $hasValue = $this->value instanceof MetricValueData;
        $expectsValue = in_array($this->state, [MetricPointState::Present, MetricPointState::Zero], true);

        throw_if($hasValue !== $expectsValue, InvalidArgumentException::class, 'Metric point state and value must agree.');

        if (! $hasValue) {
            return;
        }

        $isZero = ($this->value->integer ?? $this->value->minorUnits) === 0
            || ($this->value->decimal !== null && preg_match('/\A0(?:\.0+)?\z/', $this->value->decimal) === 1);

        throw_if(($this->state === MetricPointState::Zero) !== $isZero, InvalidArgumentException::class, 'Metric zero state must agree with its value.');
    }

    public function numericValue(): ?float
    {
        $value = $this->value;

        if (! $value instanceof MetricValueData) {
            return null;
        }

        return match ($value->type) {
            MetricValueType::Integer => (float) $value->integer,
            MetricValueType::Decimal => (float) $value->decimal,
            MetricValueType::MinorCurrencyUnit => (float) $value->minorUnits,
        };
    }
}
