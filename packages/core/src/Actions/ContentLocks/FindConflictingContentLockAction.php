<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentLocks;

use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class FindConflictingContentLockAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $model, Authenticatable $user): ?ContentLock
    {
        /** @var ContentLock|null $lock */
        $lock = ContentLock::query()
            ->forModel($model)
            ->where('expires_at', '>', Date::now())
            ->first();

        if (! $lock instanceof ContentLock || $lock->isOwnedBy($user)) {
            return null;
        }

        return $lock->load('user');
    }
}
