<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\BlueprintSubjectEnum;
use Spatie\LaravelData\Data;

/**
 * Livewire-safe descriptor for BlueprintSubjectEnum values.
 *
 * BlueprintSubjectEnum implements HasLabel with a getLabel() method. When the label is
 * backed by a translation closure, Livewire's dehydration serialises the
 * closure as `{}`, crashing with "Property type not supported in Livewire".
 *
 * Pass this Data object across the Livewire boundary instead of the raw enum.
 * Labels are resolved eagerly at construction time, before dehydration occurs.
 */
class BlueprintSubjectDescriptorData extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $key,
        public readonly string $model,
    ) {}

    public static function fromEnum(BlueprintSubjectEnum $typeEnum): self
    {
        return new self(
            value: $typeEnum->value,
            label: $typeEnum->getLabel(),
            key: $typeEnum->getKey(),
            model: $typeEnum->getModel(),
        );
    }

    public function toEnum(): BlueprintSubjectEnum
    {
        return BlueprintSubjectEnum::from($this->value);
    }
}
