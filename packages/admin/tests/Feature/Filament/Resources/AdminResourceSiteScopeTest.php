<?php

declare(strict_types=1);

use Capell\Admin\Filament\Exports\RedirectExporter;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Admin\Filament\Resources\Redirects\RedirectResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Support\MediaScope;
use Capell\Admin\Tests\Fixtures\Models\RealSiteScopedAdminResourceUser;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @param SupportCollection<int, int> $assignedSiteIds */
function createScopedUserForAdminResourceSiteScopeTest(SupportCollection $assignedSiteIds, bool $isGlobalAdmin = false): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        public bool $globalAdmin = false;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return $this->globalAdmin;
        }

        public function hasRole(string $role): bool
        {
            return true;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Scoped User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;
    $user->globalAdmin = $isGlobalAdmin;

    return $user;
}

function createRealSiteScopedAdminResourceUser(): RealSiteScopedAdminResourceUser
{
    $user = new RealSiteScopedAdminResourceUser;

    $user->forceFill([
        'name' => 'Real Site Scoped User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->save();

    return $user;
}

function assignRealAdminResourceRoleForSite(RealSiteScopedAdminResourceUser $user, Site $site, Role $role): void
{
    DB::table('model_has_roles')->insert([
        'role_id' => $role->getKey(),
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->getKey(),
        'team_id' => $site->getKey(),
    ]);
}

function findEditorRoleForAdminResourceSiteScopeTest(): Role
{
    $role = Role::findOrCreate('editor');
    assert($role instanceof Role);

    return $role;
}

it('denies page listings for non-global users without assigned sites', function (): void {
    Page::factory()->count(2)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(PageResource::getEloquentQuery()->count())->toBe(0);
});

it('denies page global search for non-global users without assigned sites', function (): void {
    Page::factory()->count(2)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(PageResource::getGlobalSearchEloquentQuery()->count())->toBe(0);
});

it('denies site listings for non-global users without assigned sites', function (): void {
    Site::factory()->count(2)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(SiteResource::getEloquentQuery()->count())->toBe(0);
});

it('denies site global search for non-global users without assigned sites', function (): void {
    Site::factory()->count(2)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(SiteResource::getGlobalSearchEloquentQuery()->count())->toBe(0);
});

it('denies redirect listings for non-global users without assigned sites', function (): void {
    PageUrl::factory()->manualRedirect()->count(2)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(RedirectResource::getEloquentQuery()->count())->toBe(0);
});

it('denies page URL listings for non-global users without assigned sites', function (): void {
    PageUrl::factory()->count(2)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(PageUrlResource::getEloquentQuery()->count())->toBe(0);
});

it('hides site-owned layouts for non-global users without assigned sites', function (): void {
    Layout::factory()->createOne();
    Layout::factory()->site(Site::factory()->createOne())->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(LayoutResource::getEloquentQuery()->pluck('site_id')->all())->toBe([null]);
});

it('denies site-owned media listings for non-global users without assigned sites', function (): void {
    $siteOwnedPage = Page::factory()->createOne();
    Media::factory()->model($siteOwnedPage)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect()));

    expect(MediaResource::getEloquentQuery()->count())->toBe(0);
});

it('limits page listings to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $assignedPage = Page::factory()->recycle($assignedSite)->create();
    Page::factory()->recycle($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(PageResource::getEloquentQuery()->pluck('id')->all())->toBe([$assignedPage->getKey()]);
});

it('hides pages from sites where the admin has no real site role assignment', function (): void {
    Permission::findOrCreate('ViewAny:Page');

    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $assignedPage = Page::factory()->recycle($assignedSite)->create();
    $otherPage = Page::factory()->recycle($otherSite)->create();
    $admin = createRealSiteScopedAdminResourceUser();
    $editorRole = findEditorRoleForAdminResourceSiteScopeTest();

    $admin->givePermissionTo('ViewAny:Page');
    assignRealAdminResourceRoleForSite($admin, $assignedSite, $editorRole);

    test()->actingAs($admin);

    $listedPageIds = PageResource::getEloquentQuery()->pluck('id')->all();
    $searchPageIds = PageResource::getGlobalSearchEloquentQuery()->pluck('id')->all();

    expect($listedPageIds)
        ->toContain($assignedPage->getKey())
        ->not->toContain($otherPage->getKey())
        ->and($searchPageIds)
        ->toContain($assignedPage->getKey())
        ->not->toContain($otherPage->getKey());
});

it('hides the page edit screen for a site the admin is not assigned to', function (): void {
    Permission::findOrCreate('ViewAny:Page');
    Permission::findOrCreate('Update:Page');

    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $otherPage = Page::factory()->recycle($otherSite)->create();
    $admin = createRealSiteScopedAdminResourceUser();
    $editorRole = findEditorRoleForAdminResourceSiteScopeTest();

    $admin->givePermissionTo(['ViewAny:Page', 'Update:Page']);
    assignRealAdminResourceRoleForSite($admin, $assignedSite, $editorRole);

    test()->actingAs($admin);

    test()->get(PageResource::getUrl('edit', ['record' => $otherPage->getKey()]))
        ->assertForbidden();
});

it('shows page listings for a site after real site permissions are granted', function (): void {
    Permission::findOrCreate('ViewAny:Page');

    $site = Site::factory()->createOne();
    $page = Page::factory()->recycle($site)->create();
    $admin = createRealSiteScopedAdminResourceUser();
    $editorRole = findEditorRoleForAdminResourceSiteScopeTest();

    $admin->givePermissionTo('ViewAny:Page');
    assignRealAdminResourceRoleForSite($admin, $site, $editorRole);

    test()->actingAs($admin);

    expect(PageResource::getEloquentQuery()->pluck('id')->all())->toBe([$page->getKey()]);
});

it('limits site listings to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    Site::factory()->createOne();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(SiteResource::getEloquentQuery()->pluck('id')->all())->toBe([$assignedSite->getKey()]);
});

it('limits global search to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $assignedPage = Page::factory()->recycle($assignedSite)->create();
    Page::factory()->recycle($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(PageResource::getGlobalSearchEloquentQuery()->pluck('id')->all())
        ->toBe([$assignedPage->getKey()])
        ->and(SiteResource::getGlobalSearchEloquentQuery()->pluck('id')->all())
        ->toBe([$assignedSite->getKey()]);
});

it('limits redirect listings to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $assignedRedirect = PageUrl::factory()->manualRedirect()->site($assignedSite)->create();
    PageUrl::factory()->manualRedirect()->site($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(RedirectResource::getEloquentQuery()->pluck('id')->all())->toBe([$assignedRedirect->getKey()]);
});

it('limits redirect exports to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $assignedRedirect = PageUrl::factory()->manualRedirect()->site($assignedSite)->create();
    PageUrl::factory()->manualRedirect()->site($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(RedirectExporter::modifyQuery(PageUrl::query())->pluck('id')->all())->toBe([$assignedRedirect->getKey()]);
});

it('limits page URL listings to assigned sites for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $assignedPageUrl = PageUrl::factory()->site($assignedSite)->create();
    PageUrl::factory()->site($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(PageUrlResource::getEloquentQuery()->pluck('id')->all())->toBe([$assignedPageUrl->getKey()]);
});

it('limits layout listings to global and assigned-site layouts for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $globalLayout = Layout::factory()->createOne();
    $assignedLayout = Layout::factory()->site($assignedSite)->create();
    Layout::factory()->site($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(LayoutResource::getEloquentQuery()->pluck('id')->all())
        ->toBe([$globalLayout->getKey(), $assignedLayout->getKey()]);
});

it('limits media listings to global and assigned-site owners for non-global users', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $globalLayout = Layout::factory()->createOne();
    $assignedPage = Page::factory()->recycle($assignedSite)->create();
    $otherPage = Page::factory()->recycle($otherSite)->create();

    $globalLayoutMedia = Media::factory()->model($globalLayout)->create();
    $assignedPageMedia = Media::factory()->model($assignedPage)->create();
    Media::factory()->model($otherPage)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    expect(MediaResource::getEloquentQuery()->pluck('id')->all())
        ->toEqualCanonicalizing([$globalLayoutMedia->getKey(), $assignedPageMedia->getKey()]);
});

it('denies media policy access to records owned by unassigned sites', function (): void {
    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    $otherPage = Page::factory()->recycle($otherSite)->create();
    $media = Media::factory()->model($otherPage)->create();

    $user = createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()]));

    expect(MediaScope::actorCanUseMedia($user, $media))->toBeFalse();
});

it('does not reuse globally cached site tabs for non-global page listings', function (): void {
    Cache::flush();

    $assignedSite = Site::factory()->createOne();
    $otherSite = Site::factory()->createOne();
    Page::factory()->recycle($assignedSite)->create();
    Page::factory()->recycle($otherSite)->create();

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect(), isGlobalAdmin: true));
    $globalComponent = Livewire::test(ListPages::class)->instance();
    assert($globalComponent instanceof ListPages);
    expect($globalComponent->getTabs())->toHaveKey($otherSite->getKey());

    test()->actingAs(createScopedUserForAdminResourceSiteScopeTest(collect([$assignedSite->getKey()])));

    $scopedComponent = Livewire::test(ListPages::class)->instance();
    assert($scopedComponent instanceof ListPages);
    $tabs = $scopedComponent->getTabs();

    expect($tabs)
        ->toHaveKey($assignedSite->getKey())
        ->not->toHaveKey($otherSite->getKey());
});
