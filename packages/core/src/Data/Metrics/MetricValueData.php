<?php

declare(strict_types=1);

namespace Capell\Core\Data\Metrics;

use Capell\Core\Enums\Metrics\MetricValueType;
use InvalidArgumentException;
use Override;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class MetricValueData extends Data
{
    public function __construct(
        public readonly MetricValueType $type,
        public readonly ?int $integer = null,
        public readonly ?string $decimal = null,
        public readonly ?int $minorUnits = null,
        public readonly ?int $scale = null,
        public readonly ?string $currency = null,
    ) {
        $populatedValues = count(array_filter(
            [$this->integer, $this->decimal, $this->minorUnits],
            static fn (int|string|null $value): bool => $value !== null,
        ));

        throw_if($populatedValues !== 1
            || ($this->type === MetricValueType::Integer && $this->integer === null)
            || ($this->type === MetricValueType::Decimal && $this->decimal === null)
            || ($this->type === MetricValueType::MinorCurrencyUnit && $this->minorUnits === null), InvalidArgumentException::class, 'Metric value payload does not match its type.');

        throw_if($this->type === MetricValueType::Integer && ($this->scale !== null || $this->currency !== null), InvalidArgumentException::class, 'Integer metric values cannot define scale or currency.');

        if ($this->type === MetricValueType::Decimal) {
            throw_if($this->scale === null || $this->scale < 0 || $this->scale > 18 || preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', $this->decimal ?? '') !== 1, InvalidArgumentException::class, 'Decimal metric values require a canonical decimal string and scale.');
            $decimalParts = explode('.', $this->decimal);
            $fraction = isset($decimalParts[1]) ? strlen($decimalParts[1]) : 0;
            throw_if($fraction !== $this->scale
                || preg_match('/\A-0(?:\.0+)?\z/', $this->decimal) === 1
                || $this->currency !== null, InvalidArgumentException::class, 'Decimal metric scale must match its fixed precision and cannot define currency.');
        }

        throw_if($this->type === MetricValueType::MinorCurrencyUnit
            && ($this->scale === null || $this->scale < 0 || $this->scale > 18 || preg_match('/\A[A-Z]{3}\z/', $this->currency ?? '') !== 1), InvalidArgumentException::class, 'Minor-unit metric values require an uppercase ISO currency and scale.');
    }

    public static function integer(int $value): self
    {
        return new self(MetricValueType::Integer, integer: $value);
    }

    public static function decimal(string $value, int $scale): self
    {
        return new self(MetricValueType::Decimal, decimal: $value, scale: $scale);
    }

    public static function money(int $minorUnits, string $currency, int $scale): self
    {
        return new self(MetricValueType::MinorCurrencyUnit, minorUnits: $minorUnits, scale: $scale, currency: $currency);
    }

    public function assertMatches(MetricRepresentationData $representation): void
    {
        throw_if($this->type !== $representation->valueType
            || $this->scale !== $representation->scale
            || $this->currency !== $representation->currency, InvalidArgumentException::class, 'Metric sample value does not match its definition representation.');
    }

    /**
     * @return array{type: string, integer: int|null, decimal: string|null, minor_units: int|null, scale: int|null, currency: string|null}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'integer' => $this->integer,
            'decimal' => $this->decimal,
            'minor_units' => $this->minorUnits,
            'scale' => $this->scale,
            'currency' => $this->currency,
        ];
    }
}
