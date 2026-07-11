<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentLocks;

use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindConflictingContentLockAction
{
    use AsObject;

    public function handle(Model $model, Authenticatable $user): ?ContentLock
    {
        /** @var ContentLock|null $lock */
        $lock = ContentLock::query()
            ->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey())
            ->where('expires_at', '>', Date::now())
            ->first();

        if (! $lock instanceof ContentLock || $lock->isOwnedBy($user)) {
            return null;
        }

        return $lock->load('user');
    }
}
