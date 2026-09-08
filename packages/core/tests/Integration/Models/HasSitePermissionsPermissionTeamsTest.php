<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\RoleCondition;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true]);
    resolve(PermissionRegistrar::class)->teams = true;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['permission.teams' => false]);
});

it('keeps role and permission helpers tied to the requested team id', function (): void {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($assignedSite->getKey());
    $permission = Permission::query()->create(['name' => 'publish site content', 'guard_name' => 'web']);
    $role = Role::query()->create(['name' => 'site-publisher', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);

    $user = new HasSitePermissionsTestUser;
    $user->forceFill([
        'name' => 'Site Publisher',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    $user->assignRoleForSite($assignedSite, $role);

    $freshUser = $user->fresh();
    throw_unless($freshUser instanceof HasSitePermissionsTestUser);

    expect($user->getAssignedSiteIds()->all())->toBe([$assignedSite->getKey()])
        ->and($user->hasRoleForSite($assignedSite, $role))->toBeTrue()
        ->and($user->hasRoleForSite($otherSite, $role))->toBeFalse()
        ->and($user->hasPermissionForSite($assignedSite, 'publish site content'))->toBeTrue()
        ->and($freshUser->hasPermissionForSite($otherSite, 'publish site content'))->toBeFalse();
});

it('does not reuse loaded permissions when the same user crosses sites', function (): void {
    $first = Site::factory()->create();
    $second = Site::factory()->create();
    $permission = Permission::findOrCreate('publish site content', 'web');
    $role = Role::findOrCreate('publisher', 'web');
    $role->givePermissionTo($permission);

    $user = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $user->assignRoleForSite($first, $role);

    expect($user->hasPermissionForSite($first, $permission->name))->toBeTrue()
        ->and($user->hasPermissionForSite($second, $permission->name))->toBeFalse()
        ->and($user->hasPermissionForSite($first, $permission->name))->toBeTrue()
        ->and(resolve(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('discovers all assigned sites independently of the active team', function (): void {
    $first = Site::factory()->create();
    $second = Site::factory()->create();
    $role = Role::findOrCreate('editor', 'web');
    $user = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $user->assignRoleForSite($first, $role);
    $user->assignRoleForSite($second, $role);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($first->getKey());

    expect($user->getAllAssignedSiteIds()->all())->toEqualCanonicalizing([$first->getKey(), $second->getKey()])
        ->and($user->getAssignedSiteIds()->all())->toBe([$first->getKey()]);
});

it('assigns and finds global administrators while a site is active', function (): void {
    $site = Site::factory()->create();
    $user = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());
    $user->assignGlobalRole('super_admin');

    expect($user->isGlobalAdmin())->toBeTrue()
        ->and(HasSitePermissionsTestUser::query()->globalAdmins()->whereKey($user)->exists())->toBeTrue()
        ->and($user->getAssignedSiteIds())->toBeEmpty()
        ->and(resolve(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($site->getKey());
});

it('restores the team and clears loaded relations after exceptions', function (): void {
    $user = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $registrar = resolve(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(123);

    expect(fn (): mixed => PermissionTeamContext::run(456, function () use ($user): void {
        $user->load('roles', 'permissions');
        throw new RuntimeException('scope failure');
    }, $user))->toThrow(RuntimeException::class, 'scope failure');

    expect($registrar->getPermissionsTeamId())->toBe(123)
        ->and($user->relationLoaded('roles'))->toBeFalse()
        ->and($user->relationLoaded('permissions'))->toBeFalse();
});

it('evaluates frontend role conditions against the resolved site', function (): void {
    $first = Site::factory()->create();
    $second = Site::factory()->create();
    $role = Role::findOrCreate('editor', 'web');
    $user = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $user->assignRoleForSite($first, $role);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    $condition = new RoleCondition;

    expect($condition->evaluate(['role' => 'editor'], new FrontendRuleContextData($request, $first)))->toBeTrue()
        ->and($condition->evaluate(['role' => 'editor'], new FrontendRuleContextData($request, $second)))->toBeFalse()
        ->and($condition->evaluate(['role' => 'editor'], new FrontendRuleContextData($request)))->toBeFalse();
});

it('exposes only the selected teams role definitions to site operators', function (): void {
    $first = Site::factory()->create();
    $second = Site::factory()->create();
    $own = Role::query()->create(['name' => 'own-editor', 'guard_name' => 'web', 'team_id' => $first->getKey()]);
    Role::query()->create(['name' => 'foreign-editor', 'guard_name' => 'web', 'team_id' => $second->getKey()]);
    Role::query()->create(['name' => 'global-template', 'guard_name' => 'web', 'team_id' => null]);
    $user = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $user->assignRoleForSite($first, $own);

    auth()->setUser($user);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($first->getKey());

    try {
        expect(RoleResource::getEloquentQuery()->pluck('id')->all())->toBe([$own->getKey()]);
        resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
        expect(RoleResource::getEloquentQuery()->exists())->toBeFalse();
    } finally {
        auth()->forgetUser();
    }
});
