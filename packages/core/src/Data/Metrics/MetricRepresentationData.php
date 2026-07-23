<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricValueType;
use Capell\Core\Enums\MetricUnitEnum;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricRepresentationData extends Data
{
    public function __construct(
        public readonly MetricUnitEnum $unit,
        public readonly MetricValueType $valueType,
        public readonly ?int $scale = null,
        public readonly ?string $currency = null,
    ) {
        $expectedValueType = match ($this->unit) {
            MetricUnitEnum::Count, MetricUnitEnum::Milliseconds, MetricUnitEnum::Bytes => MetricValueType::Integer,
            MetricUnitEnum::Decimal, MetricUnitEnum::Percentage => MetricValueType::Decimal,
            MetricUnitEnum::MinorCurrencyUnit => MetricValueType::MinorCurrencyUnit,
        };

        throw_if($this->valueType !== $expectedValueType, InvalidArgumentException::class, 'Metric unit and value type are incompatible.');

        throw_if($this->valueType === MetricValueType::Integer && ($this->scale !== null || $this->currency !== null), InvalidArgumentException::class, 'Integer metric representations cannot define scale or currency.');

        throw_if($this->valueType === MetricValueType::Decimal
            && ($this->scale === null || $this->scale < 0 || $this->scale > 18 || $this->currency !== null), InvalidArgumentException::class, 'Decimal metric representations require a fixed scale without currency.');

        throw_if($this->valueType === MetricValueType::MinorCurrencyUnit
            && ($this->scale === null || $this->scale < 0 || $this->scale > 18 || preg_match('/\A[A-Z]{3}\z/', $this->currency ?? '') !== 1), InvalidArgumentException::class, 'Currency metric representations require fixed uppercase currency and scale.');
    }

    /** @return array{unit: string, value_type: string, scale: int|null, currency: string|null} */
    #[Override]
    public function toArray(): array
    {
        return [
            'unit' => $this->unit->value,
            'value_type' => $this->valueType->value,
            'scale' => $this->scale,
            'currency' => $this->currency,
        ];
    }
}
