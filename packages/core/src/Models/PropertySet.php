<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\PropertySetFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, versioned collection of typed properties. Defined by Core
 * (built-ins), extensions, or blueprints, and attached to one or more
 * blueprints via {@see BlueprintPropertySet}.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property int $version
 * @property string|null $owner_package
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PropertySet extends Model
{
    /** @use HasFactory<PropertySetFactory> */
    use HasFactory;

    protected static string $factory = PropertySetFactory::class;

    protected $fillable = [
        'key',
        'name',
        'version',
        'owner_package',
    ];

    protected $casts = [
        'version' => 'integer',
    ];

    /**
     * @return HasMany<PropertyDefinition, $this>
     */
    public function definitions(): HasMany
    {
        return $this->hasMany(PropertyDefinition::class)->orderBy('position');
    }
}
