<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('explains New page when the current site has no pages', function (): void {
    $this->actingAsAdmin();
    $site = Site::factory()->createOne();
    session()->put('capell.current_site_id', $site->getKey());

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.no_pages_yet'))
        ->assertSee(__('capell-admin::generic.no_pages_description'))
        ->assertDontSee(__('capell-admin::generic.no_pages_found_description'))
        ->assertDontSee(__('capell-admin::button.clear_search_and_filters'));
});

it('tells the editor to clear a search that matches no pages and clears it on request', function (): void {
    $this->actingAsAdmin();
    $site = Site::factory()->createOne();
    $page = Page::factory()->site($site)->withTranslations()->createOne();

    Livewire::test(ListPages::class)
        ->searchTable('nothing-is-called-this')
        ->assertCanNotSeeTableRecords([$page])
        ->assertSee(__('capell-admin::generic.no_pages_found'))
        ->assertSee(__('capell-admin::generic.no_pages_found_description'))
        ->assertSee(__('capell-admin::button.clear_search_and_filters'))
        ->assertDontSee(__('capell-admin::generic.no_pages_yet'))
        ->callAction(TestAction::make('clear_search_and_filters')->table())
        ->assertCanSeeTableRecords([$page])
        ->assertDontSee(__('capell-admin::generic.no_pages_found_description'));
});

it('treats a filter away from its default as a narrowed table', function (): void {
    $this->actingAsAdmin();
    $site = Site::factory()->createOne();
    Page::factory()->site($site)->withTranslations()->createOne(['visible_from' => now()->subDay()]);

    Livewire::test(ListPages::class)
        ->filterTable('publish_status', PublishVisibilityStateEnum::draft->value)
        ->assertSee(__('capell-admin::generic.no_pages_found'))
        ->assertSee(__('capell-admin::button.clear_search_and_filters'))
        ->assertDontSee(__('capell-admin::generic.no_pages_yet'));
});
