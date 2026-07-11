<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Layouts\RelationManagers\PagesRelationManager;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Livewire;

/** @param SupportCollection<int, int> $assignedSiteIds */
function createScopedUserForLayoutPagesRelationManagerTest(SupportCollection $assignedSiteIds): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<self>> */
        use HasFactory;

        /** @var SupportCollection<int, int> */
        public SupportCollection $assignedSiteIds;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        /** @return SupportCollection<int, int> */
        public function getAssignedSiteIds(): SupportCollection
        {
            return $this->assignedSiteIds;
        }

        public function isGlobalAdmin(): bool
        {
            return false;
        }

        public function hasRole(string $role): bool
        {
            return true;
        }
    };

    $user->forceFill([
        'name' => 'Scoped Layout Relation User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;

    return $user;
}

it('can list pages for a layout', function (): void {
    test()->actingAsAdmin();

    $layout = Layout::factory()
        ->has(Page::factory()->withTranslations()->count(5), 'pages')
        ->create();

    $page = $layout->pages->first();

    Livewire::test(PagesRelationManager::class, [
        'ownerRecord' => $layout,
        'pageClass' => EditLayout::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->assertCanSeeTableRecords($layout->pages)
        ->assertTableColumnStateSet('name', [$page->name], record: $page);
});

it('shows page guidance when no pages use the layout', function (): void {
    test()->actingAsAdmin();

    $layout = Layout::factory()->createOne();

    Livewire::test(PagesRelationManager::class, [
        'ownerRecord' => $layout,
        'pageClass' => EditLayout::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_layout_pages'))
        ->assertSee(__('capell-admin::generic.no_layout_pages_description'));
});

it('can search pages for a layout', function (): void {
    test()->actingAsAdmin();

    $layout = Layout::factory()
        ->has(Page::factory()->withTranslations()->count(5), 'pages')
        ->create();

    $page = $layout->pages->random();

    Livewire::test(PagesRelationManager::class, [
        'ownerRecord' => $layout,
        'pageClass' => EditLayout::class,
    ])
        ->assertSuccessful()
        ->searchTable($page->getKey())
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$page]);
});

it('limits layout pages relation to assigned sites for non-global users', function (): void {
    $layout = Layout::factory()->createOne();
    $assignedSite = Site::factory()->withTranslations()->create();
    $hiddenSite = Site::factory()->withTranslations()->create();
    $assignedPage = Page::factory()->recycle($assignedSite)->for($layout)->withTranslations()->create();
    $hiddenPage = Page::factory()->recycle($hiddenSite)->for($layout)->withTranslations()->create();

    test()->actingAs(createScopedUserForLayoutPagesRelationManagerTest(collect([$assignedSite->getKey()])));

    Livewire::test(PagesRelationManager::class, [
        'ownerRecord' => $layout,
        'pageClass' => EditLayout::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$assignedPage])
        ->assertCanNotSeeTableRecords([$hiddenPage]);
});

it('denies layout pages relation for non-global users without assigned sites', function (): void {
    $layout = Layout::factory()->createOne();
    Page::factory()->for($layout)->withTranslations()->count(2)->create();

    test()->actingAs(createScopedUserForLayoutPagesRelationManagerTest(collect()));

    Livewire::test(PagesRelationManager::class, [
        'ownerRecord' => $layout,
        'pageClass' => EditLayout::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(0);
});
