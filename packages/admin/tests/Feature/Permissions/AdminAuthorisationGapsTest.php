<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    resolve(PermissionRegistrar::class)->teams = true;
});

afterEach(function (): void {
    auth()->logout();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** @param list<string> $permissions */
function authorisationGapUser(Site $site, array $permissions = []): RealSiteScopedAdminResourceUser
{
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->id);
    $role = Role::query()->create(['name' => fake()->unique()->uuid(), 'guard_name' => 'web']);
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = new RealSiteScopedAdminResourceUser;
    $user->forceFill(['name' => 'Site user', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')])->save();
    $user->assignRoleForSite($site, $role);

    return $user->refresh();
}

it('limits user record resolution and bulk deletion to the active site and preserves the actor', function (): void {
    $site = Site::factory()->create();
    $foreignSite = Site::factory()->create();
    $actor = authorisationGapUser($site, ['ViewAny:User', 'DeleteAny:User', 'Delete:User']);
    $local = authorisationGapUser($site);
    $foreign = authorisationGapUser($foreignSite);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->id);
    test()->actingAs($actor);

    expect(UserResource::getEloquentQuery()->whereKey($foreign->id)->exists())->toBeFalse()
        ->and(UserResource::getGlobalSearchEloquentQuery()->whereKey($foreign->id)->exists())->toBeFalse();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$actor, $local])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableBulkAction('delete', [$actor, $local, $foreign]);

    expect($actor->fresh())->not->toBeNull()
        ->and($local->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull();
});

it('requires explicit replication and creation permissions to replicate a site', function (): void {
    $site = Site::factory()->create();
    $actor = authorisationGapUser($site);
    test()->actingAs($actor);

    expect(SiteResource::canReplicate($site))->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('Replicate:Site', 'web'));
    expect(SiteResource::canReplicate($site))->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('Create:Site', 'web'));
    expect(SiteResource::canReplicate($site))->toBeTrue()
        ->and(SiteResource::canReplicate(Site::factory()->create()))->toBeFalse();
});

it('requires bulk restore and force delete permissions for sites and layouts', function (string $resource, string $subject): void {
    $site = Site::factory()->create();
    $actor = authorisationGapUser($site);
    test()->actingAs($actor);

    expect($resource::canRestoreAny())->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('RestoreAny:' . $subject, 'web'));
    expect($resource::canRestoreAny())->toBeTrue()
        ->and($resource::canForceDeleteAny())->toBeFalse();
    $actor->givePermissionTo(Permission::findOrCreate('ForceDeleteAny:' . $subject, 'web'));
    expect($resource::canForceDeleteAny())->toBeTrue();
})->with([[SiteResource::class, 'Site'], [LayoutResource::class, 'Layout']]);

it('authorises each record in a mixed bulk selection', function (string $subject, string $component, string $ability): void {
    config(['permission.teams' => false, 'auth.providers.users.model' => User::class]);
    resolve(PermissionRegistrar::class)->teams = false;
    $site = Site::factory()->createOne();
    $allowed = $subject === 'Site' ? $site : Layout::factory()->site($site)->createOne();
    $denied = $subject === 'Site' ? Site::factory()->createOne() : Layout::factory()->site($site)->createOne();
    if ($ability === 'restore') {
        $allowed->delete();
        $denied->delete();
    }

    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect($subject === 'Site' ? [$allowed->id, $denied->id] : [$site->id]);
    foreach (['ViewAny:' . $subject, ucfirst($ability) . 'Any:' . $subject, ucfirst($ability) . ':' . $subject] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    test()->actingAs($actor);
    Gate::before(static function (User $user, string $requestedAbility, array $arguments) use ($actor, $ability, $denied): ?bool {
        $record = $arguments[0] ?? null;

        return $user->is($actor) && $requestedAbility === $ability && $record instanceof Model && $record->is($denied)
            ? false
            : null;
    });

    expect(Gate::allows($ability . 'Any', $allowed::class))->toBeTrue()
        ->and(Gate::allows($ability, $allowed))->toBeTrue()
        ->and(Gate::allows($ability, $denied))->toBeFalse();

    $table = Livewire::test($component);
    if ($ability === 'restore') {
        $table->filterTable('trashed', true);
    }

    $table->assertCanSeeTableRecords([$allowed, $denied])
        ->callTableBulkAction($ability, [$denied, $allowed]);

    expect($allowed->fresh()->trashed())->toBe($ability === 'delete')
        ->and($denied->fresh()->trashed())->toBe($ability === 'restore');
})->with([
    'sites' => ['Site', ListSites::class],
    'layouts' => ['Layout', ListLayouts::class],
])->with(['restore', 'delete']);

it('leaves deleted records untouched when only bulk restore is permitted', function (string $subject, string $component): void {
    $site = Site::factory()->createOne();
    $record = $subject === 'Site' ? $site : Layout::factory()->site($site)->createOne();
    $record->delete();

    $actor = authorisationGapUser($site, ['ViewAny:' . $subject, 'RestoreAny:' . $subject]);
    test()->actingAs($actor);

    expect(Gate::allows('restoreAny', $record::class))->toBeTrue()
        ->and(Gate::allows('restore', $record))->toBeFalse();

    Livewire::test($component)
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$record])
        ->callTableBulkAction('restore', [$record]);

    expect($record->fresh()->trashed())->toBeTrue();
})->with([
    'sites' => ['Site', ListSites::class],
    'layouts' => ['Layout', ListLayouts::class],
]);

it('preserves global user visibility and fails closed without an authenticated actor', function (): void {
    $site = Site::factory()->create();
    $local = authorisationGapUser($site);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $admin = authorisationGapUser($site);
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    test()->actingAs($admin);

    expect(UserResource::getEloquentQuery()->whereKey($local->id)->exists())->toBeTrue();
    auth()->logout();
    expect(UserResource::getEloquentQuery()->exists())->toBeFalse();
});
