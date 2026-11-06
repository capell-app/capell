<?php

declare(strict_types=1);

namespace Capell\Core\Support\Permissions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;

final class PermissionTeamContext
{
    /**
     * Loaded Eloquent relations are independent of Spatie's permission cache.
     * Refresh at entry for every authorisation computation. Relations loaded in
     * another team must be cleared on exit, including exceptional exits.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public static function run(int|string|null $teamId, Closure $operation, ?Model $actor = null): mixed
    {
        $registrar = resolve(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $actor?->unsetRelation('roles')->unsetRelation('permissions');
        $registrar->setPermissionsTeamId($teamId);

        try {
            return $operation();
        } finally {
            $registrar->setPermissionsTeamId($previous);
            if ($previous !== $teamId) {
                $actor?->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }
}
