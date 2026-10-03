<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Layouts\Pages\ListLayouts;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('blocks permanent layout deletion while pages still use it', function (bool $bulk, bool $trashed): void {
    $layout = Layout::factory()->createOne();
    $page = Page::factory()->createOne(['layout_id' => $layout->id]);
    if ($trashed) {
        $page->delete();
    }

    $layout->delete();

    if ($bulk) {
        Livewire::test(ListLayouts::class)
            ->filterTable('trashed', true)
            ->selectTableRecords([$layout])
            ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
            ->assertNotified($trashed ? __('capell-admin::message.content_graph_delete_blocked') : __('capell-admin::message.layout_not_deletable', ['name' => $layout->name]));
    } else {
        Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
            ->callAction(ForceDeleteAction::class)
            ->assertNotified($trashed ? __('capell-admin::message.content_graph_delete_blocked') : __('capell-admin::message.layout_not_deletable', ['name' => $layout->name]));
    }

    expect(Layout::withTrashed()->find($layout->id))->not->toBeNull();
})->with(['single' => false, 'bulk' => true])->with(['active' => false, 'trashed' => true]);

it('blocks permanent site deletion while a child remains', function (string $child, bool $trashed, bool $bulk): void {
    $site = Site::factory()->createOne();
    $record = match ($child) {
        'page' => Page::factory()->createOne(['site_id' => $site->id]),
        'domain' => SiteDomain::factory()->createOne(['site_id' => $site->id]),
        default => throw new InvalidArgumentException('Unknown site child fixture: ' . $child),
    };
    if ($trashed) {
        $record->delete();
    }

    $site->delete();

    if ($bulk) {
        Livewire::test(ListSites::class)
            ->filterTable('trashed', true)
            ->selectTableRecords([$site])
            ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk());
    } else {
        Livewire::test(EditSite::class, ['record' => $site->getRouteKey()])
            ->callAction(ForceDeleteAction::class);
    }

    expect(Site::withTrashed()->find($site->id))->not->toBeNull();
})->with(['page', 'domain'])->with(['active' => false, 'trashed' => true])->with(['single' => false, 'bulk' => true]);

it('can permanently delete an unused layout', function (bool $bulk): void {
    $layout = Layout::factory()->createOne();
    $layout->delete();

    if ($bulk) {
        Livewire::test(ListLayouts::class)
            ->filterTable('trashed', true)
            ->selectTableRecords([$layout])
            ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk());
    } else {
        Livewire::test(EditLayout::class, ['record' => $layout->getRouteKey()])
            ->callAction(ForceDeleteAction::class);
    }

    expect(Layout::withTrashed()->find($layout->id))->toBeNull();
})->with(['single' => false, 'bulk' => true]);

it('blocks the entire bulk selection when an individual record is not authorised', function (): void {
    $layouts = Layout::factory()->count(2)->create();
    $layouts->each->delete();

    test()->actingAsUser();
    test()->authenticatedUser()->assignedSiteIds = collect($layouts->pluck('site_id')->all());
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => match ($ability) {
        'viewAny', 'view', 'forceDeleteAny' => true,
        'forceDelete' => $arguments[0]->id === $layouts->first()->id,
        default => null,
    });

    Livewire::test(ListLayouts::class)
        ->filterTable('trashed', true)
        ->selectTableRecords($layouts)
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
        ->assertForbidden();

    expect(Layout::withTrashed()->whereKey($layouts->modelKeys())->count())->toBe(2);
});
