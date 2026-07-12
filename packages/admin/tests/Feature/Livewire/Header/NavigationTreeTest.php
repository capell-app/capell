<?php

declare(strict_types=1);

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Livewire\Header\NavigationTree;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses()->group('header-navigation');

/**
 * @param  SupportCollection<int, int>  $assignedSiteIds
 */
function createHeaderNavigationUser(SupportCollection $assignedSiteIds, bool $canViewPages = true): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        public bool $canViewPages = true;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        public function hasRole(string $role): bool
        {
            return false;
        }

        public function checkPermissionTo(string $permission): bool
        {
            return $this->canViewPages;
        }

        public function getMorphClass(): string
        {
            return User::class;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }
    };

    $user->forceFill([
        'name' => 'Header Navigation User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;
    $user->canViewPages = $canViewPages;

    return $user;
}

it('does not render page records before the dropdown is opened', function (): void {
    $site = Site::factory()->createOne();
    Page::factory()->recycle($site)->create(['name' => 'Deferred root page']);
    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    $pageQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$pageQueries): void {
        if (str_contains((string) $query->sql, 'pages')) {
            $pageQueries[] = $query->sql;
        }
    });

    Livewire::test(NavigationTree::class)
        ->assertSet('loaded', false)
        ->assertDontSee('Deferred root page');

    expect($pageQueries)->toBe([]);
});

it('renders the dropdown panel at the intended width', function (): void {
    $site = Site::factory()->createOne();
    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    Livewire::test(NavigationTree::class)
        ->assertSeeHtml('fi-dropdown-panel fi-width-xl')
        ->assertSeeHtml('wire:click="loadTree"')
        ->assertSeeHtml('wire:target="loadTree"')
        ->assertDontSeeHtml('wire:click="open"')
        ->assertDontSeeHtml('wire:target="open"')
        ->assertDontSeeHtml('w-[36rem]');
});

it('shows the site root expanded for a single permitted site and paginates root pages', function (): void {
    $site = Site::factory()->createOne(['name' => 'Primary Site']);
    SiteDomain::factory()
        ->default()
        ->site($site)
        ->create([
            'language_id' => $site->language_id,
            'domain' => 'primary.example.com',
        ]);

    for ($index = 1; $index <= 11; $index++) {
        $page = Page::factory()
            ->recycle($site)
            ->create([
                'name' => sprintf('Root page %02d', $index),
                'order' => $index,
            ]);

        if ($index === 1) {
            PageUrl::factory()
                ->site($site)
                ->page($page)
                ->create([
                    'language_id' => $site->language_id,
                    'url' => '/root-page-01',
                ]);
        }
    }

    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    $siteEditUrl = AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $site]);

    $component = Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->assertSet('singleSite', true)
        ->assertSee('Primary Site')
        ->assertSee('http://primary.example.com')
        ->assertSee('http://primary.example.com/root-page-01')
        ->assertSeeHtml('href="' . e($siteEditUrl) . '"')
        ->assertSeeHtml('aria-expanded="true"')
        ->assertSee('Root page 01')
        ->assertSee('Root page 10')
        ->assertDontSee('Root page 11')
        ->call('loadMoreRoot', $site->getKey())
        ->assertSee('Root page 11')
        ->call('toggleSite', $site->getKey())
        ->assertSet('expandedSites', [])
        ->call('loadTree');

    expect($component->get('expandedSites'))->toHaveKey($site->getKey());
});

it('shows sites first for multi-site admins and lazily loads a selected site', function (): void {
    $siteA = Site::factory()->createOne(['name' => 'Site A']);
    $siteB = Site::factory()->createOne(['name' => 'Site B']);
    Page::factory()->recycle($siteA)->create(['name' => 'Site A root']);
    Page::factory()->recycle($siteB)->create(['name' => 'Site B root']);

    Auth::login(createHeaderNavigationUser(collect([$siteA->getKey(), $siteB->getKey()])));

    $siteAEditUrl = AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $siteA]);
    $siteBEditUrl = AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $siteB]);

    $pageQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$pageQueries): void {
        if (str_contains((string) $query->sql, 'pages')) {
            $pageQueries[] = $query->sql;
        }
    });

    $component = Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->assertSet('singleSite', false)
        ->assertSet('expandedSites', [])
        ->assertSet('rootBranches', [])
        ->assertSee('Site A')
        ->assertSee('Site B')
        ->assertSeeHtml('href="' . e($siteAEditUrl) . '"')
        ->assertSeeHtml('href="' . e($siteBEditUrl) . '"')
        ->assertDontSee('Site A root');

    expect($pageQueries)->toBe([]);

    $component
        ->call('toggleSite', $siteA->getKey())
        ->assertSee('Site A root')
        ->assertDontSee('Site B root');
});

it('does not leak roots for unassigned site ids', function (): void {
    $assignedSite = Site::factory()->createOne(['name' => 'Assigned Site']);
    $unassignedSite = Site::factory()->createOne(['name' => 'Private Site']);
    Page::factory()->recycle($assignedSite)->create(['name' => 'Assigned root']);
    Page::factory()->recycle($unassignedSite)->create(['name' => 'Private root']);

    Auth::login(createHeaderNavigationUser(collect([$assignedSite->getKey()])));

    Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->call('toggleSite', $unassignedSite->getKey())
        ->assertDontSee('Private Site')
        ->assertDontSee('Private root')
        ->call('loadMoreRoot', $unassignedSite->getKey())
        ->assertDontSee('Private Site')
        ->assertDontSee('Private root');
});

it('loads children for expanded pages ten at a time', function (): void {
    $site = Site::factory()->createOne();
    $parent = Page::factory()->recycle($site)->create(['name' => 'News']);

    for ($index = 1; $index <= 11; $index++) {
        Page::factory()
            ->recycle($site)
            ->parent($parent)
            ->create([
                'name' => sprintf('News article %02d', $index),
                'order' => $index,
            ]);
    }

    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->call('togglePage', $parent->getKey(), $site->getKey())
        ->assertSee('News article 01')
        ->assertSee('News article 10')
        ->assertDontSee('News article 11')
        ->call('loadMoreChildren', $parent->getKey(), $site->getKey())
        ->assertSee('News article 11');
});

it('links admin rows to edit pages and hides visit URLs for pages that are not publicly reachable', function (): void {
    $site = Site::factory()->createOne();
    SiteDomain::factory()
        ->default()
        ->site($site)
        ->create([
            'language_id' => $site->language_id,
            'domain' => 'primary.example.com',
        ]);

    $publishedPage = Page::factory()
        ->published()
        ->recycle($site)
        ->create(['name' => 'Published landing page']);
    PageUrl::factory()
        ->site($site)
        ->page($publishedPage)
        ->create([
            'language_id' => $site->language_id,
            'url' => '/published-landing-page',
            'status' => true,
        ]);

    $pendingPage = Page::factory()
        ->pending()
        ->recycle($site)
        ->create(['name' => 'Kitchen sink showcase']);
    PageUrl::factory()
        ->site($site)
        ->page($pendingPage)
        ->create([
            'language_id' => $site->language_id,
            'url' => '/kitchen-sink-showcase',
            'status' => true,
        ]);

    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->assertSee('Published landing page')
        ->assertSee('Kitchen sink showcase')
        ->assertSeeHtml('href="' . e(GetEditPageResourceUrlAction::run($publishedPage)) . '"')
        ->assertSeeHtml('href="' . e(GetEditPageResourceUrlAction::run($pendingPage)) . '"')
        ->assertSee('http://primary.example.com/published-landing-page')
        ->assertDontSee('http://primary.example.com/kitchen-sink-showcase');
});

it('collapses expanded pages and paginates then clears header search results', function (): void {
    $site = Site::factory()->createOne();
    $parent = Page::factory()->recycle($site)->create(['name' => 'Knowledge']);
    Page::factory()->recycle($site)->parent($parent)->create(['name' => 'Knowledge child']);

    for ($index = 1; $index <= 11; $index++) {
        Page::factory()
            ->recycle($site)
            ->create([
                'name' => sprintf('Boris search result %02d', $index),
                'order' => $index,
            ]);
    }

    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->call('togglePage', $parent->getKey(), $site->getKey())
        ->assertSet('expandedPages.' . $parent->getKey(), true)
        ->assertSet(sprintf('pageBranches.%s.items.0.name', $parent->getKey()), 'Knowledge child')
        ->call('togglePage', $parent->getKey(), $site->getKey())
        ->assertSet('expandedPages', [])
        ->set('search', 'Boris')
        ->assertSee('Boris search result 01')
        ->assertSee('Boris search result 10')
        ->assertDontSee('Boris search result 11')
        ->call('loadMoreSearchResults')
        ->assertSee('Boris search result 11')
        ->call('clearSearch')
        ->assertSet('search', '')
        ->assertSet('searchResults.paths', []);
});

it('searches across all permitted sites and renders visible ancestor paths', function (): void {
    $siteA = Site::factory()->createOne(['name' => 'Politics']);
    $siteB = Site::factory()->createOne(['name' => 'Archive']);
    $parentA = Page::factory()->recycle($siteA)->create(['name' => 'Parent 1']);
    $parentB = Page::factory()->recycle($siteB)->create(['name' => 'Parent 2']);
    Page::factory()->recycle($siteA)->parent($parentA)->create(['name' => 'Boris profile']);
    Page::factory()->recycle($siteB)->parent($parentB)->create(['name' => 'Boris archive']);

    Auth::login(createHeaderNavigationUser(collect([$siteA->getKey(), $siteB->getKey()])));

    Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->set('search', 'Boris')
        ->assertSee('Politics')
        ->assertSee('Archive')
        ->assertSee('Parent 1')
        ->assertSee('Parent 2')
        ->assertSee('Boris profile')
        ->assertSee('Boris archive');
});

it('suppresses search results when a required ancestor is not visible', function (): void {
    $site = Site::factory()->createOne();
    $restrictedType = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'restricted-header-navigation-viewer', 'guard_name' => 'web']);
    $restrictedType->roleRestrictions()->create(['role_id' => $role->getKey()]);

    $restrictedParent = Page::factory()
        ->recycle($site)
        ->blueprint($restrictedType)
        ->create(['name' => 'Restricted parent']);

    Page::factory()
        ->recycle($site)
        ->parent($restrictedParent)
        ->create(['name' => 'Boris hidden path']);

    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    Livewire::test(NavigationTree::class)
        ->call('loadTree')
        ->set('search', 'Boris')
        ->assertDontSee('Restricted parent')
        ->assertDontSee('Boris hidden path');
});

it('does not mark a page as expandable when its only children are hidden by role restrictions', function (): void {
    $site = Site::factory()->createOne();
    $parent = Page::factory()->recycle($site)->create(['name' => 'Parent']);
    $restrictedType = Blueprint::factory()->page()->create();
    $role = Role::create(['name' => 'restricted-header-navigation-child-viewer', 'guard_name' => 'web']);
    $restrictedType->roleRestrictions()->create(['role_id' => $role->getKey()]);

    Page::factory()
        ->recycle($site)
        ->parent($parent)
        ->blueprint($restrictedType)
        ->create(['name' => 'Hidden child']);

    Auth::login(createHeaderNavigationUser(collect([$site->getKey()])));

    $component = Livewire::test(NavigationTree::class)
        ->call('loadTree');

    $branch = $component->get('rootBranches')[$site->getKey()];

    expect($branch['items'][0]['name'])->toBe('Parent')
        ->and($branch['items'][0]['has_children'])->toBeFalse();
});
