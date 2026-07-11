<?php

declare(strict_types=1);

use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

it('creates every Capell custom permission', function (): void {
    EnsureCapellPermissionsAction::run();

    expect(Permission::query()->pluck('name')->all())->toEqualCanonicalizing(CapellPermission::names());
});

it('is idempotent', function (): void {
    EnsureCapellPermissionsAction::run();
    EnsureCapellPermissionsAction::run();

    foreach (CapellPermission::names() as $permissionName) {
        expect(Permission::query()->where('name', $permissionName)->count())->toBe(1);
    }
});

it('uses the provided auth guard', function (): void {
    EnsureCapellPermissionsAction::run('admin');

    foreach (CapellPermission::names() as $permissionName) {
        expect(Permission::query()
            ->where('name', $permissionName)
            ->where('guard_name', 'admin')
            ->exists())->toBeTrue();
    }
});

it('forgets cached permissions after syncing', function (): void {
    $permissionRegistrar = resolve(PermissionRegistrar::class);
    $permissionRegistrar->cacheKey = 'capell-test-permissions';

    cache()->forever($permissionRegistrar->cacheKey, ['stale']);

    EnsureCapellPermissionsAction::run();

    expect(cache()->has($permissionRegistrar->cacheKey))->toBeFalse();
});
