<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $deletion_batch_id
 * @property string $model_type
 * @property int $model_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DeletionBatch $batch
 *
 * @method static Builder<static>|DeletionBatchRecord newModelQuery()
 * @method static Builder<static>|DeletionBatchRecord newQuery()
 * @method static Builder<static>|DeletionBatchRecord query()
 *
 * @mixin Model
 */
final class DeletionBatchRecord extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'deletion_batch_id',
        'model_type',
        'model_id',
    ];

    /** @return BelongsTo<DeletionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeletionBatch::class, 'deletion_batch_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
