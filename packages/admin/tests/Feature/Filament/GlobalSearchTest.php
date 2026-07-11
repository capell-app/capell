<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('global-search');

beforeEach(function (): void {
    test()->actingAsAdmin();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
    Filament::setServingStatus();
});

it('finds a site by every globally searchable site attribute', function (): void {
    $siteNameToken = 'capell-admin-site-name-token';
    $siteTitleToken = 'capell-admin-site-title-token';

    $site = Site::factory()->createOne([
        'name' => $siteNameToken,
    ]);

    $site->translations()->create([
        'language_id' => $site->language_id,
        'title' => $siteTitleToken,
    ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());

    $resultsByName = $globalSearchProvider->getResults($siteNameToken);
    $resultsByTranslationTitle = $globalSearchProvider->getResults($siteTitleToken);

    $siteResultByName = $resultsByName?->getCategories()->get(SiteResource::getPluralModelLabel())?->first();
    $siteResultByTranslationTitle = $resultsByTranslationTitle?->getCategories()->get(SiteResource::getPluralModelLabel())?->first();

    expect($siteResultByName)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($siteResultByName->title)->toBe($site->name)
        ->and($siteResultByName->url)->toBe(SiteResource::getUrl('edit', ['record' => $site]))
        ->and($siteResultByTranslationTitle)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($siteResultByTranslationTitle->title)->toBe($site->name)
        ->and($siteResultByTranslationTitle->url)->toBe(SiteResource::getUrl('edit', ['record' => $site]));
});

it('finds a layout by every globally searchable layout attribute', function (): void {
    $layoutNameToken = 'capell-admin-layout-name-token';
    $layoutKeyToken = 'capell-admin-layout-key-token';

    $layout = Layout::factory()->createOne([
        'name' => $layoutNameToken,
        'key' => $layoutKeyToken,
    ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());

    $resultsByName = $globalSearchProvider->getResults($layoutNameToken);
    $resultsByKey = $globalSearchProvider->getResults($layoutKeyToken);

    $layoutResultByName = $resultsByName?->getCategories()->get(LayoutResource::getPluralModelLabel())?->first();
    $layoutResultByKey = $resultsByKey?->getCategories()->get(LayoutResource::getPluralModelLabel())?->first();

    expect($layoutResultByName)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($layoutResultByName->title)->toBe($layout->name)
        ->and($layoutResultByName->url)->toBe(LayoutResource::getUrl('edit', ['record' => $layout]))
        ->and($layoutResultByKey)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($layoutResultByKey->title)->toBe($layout->name)
        ->and($layoutResultByKey->url)->toBe(LayoutResource::getUrl('edit', ['record' => $layout]));
});

it('finds a page by every globally searchable page attribute', function (): void {
    $pageNameToken = 'capell-admin-page-name-token';
    $pageTitleToken = 'capell-admin-page-title-token';
    $pageSlugToken = 'capell-admin-page-slug-token';

    $page = Page::factory()
        ->withTranslations(data: [
            'title' => $pageTitleToken,
        ], slug: $pageSlugToken)
        ->createOne([
            'name' => $pageNameToken,
        ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());

    $resultsByName = $globalSearchProvider->getResults($pageNameToken);
    $resultsByTranslationTitle = $globalSearchProvider->getResults($pageTitleToken);
    $resultsBySlug = $globalSearchProvider->getResults($pageSlugToken);

    $pageResultByName = $resultsByName?->getCategories()->get(PageResource::getPluralModelLabel())?->first();
    $pageResultByTranslationTitle = $resultsByTranslationTitle?->getCategories()->get(PageResource::getPluralModelLabel())?->first();
    $pageResultBySlug = $resultsBySlug?->getCategories()->get(PageResource::getPluralModelLabel())?->first();

    expect($pageResultByName)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($pageResultByName->title)->toBe($page->name)
        ->and($pageResultByName->url)->toBe(PageResource::getUrl('edit', ['record' => $page]))
        ->and($pageResultByTranslationTitle)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($pageResultByTranslationTitle->title)->toBe($page->name)
        ->and($pageResultByTranslationTitle->url)->toBe(PageResource::getUrl('edit', ['record' => $page]))
        ->and($pageResultBySlug)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($pageResultBySlug->title)->toBe($page->name)
        ->and($pageResultBySlug->url)->toBe(PageResource::getUrl('edit', ['record' => $page]));
});

it('finds a blueprint by every globally searchable blueprint attribute', function (): void {
    $blueprintNameToken = 'capell-admin-blueprint-name-token';
    $blueprintKeyToken = 'capell-admin-blueprint-key-token';
    $blueprintNotesToken = 'capell-admin-blueprint-notes-token';
    $blueprintComponentToken = 'capell-admin-blueprint-component-token';

    $blueprint = Blueprint::factory()->createOne([
        'name' => $blueprintNameToken,
        'key' => $blueprintKeyToken,
        'admin' => [
            'configurator' => 'Default',
            'notes' => $blueprintNotesToken,
        ],
        'component' => $blueprintComponentToken,
    ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());

    $resultsByName = $globalSearchProvider->getResults($blueprintNameToken);
    $resultsByKey = $globalSearchProvider->getResults($blueprintKeyToken);
    $resultsByNotes = $globalSearchProvider->getResults($blueprintNotesToken);
    $resultsByComponent = $globalSearchProvider->getResults($blueprintComponentToken);

    $blueprintResultByName = $resultsByName?->getCategories()->get(BlueprintResource::getPluralModelLabel())?->first();
    $blueprintResultByKey = $resultsByKey?->getCategories()->get(BlueprintResource::getPluralModelLabel())?->first();
    $blueprintResultByNotes = $resultsByNotes?->getCategories()->get(BlueprintResource::getPluralModelLabel())?->first();
    $blueprintResultByComponent = $resultsByComponent?->getCategories()->get(BlueprintResource::getPluralModelLabel())?->first();
    $blueprintUrl = BlueprintResource::getGlobalSearchResultUrl($blueprint);

    expect($blueprintResultByName)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($blueprintResultByName->title)->toBe($blueprint->name)
        ->and($blueprintResultByName->url)->toBe($blueprintUrl)
        ->and($blueprintResultByKey)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($blueprintResultByKey->title)->toBe($blueprint->name)
        ->and($blueprintResultByKey->url)->toBe($blueprintUrl)
        ->and($blueprintResultByNotes)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($blueprintResultByNotes->title)->toBe($blueprint->name)
        ->and($blueprintResultByNotes->url)->toBe($blueprintUrl)
        ->and($blueprintResultByComponent)->toBeInstanceOf(GlobalSearchResult::class)
        ->and($blueprintResultByComponent->title)->toBe($blueprint->name)
        ->and($blueprintResultByComponent->url)->toBe($blueprintUrl);
});

it('ranks a page name match before a translated title match', function (): void {
    $search = 'capell-admin-page-relevance-token';

    $nameMatch = Page::factory()->createOne(['name' => $search . ' name match']);
    Page::factory()
        ->withTranslations(data: ['title' => $search . ' translated title match'])
        ->createOne(['name' => 'Z page title match']);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());
    $result = $globalSearchProvider->getResults($search)?->getCategories()->get(PageResource::getPluralModelLabel())?->first();

    expect($result)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($result->title)->toBe($nameMatch->name);
});

it('ranks a site name match before a translated title match', function (): void {
    $search = 'capell-admin-site-relevance-token';

    $nameMatch = Site::factory()->createOne(['name' => $search . ' name match']);
    $titleMatch = Site::factory()->createOne(['name' => 'Z site title match']);
    $titleMatch->translations()->create([
        'language_id' => $titleMatch->language_id,
        'title' => $search . ' translated title match',
    ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());
    $result = $globalSearchProvider->getResults($search)?->getCategories()->get(SiteResource::getPluralModelLabel())?->first();

    expect($result)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($result->title)->toBe($nameMatch->name);
});

it('ranks a layout name match before a key match', function (): void {
    $search = 'capell-admin-layout-relevance-token';

    $nameMatch = Layout::factory()->createOne(['name' => $search . ' name match']);
    Layout::factory()->createOne([
        'name' => 'Z layout key match',
        'key' => $search . '-key-match',
    ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());
    $result = $globalSearchProvider->getResults($search)?->getCategories()->get(LayoutResource::getPluralModelLabel())?->first();

    expect($result)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($result->title)->toBe($nameMatch->name);
});

it('ranks a blueprint name match before a key match', function (): void {
    $search = 'capell-admin-blueprint-relevance-token';

    $nameMatch = Blueprint::factory()->createOne(['name' => $search . ' name match']);
    Blueprint::factory()->createOne([
        'name' => 'Z blueprint key match',
        'key' => $search . '-key-match',
    ]);

    $globalSearchProvider = expectPresent(Filament::getGlobalSearchProvider());
    $result = $globalSearchProvider->getResults($search)?->getCategories()->get(BlueprintResource::getPluralModelLabel())?->first();

    expect($result)
        ->toBeInstanceOf(GlobalSearchResult::class)
        ->and($result->title)->toBe($nameMatch->name);
});

it('escapes page breadcrumbs in global search details', function (): void {
    $site = Site::factory()->createOne(['default' => false, 'name' => 'Site <script>alert(1)</script>']);
    $parent = Page::factory()->site($site)->create(['name' => 'Parent <script>alert(2)</script>']);
    $page = Page::factory()->site($site)->parent($parent)->create(['name' => 'Child page']);

    $details = PageResource::getGlobalSearchResultDetails($page->load(['site', 'ancestors']));
    /** @var array<string, Htmlable|string|null> $details */
    $breadcrumb = collect($details)
        ->first(fn (mixed $detail): bool => $detail instanceof Htmlable);

    expect($breadcrumb)
        ->toBeInstanceOf(Htmlable::class);

    throw_unless($breadcrumb instanceof Htmlable, RuntimeException::class, 'Expected breadcrumb detail to be HTML.');

    expect($breadcrumb->toHtml())
        ->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<script>alert(2)</script>')
        ->toContain('Site &lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('Parent &lt;script&gt;alert(2)&lt;/script&gt;');
});

it('renders search results in the global search component', function (): void {
    Layout::factory()->createOne([
        'name' => 'capell-admin-component-search',
        'key' => 'capell-admin-component-search',
    ]);

    Livewire::test(GlobalSearch::class)
        ->set('search', 'capell-admin-component-search')
        ->assertSee('capell-admin-component-search');
});
