<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\TermFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single term inside a {@see Taxonomy}. May carry its own structured data
 * via {@see TermPropertyValue} when the taxonomy attaches a property set.
 *
 * @property int $id
 * @property int $taxonomy_id
 * @property int|null $parent_id
 * @property string $slug
 * @property string $name
 * @property string|null $semantic
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory;

    protected static string $factory = TermFactory::class;

    protected $fillable = [
        'taxonomy_id',
        'parent_id',
        'slug',
        'name',
        'semantic',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Taxonomy, $this>
     */
    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Term, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return HasMany<TermPropertyValue, $this>
     */
    public function propertyValues(): HasMany
    {
        return $this->hasMany(TermPropertyValue::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Page, $this>
     */
    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(Page::class, 'page_term')->withPivot('position')->withTimestamps();
    }
}
