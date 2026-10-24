<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\AssignPermissionsToRole;
use Capell\Admin\Actions\EnsureCapellPermissionsAction;
use Capell\Admin\Actions\GrantCapellDefaultRolePermissionsAction;
use Capell\Admin\Actions\SeedDefaultRolesAction;
use Capell\Admin\Actions\Shield\ResolveDefaultGlobalResourcePermissionsAction;
use Capell\Admin\Actions\Shield\ResolveDefaultRolePermissionsAction;
use Capell\Admin\Enums\PermissionSyncMode;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Policies\BlueprintPolicy;
use Capell\Admin\Policies\LanguagePolicy;
use Capell\Admin\Policies\LayoutPolicy;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Policies\RolePolicy;
use Capell\Admin\Policies\SiteDomainPolicy;
use Capell\Admin\Policies\SitePolicy;
use Capell\Admin\Policies\ThemePolicy;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Tests\Fixtures\Models\AttributedRole;
use Capell\Admin\Tests\Fixtures\Models\InheritedAttributedRole;
use Capell\Admin\Tests\Fixtures\Policies\DenyRolePolicy;
use Capell\Core\Models\BlockTemplate;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin policies live in `Capell\Admin\Policies\*` but they gate models in
 * `Capell\Core\Models\*`. Laravel's convention-based policy discovery only
 * finds policies in `App\Policies\{Model}Policy` — it will NOT resolve
 * admin-package policies. Filament's resource system registers them for
 * HTTP routes going through a Filament panel, but anything outside that
 * (Actions invoked from CLI / jobs / bulk flows) falls back to Laravel's
 * Gate, which then returns "denied by default" for every ability.
 *
 * AdminServiceProvider::registerPolicies() must therefore globally register
 * every policy via `Gate::policy()`. This test guards that contract.
 */
it('registers every admin policy globally via the service provider', function (): void {
    expect(Gate::getPolicyFor(Page::class))->toBeInstanceOf(PagePolicy::class)
        ->and(Gate::getPolicyFor(Layout::class))->toBeInstanceOf(LayoutPolicy::class)
        ->and(Gate::getPolicyFor(Site::class))->toBeInstanceOf(SitePolicy::class)
        ->and(Gate::getPolicyFor(Blueprint::class))->toBeInstanceOf(BlueprintPolicy::class)
        ->and(Gate::getPolicyFor(Language::class))->toBeInstanceOf(LanguagePolicy::class)
        ->and(Gate::getPolicyFor(Theme::class))->toBeInstanceOf(ThemePolicy::class)
        ->and(Gate::getPolicyFor(SiteDomain::class))->toBeInstanceOf(SiteDomainPolicy::class);

    if (class_exists(RedirectPolicy::class)) {
        expect(Gate::getPolicyFor(PageUrl::class))->toBeInstanceOf(RedirectPolicy::class);
    }
});

it('lets a site administrator manage domains using the existing site update permission', function (string $permission): void {
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $ownDomain = SiteDomain::factory()->site($site)->createOne();
    $foreignDomain = SiteDomain::factory()->site($foreign)->createOne();
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);
    if ($permission === 'default admin') {
        foreach (['ViewAny:Site', 'View:Site', 'Update:Site', 'UpdateOwn:Site', 'Create:Site', 'Delete:Site', 'DeleteAny:Site'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $actor->givePermissionTo(ResolveDefaultRolePermissionsAction::run('admin', 'web'));
    } else {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    test()->actingAs($actor);

    expect($actor->checkPermissionTo('Create:Site'))->toBeFalse()
        ->and($actor->checkPermissionTo('Delete:Site'))->toBeFalse()
        ->and(Gate::allows('create', [SiteDomain::class, $site]))->toBeTrue()
        ->and(Gate::allows('create', [SiteDomain::class, $foreign]))->toBeFalse()
        ->and(Gate::allows('create', SiteDomain::class))->toBeTrue()
        ->and(Gate::allows('deleteAny', [SiteDomain::class, $site]))->toBeTrue()
        ->and(Gate::allows('deleteAny', [SiteDomain::class, $foreign]))->toBeFalse();
    foreach (['update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::allows($ability, $ownDomain))->toBeTrue()
            ->and(Gate::allows($ability, $foreignDomain))->toBeFalse();
    }

    foreach (['deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
        expect(Gate::allows($ability, [SiteDomain::class, $site]))->toBeTrue()
            ->and(Gate::allows($ability, [SiteDomain::class, $foreign]))->toBeFalse()
            ->and(Gate::allows($ability, SiteDomain::class))->toBeTrue();
    }
})->with(['Update:Site', 'UpdateOwn:Site', 'default admin']);

it('denies domain management to assigned users without site permissions', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->site($site)->createOne();
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);
    expect(Gate::allows('create', [SiteDomain::class, $site]))->toBeFalse()
        ->and(Gate::allows('create', SiteDomain::class))->toBeFalse()
        ->and(Gate::allows('deleteAny', [SiteDomain::class, $site]))->toBeFalse()
        ->and(Gate::allows('update', $domain))->toBeFalse()
        ->and(Gate::allows('delete', $domain))->toBeFalse();
});

it('preserves default admin global resource parity and keeps editors without access', function (string $defaults, ResourceEnum $resource): void {
    EnsureCapellPermissionsAction::run();
    AssignPermissionsToRole::run(resources: [$resource]);
    $admin = Role::findOrCreate('admin', 'web');
    $editor = Role::findOrCreate('editor', 'web');
    $admin->syncPermissions([]);
    $editor->syncPermissions([]);
    if ($defaults === 'fresh install') {
        $admin->givePermissionTo(ResolveDefaultRolePermissionsAction::run('admin', 'web'));
        $editor->givePermissionTo(ResolveDefaultRolePermissionsAction::run('editor', 'web'));
    } elseif ($defaults === 'seeder') {
        SeedDefaultRolesAction::run();
    } else {
        GrantCapellDefaultRolePermissionsAction::run($defaults === 'install grant' ? PermissionSyncMode::Install : PermissionSyncMode::Upgrade);
    }

    $model = $resource->value::getModel();
    $record = $model::factory()->createOne();
    $administrator = User::factory()->createOne()->assignRole($admin);
    $author = User::factory()->createOne()->assignRole($editor);
    foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'] as $ability) {
        expect(Gate::forUser($administrator)->allows($ability, $model))->toBeTrue()
            ->and(Gate::forUser($author)->allows($ability, $model))->toBeFalse();
    }

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
        expect(Gate::forUser($administrator)->allows($ability, $record))->toBeTrue()
            ->and(Gate::forUser($author)->allows($ability, $record))->toBeFalse();
    }

    $author->givePermissionTo(ResolveDefaultGlobalResourcePermissionsAction::run($resource));

    foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'] as $ability) {
        expect(Gate::forUser($author)->allows($ability, $model))->toBeTrue();
    }

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
        expect(Gate::forUser($author)->allows($ability, $record))->toBeTrue();
    }
})->with(['fresh install', 'seeder', 'install grant', 'upgrade grant'])->with([ResourceEnum::Theme, ResourceEnum::Blueprint, ResourceEnum::Language]);

it('preserves explicit denying host policies for a model or its ancestor during provider boot', function (bool $ancestor): void {
    $actor = User::factory()->createOne();
    $actor->givePermissionTo(Permission::findOrCreate('Create:Language', 'web'));

    $gate = new Illuminate\Auth\Access\Gate(app(), fn () => $actor);
    Gate::swap($gate);
    $policy = new class
    {
        public function create(User $user): bool
        {
            return false;
        }
    };
    $model = $ancestor ? Model::class : Language::class;
    Gate::policy($model, $policy::class);
    $provider = expectPresent(app()->getProvider(AdminServiceProvider::class));
    $provider->callBootedCallbacks();

    expect(Gate::getPolicyFor(Language::class))->toBeInstanceOf($policy::class)
        ->and(Gate::forUser($actor)->allows('create', Language::class))->toBeFalse();
})->with([false, true]);

it('preserves explicit denying host policies when the admin provider boots', function (string $model, string $subject): void {
    $model = $model === 'configured-role' ? Utils::getRoleModel() : $model;
    $actor = User::factory()->createOne();
    $actor->givePermissionTo(Permission::findOrCreate('ViewAny:' . $subject, 'web'));
    if ($subject === 'Role') {
        // The test host explicitly registers its legacy Role policy.
        $actor->givePermissionTo(Permission::findOrCreate('view_any_role', 'web'));
    }

    expect(Gate::forUser($actor)->allows('viewAny', $model))->toBeTrue();
    $policy = new class
    {
        public function viewAny(User $user): bool
        {
            return false;
        }
    };
    Gate::policy($model, $policy::class);
    expect(Gate::forUser($actor)->denies('viewAny', $model))->toBeTrue();
    $provider = expectPresent(app()->getProvider(AdminServiceProvider::class));
    $provider->callBootedCallbacks();
    expect(Gate::getPolicyFor($model))->toBeInstanceOf($policy::class)
        ->and(Gate::forUser($actor)->denies('viewAny', $model))->toBeTrue();
})->with([
    [Activity::class, 'Activity'],
    [BlockTemplate::class, 'BlockTemplate'],
    ['configured-role', 'Role'],
]);

it('registers defaults despite convention based policy discovery', function (): void {
    $actor = User::factory()->createOne();
    $actor->givePermissionTo(Permission::findOrCreate('ViewAny:Role', 'web'));

    $gate = new Illuminate\Auth\Access\Gate(app(), fn (): User => $actor);
    $gate->guessPolicyNamesUsing(fn (string $model): string => Capell\Tests\Fixtures\Policies\RolePolicy::class);
    Gate::swap($gate);
    $model = Utils::getRoleModel();
    expect(Gate::getPolicyFor($model))->toBeInstanceOf(Capell\Tests\Fixtures\Policies\RolePolicy::class)
        ->and(Gate::policies())->toBe([]);
    expectPresent(app()->getProvider(AdminServiceProvider::class))->callBootedCallbacks();
    expect(Gate::getPolicyFor($model))->toBeInstanceOf(RolePolicy::class)
        ->and(Gate::allows('viewAny', $model))->toBeTrue();
});

it('preserves attributed denying host policies on configured models and their ancestors', function (bool $inherited): void {
    $modelClass = $inherited ? InheritedAttributedRole::class : AttributedRole::class;
    resolve(PermissionRegistrar::class)->setRoleClass($modelClass);
    $actor = User::factory()->createOne();
    $actor->givePermissionTo(Permission::findOrCreate('ViewAny:Role', 'web'));

    $gate = new Illuminate\Auth\Access\Gate(app(), fn (): User => $actor);
    Gate::swap($gate);

    expect(Gate::getPolicyFor($modelClass))->toBeInstanceOf(DenyRolePolicy::class)
        ->and(Gate::denies('viewAny', $modelClass))->toBeTrue();
    expectPresent(app()->getProvider(AdminServiceProvider::class))->callBootedCallbacks();
    expect(Gate::getPolicyFor($modelClass))->toBeInstanceOf(DenyRolePolicy::class)
        ->and(Gate::denies('viewAny', $modelClass))->toBeTrue();
})->with([false, true]);
