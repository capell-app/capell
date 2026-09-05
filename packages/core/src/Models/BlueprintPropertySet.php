<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Database\Factories\BlueprintPropertySetFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blueprint's attachment of a {@see PropertySet}, with optional per-property
 * overrides (requirement/agent_visible/description/promoted_field) keyed by
 * the property's key within the set. Overrides are resolved by
 * {@see ResolveEffectiveDefinitionsAction},
 * clamped against any `locked` definition's floor — this table stores the
 * raw request, never a second, independently-interpreted override format.
 *
 * @property int $id
 * @property int $blueprint_id
 * @property int $property_set_id
 * @property array<string, array<string, mixed>>|null $overrides
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BlueprintPropertySet extends Model
{
    /** @use HasFactory<BlueprintPropertySetFactory> */
    use HasFactory;

    protected static string $factory = BlueprintPropertySetFactory::class;

    protected $fillable = [
        'blueprint_id',
        'property_set_id',
        'overrides',
    ];

    protected $casts = [
        'overrides' => 'array',
    ];

    /**
     * @return BelongsTo<Blueprint, $this>
     */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    /**
     * @return BelongsTo<PropertySet, $this>
     */
    public function propertySet(): BelongsTo
    {
        return $this->belongsTo(PropertySet::class);
    }
}
