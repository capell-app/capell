<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Capell\Core\Actions\Properties\BuildPropertyValidationRulesAction;
use Capell\Core\Casts\PropertyConstraintsCast;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PropertyDefinition;
use Spatie\LaravelData\Data;

/**
 * The declarative validation rules a {@see PropertyDefinition} carries, stored
 * as versioned JSON in `property_definitions.constraints`
 * ({@see PropertyConstraintsCast}). {@see BuildPropertyValidationRulesAction}
 * turns them into the one rule set every write path (admin editor, agent API,
 * SiteSpec import) enforces.
 *
 * Storage keys are snake_case and carry a `version` so the shape can evolve:
 * unknown keys from a newer version are ignored rather than failing reads.
 */
final class PropertyConstraintsData extends Data
{
    public const int CURRENT_VERSION = 1;

    /**
     * @param  list<string>  $allowedBlueprints  Blueprint keys an entry reference may point at.
     * @param  list<string>  $allowedTaxonomies  Taxonomy keys a term reference may point at.
     * @param  list<string>  $allowedMimeTypes  Media types (`image/png`, or `image/*`) a media value may have.
     */
    public function __construct(
        public int $version = self::CURRENT_VERSION,
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public bool $uniquePerSite = false,
        public array $allowedBlueprints = [],
        public array $allowedTaxonomies = [],
        public array $allowedMimeTypes = [],
        public ?int $minWidth = null,
        public ?int $maxWidth = null,
        public ?int $minHeight = null,
        public ?int $maxHeight = null,
    ) {}

    /**
     * Hydrate from the stored (snake_case) shape. Tolerant by design: a value
     * of the wrong shape is dropped, never thrown, so a malformed row can
     * never break reads of the rest of the blueprint.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function fromStored(array $stored): self
    {
        return new self(
            version: is_int($stored['version'] ?? null) ? $stored['version'] : self::CURRENT_VERSION,
            min: self::number($stored['min'] ?? null),
            max: self::number($stored['max'] ?? null),
            minLength: self::integer($stored['min_length'] ?? null),
            maxLength: self::integer($stored['max_length'] ?? null),
            pattern: is_string($stored['pattern'] ?? null) && $stored['pattern'] !== '' ? $stored['pattern'] : null,
            uniquePerSite: ($stored['unique_per_site'] ?? false) === true,
            allowedBlueprints: self::strings($stored['allowed_blueprints'] ?? []),
            allowedTaxonomies: self::strings($stored['allowed_taxonomies'] ?? []),
            allowedMimeTypes: self::strings($stored['allowed_mime_types'] ?? []),
            minWidth: self::integer($stored['min_width'] ?? null),
            maxWidth: self::integer($stored['max_width'] ?? null),
            minHeight: self::integer($stored['min_height'] ?? null),
            maxHeight: self::integer($stored['max_height'] ?? null),
        );
    }

    /**
     * The stored (snake_case) shape, omitting rules that are not set.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return ['version' => $this->version, ...array_filter([
            'min' => $this->min,
            'max' => $this->max,
            'min_length' => $this->minLength,
            'max_length' => $this->maxLength,
            'pattern' => $this->pattern,
            'unique_per_site' => $this->uniquePerSite ?: null,
            'allowed_blueprints' => $this->allowedBlueprints ?: null,
            'allowed_taxonomies' => $this->allowedTaxonomies ?: null,
            'allowed_mime_types' => $this->allowedMimeTypes ?: null,
            'min_width' => $this->minWidth,
            'max_width' => $this->maxWidth,
            'min_height' => $this->minHeight,
            'max_height' => $this->maxHeight,
        ], static fn (mixed $value): bool => $value !== null)];
    }

    /**
     * Whether no rule is set at all.
     */
    public function isEmpty(): bool
    {
        return $this->toStored() === ['version' => $this->version];
    }

    /**
     * The stored keys of every rule that is set but cannot apply to the given
     * property type (a length on a number, a taxonomy list on text, ...).
     *
     * @return list<string>
     */
    public function inapplicableRulesFor(PropertyType $type): array
    {
        $applicable = match (true) {
            $type->isNumeric() => ['min', 'max', 'unique_per_site'],
            $type->isText() => ['min_length', 'max_length', 'pattern', 'unique_per_site'],
            $type === PropertyType::EntryReference => ['allowed_blueprints', 'unique_per_site'],
            $type === PropertyType::TermReference => ['allowed_taxonomies'],
            $type === PropertyType::Media => ['allowed_mime_types', 'min_width', 'max_width', 'min_height', 'max_height'],
            default => [],
        };

        return array_values(array_diff(
            array_keys($this->toStored()),
            ['version', ...$applicable],
        ));
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        )));
    }
}
