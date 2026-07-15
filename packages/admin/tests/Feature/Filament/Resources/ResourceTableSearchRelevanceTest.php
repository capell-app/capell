<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('table-search-relevance');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('ranks page name matches before translated and related matches', function (): void {
    $search = 'capell-page-table-relevance';

    $nameMatch = Page::factory()->withTranslations()->createOne([
        'name' => $search . ' name match',
    ]);
    $translationMatch = Page::factory()->withTranslations(data: [
        'title' => $search . ' translated match',
    ])->createOne([
        'name' => 'Z page translation match',
    ]);
    $urlMatch = Page::factory()->withTranslations()->createOne([
        'name' => 'Z page URL match',
    ]);

    PageUrl::factory()
        ->page($urlMatch)
        ->site($urlMatch->site)
        ->createOne([
            'language_id' => $urlMatch->site->language_id,
            'url' => '/' . $search . '-url-match',
        ]);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->searchTable($search)
        ->assertCanSeeTableRecords([$nameMatch, $urlMatch, $translationMatch], inOrder: true);
});

it('ranks site name matches before translated and related matches', function (): void {
    $search = 'capell-site-table-relevance';

    $nameMatch = Site::factory()->withTranslations()->createOne([
        'name' => $search . ' name match',
    ]);
    $translationMatch = Site::factory()->withTranslations(data: [
        'title' => $search . ' translated match',
    ])->createOne([
        'name' => 'Z site translation match',
    ]);
    $domainMatch = Site::factory()->withTranslations()->createOne([
        'name' => 'Z site domain match',
    ]);

    SiteDomain::factory()
        ->site($domainMatch)
        ->createOne([
            'language_id' => $domainMatch->language_id,
            'domain' => $search . '.test',
        ]);

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->searchTable($search)
        ->assertCanSeeTableRecords([$nameMatch, $domainMatch, $translationMatch], inOrder: true);
});

it('keeps explicit table sorting ahead of site search relevance', function (): void {
    $search = 'capell-site-table-explicit-sort';

    $nameMatch = Site::factory()->withTranslations()->createOne([
        'name' => 'Z ' . $search . ' name match',
    ]);
    $translationMatch = Site::factory()->withTranslations(data: [
        'title' => $search . ' translated match',
    ])->createOne([
        'name' => 'A site translation match',
    ]);

    Livewire::test(ListSites::class)
        ->assertSuccessful()
        ->searchTable($search)
        ->sortTable('name')
        ->assertCanSeeTableRecords([$translationMatch, $nameMatch], inOrder: true);
});
