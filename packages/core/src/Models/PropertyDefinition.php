<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\PropertyDefinitionFactory;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A single typed property inside a {@see PropertySet}: a key, its value
 * shape, an optional schema.org mapping, how strictly it must be filled in,
 * and whether it is visible to the agent layer.
 *
 * @property int $id
 * @property int $property_set_id
 * @property string $key
 * @property PropertyType $type
 * @property string|null $semantic
 * @property PropertyRequirement $requirement
 * @property bool $agent_visible
 * @property bool $localised
 * @property bool $multiple
 * @property bool $locked
 * @property string|null $description
 * @property array<string, mixed>|null $unit_config
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PropertyDefinition extends Model
{
    /** @use HasFactory<PropertyDefinitionFactory> */
    use HasFactory;

    protected static string $factory = PropertyDefinitionFactory::class;

    protected $fillable = [
        'property_set_id',
        'key',
        'type',
        'semantic',
        'requirement',
        'agent_visible',
        'localised',
        'multiple',
        'locked',
        'description',
        'unit_config',
        'position',
    ];

    protected $casts = [
        'type' => PropertyType::class,
        'requirement' => PropertyRequirement::class,
        'agent_visible' => 'boolean',
        'localised' => 'boolean',
        'multiple' => 'boolean',
        'locked' => 'boolean',
        'unit_config' => 'array',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<PropertySet, $this>
     */
    public function propertySet(): BelongsTo
    {
        return $this->belongsTo(PropertySet::class);
    }

    /**
     * The definition's fully-qualified reference, e.g. `commerce.product.price`.
     */
    public function qualifiedKey(): string
    {
        $propertySet = $this->propertySet;

        if (! $propertySet instanceof PropertySet) {
            throw new RuntimeException(sprintf(
                'PropertyDefinition [%d] has no resolvable property set (property_set_id %d).',
                $this->id,
                $this->property_set_id,
            ));
        }

        return $propertySet->key . '.' . $this->key;
    }
}
