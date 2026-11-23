<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages\Concerns;

use Capell\Admin\Support\Shield\RolePermissionCeiling;
use Illuminate\Validation\ValidationException;

trait RefusesPermissionsBeyondTheEditors
{
    /**
     * Refuse permissions the signed-in editor does not hold in the role's team.
     * A permission the role already carries is left alone, so an editor can still
     * rename or trim a role without being asked to hold everything on it.
     *
     * @param  iterable<mixed>  $requested
     * @param  list<string>  $alreadyOnRole
     */
    protected function refusePermissionsBeyondTheEditors(iterable $requested, int|string|null $teamId, array $alreadyOnRole = []): void
    {
        $beyond = array_values(array_diff(
            resolve(RolePermissionCeiling::class)->exceeding(auth()->user(), $requested, $teamId),
            $alreadyOnRole,
        ));

        if ($beyond === []) {
            return;
        }

        throw ValidationException::withMessages([
            'data.name' => (string) __('capell-admin::generic.role_permissions_beyond_your_own', ['permissions' => implode(', ', $beyond)]),
        ]);
    }
}
