<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallImpersonationPermissionAction;
use Spatie\Permission\Models\Permission;

it('creates the impersonate_users permission', function (): void {
    expect(
        Permission::query()->where('name', InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE)->exists(),
    )->toBeFalse();

    InstallImpersonationPermissionAction::run();

    expect(
        Permission::query()->where('name', InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE)->exists(),
    )->toBeTrue();
});

it('is idempotent — running twice does not duplicate the permission', function (): void {
    InstallImpersonationPermissionAction::run();
    InstallImpersonationPermissionAction::run();

    expect(
        Permission::query()->where('name', InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE)->count(),
    )->toBe(1);
});
