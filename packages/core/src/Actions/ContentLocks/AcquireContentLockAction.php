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

    public function handle(Model $model, Authenticatable $user, int $ttlMinutes = ContentLock::DEFAULT_TTL_MINUTES): ContentLock
    {
        $now = Date::now();

        ContentLock::query()
            ->forModel($model)
            ->where('expires_at', '<=', $now)
            ->delete();

        $expiresAt = $now->copy()->addMinutes($ttlMinutes);
        $lock = ContentLock::query()->firstOrCreate([
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
        ], [
            'user_id' => $user->getAuthIdentifier(),
            'expires_at' => $expiresAt,
        ]);

        if (! $lock->isOwnedBy($user)) {
            return $lock->load('user');
        }

        if (! $lock->wasRecentlyCreated) {
            $lock->forceFill([
                'expires_at' => $expiresAt,
            ])->save();
        }

        return $lock->refresh()->load('user');
    }
}
