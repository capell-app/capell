<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentLocks;

use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ReleaseContentLockAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $model, Authenticatable $user): int
    {
        return ContentLock::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->delete();
    }
}
