<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Activity\BuildActivityChangeSetAction;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Filament\Resources\Roles\Pages\EditRole;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

function firstConfiguredCustomPermissionName(): string
{
    $permissionName = array_key_first(FilamentShield::getCustomPermissions() ?? []);

    Assert::assertIsString($permissionName, 'Expected at least one configured Shield custom permission for this test.');
    Assert::assertNotSame('', $permissionName, 'Expected a non-empty configured Shield custom permission name for this test.');

    return $permissionName;
}

/**
 * @param  array<string, mixed>  $formState
 * @return list<string>
 */
function selectedPermissionNamesFromRoleFormState(array $formState): array
{
    return array_values(collect($formState)
        ->except(['name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()])
        ->filter(fn (mixed $value): bool => is_array($value))
        ->flatten()
        ->filter(fn (mixed $permissionName): bool => is_string($permissionName) && $permissionName !== '')
        ->unique()
        ->values()
        ->all());
}

it('persists and clears permissions from the select all toggle', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $permissionName = firstConfiguredCustomPermissionName();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.select_all', true)
        ->call('save')
        ->assertHasNoFormErrors();

    $role->refresh();

    expect($role->hasPermissionTo($permissionName, 'web'))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.select_all', false)
        ->call('save')
        ->assertHasNoFormErrors();

    $role->refresh();

    expect($role->permissions()->exists())->toBeFalse();
});

it('logs role permission changes after saving the role form', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $permissionName = firstConfiguredCustomPermissionName();

    $roleActivityCount = fn (): int => Activity::query()
        ->where('subject_type', $role::class)
        ->where('subject_id', $role->getKey())
        ->count();

    expect(Permission::query()->where('name', $permissionName)->where('guard_name', 'web')->exists())->toBeFalse()
        ->and($roleActivityCount())->toBe(0);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'content_manager',
            'guard_name' => 'web',
            'custom_permissions_tab' => [$permissionName],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Permission::query()->where('name', $permissionName)->where('guard_name', 'web')->exists())->toBeTrue()
        ->and($roleActivityCount())->toBe(1);

    $activity = Activity::query()
        ->where('subject_type', $role::class)
        ->where('subject_id', $role->getKey())
        ->firstOrFail();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('updated')
        ->and($activity->changes()->get('old'))->toBe(['permissions' => []])
        ->and($activity->changes()->get('attributes'))->toBe(['permissions' => [$permissionName]]);

    $changeSet = BuildActivityChangeSetAction::run($activity);

    expect($changeSet->fields)->toHaveCount(1)
        ->and($changeSet->fields[0]->path)->toBe('permissions')
        ->and($changeSet->fields[0]->beforeValue)->toBe([])
        ->and($changeSet->fields[0]->afterValue)->toBe([$permissionName]);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'content_manager',
            'guard_name' => 'web',
            'custom_permissions_tab' => [$permissionName],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($roleActivityCount())->toBe(1);
});

it('logs role permission identity changes when the role guard changes', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $webPermission = Permission::findOrCreate('View:Page', 'web');
    $role->givePermissionTo($webPermission);

    $roleActivityCount = fn (): int => Activity::query()
        ->where('subject_type', $role::class)
        ->where('subject_id', $role->getKey())
        ->count();

    expect($roleActivityCount())->toBe(0);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'content_manager',
            'guard_name' => 'admin',
            'resources_tab' => ['View:Page'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Permission::query()->where('name', 'View:Page')->where('guard_name', 'admin')->exists())->toBeTrue()
        ->and($roleActivityCount())->toBe(1);

    $activity = Activity::query()
        ->where('subject_type', $role::class)
        ->where('subject_id', $role->getKey())
        ->firstOrFail();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('updated')
        ->and($activity->changes()->get('old'))->toBe(['permissions' => ['web:View:Page']])
        ->and($activity->changes()->get('attributes'))->toBe(['permissions' => ['admin:View:Page']]);

    $changeSet = BuildActivityChangeSetAction::run($activity);

    expect($changeSet->fields)->toHaveCount(1)
        ->and($changeSet->fields[0]->path)->toBe('permissions')
        ->and($changeSet->fields[0]->beforeValue)->toBe(['web:View:Page'])
        ->and($changeSet->fields[0]->afterValue)->toBe(['admin:View:Page']);
});

it('shows a permission diff preview while editing a role', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    Permission::findOrCreate('View:Page', 'web');
    Permission::findOrCreate('Update:Page', 'web');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.pages_tab', ['View:Page', 'Update:Page'])
        ->assertSee('2 added, 0 removed');
});

it('shows a no-change permission diff preview while editing a role', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertSee(__('capell-admin::generic.role_permissions_no_changes'));
});

it('renders warning copy for clearing all role permissions', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->assertSee(__('capell-admin::generic.role_permissions_search_help'))
        ->assertSee(__('capell-admin::generic.role_select_all_permissions_clear_warning'));
});

it('previews valid shield custom permissions before they are persisted', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $permissionName = firstConfiguredCustomPermissionName();

    expect(Permission::query()->where('name', $permissionName)->where('guard_name', 'web')->exists())->toBeFalse();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.custom_permissions_tab', [$permissionName])
        ->assertSee('1 added, 0 removed');
});

it('previews permission identity changes when the role guard changes', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $permission = Permission::findOrCreate('View:Page', 'web');
    $role->givePermissionTo($permission);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm([
            'name' => 'content_manager',
            'guard_name' => 'admin',
            'resources_tab' => ['View:Page'],
        ])
        ->assertSee('1 added, 1 removed')
        ->assertDontSee(__('capell-admin::generic.role_permissions_no_changes'));
});

it('previews permissions added by the select all toggle', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');

    $component = Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.select_all', true);

    $selectedPermissionCount = count(selectedPermissionNamesFromRoleFormState($component->get('data')));

    expect($selectedPermissionCount)->toBeGreaterThan(0);

    $component->assertSee($selectedPermissionCount . ' added, 0 removed');
});

it('previews permissions removed by the select all toggle', function (): void {
    $role = Role::findOrCreate('content_manager', 'web');
    $permissionNames = selectedPermissionNamesFromRoleFormState(Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.select_all', true)
        ->get('data'));

    collect($permissionNames)
        ->each(fn (string $permissionName): Spatie\Permission\Contracts\Permission => Permission::findOrCreate($permissionName, 'web'));

    $role->givePermissionTo($permissionNames);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->set('data.select_all', false)
        ->assertSee('0 added, ' . count($permissionNames) . ' removed');
});

it('shows the reset action for built-in roles only', function (): void {
    $builtInRole = Role::findOrCreate('admin', 'web');
    $customRole = Role::findOrCreate('content_manager', 'web');

    Livewire::test(EditRole::class, ['record' => $builtInRole->getKey()])
        ->assertActionVisible('resetRolePermissions');

    Livewire::test(EditRole::class, ['record' => $customRole->getKey()])
        ->assertActionHidden('resetRolePermissions');
});

it('resets built-in role permissions from the edit page', function (): void {
    EnsureCapellPermissionsAction::run();

    $role = Role::findOrCreate('admin', 'web');
    $customPermission = Permission::findOrCreate('custom.permission', 'web');
    $role->givePermissionTo($customPermission);

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->callAction('resetRolePermissions')
        ->assertNotified(__('capell-admin::generic.reset_role_permissions_success'))
        ->assertHasNoFormErrors();

    $role->refresh();

    expect($role->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue()
        ->and($role->hasPermissionTo('custom.permission', 'web'))->toBeFalse();

    $activity = Activity::query()
        ->where('subject_type', $role::class)
        ->where('subject_id', $role->getKey())
        ->firstOrFail();

    $attributes = $activity->changes()->get('attributes');

    expect($attributes)->toBeArray()
        ->and($activity)->not->toBeNull()
        ->and($activity->event)->toBe('updated')
        ->and($activity->changes()->get('old'))->toBe(['permissions' => ['custom.permission']])
        ->and($attributes['permissions'])->toContain(CapellPermission::ManageSitePermissions->name());
});
