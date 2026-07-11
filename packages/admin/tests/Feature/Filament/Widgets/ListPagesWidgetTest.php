<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page');

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  SupportCollection<int, int>  $assignedSiteIds
 */
function createScopedUserForListPagesFilamentWidgetTest(SupportCollection $assignedSiteIds): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<static>> */
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
    };

    $user->forceFill([
        'name' => 'Scoped Widget User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
    $user->assignedSiteIds = $assignedSiteIds;

    return $user;
}

test('see livewire component', function (): void {
    Permission::create(['name' => 'View:ListPagesFilamentWidget', 'guard_name' => 'web']);

    test()->actingAsAdmin();

    test()->authenticatedUser()->givePermissionTo('View:ListPagesFilamentWidget');

    Livewire::test(ListPagesFilamentWidget::class)->assertOk();
});

it('renders the pages widget', function (): void {
    test()->actingAsAdmin();

    Page::factory()
        ->count(5)
        ->withTranslations()
        ->create();

    Livewire::test(ListPagesFilamentWidget::class)
        ->assertOk()
        ->assertCountTableRecords(5);
});

it('renders pages with URL records that have no matching site domain', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()->withTranslations()->create();
    $language = Language::factory()->createOne();
    $page = Page::factory()
        ->recycle($site)
        ->create(['name' => 'Domainless URL page']);

    PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->create(['url' => '/domainless-url-page']);

    Livewire::test(ListPagesFilamentWidget::class)
        ->assertOk()
        ->assertSee('Domainless URL page');
});

it('limits latest pages to the selected dashboard date range', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-12 10:00:00'));
    test()->actingAsAdmin();

    $recentPage = Page::factory()
        ->withTranslations()
        ->create([
            'name' => 'Updated today',
            'updated_at' => CarbonImmutable::parse('2026-05-12 09:00:00'),
        ]);
    $olderPage = Page::factory()
        ->withTranslations()
        ->create([
            'name' => 'Updated last month',
            'updated_at' => CarbonImmutable::parse('2026-04-12 09:00:00'),
        ]);

    Livewire::test(ListPagesFilamentWidget::class, ['pageFilters' => ['date_range' => 'today']])
        ->assertOk()
        ->assertCanSeeTableRecords([$recentPage])
        ->assertCanNotSeeTableRecords([$olderPage]);
});

it('describes the dashboard period for recently updated pages', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-12 10:00:00'));
    test()->actingAsAdmin();

    Page::factory()
        ->withTranslations()
        ->create([
            'name' => 'Updated this month',
            'updated_at' => CarbonImmutable::parse('2026-05-03 09:00:00'),
        ]);

    Livewire::test(ListPagesFilamentWidget::class, ['pageFilters' => ['date_range' => 'this_month']])
        ->assertOk()
        ->assertSee('Recently updated pages')
        ->assertSee('Showing pages updated this month.');
});

it('links only the page name in the pages widget when the URL description is rendered', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->withTranslations($language, siteDomainData: [
            'default' => true,
            'domain' => 'localhost',
            'path' => null,
            'scheme' => 'http',
        ])
        ->create(['language_id' => $language->getKey()]);
    $page = Page::factory()
        ->recycle($site)
        ->create(['name' => 'Linked widget page']);

    PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->create(['url' => '/linked-widget-page']);

    Livewire::test(ListPagesFilamentWidget::class)
        ->assertOk()
        ->assertSeeHtml('<a href="' . e(GetEditPageResourceUrlAction::run($page)) . '" class="hover:underline">Linked widget page</a>')
        ->assertSeeHtml('<a href="http://localhost/linked-widget-page" class="text-xs text-gray-500 dark:text-gray-400" target="_blank">/linked-widget-page</a>');
});

it('limits latest pages to assigned sites for non-global users', function (): void {
    Gate::before(fn (): bool => true);

    $assignedSite = Site::factory()->withTranslations()->create();
    $otherSite = Site::factory()->withTranslations()->create();
    $assignedPage = Page::factory()->recycle($assignedSite)->withTranslations()->create();
    Page::factory()->recycle($otherSite)->withTranslations()->create();

    test()->actingAs(createScopedUserForListPagesFilamentWidgetTest(collect([$assignedSite->getKey()])));

    Livewire::test(ListPagesFilamentWidget::class)
        ->assertOk()
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$assignedPage]);
});

it('escapes ancestor names in the pages widget', function (): void {
    test()->actingAsAdmin();

    $parent = Page::factory()
        ->withTranslations()
        ->create(['name' => 'Parent <script>alert(1)</script>']);

    Page::factory()
        ->parent($parent)
        ->withTranslations()
        ->create(['name' => 'Child page']);

    Livewire::test(ListPagesFilamentWidget::class)
        ->assertOk()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('Parent &lt;script&gt;alert(1)&lt;/script&gt;');
});
