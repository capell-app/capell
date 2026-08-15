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

    public function handle(Model $model, Authenticatable $user, int $ttlMinutes = ContentLock::DEFAULT_TTL_MINUTES): ContentLock
    {
        $now = Date::now();

        ContentLock::query()->upsert([
            [
                'user_id' => $user->getAuthIdentifier(),
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'expires_at' => $now->copy()->addMinutes($ttlMinutes),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['model_type', 'model_id'], ['user_id', 'expires_at', 'updated_at']);

        return ContentLock::query()
            ->forModel($model)
            ->firstOrFail()
            ->load('user');
    }
}
