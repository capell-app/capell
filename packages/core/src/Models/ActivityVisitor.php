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
 * A privacy-first visitor-day marker. The hash is derived from a salt that
 * rotates every UTC day, so rows cannot be correlated across days, and no
 * address, user agent or identifier is persisted.
 *
 * @property int $id
 * @property int $site_id
 * @property string $language
 * @property CarbonImmutable $day
 * @property string $visitor_hash
 * @property CarbonImmutable $first_seen_at
 * @property-read Site $site
 *
 * @method static Builder<static>|ActivityVisitor newModelQuery()
 * @method static Builder<static>|ActivityVisitor newQuery()
 * @method static Builder<static>|ActivityVisitor query()
 */
final class ActivityVisitor extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'activity_visitors';

    protected $fillable = [
        'site_id',
        'language',
        'day',
        'visitor_hash',
        'first_seen_at',
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
            'day' => 'immutable_date',
            'first_seen_at' => 'immutable_datetime',
        ];
    }
}
