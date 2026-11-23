<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Filament\Resources\Roles\Pages\CreateRole;
use Capell\Admin\Filament\Resources\Roles\Pages\EditRole;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Tests\Support\Models\TeamScopedPanelUser;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(CreatesAdminUser::class)->group('admin');

const CEILING_HELD = 'PageExport';
const CEILING_BEYOND = 'SiteExport';

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach ([CEILING_HELD, CEILING_BEYOND, ...ceilingRolePermissions()] as $name) {
        Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
});

/**
 * The names that open the role screens: the Shield names in the host's configured
 * case and separator, plus the snake-case ones the test application's own Role
 * policy checks.
 *
 * @return list<string>
 */
function ceilingRolePermissions(): array
{
    $permissions = Utils::getConfig()->permissions;

    return array_values(array_unique([
        ...array_map(
            fn (string $affix): string => FilamentShield::defaultPermissionKeyBuilder(affix: $affix, separator: $permissions->separator, subject: 'Role', case: $permissions->case),
            ['view', 'view_any', 'create', 'update'],
        ),
        'view_role', 'view_any_role', 'create_role', 'update_role',
    ]));
}

/** @return list<string> */
function ceilingRoleNames(): array
{
    return array_values(Role::query()->orderBy('name')->pluck('name')->all());
}

it('refuses a new role that carries a permission its creator does not hold', function (): void {
    $actor = test()->createUserWithPermission([...ceilingRolePermissions(), CEILING_HELD]);
    test()->actingAs($actor);

    expect(RoleResource::canCreate())->toBeTrue();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'escalated', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_HELD, CEILING_BEYOND]])
        ->call('create')
        ->assertHasErrors(['data.name']);

    expect(ceilingRoleNames())->not->toContain('escalated');

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'within-ceiling', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_HELD]])
        ->call('create')
        ->assertHasNoErrors();

    expect(Role::findByName('within-ceiling', 'web')->permissions->pluck('name')->all())->toBe([CEILING_HELD]);
});

it('refuses to add a permission its editor does not hold, while leaving permissions already on the role alone', function (): void {
    $role = Role::query()->firstOrCreate(['name' => 'content_manager', 'guard_name' => 'web']);
    $role->givePermissionTo(CEILING_BEYOND);

    $actor = test()->createUserWithPermission([...ceilingRolePermissions(), CEILING_HELD]);
    test()->actingAs($actor);

    expect(RoleResource::canEdit($role))->toBeTrue();

    Livewire::test(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['name' => 'content_manager', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_BEYOND, CEILING_HELD]])
        ->call('save')
        ->assertHasNoErrors();

    expect($role->refresh()->permissions->pluck('name')->sort()->values()->all())->toBe([CEILING_HELD, CEILING_BEYOND]);

    $other = Role::query()->firstOrCreate(['name' => 'plain', 'guard_name' => 'web']);

    Livewire::test(EditRole::class, ['record' => $other->getKey()])
        ->fillForm(['name' => 'plain', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_BEYOND]])
        ->call('save')
        ->assertHasErrors(['data.name']);

    expect($other->refresh()->permissions)->toHaveCount(0);
});

it('lets a super admin put any permission on a role', function (): void {
    test()->actingAsAdmin();

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'unrestricted', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_BEYOND]])
        ->call('create')
        ->assertHasNoErrors();

    expect(Role::findByName('unrestricted', 'web')->permissions->pluck('name')->all())->toBe([CEILING_BEYOND]);
});

it('measures the ceiling in the role team, not in another site the editor belongs to', function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;

    try {
        $siteA = Site::factory()->withTranslations()->create();
        $siteB = Site::factory()->withTranslations()->create();
        $actor = TeamScopedPanelUser::query()->create(['name' => 'Two-site editor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);

        $roleAdmin = Role::query()->firstOrCreate(['name' => 'role-admin', 'guard_name' => 'web']);
        $roleAdmin->syncPermissions(ceilingRolePermissions());
        $rich = Role::query()->firstOrCreate(['name' => 'rich-editor', 'guard_name' => 'web']);
        $rich->syncPermissions([CEILING_HELD, CEILING_BEYOND]);

        $actor->assignRoleForSite($siteA, $roleAdmin);
        $actor->assignRoleForSite($siteA, $rich);
        $actor->assignRoleForSite($siteB, $roleAdmin);

        setPermissionsTeamId($siteB->getKey());
        test()->actingAs($actor);

        expect(RoleResource::canCreate())->toBeTrue();

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'borrowed', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_BEYOND]])
            ->call('create')
            ->assertHasErrors(['data.name']);

        expect(ceilingRoleNames())->not->toContain('borrowed');

        setPermissionsTeamId($siteA->getKey());
        test()->actingAs($actor->refresh());

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'own-team', 'guard_name' => 'web', 'custom_permissions_tab' => [CEILING_BEYOND]])
            ->call('create')
            ->assertHasNoErrors();

        expect(ceilingRoleNames())->toContain('own-team');
    } finally {
        setPermissionsTeamId(null);
        resolve(PermissionRegistrar::class)->teams = false;
        config(['permission.teams' => false]);
    }
});
