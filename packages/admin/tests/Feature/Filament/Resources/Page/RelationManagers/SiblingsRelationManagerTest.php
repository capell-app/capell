<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\SiblingsRelationManager;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Livewire\Livewire;

it('can list sibling pages', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->withTranslations()
        ->create();
    $parent = Page::factory()
        ->recycle($site)
        ->withTranslations()
        ->create();

    Page::factory()
        ->count(5)
        ->recycle($site)
        ->withTranslations()
        ->parent($parent)
        ->create();

    $siblingPage = $parent->children->first();

    Livewire::test(SiblingsRelationManager::class, [
        'ownerRecord' => $siblingPage,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.page_siblings_description'))
        ->assertCountTableRecords(4)
        ->assertCanSeeTableRecords($parent->children->where('id', '!=', $siblingPage->id));
});

it('shows sibling guidance when the page has no siblings', function (): void {
    test()->actingAsAdmin();

    $page = Page::factory()->createOne();

    Livewire::test(SiblingsRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_sibling_pages'))
        ->assertSee(__('capell-admin::generic.no_sibling_pages_description'));
});

it('can search sibling pages', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->withTranslations()
        ->create();
    $parent = Page::factory()
        ->withTranslations()
        ->site($site)
        ->create();

    Page::factory()->withTranslations()->site($site)->parent($parent)->count(5)->create();

    $siblingPage = $parent->children->random();

    $searchPage = $parent->children->where('id', '!=', $siblingPage->id)->random();

    Livewire::test(SiblingsRelationManager::class, [
        'ownerRecord' => $siblingPage,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->searchTable($searchPage->getKey())
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$searchPage]);
});
