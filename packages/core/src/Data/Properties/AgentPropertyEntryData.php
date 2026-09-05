<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Capell\Core\Enums\PropertyType;
use Spatie\LaravelData\Data;

/**
 * One resolved, agent-visible property value ready for the public read layer
 * (Phase 2). Carries enough to render either a schema.org JSON-LD value or a
 * plain API response field.
 */
final class AgentPropertyEntryData extends Data
{
    public function __construct(
        public string $qualifiedKey,
        public ?string $semantic,
        public PropertyType $type,
        public mixed $value,
        public ?string $currency = null,
        public ?string $unit = null,
        public int $position = 0,
    ) {}
}
