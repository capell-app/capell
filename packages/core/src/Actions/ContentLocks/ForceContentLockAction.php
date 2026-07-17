<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentLocks;

use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ForceContentLockAction
{
    use AsFake;
    use AsObject;

    private const int DEFAULT_TTL_MINUTES = 15;

    public function handle(Model $model, Authenticatable $user, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): ContentLock
    {
        ContentLock::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->delete();

        return ContentLock::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
            'expires_at' => Date::now()->addMinutes($ttlMinutes),
        ])->load('user');
    }
}
