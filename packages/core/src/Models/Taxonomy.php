<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\TaxonomyFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A site-scoped vocabulary of {@see Term}s. May optionally attach a
 * {@see PropertySet} to its terms, so every term in the taxonomy carries the
 * same structured data shape (e.g. a `brand` taxonomy carrying logo/country).
 *
 * @property int $id
 * @property int $site_id
 * @property string $key
 * @property string $name
 * @property bool $hierarchical
 * @property int|null $property_set_id
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Taxonomy extends Model
{
    /** @use HasFactory<TaxonomyFactory> */
    use HasFactory;

    protected static string $factory = TaxonomyFactory::class;

    protected $fillable = [
        'site_id',
        'key',
        'name',
        'hierarchical',
        'property_set_id',
        'position',
    ];

    protected $casts = [
        'hierarchical' => 'boolean',
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<PropertySet, $this>
     */
    public function propertySet(): BelongsTo
    {
        return $this->belongsTo(PropertySet::class);
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function terms(): HasMany
    {
        return $this->hasMany(Term::class)->orderBy('position');
    }
}
