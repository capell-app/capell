<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\TermPropertyValue;
use Spatie\LaravelData\Data;

/**
 * A typed property value at the application boundary — the shape write
 * actions receive and the shape read actions hand back, independent of the
 * underlying multi-column storage row.
 */
final class PropertyValueData extends Data
{
    public function __construct(
        public string $propertyKey,
        public PropertyType $type,
        public mixed $value,
        public ?string $currency = null,
        public ?string $unit = null,
        public int $position = 0,
        public ?int $translationId = null,
    ) {}

    public static function fromPageValue(PagePropertyValue $value, string $propertyKey, PropertyType $type): self
    {
        return new self(
            propertyKey: $propertyKey,
            type: $type,
            value: self::extractValue($value->value_text, $value->value_number, $value->value_boolean, $value->value_datetime, $type),
            currency: $value->currency,
            unit: $value->unit,
            position: $value->position,
            translationId: $value->translation_id,
        );
    }

    public static function fromTermValue(TermPropertyValue $value, string $propertyKey, PropertyType $type): self
    {
        return new self(
            propertyKey: $propertyKey,
            type: $type,
            value: self::extractValue($value->value_text, $value->value_number, $value->value_boolean, $value->value_datetime, $type),
            currency: $value->currency,
            unit: $value->unit,
            position: $value->position,
        );
    }

    /**
     * Map this value onto the multi-column storage shape shared by
     * `page_property_values` and `term_property_values`.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        $isPlainText = ! $this->type->isNumeric()
            && ! $this->type->isBoolean()
            && ! $this->type->isTemporal()
            && ! $this->type->isReference();

        return [
            'value_text' => $isPlainText && is_scalar($this->value) ? (string) $this->value : null,
            'value_number' => $this->type->isNumeric() ? (is_numeric($this->value) ? (string) $this->value : null) : null,
            'value_boolean' => $this->type->isBoolean() ? (bool) $this->value : null,
            'value_datetime' => $this->type->isTemporal() ? $this->value : null,
            'currency' => $this->currency,
            'unit' => $this->unit,
            'position' => $this->position,
        ];
    }

    private static function extractValue(
        ?string $valueText,
        int|float|string|null $valueNumber,
        ?bool $valueBoolean,
        mixed $valueDatetime,
        PropertyType $type,
    ): mixed {
        return match (true) {
            $type->isNumeric() => $valueNumber !== null ? (float) $valueNumber : null,
            $type->isBoolean() => $valueBoolean,
            $type->isTemporal() => $valueDatetime,
            default => $valueText,
        };
    }
}
