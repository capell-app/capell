<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Pages\PageTableStatusResolver;
use Capell\Admin\Data\Pages\PageTableStatusData;
use Capell\Admin\Enums\CacheEnum;
use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\Table\ReplicatePageAction;
use Capell\Admin\Filament\Components\Tables\Actions\VisitUrlAction;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('supports common page table controls', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->recycle($language)
        ->withTranslations()
        ->create();
    Page::factory()->createOne(['name' => 'Outside site page']);

    $defaultType = Blueprint::factory()->page()->default()->create();
    $alternateType = Blueprint::factory()->page()->create();

    $pages = Page::factory()
        ->count(5)
        ->recycle($language)
        ->recycle($site)
        ->withTranslations()
        ->sequence(fn (Sequence $sequence): array => [
            'name' => sprintf('Language(%d)', $sequence->index),
            'blueprint_id' => $sequence->index === 0 ? $alternateType->getKey() : $defaultType->getKey(),
        ])
        ->create();

    $alternateTypePage = $pages->firstWhere('blueprint_id', $alternateType->getKey());
    $searchPage = $pages->get(2);
    $sortedPages = Page::query()
        ->whereIn('id', $pages->pluck('id'))
        ->orderBy('name')
        ->get();

    expect($alternateTypePage)->toBeInstanceOf(Page::class)
        ->and($searchPage)->toBeInstanceOf(Page::class);
    assert($alternateTypePage instanceof Page);
    assert($searchPage instanceof Page);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->filterTable('site_id', $site->getKey())
        ->assertTableFilterExists('filter')
        ->set('tableFilters.filter.language_id', $language->getKey())
        ->assertCountTableRecords(5)
        ->assertCanSeeTableRecords($pages)
        ->sortTable('name')
        ->assertCanSeeTableRecords($sortedPages, inOrder: true)
        ->searchTable($searchPage->name)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$searchPage])
        ->assertCanNotSeeTableRecords($pages->where('id', '!=', $searchPage->getKey()))
        ->searchTable()
        ->filterTable('blueprint_id', $alternateType->getKey())
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$alternateTypePage])
        ->resetTableFilters()
        ->set('activeTab', $site->getKey())
        ->assertCountTableRecords(5);
    // TODO fix this when accessing morph json relations
    // ->assertTableColumnStateSet('translation.title', state: $page->translation->title, record: $page)
});

test('shows normal pages by default and keeps system pages behind the advanced filter', function (): void {
    $defaultType = Blueprint::factory()
        ->page()
        ->createOne(['group' => null, 'name' => 'Default page']);
    $marketingType = Blueprint::factory()
        ->page()
        ->createOne(['group' => 'marketing', 'name' => 'Marketing page']);
    $systemType = Blueprint::factory()
        ->page()
        ->createOne(['group' => BlueprintGroupEnum::System->value, 'name' => 'System page']);

    $defaultPage = Page::factory()
        ->blueprint($defaultType)
        ->createOne(['name' => 'Default visible page']);
    $marketingPage = Page::factory()
        ->site($defaultPage->site)
        ->blueprint($marketingType)
        ->createOne(['name' => 'Marketing visible page']);
    $systemPage = Page::factory()
        ->site($defaultPage->site)
        ->blueprint($systemType)
        ->createOne(['name' => 'Protected system page']);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$defaultPage, $marketingPage])
        ->assertCanNotSeeTableRecords([$systemPage]);
});

test('offers a guided page type chooser while keeping quick create available', function (): void {
    $landingType = Blueprint::factory()
        ->page()
        ->default()
        ->createOne([
            'key' => 'landing-page',
            'name' => 'Landing page',
            'admin' => [
                'icon' => 'heroicon-o-rocket-launch',
                'notes' => 'Best for campaign entry pages.',
            ],
        ]);
    $disabledType = Blueprint::factory()
        ->page()
        ->createOne([
            'name' => 'Disabled page',
            'status' => false,
        ]);

    Page::factory()
        ->blueprint($landingType)
        ->createOne(['name' => 'Campaign page']);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertActionVisible('choosePageType')
        ->assertActionVisible('create')
        ->mountAction('choosePageType')
        ->assertMountedActionModalSee(__('capell-admin::generic.page_type_chooser_heading'))
        ->assertMountedActionModalSee('Landing page')
        ->assertMountedActionModalSee('Best for campaign entry pages.')
        ->assertMountedActionModalSee(__('capell-admin::generic.page_type_chooser_default_badge'))
        ->assertMountedActionModalSee(trans_choice('capell-admin::generic.page_type_chooser_usage', 1, ['count' => 1]))
        ->assertMountedActionModalSeeHtml('href="' . e(PageResource::getUrl('create', ['type' => 'landing-page'])) . '"')
        ->assertMountedActionModalDontSee($disabledType->name);
});

test('can show all pages or only system pages through the system pages filter', function (): void {
    $defaultType = Blueprint::factory()
        ->page()
        ->createOne(['group' => null, 'name' => 'Default page']);
    $marketingType = Blueprint::factory()
        ->page()
        ->createOne(['group' => 'marketing', 'name' => 'Marketing page']);
    $systemType = Blueprint::factory()
        ->page()
        ->createOne(['group' => BlueprintGroupEnum::System->value, 'name' => 'System page']);

    $defaultPage = Page::factory()
        ->blueprint($defaultType)
        ->createOne(['name' => 'Default filtered page']);
    $marketingPage = Page::factory()
        ->site($defaultPage->site)
        ->blueprint($marketingType)
        ->createOne(['name' => 'Marketing filtered page']);
    $systemPage = Page::factory()
        ->site($defaultPage->site)
        ->blueprint($systemType)
        ->createOne(['name' => 'System filtered page']);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(2)
        ->set('tableFilters.system_pages.value', true)
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$defaultPage, $marketingPage, $systemPage])
        ->set('tableFilters.system_pages.value', false)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$systemPage])
        ->assertCanNotSeeTableRecords([$defaultPage, $marketingPage]);
});

test('site tab badges follow the system pages filter', function (): void {
    Cache::flush();

    $defaultType = Blueprint::factory()
        ->page()
        ->createOne(['group' => null]);
    $systemType = Blueprint::factory()
        ->page()
        ->createOne(['group' => BlueprintGroupEnum::System->value]);
    $primarySite = Site::factory()->createOne();
    $secondarySite = Site::factory()->createOne();

    Page::factory()
        ->site($primarySite)
        ->blueprint($defaultType)
        ->createOne();
    Page::factory()
        ->site($primarySite)
        ->blueprint($systemType)
        ->createOne();
    Page::factory()
        ->site($secondarySite)
        ->blueprint($defaultType)
        ->createOne();

    $normalTabs = Livewire::test(ListPages::class)
        ->instance()
        ->getTabs();

    $allTabs = Livewire::test(ListPages::class)
        ->set('tableFilters.system_pages.value', true)
        ->instance()
        ->getTabs();

    $systemTabs = Livewire::test(ListPages::class)
        ->set('tableFilters.system_pages.value', false)
        ->instance()
        ->getTabs();

    expect($normalTabs[$primarySite->getKey()]->getBadge())->toBe('1')
        ->and($allTabs[$primarySite->getKey()]->getBadge())->toBe('2')
        ->and($systemTabs[$primarySite->getKey()]->getBadge())->toBe('1')
        ->and(Cache::has(CacheEnum::siteTabs(Site::class, 'pages')))->toBeFalse();
});

test('renders page summary and publish status in the pages table', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-23 09:00:00'));

    $parent = Page::factory()->createOne(['name' => 'Parent page']);
    $page = Page::factory()
        ->parent($parent)
        ->create([
            'name' => 'Scheduled page',
            'updated_at' => CarbonImmutable::parse('2026-05-22 09:00:00'),
            'visible_from' => CarbonImmutable::parse('2026-05-27 10:00:00'),
        ]);
    $page->pageUrls()->delete();

    PageUrl::factory()
        ->page($page)
        ->site($page->site)
        ->language($page->site->language)
        ->create(['url' => '/company/scheduled-page']);

    Page::factory()
        ->parent($page)
        ->create(['name' => 'Child page']);

    $component = Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertTableColumnVisible('blueprint.name')
        ->assertSee(__('capell-admin::table.page_type'))
        ->assertSee('Scheduled page')
        ->assertSee('Parent page')
        ->assertSee('/company/scheduled-page')
        ->assertSee('1 child')
        ->assertSee(__('capell-admin::table.page_meta_layout_value', ['layout' => $page->layout->name]))
        ->assertSee($page->blueprint->name)
        ->assertSee('4d')
        ->assertSee('1 day ago')
        ->assertDontSeeHtml('data-page-summary-thumbnail')
        ->assertDontSee(__('capell-admin::table.page_health_missing_image'))
        ->assertDontSee(__('capell-admin::table.image'))
        ->assertTableActionVisible(VisitUrlAction::getDefaultName(), record: $page);

    expect($component->instance()->isTableColumnToggledHidden('blueprint.name'))->toBeFalse();
});

test('groups related page record actions', function (): void {
    $page = Page::factory()->createOne();
    $pageName = $page->name;
    $blueprintName = 'Updated page type';

    $component = Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertTableActionHasUrl('edit-layout', LayoutResource::getUrl('edit', ['record' => $page->layout]), record: $page)
        ->mountTableAction('edit-blueprint', $page)
        ->assertMountedActionModalSee($page->blueprint->name)
        ->assertSchemaStateSet([
            'type' => BlueprintSubjectEnum::Page->value,
        ])
        ->setTableActionData(['name' => $blueprintName])
        ->callMountedTableAction()
        ->assertHasNoFormErrors();

    $recordActions = $component->instance()->getTable()->getRecordActions();

    expect($recordActions[0]->getName())->toBe('edit')
        ->and($recordActions[1])->toBeInstanceOf(ActionGroup::class);

    assert($recordActions[1] instanceof ActionGroup);

    expect(array_keys($recordActions[1]->getFlatActions()))
        ->toContain(VisitUrlAction::getDefaultName(), 'edit-layout', 'edit-blueprint')
        ->and(array_keys($recordActions[1]->getFlatActions()))->not->toContain('edit');

    expect($page->refresh()->name)->toBe($pageName)
        ->and($page->blueprint->refresh()->name)->toBe($blueprintName);
});

test('hides blueprint editing from page listers without blueprint update permission', function (): void {
    $page = Page::factory()->createOne();
    Permission::findOrCreate('ViewAny:Page');

    $user = test()->createUserWithPermission('ViewAny:Page');
    $user->assignedSiteIds = collect([$page->site_id]);

    test()->actingAs($user);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$page])
        ->assertTableActionHidden('edit-layout', record: $page)
        ->assertTableActionHidden('edit-blueprint', record: $page);
});

test('shows blueprint editing to page listers with blueprint update permission', function (): void {
    $page = Page::factory()->createOne();
    Permission::findOrCreate('ViewAny:Page');
    Permission::findOrCreate(ResourceEnum::Blueprint->permission('update'));

    $user = test()->createUserWithPermission([
        'ViewAny:Page',
        ResourceEnum::Blueprint->permission('update'),
    ]);
    $user->assignedSiteIds = collect([$page->site_id]);

    test()->actingAs($user);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$page])
        ->assertTableActionVisible('edit-blueprint', record: $page);
});

test('loads optional page table relations only when their columns are visible', function (): void {
    $parent = Page::factory()->createOne(['name' => 'Optional relation parent']);
    $page = Page::factory()
        ->parent($parent)
        ->withTranslations()
        ->createOne(['name' => 'Optional relation page']);

    $defaultComponent = Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->searchTable($page->name)
        ->instance();

    $defaultRecord = $defaultComponent->getFilteredTableQuery()->first();
    assert($defaultRecord instanceof Page);

    expect($defaultRecord->relationLoaded('parent'))->toBeFalse()
        ->and($defaultRecord->relationLoaded('translations'))->toBeFalse()
        ->and($defaultRecord->relationLoaded('creator'))->toBeFalse()
        ->and($defaultRecord->relationLoaded('editor'))->toBeFalse()
        ->and($defaultRecord->relationLoaded('layout'))->toBeTrue()
        ->and($defaultRecord->relationLoaded('blueprint'))->toBeTrue()
        ->and($defaultRecord->relationLoaded('translation'))->toBeTrue()
        ->and($defaultRecord->relationLoaded('pageUrls'))->toBeTrue();

    $expandedComponent = Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->searchTable($page->name)
        ->instance();

    $expandedRecord = $expandedComponent->getFilteredTableQuery()->first();
    assert($expandedRecord instanceof Page);

    expect($expandedRecord->relationLoaded('parent'))->toBeTrue()
        ->and($expandedRecord->relationLoaded('translations'))->toBeTrue()
        ->and($expandedRecord->relationLoaded('creator'))->toBeTrue()
        ->and($expandedRecord->relationLoaded('editor'))->toBeTrue();
});

test('resolves visit action url for the filtered page language', function (): void {
    $english = Language::factory()->english()->create();
    $french = Language::factory()->french()->create();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $french], siteDomainData: [
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ])
        ->create();

    $page = Page::factory()
        ->site($site)
        ->withTranslations([$english, $french])
        ->create(['name' => 'Translated page']);

    $frenchUrl = PageUrl::query()
        ->where('pageable_id', $page->getKey())
        ->where('pageable_type', $page->getMorphClass())
        ->where('language_id', $french->getKey())
        ->sole();

    $frenchUrl->update(['url' => '/fr/translated-page']);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->set('tableFilters.filter.language_id', $french->getKey())
        ->assertSee('/fr/translated-page')
        ->assertTableActionVisible(VisitUrlAction::getDefaultName(), record: $page);
});

test('hides visit action when the page url has no active site domain', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language)
        ->create();

    SiteDomain::query()
        ->where('site_id', $site->getKey())
        ->where('language_id', $language->getKey())
        ->delete();

    $page = Page::factory()
        ->site($site)
        ->withTranslations($language)
        ->create(['name' => 'Page with deleted domain']);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertSee('/page-with-deleted-domain-en')
        ->assertTableActionHidden(VisitUrlAction::getDefaultName(), record: $page);
});

test('renders compact page health indicators in the page summary', function (): void {
    $page = Page::factory()->createOne(['name' => 'Needs work page']);

    $page->pageUrls()->delete();
    $page->translations()->delete();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertSee('Needs work page')
        ->assertSee(__('capell-admin::table.page_health_missing_url'))
        ->assertSee(__('capell-admin::table.page_health_missing_title'))
        ->assertDontSee(__('capell-admin::table.page_health_missing_image'))
        ->assertTableActionHidden(VisitUrlAction::getDefaultName(), record: $page);
});

test('page table status can be supplied by a package resolver', function (): void {
    $visiblePage = Page::factory()->createOne(['name' => 'Visible workflow page']);
    Page::factory()->createOne(['name' => 'Hidden workflow page']);

    app()->bind(PageTableStatusResolver::class, fn (): PageTableStatusResolver => new readonly class($visiblePage->getKey()) implements PageTableStatusResolver
    {
        public function __construct(private int $visiblePageId) {}

        /**
         * @param  Builder<Page>  $query
         * @return Builder<Page>
         */
        public function modifyQuery(Builder $query): Builder
        {
            return $query->whereKey($this->visiblePageId);
        }

        public function resolve(Page $page): PageTableStatusData
        {
            return new PageTableStatusData(
                label: 'Awaiting review',
                shortLabel: 'Review',
                tooltip: 'Workflow package supplied this status.',
                color: 'info',
                icon: Heroicon::OutlinedClipboardDocumentCheck,
            );
        }
    });

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->assertSee('Visible workflow page')
        ->assertDontSee('Hidden workflow page')
        ->assertSee('Review')
        ->assertSee('Workflow package supplied this status.');
});

test('escapes page urls before rendering links', function (): void {
    $page = Page::factory()->createOne();

    PageUrl::factory()
        ->page($page)
        ->site($page->site)
        ->language($page->site->language)
        ->create(['url' => "/safe'><script>alert(1)</script>"]);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('escapes page names before rendering table links', function (): void {
    Page::factory()->createOne(['name' => 'Page <script>alert(1)</script>']);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('Page &lt;script&gt;alert(1)&lt;/script&gt;');
});

test('can delete page', function (): void {
    $page = Page::factory()->createOne();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->callAction(TestAction::make(DeleteAction::class)->table($page))
        ->assertHasNoFormErrors();

    assertSoftDeleted($page, ['id' => $page->id]);
});

test('can group delete pages', function (): void {
    $pages = Page::factory()->count(5)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    foreach ($pages as $page) {
        assertSoftDeleted($page, ['id' => $page->id]);
    }
});

test('can replicate page', function (): void {
    $page = Page::factory()->createOne();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(
            TestAction::make(ReplicatePageAction::class)->table($page),
            data: [
                'translations' => $page->site->languages->mapWithKeys(fn (Language $language): array => [
                    (string) Str::uuid() => [
                        'language_id' => $language->getKey(),
                        'title' => $page->name,
                        'meta' => [
                            'slug' => Str::slug($page->name),
                        ],
                    ],
                ])->all(),
            ],
        )
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    assertDatabaseHas('pages', [
        'name' => $page->name . ' (2)',
    ]);
});
