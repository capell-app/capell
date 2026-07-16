<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentLocks;

use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AcquireContentLockAction
{
    use AsFake;
    use AsObject;

    private const int DEFAULT_TTL_MINUTES = 15;

    public function handle(Model $model, Authenticatable $user, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): ContentLock
    {
        $now = Date::now();

        ContentLock::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->where('expires_at', '<=', $now)
            ->delete();

        /** @var ContentLock|null $lock */
        $lock = ContentLock::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->first();

        if ($lock instanceof ContentLock && ! $lock->isOwnedBy($user)) {
            return $lock->load('user');
        }

        $expiresAt = $now->copy()->addMinutes($ttlMinutes);

        if ($lock instanceof ContentLock) {
            $lock->forceFill([
                'expires_at' => $expiresAt,
            ])->save();

            return $lock->refresh()->load('user');
        }

        return ContentLock::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'expires_at' => $expiresAt,
        ])->load('user');
    }
}
