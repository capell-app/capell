<?php

declare(strict_types=1);

namespace Capell\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $model_type
 * @property int $model_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Model|null $user
 *
 * @method static Builder<static>|ContentLock newModelQuery()
 * @method static Builder<static>|ContentLock newQuery()
 * @method static Builder<static>|ContentLock query()
 *
 * @mixin Model
 */
final class ContentLock extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'model_type',
        'model_id',
        'expires_at',
    ];

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');

        return $this->belongsTo($userModel, 'user_id');
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        return (string) $this->user_id === (string) $user->getAuthIdentifier();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
