<?php

declare(strict_types=1);

namespace Capell\Core\Casts;

use Capell\Core\Data\Properties\PropertyConstraintsData;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;
use Override;

/**
 * Round-trips `property_definitions.constraints` between its versioned JSON
 * storage and {@see PropertyConstraintsData}. An empty rule set is stored as
 * NULL so "no rules" has exactly one representation.
 *
 * @implements CastsAttributes<PropertyConstraintsData|null, PropertyConstraintsData|array<string, mixed>|null>
 */
final class PropertyConstraintsCast implements CastsAttributes
{
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PropertyConstraintsData
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? PropertyConstraintsData::fromStored($decoded) : null;
    }

    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = PropertyConstraintsData::fromStored($value);
        }

        throw_unless($value instanceof PropertyConstraintsData, InvalidArgumentException::class, 'Property constraints must be a PropertyConstraintsData instance, an array or null.');

        if ($value->isEmpty()) {
            return null;
        }

        return json_encode($value->toStored(), JSON_THROW_ON_ERROR);
    }
}
