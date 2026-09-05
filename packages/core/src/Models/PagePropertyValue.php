<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\PagePropertyValueFactory;
use Capell\Core\Models\Concerns\HasPublishDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single typed value for one of a page's properties. Single-copy per
 * (page, property_definition, translation, position) — there is no
 * draft/published duplication here, mirroring how the page's own content
 * (title/content on {@see Translation}) has none either: visibility is a
 * read-time gate against the owning page's `visible_from`/`visible_until`
 * (see {@see HasPublishDates}), not a stored
 * projection. See the CAP-0460 Task 0 assumption-check note.
 *
 * @property int $id
 * @property int $site_id
 * @property int $page_id
 * @property int|null $translation_id
 * @property int $property_definition_id
 * @property int $position
 * @property string|null $value_text
 * @property string|null $value_number
 * @property bool|null $value_boolean
 * @property CarbonImmutable|null $value_datetime
 * @property string|null $currency
 * @property string|null $unit
 * @property int|null $term_id
 * @property int|null $referenced_page_id
 * @property int|null $media_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PagePropertyValue extends Model
{
    /** @use HasFactory<PagePropertyValueFactory> */
    use HasFactory;

    protected static string $factory = PagePropertyValueFactory::class;

    protected $fillable = [
        'site_id',
        'page_id',
        'translation_id',
        'property_definition_id',
        'position',
        'value_text',
        'value_number',
        'value_boolean',
        'value_datetime',
        'currency',
        'unit',
        'term_id',
        'referenced_page_id',
        'media_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'value_boolean' => 'boolean',
        'value_datetime' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return BelongsTo<Translation, $this>
     */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    /**
     * @return BelongsTo<PropertyDefinition, $this>
     */
    public function propertyDefinition(): BelongsTo
    {
        return $this->belongsTo(PropertyDefinition::class);
    }

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function referencedPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'referenced_page_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    protected function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }
}
