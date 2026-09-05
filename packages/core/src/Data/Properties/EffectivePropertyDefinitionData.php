<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PropertyDefinition;
use Spatie\LaravelData\Data;

/**
 * A property definition as it applies to one specific blueprint attachment —
 * the base {@see PropertyDefinition} merged with that
 * blueprint's overrides, clamped to the definition's `locked` floor. This is
 * the ONLY shape write/read/curation code should consult; nothing re-derives
 * effective config from the raw override JSON independently.
 */
final class EffectivePropertyDefinitionData extends Data
{
    /**
     * @param  array<string, mixed>|null  $unitConfig
     */
    public function __construct(
        public int $definitionId,
        public int $propertySetId,
        public string $propertySetKey,
        public string $key,
        public PropertyType $type,
        public ?string $semantic,
        public PropertyRequirement $requirement,
        public bool $agentVisible,
        public bool $localised,
        public bool $multiple,
        public bool $locked,
        public ?string $description,
        public ?array $unitConfig,
        public int $position,
        public ?string $promotedField = null,
    ) {}

    public function qualifiedKey(): string
    {
        return $this->propertySetKey . '.' . $this->key;
    }
}
