<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Actions\ReplicateAction;
use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class)
    ->group('page-url');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

test('can list page urls', function (): void {
    $language = Language::factory()->createOne();

    $pages = Page::factory()
        ->count(5)
        ->recycle($language)
        ->withTranslations()
        ->create();

    $pageUrls = PageUrl::query()
        ->where('pageable_type', $pages->first()->getMorphClass())
        ->whereIn('pageable_id', $pages->pluck('id'))->get();

    expect($pageUrls)->toHaveCount(5);

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->assertCanSeeTableRecords($pageUrls);
});

test('can search page urls', function (): void {
    $language = Language::factory()->createOne();

    $pages = Page::factory()
        ->count(3)
        ->recycle($language)
        ->withTranslations()
        ->create();

    $pageUrls = PageUrl::with('siteDomain')
        ->where('pageable_type', $pages->first()->getMorphClass())
        ->whereIn('pageable_id', $pages->pluck('id'))
        ->get();

    $pageUrl = $pageUrls->random()->full_url;

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->searchTable($pageUrl)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords($pageUrls->where('full_url', $pageUrl))
        ->assertCanNotSeeTableRecords($pageUrls->where('full_url', '!=', $pageUrl));
});

test('can search full page url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => 'test-domain.com',
        'scheme' => 'https',
        'language_id' => $language->id,
        'path' => null,
        'default' => true,
    ]);
    $page = Page::factory()->recycle($site)->recycle($language)->create();
    $pageUrl = PageUrl::factory()->page($page)->language($language)->site($site)->create([
        'url' => '/unique-test-url-' . uniqid(),
    ]);
    PageUrl::factory()->count(4)->create();

    $fullUrl = $siteDomain->scheme . '://' . $siteDomain->domain . ($siteDomain->path ?? '') . $pageUrl->url;

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->searchTable($fullUrl)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$pageUrl]);
});

test('can search full page url for a null site domain', function (): void {
    config([
        'app.url' => 'https://capell.test',
        'capell-frontend.default_scheme' => 'https',
    ]);

    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne([
        'site_id' => $site->id,
        'domain' => null,
        'scheme' => null,
        'language_id' => $language->id,
        'path' => '/tenant',
        'default' => true,
    ]);
    $page = Page::factory()->recycle($site)->recycle($language)->create();
    $pageUrl = PageUrl::factory()->page($page)->language($language)->site($site)->create([
        'url' => '/unique-null-domain-url-' . uniqid(),
    ]);
    PageUrl::factory()->count(4)->create();

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(5)
        ->searchTable($siteDomain->full_url . $pageUrl->url)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$pageUrl]);
});

test('can sort page urls', function (): void {
    $pages = Page::factory()
        ->count(3)
        ->withTranslations()
        ->create();

    $pageUrls = PageUrl::with('siteDomain')
        ->where('pageable_type', $pages->first()->getMorphClass())
        ->whereIn('pageable_id', $pages->pluck('id'))
        ->get();

    $sorted = $pageUrls->pluck('url', 'id')->toArray();

    $collator = new Collator(app()->getLocale());
    $collator->asort($sorted);

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords($pageUrls->count())
        ->sortTable('url')
        ->assertCanSeeTableRecords(array_keys($sorted), inOrder: true);
});

test('can replicate page url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();
    $pageUrl = $page->pageUrl;

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(
            TestAction::make(ReplicateAction::class)->table($pageUrl),
            data: [
                'url' => $pageUrl->url . '-copy',
            ],
        )
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    assertDatabaseHas('page_urls', [
        'url' => $pageUrl->url,
    ]);
});

test('can create page url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()
        ->state(['language_id' => $language->id])
        ->has(SiteDomain::factory()->default()->state(['language_id' => $language->id]))
        ->create();

    $page = Page::factory()
        ->recycle($site)
        ->withTranslations()
        ->create();

    $pageUrl = $page->pageUrl;

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->mountAction(CreateAction::class)
        ->fillForm([
            'site_id' => $pageUrl->site_id,
            'language_id' => $pageUrl->language_id,
            'pageable_type' => $pageUrl->pageable->getMorphClass(),
            'pageable_id' => $pageUrl->pageable->getKey(),
            'url' => $pageUrl->url . '-copy',
        ])
        ->assertSchemaStateSet([
            'site_id' => $pageUrl->site_id,
            'language_id' => $pageUrl->language_id,
            'pageable_type' => $pageUrl->pageable->getMorphClass(),
            'pageable_id' => $pageUrl->pageable->getKey(),
            'url' => $pageUrl->url . '-copy',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(2);

    assertDatabaseHas('page_urls', [
        'site_id' => $pageUrl->site_id,
        'language_id' => $pageUrl->language_id,
        'pageable_type' => $pageUrl->pageable->getMorphClass(),
        'pageable_id' => $pageUrl->pageable->getKey(),
        'url' => $pageUrl->url,
    ]);
});

test('create page url form explains url input before destination is selected', function (): void {
    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->mountAction(CreateAction::class)
        ->assertMountedActionModalSee(__('capell-admin::generic.page_url_path_info'));
});

test('can not create page url', function (): void {
    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_page_urls'))
        ->assertSee(__('capell-admin::generic.no_page_urls_description'))
        ->callAction(CreateAction::class)
        ->assertHasFormErrors([
            'url' => ['required'],
        ])
        ->assertCountTableRecords(0);
});

test('can update page url', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->recycle($site)->withTranslations()->create();
    $newPage = Page::factory()->recycle($site)->recycle($language)->withTranslations()->create();
    $pageUrl = $page->pageUrl;
    $newUrl = PageUrl::factory()->make();

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($pageUrl),
            data: [
                'site_id' => $site->id,
                'language_id' => $language->id,
                'pageable_type' => $newPage->getMorphClass(),
                'pageable_id' => $newPage->getKey(),
                'type' => UrlTypeEnum::Alias->value,
                'url' => $newUrl->url,
                'status' => '0',
            ],
        )
        ->assertHasNoFormErrors();

    expect($pageUrl->refresh())
        ->site_id->toBe($site->id)
        ->language_id->toBe($language->id)
        ->pageable_type->toBe($newPage->getMorphClass())
        ->pageable_id->toBe($newPage->getKey())
        ->type->toBe(UrlTypeEnum::Alias)
        ->url->toBe($newUrl->url)
        ->status->toBeFalse();
});

test('can not update page url', function (): void {
    $pageUrl = PageUrl::factory()->createOne();

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($pageUrl),
            data: [
                'url' => '',
            ],
        )
        ->assertHasFormErrors([
            'url' => ['required'],
        ]);
});

test('can delete page url', function (): void {
    $page = Page::factory()->withTranslations()->create();

    $pageUrl = $page->pageUrl;

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->assertCountTableRecords(1)
        ->callAction(TestAction::make(DeleteAction::class)->table($pageUrl))
        ->assertHasNoFormErrors()
        ->assertCountTableRecords(0);

    assertSoftDeleted($pageUrl, ['id' => $pageUrl->id]);
});

test('can group delete page urls', function (): void {
    $pageUrls = PageUrl::factory()->count(5)->create();

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->selectTableRecords($pageUrls)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    foreach ($pageUrls as $pageUrl) {
        assertSoftDeleted($pageUrl, ['id' => $pageUrl->id]);
    }
});
