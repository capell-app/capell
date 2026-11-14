<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Concerns\HasCapellTable;
use Capell\Core\Enums\AiDisclosure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * Which AI capability wrote a field of a page, and whether a person has reviewed
 * it since. One row per page, language and field: a later AI write replaces the
 * row and clears its confirmation, so an unreviewed AI change is never hidden by
 * an older review.
 *
 * @property int $id
 * @property string $pageable_type
 * @property int $pageable_id
 * @property int|null $language_id
 * @property string $field
 * @property int|null $generation_history_id
 * @property string|null $provider
 * @property string|null $model
 * @property string|null $capability
 * @property int|null $actor_id
 * @property int|null $confirmed_by
 * @property CarbonImmutable|null $confirmed_at
 * @property AiDisclosure $disclosure
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class AiProvenance extends Model
{
    use HasCapellTable;

    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'capell_ai_provenance';

    /** @var list<string> */
    protected $fillable = [
        'pageable_type',
        'pageable_id',
        'language_id',
        'field',
        'generation_history_id',
        'provider',
        'model',
        'capability',
        'actor_id',
        'confirmed_by',
        'confirmed_at',
        'disclosure',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function pageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'pageable_id' => 'integer',
            'language_id' => 'integer',
            'generation_history_id' => 'integer',
            'actor_id' => 'integer',
            'confirmed_by' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'disclosure' => AiDisclosure::class,
        ];
    }
}
