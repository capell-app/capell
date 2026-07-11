<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property string $root_type
 * @property int $root_id
 * @property CarbonImmutable|null $restored_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, DeletionBatchRecord> $records
 * @property-read int|null $records_count
 *
 * @method static Builder<static>|DeletionBatch newModelQuery()
 * @method static Builder<static>|DeletionBatch newQuery()
 * @method static Builder<static>|DeletionBatch open()
 * @method static Builder<static>|DeletionBatch query()
 *
 * @mixin Model
 */
final class DeletionBatch extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'root_type',
        'root_id',
        'restored_at',
    ];

    /** @return HasMany<DeletionBatchRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(DeletionBatchRecord::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopeOpen(Builder $query): void
    {
        $query->whereNull('restored_at');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'restored_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
