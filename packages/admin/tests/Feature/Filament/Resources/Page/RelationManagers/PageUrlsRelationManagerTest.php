<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\UrlsRelationManager;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\assertSoftDeleted;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('can list page URLs', function (): void {
    $page = Page::factory()->createOne();

    PageUrl::factory()->count(10)->page($page)->site($page->site)->create();

    $pageUrl = $page->pageUrls->first();
    $pageUrl->load('siteDomain');

    Livewire::test(UrlsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertCountTableRecords(10)
        ->assertCanSeeTableRecords($page->pageUrls)
        ->assertTableColumnStateSet('url', [$pageUrl->full_url], record: $pageUrl);
});

it('shows URL guidance when the page has no URLs', function (): void {
    $page = Page::factory()->createOne();

    Livewire::test(UrlsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_page_urls'))
        ->assertSee(__('capell-admin::generic.no_page_urls_description'));
});

it('can search page URLs', function (): void {
    $page = Page::factory()->createOne();

    PageUrl::factory()->count(10)->page($page)->site($page->site)->create();

    $pageUrl = $page->pageUrls->random();

    Livewire::test(UrlsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->searchTable($pageUrl->getKey())
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$pageUrl]);
});

it('can bulk delete page URLs', function (): void {
    $page = Page::factory()->createOne();

    $pageUrls = PageUrl::factory()->count(10)->page($page)->site($page->site)->create();

    Livewire::test(UrlsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->selectTableRecords($pageUrls)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertHasNoFormErrors();

    foreach ($pageUrls as $pageUrl) {
        assertSoftDeleted($pageUrl, ['id' => $pageUrl->id]);
    }
});

it('can update a page URL', function (): void {
    $page = Page::factory()
        ->create();

    PageUrl::factory()->count(2)->page($page)->site($page->site)->create();

    $pageUrl = $page->pageUrls->first();
    $newPageUrl = $page->pageUrls->last();

    Livewire::test(UrlsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->mountTableAction(EditAction::class, $pageUrl)
        ->assertMountedActionModalSee(__('capell-admin::generic.page_url_path_info'));

    Livewire::test(UrlsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->callAction(
            TestAction::make(EditAction::class)->table($pageUrl),
            data: [
                'type' => UrlTypeEnum::Alias->value,
                'url' => '/new-url',
                'language_id' => $newPageUrl->language_id,
                'status' => '0',
            ],
        )
        ->assertHasNoFormErrors();

    expect($pageUrl->refresh())
        ->type->toBe(UrlTypeEnum::Alias)
        ->url->toBe('/new-url')
        ->language_id->toBe($newPageUrl->language_id)
        ->status->toBeFalse();
});
