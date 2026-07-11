<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Shield;

use Capell\Admin\Data\Shield\RolePermissionChangeSetData;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

class LogRolePermissionChangesAction
{
    use AsObject;

    public function handle(Role $role, RolePermissionChangeSetData $changeSet, ?Model $actor = null): void
    {
        if (! $changeSet->hasChanges()) {
            return;
        }

        activity()
            ->tap(function (Activity $activity) use ($role): void {
                $activity->subject_type = $role::class;
                $activity->subject_id = $role->getKey();
            })
            ->causedBy($actor)
            ->event('updated')
            ->withProperties([
                'old' => [
                    'permissions' => $changeSet->before,
                ],
                'attributes' => [
                    'permissions' => $changeSet->after,
                ],
            ])
            ->log($changeSet->summary());
    }
}
