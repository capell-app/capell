<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Lightweight index row for one revision event, so the admin history timeline
 * lists fast without scanning stored_events. Rebuilt from the event stream by
 * PageProjector; the authoritative payload still lives in the event store.
 *
 * @property string $page_uuid
 * @property int $version
 * @property int|null $actor_id
 * @property string $summary
 * @property bool $is_rollback
 * @property CarbonImmutable $occurred_at
 */
class PageRevision extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    protected $fillable = [
        'page_uuid',
        'version',
        'actor_id',
        'summary',
        'is_rollback',
        'occurred_at',
    ];

    /**
     * The user who made this change, when known. Null for console or
     * unauthenticated saves (the index stores no actor then). Resolves the host
     * app's configured user model rather than hard-coding one, consistent with
     * the rest of core.
     *
     * @return BelongsTo<Model, $this>
     */
    public function actor(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel, 'actor_id');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_rollback' => 'boolean',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
