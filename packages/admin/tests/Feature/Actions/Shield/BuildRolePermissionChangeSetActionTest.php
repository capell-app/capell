<?php

declare(strict_types=1);

use Capell\Admin\Actions\Shield\BuildRolePermissionChangeSetAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

it('builds a permission change set from shield form state', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $existingPermission = Permission::findOrCreate('View:Page', 'web');
    Permission::findOrCreate('Update:Page', 'web');
    Permission::findOrCreate('Delete:Page', 'web');
    $role->givePermissionTo($existingPermission);

    $changeSet = BuildRolePermissionChangeSetAction::run($role, [
        'name' => 'content_manager',
        'guard_name' => 'web',
        'select_all' => false,
        'pages_tab' => ['View:Page', 'Update:Page'],
        'custom_permissions_tab' => [],
    ]);

    expect($changeSet->before)->toBe(['View:Page'])
        ->and($changeSet->after)->toBe(['Update:Page', 'View:Page'])
        ->and($changeSet->added)->toBe(['Update:Page'])
        ->and($changeSet->removed)->toBe([])
        ->and($changeSet->hasChanges())->toBeTrue()
        ->and($changeSet->summary())->toBe('1 added, 0 removed');
});

it('builds sorted changes from nested shield fields without duplicates or invalid strings', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $viewPermission = Permission::findOrCreate('View:Page', 'web');
    $deletePermission = Permission::findOrCreate('Delete:Page', 'web');
    Permission::findOrCreate('Update:Page', 'web');
    Permission::findOrCreate('Archive:Page', 'admin');
    $role->givePermissionTo([$deletePermission, $viewPermission]);

    $changeSet = BuildRolePermissionChangeSetAction::run($role, [
        'name' => 'content_manager',
        'guard_name' => 'web',
        'select_all' => false,
        'label' => 'Update:Page',
        'description' => 'Internal editor role',
        'pages_tab' => [
            ['View:Page', 'View:Page'],
            ['View:Page', ''],
        ],
        'custom_permissions_tab' => [
            'Archive:Page',
            'Unknown:Page',
        ],
    ]);

    expect($changeSet->before)->toBe(['Delete:Page', 'View:Page'])
        ->and($changeSet->after)->toBe(['View:Page'])
        ->and($changeSet->added)->toBe([])
        ->and($changeSet->removed)->toBe(['Delete:Page'])
        ->and($changeSet->unchanged)->toBe(['View:Page'])
        ->and($changeSet->hasChanges())->toBeTrue()
        ->and($changeSet->summary())->toBe('0 added, 1 removed');
});
