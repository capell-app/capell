<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallCapellCustomPermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Spatie\Permission\Models\Permission;

it('creates Capell custom permissions used by policies and actions', function (): void {
    InstallCapellCustomPermissionsAction::run();

    expect(Permission::query()->pluck('name')->all())->toEqualCanonicalizing(CapellPermission::names());
});

it('is idempotent', function (): void {
    InstallCapellCustomPermissionsAction::run();
    InstallCapellCustomPermissionsAction::run();

    foreach (CapellPermission::names() as $permissionName) {
        expect(Permission::query()->where('name', $permissionName)->count())->toBe(1);
    }
});
