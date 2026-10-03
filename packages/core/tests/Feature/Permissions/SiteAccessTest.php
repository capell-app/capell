<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Permissions\SiteAccess;
use Capell\Core\Tests\Support\Models\HasSitePermissionsTestUser;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
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

it('memoises current access by actor and team within the request', function (): void {
    $alpha = Site::factory()->create();
    $beta = Site::factory()->create();
    $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Memoised', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $role = Role::findOrCreate('memoised-editor', 'web');
    $actor->assignRoleForSite($alpha, $role);
    $actor->assignRoleForSite($beta, $role);

    test()->actingAs($actor);
    setPermissionsTeamId($alpha->id);
    $first = SiteAccess::current();
    expect(SiteAccess::current())->toBe($first)->and($first->allowedSiteIds())->toBe([$alpha->id]);

    setPermissionsTeamId($beta->id);
    $second = SiteAccess::current();
    expect($second)->not->toBe($first)->and($second->allowedSiteIds())->toBe([$beta->id])
        ->and(SiteAccess::current())->toBe($second);
    setPermissionsTeamId($alpha->id);
    expect(SiteAccess::current())->toBe($first);
    $otherActor = HasSitePermissionsTestUser::query()->create(['name' => 'Other actor', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $otherActor->assignRoleForSite($beta, $role);

    test()->actingAs($otherActor);
    expect(SiteAccess::current())->not->toBe($first)->and(SiteAccess::current()->allowedSiteIds())->toBe([]);
    test()->actingAs($actor);
    expect(SiteAccess::current())->toBe($first);
    $actor->setAttribute('remember_token', null);
    auth()->logout();
    expect(SiteAccess::current()->allowedSiteIds())->toBe([]);
});

it('does not retain current access across request replacement in a long lived application', function (): void {
    $alpha = Site::factory()->create();
    $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Worker', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $role = Role::findOrCreate('request-editor', 'web');
    $actor->assignRoleForSite($alpha, $role);
    test()->actingAs($actor);
    setPermissionsTeamId($alpha->id);
    $first = SiteAccess::current();
    expect(SiteAccess::current())->toBe($first);
    $request = request();
    try {
        app()->instance('request', Request::create('/next-request'));
        $actor->removeRoleForSite($alpha, $role);
        expect(SiteAccess::current())->not->toBe($first)
            ->and(SiteAccess::current()->allowedSiteIds())->toBe([]);
    } finally {
        app()->instance('request', $request);
    }
});

it('captures active team access and makes cross site membership an explicit choice', function (): void {
    $alpha = Site::factory()->create();
    $beta = Site::factory()->create();
    $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $role = Role::findOrCreate('site-access-editor', 'web');
    $actor->assignRoleForSite($alpha, $role);
    $actor->assignRoleForSite($beta, $role);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($alpha->getKey());
    $snapshot = SiteAccess::forActor($actor);

    expect($snapshot->allowedSiteIds())->toBe([$alpha->getKey()])
        ->and($snapshot->query(Site::class)->pluck('id')->all())->toBe([$alpha->getKey()])
        ->and($snapshot->can($beta))->toBeFalse()
        ->and(SiteAccess::forActor($actor, acrossAssignedSites: true)->allowedSiteIds())->toBe([$alpha->getKey(), $beta->getKey()]);

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($beta->getKey());

    expect($snapshot->can($alpha))->toBeTrue()
        ->and(SiteAccess::forActor($actor)->can($alpha))->toBeFalse()
        ->and(SiteAccess::forActor($actor)->can($beta))->toBeTrue();
});

it('keeps null team global access independent of the selected team', function (): void {
    $site = Site::factory()->create();
    $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Global', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $actor->assignGlobalRole('super_admin');

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());
    $access = SiteAccess::forActor($actor);

    expect($access->allowedSiteIds())->toBeNull()
        ->and($access->can($site))->toBeTrue()
        ->and($access->query(Site::class)->count())->toBe(1);
});

it('denies actors without a site membership contract and guests', function (): void {
    $site = Site::factory()->create();
    $layout = Layout::factory()->create(['site_id' => null]);

    foreach ([null, new GenericUser(['id' => 999])] as $actor) {
        $access = SiteAccess::forActor($actor);
        expect($access->allowedSiteIds())->toBe([])
            ->and($access->can($site))->toBeFalse()
            ->and($access->query(Site::class)->count())->toBe(0);
    }

    expect(SiteAccess::forActor(null)->canUseLayout($layout))->toBeFalse()
        ->and(SiteAccess::forActor(null)->query(Layout::class)->count())->toBe(0);
});

it('uses the same access for shared layouts and their media including translations', function (): void {
    $site = Site::factory()->create();
    $shared = Layout::factory()->create(['site_id' => null]);
    $foreign = Layout::factory()->for($site)->create();
    $translation = Translation::factory()->translatable($shared)->create();
    $media = Media::factory()->model($shared)->create();
    $translatedMedia = Media::factory()->model($translation)->create();
    $foreignMedia = Media::factory()->model($foreign)->create();
    $actor = new GenericUser(['id' => 999]);
    $access = SiteAccess::forActor($actor);

    expect($access->canUseLayout($shared))->toBeTrue()
        ->and($access->canUseLayout($foreign))->toBeFalse()
        ->and($access->canUseMedia($media))->toBeTrue()
        ->and($access->canUseMedia($translatedMedia))->toBeTrue()
        ->and($access->canUseMedia($foreignMedia))->toBeFalse()
        ->and($access->query(Media::class)->pluck('id')->all())->toEqualCanonicalizing([$media->getKey(), $translatedMedia->getKey()]);
});

it('never lets a shared theme override more specific foreign event attribution', function (): void {
    $alpha = Site::factory()->create();
    $beta = Site::factory()->theme($alpha->theme)->create();
    $page = Page::factory()->site($beta)->create();
    PublicRenderContractEvent::query()->create(['result' => 'failed', 'page_id' => $page->getKey(), 'theme_id' => $alpha->theme_id]);
    $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Scoped', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $actor->assignRoleForSite($alpha, Role::findOrCreate('event-reader', 'web'));

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($alpha->getKey());

    expect(SiteAccess::forActor($actor)->query(PublicRenderContractEvent::class)->count())->toBe(0);
});

it('counts layout groups using the same site access as layout records', function (): void {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    Layout::factory()->site($site)->create(['group' => 'private']);
    Layout::factory()->site($other)->create(['group' => 'private']);
    $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Groups', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
    $actor->assignRoleForSite($site, Role::findOrCreate('layout-groups', 'web'));

    resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());

    expect(SiteAccess::forActor($actor)->layoutGroups())->toBe(['private' => 'private (1)'])
        ->and(SiteAccess::forActor(null)->layoutGroups())->toBe([]);
});

it('denies unsupported media owners consistently in policy and query access', function (): void {
    $originalMorphMap = Relation::morphMap();
    Relation::morphMap(['site-access-taxonomy' => Taxonomy::class]);

    try {
        $site = Site::factory()->create();
        $taxonomy = Taxonomy::factory()->create(['site_id' => $site->getKey()]);
        $media = Media::factory()->model($taxonomy)->create();
        $actor = HasSitePermissionsTestUser::query()->create(['name' => 'Media', 'email' => fake()->unique()->safeEmail(), 'password' => bcrypt('password')]);
        $actor->assignRoleForSite($site, Role::findOrCreate('media-owner', 'web'));

        resolve(PermissionRegistrar::class)->setPermissionsTeamId($site->getKey());
        $access = SiteAccess::forActor($actor);

        expect($access->canUseMedia($media))->toBeFalse()
            ->and($access->query(Media::class)->whereKey($media->getKey())->exists())->toBeFalse()
            ->and($access->canUseRecord($taxonomy))->toBeTrue();
    } finally {
        Relation::morphMap($originalMorphMap, false);
    }
});
