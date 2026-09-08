<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages;

use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;
use Capell\Admin\Filament\Resources\Roles\Pages\Concerns\HasTopFormActions;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Support\SiteScope;
use Illuminate\Auth\Access\AuthorizationException;
use Override;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends ShieldCreateRole
{
    use HasTopFormActions;

    protected static string $resource = RoleResource::class;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);

        if (config('permission.teams')) {
            $team = resolve(PermissionRegistrar::class)->getPermissionsTeamId();
            $actor = auth()->user();
            throw_unless($team !== null || ($actor !== null && SiteScope::isGlobalActor($actor)), AuthorizationException::class);
            $data[(string) config('permission.column_names.team_foreign_key', 'team_id')] = $team;
        }

        return $data;
    }
}
