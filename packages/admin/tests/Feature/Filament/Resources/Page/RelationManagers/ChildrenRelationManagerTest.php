<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Pages\RelationManagers\ChildrenRelationManager;
use Capell\Core\Models\Page;
use Livewire\Livewire;

it('can list child pages', function (): void {
    test()->actingAsAdmin();

    $parentPage = Page::factory()->children(5)->create();

    expect($parentPage->children)->toHaveCount(5);

    Livewire::test(ChildrenRelationManager::class, [
        'ownerRecord' => $parentPage,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.page_children_description'))
        ->assertCountTableRecords(5)
        ->assertCanSeeTableRecords($parentPage->children);
});

it('shows child guidance when the page has no children', function (): void {
    test()->actingAsAdmin();

    $page = Page::factory()->createOne();

    Livewire::test(ChildrenRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_child_pages'))
        ->assertSee(__('capell-admin::generic.no_child_pages_description'));
});

it('can search child pages', function (): void {
    test()->actingAsAdmin();

    $parentPage = Page::factory()->children(5)->create();

    $childPage = $parentPage->children->random();

    Livewire::test(ChildrenRelationManager::class, [
        'ownerRecord' => $parentPage,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->searchTable($childPage->getKey())
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$childPage]);
});
