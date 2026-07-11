<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages;

use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;
use Capell\Admin\Filament\Resources\Roles\Pages\Concerns\HasTopFormActions;
use Capell\Admin\Filament\Resources\Roles\RoleResource;

class CreateRole extends ShieldCreateRole
{
    use HasTopFormActions;

    protected static string $resource = RoleResource::class;
}
