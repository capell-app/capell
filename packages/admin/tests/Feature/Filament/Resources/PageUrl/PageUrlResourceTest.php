<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\PageUrls\Pages\ManagePageUrls;
use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('page', 'page-url');

it('admin can see page urls', function (): void {
    test()->actingAsAdmin();

    get(PageUrlResource::getUrl())
        ->assertOk();
});

it('admin render page urls page with redirect filter', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->create();
    SiteDomain::factory()->default()->recycle($site)->recycle($language)->create();
    $page = Page::factory()->recycle($site)->create();
    PageUrl::factory()->site($site)->language($language)->page($page)->redirect()->create();
    Translation::factory()->language($language)->translatable($page)->create();

    get(PageUrlResource::getUrl(parameters: ['filters' => ['filters[type][value]' => 'redirect']]))
        ->assertOk()
        ->assertSeeText('Showing 1 result');
});

it('filters page URLs by both pageable type and identifier', function (): void {
    test()->actingAsAdmin();

    $firstPage = Page::factory()->createOne(['name' => 'First pageable page']);
    $secondPage = Page::factory()->site($firstPage->site)->createOne(['name' => 'Second pageable page']);
    $firstUrl = PageUrl::factory()->page($firstPage)->site($firstPage->site)->language($firstPage->site->language)->createOne(['url' => '/first-pageable']);
    $secondUrl = PageUrl::factory()->page($secondPage)->site($secondPage->site)->language($secondPage->site->language)->createOne(['url' => '/second-pageable']);

    Livewire::test(ManagePageUrls::class)
        ->assertSuccessful()
        ->set('tableFilters.pageable', [
            'pageable_type' => $firstPage->getMorphClass(),
            'pageable_id' => $firstPage->getKey(),
        ])
        ->assertCanSeeTableRecords([$firstUrl])
        ->assertCanNotSeeTableRecords([$secondUrl]);
});

it('cannot see page urls', function (): void {
    test()->actingAsUser();

    get(PageUrlResource::getUrl())
        ->assertForbidden();
});
