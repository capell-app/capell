<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Database\Factories\TermPropertyValueFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single typed value for one of a term's properties. Terms are not
 * drafted in v1 — always live, no translation/state dimension.
 *
 * @property int $id
 * @property int $term_id
 * @property int $property_definition_id
 * @property int $position
 * @property string|null $value_text
 * @property string|null $value_number
 * @property bool|null $value_boolean
 * @property CarbonImmutable|null $value_datetime
 * @property string|null $currency
 * @property string|null $unit
 * @property int|null $referenced_term_id
 * @property int|null $referenced_page_id
 * @property int|null $media_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class TermPropertyValue extends Model
{
    /** @use HasFactory<TermPropertyValueFactory> */
    use HasFactory;

    protected static string $factory = TermPropertyValueFactory::class;

    protected $fillable = [
        'term_id',
        'property_definition_id',
        'position',
        'value_text',
        'value_number',
        'value_boolean',
        'value_datetime',
        'currency',
        'unit',
        'referenced_term_id',
        'referenced_page_id',
        'media_id',
    ];

    protected $casts = [
        'position' => 'integer',
        'value_boolean' => 'boolean',
        'value_datetime' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<Term, $this>
     */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /**
     * @return BelongsTo<PropertyDefinition, $this>
     */
    public function propertyDefinition(): BelongsTo
    {
        return $this->belongsTo(PropertyDefinition::class);
    }
}
