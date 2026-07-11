<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages;

use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles as ShieldListRoles;
use Capell\Admin\Filament\Resources\Roles\RoleResource;

class ListRoles extends ShieldListRoles
{
    protected static string $resource = RoleResource::class;
}
