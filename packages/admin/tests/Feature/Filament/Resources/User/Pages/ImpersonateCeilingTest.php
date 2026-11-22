<?php

declare(strict_types=1);

use Capell\Admin\Actions\InstallImpersonationPermissionAction;
use Capell\Admin\Filament\Resources\Users\Pages\ListUsers;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Admin\Tests\Support\SiteScopedUserFactory;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use STS\FilamentImpersonate\Actions\Impersonate;
use STS\FilamentImpersonate\Facades\Impersonation;

uses(CreatesAdminUser::class)
    ->group('user');

beforeEach(function (): void {
    config(['permission.teams' => true, 'auth.providers.users.model' => RealSiteScopedAdminResourceUser::class]);
    resolve(PermissionRegistrar::class)->teams = true;
    test()->site = Site::factory()->createOne();
    test()->otherSite = Site::factory()->createOne();

    resolve(PermissionRegistrar::class)->setPermissionsTeamId(test()->site->getKey());

    InstallImpersonationPermissionAction::run();
    PermissionTeamContext::run(test()->otherSite->getKey(), function (): void {
        Role::findOrCreate('test-site-member', 'web');
    });
    foreach (['ViewAny:User', 'Update:Page', 'Delete:Page', 'Update:Site'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

afterEach(function (): void {
    auth()->logout();
    resolve(PermissionRegistrar::class)->setPermissionsTeamId(null);
    resolve(PermissionRegistrar::class)->teams = false;
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** @param list<string> $extra */
function impersonationActor(Site $site, array $extra = []): RealSiteScopedAdminResourceUser
{
    return SiteScopedUserFactory::create($site, array_values([
        InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE,
        'ViewAny:User',
        'Update:Page',
        ...$extra,
    ]));
}

/** @param list<string> $permissions */
function giveOnSite(RealSiteScopedAdminResourceUser $user, Site $site, array $permissions): void
{
    PermissionTeamContext::run($site->getKey(), function () use ($user, $permissions): void {
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }
    }, $user);
}

/** Calls the package action's entry point directly, as a forged request that skips the hidden button would. */
function forgeImpersonation(RealSiteScopedAdminResourceUser $actor, RealSiteScopedAdminResourceUser $target): mixed
{
    test()->actingAs($actor);

    return Impersonate::make()->impersonate($target);
}

it('refuses to impersonate a user who holds a stronger role on a site the actor cannot access', function (): void {
    $actor = impersonationActor(test()->site);
    $target = SiteScopedUserFactory::create(test()->site, ['Update:Page']);
    $target->assignRoleForSite(test()->otherSite, 'test-site-member');
    giveOnSite($target, test()->otherSite, ['Delete:Page', 'Update:Site']);

    test()->actingAs($actor);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden(Impersonate::getDefaultName() ?? 'impersonate', $target);

    expect(forgeImpersonation($actor, $target))->toBeFalse();

    expect(Impersonation::isImpersonating())->toBeFalse()
        ->and(auth()->id())->toBe($actor->getKey());
});

it('refuses a target holding one permission on the shared site that the actor lacks', function (): void {
    $actor = impersonationActor(test()->site);
    $target = SiteScopedUserFactory::create(test()->site, ['Update:Page']);
    giveOnSite($target, test()->site, ['Delete:Page']);

    expect(forgeImpersonation($actor, $target))->toBeFalse();

    expect(Impersonation::isImpersonating())->toBeFalse()
        ->and(auth()->id())->toBe($actor->getKey());
});

it('refuses a target who holds an account-wide permission the actor lacks', function (): void {
    $actor = impersonationActor(test()->site);
    $target = SiteScopedUserFactory::create(test()->site);
    PermissionTeamContext::run(null, fn (): RealSiteScopedAdminResourceUser => $target->givePermissionTo('Delete:Page'), $target);

    expect(forgeImpersonation($actor, $target))->toBeFalse();

    expect(Impersonation::isImpersonating())->toBeFalse();
});

it('does not treat an account-wide role short of super admin as unlimited', function (): void {
    // Only the super admin role makes an actor global. An editor whose role is
    // held account-wide still has a permission ceiling, and it is compared.
    $actor = SiteScopedUserFactory::create(test()->site);
    $target = SiteScopedUserFactory::create(test()->site);
    PermissionTeamContext::run(null, function () use ($actor, $target): void {
        $actor->givePermissionTo([InstallImpersonationPermissionAction::PERMISSION_IMPERSONATE, 'ViewAny:User', 'Update:Page']);
        $target->givePermissionTo(['Update:Page', 'Delete:Page', 'Update:Site']);
    }, $actor);

    expect(forgeImpersonation($actor, $target))->toBeFalse();

    expect(Impersonation::isImpersonating())->toBeFalse()
        ->and(auth()->id())->toBe($actor->getKey());
});

it('still impersonates a target confined to the actor site with a subset of the actor permissions', function (): void {
    $actor = impersonationActor(test()->site);
    $target = SiteScopedUserFactory::create(test()->site, ['Update:Page']);

    test()->actingAs($actor);

    Livewire::test(ListUsers::class)
        ->assertTableActionVisible(Impersonate::getDefaultName() ?? 'impersonate', $target)
        ->callTableAction(Impersonate::getDefaultName() ?? 'impersonate', $target);

    expect(Impersonation::isImpersonating())->toBeTrue()
        ->and(session('impersonated_by'))->toBe($actor->getKey());
});

it('lets an actor assigned to both sites impersonate a target spanning them within their rights', function (): void {
    $actor = impersonationActor(test()->site);
    $actor->assignRoleForSite(test()->otherSite, 'test-site-member');
    giveOnSite($actor, test()->otherSite, ['Delete:Page']);
    $target = SiteScopedUserFactory::create(test()->site, ['Update:Page']);
    $target->assignRoleForSite(test()->otherSite, 'test-site-member');
    giveOnSite($target, test()->otherSite, ['Delete:Page']);

    expect($target->canBeImpersonated($actor))->toBeTrue();
});
