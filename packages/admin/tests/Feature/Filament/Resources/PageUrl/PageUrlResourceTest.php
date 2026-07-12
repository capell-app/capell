<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\PageUrls\PageUrlResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

use function Pest\Laravel\get;

uses(CreatesAdminUser::class)
    ->group('page', 'page-url');

test('admin can see page urls', function (): void {
    test()->actingAsAdmin();

    get(PageUrlResource::getUrl())
        ->assertOk();
});

test('admin render page urls page with redirect filter', function (): void {
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

test('cannot see page urls', function (): void {
    test()->actingAsUser();

    get(PageUrlResource::getUrl())
        ->assertForbidden();
});
