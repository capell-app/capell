<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $site_id
 * @property string $language
 * @property ActivityBucketSubjectEnum $subject_type
 * @property string $subject_key
 * @property CarbonImmutable $bucket_started_at
 * @property int $count
 * @property-read Site $site
 *
 * @method static Builder<static>|ActivityBucket newModelQuery()
 * @method static Builder<static>|ActivityBucket newQuery()
 * @method static Builder<static>|ActivityBucket query()
 */
final class ActivityBucket extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $table = 'activity_buckets';

    protected $fillable = [
        'site_id',
        'language',
        'subject_type',
        'subject_key',
        'bucket_started_at',
        'count',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'subject_type' => ActivityBucketSubjectEnum::class,
            'bucket_started_at' => 'immutable_datetime',
            'count' => 'integer',
        ];
    }
}
