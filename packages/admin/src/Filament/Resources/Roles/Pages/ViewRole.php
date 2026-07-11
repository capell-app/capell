<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Resources\Roles\Pages;

use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ViewRole as ShieldViewRole;
use Capell\Admin\Filament\Resources\Roles\RoleResource;

class ViewRole extends ShieldViewRole
{
    protected static string $resource = RoleResource::class;
}
