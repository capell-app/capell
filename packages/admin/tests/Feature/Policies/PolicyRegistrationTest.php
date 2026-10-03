<?php

declare(strict_types=1);

use Capell\Admin\Actions\Shield\ResolveDefaultRolePermissionsAction;
use Capell\Admin\Policies\BlueprintPolicy;
use Capell\Admin\Policies\LanguagePolicy;
use Capell\Admin\Policies\LayoutPolicy;
use Capell\Admin\Policies\PagePolicy;
use Capell\Admin\Policies\RedirectPolicy;
use Capell\Admin\Policies\SiteDomainPolicy;
use Capell\Admin\Policies\SitePolicy;
use Capell\Admin\Policies\ThemePolicy;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

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
