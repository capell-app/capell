<?php

declare(strict_types=1);

namespace Capell\Admin\Support\EventSourcing;

use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Whether the signed-in user may compare or restore versions of one record.
 *
 * Restoring rewrites the record, so it needs the rollback permission and the
 * record policy's update decision, both evaluated in the record's own site
 * team. The team a session happens to have selected must not decide: a
 * permission held on another site grants nothing here.
 */
final class EventSourcedRestoreAccess
{
    public function allows(Model $owner): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable) {
            return false;
        }

        return $this->inOwnerTeam($actor, $owner, static fn (): bool => Gate::forUser($actor)->allows(CapellPermission::RollbackPage->name())
            && Gate::forUser($actor)->allows('update', $owner));
    }

    /** @param Closure(): bool $decision */
    private function inOwnerTeam(Authenticatable $actor, Model $owner, Closure $decision): bool
    {
        $siteId = $owner->getAttribute('site_id');

        if (! config('permission.teams') || blank($siteId)) {
            return $decision();
        }

        $teamId = SiteAccess::forActor($actor)->isGlobal() ? null : $siteId;

        return PermissionTeamContext::run($teamId, $decision, $actor instanceof Model ? $actor : null);
    }
}
