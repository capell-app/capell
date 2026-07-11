<?php

declare(strict_types=1);

use Capell\Admin\Filament\RelationManagers\ActivityHistoryRelationManager;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(CreatesAdminUser::class)
    ->group('activity');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('shows activity history for the owning page only', function (): void {
    $page = Page::factory()->createOne(['name' => 'Tracked page']);
    $otherPage = Page::factory()->createOne(['name' => 'Other page']);

    $pageActivity = activity()
        ->performedOn($page)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Old tracked page'],
            'attributes' => ['name' => 'Tracked page'],
        ])
        ->log('updated tracked page');

    $otherActivity = activity()
        ->performedOn($otherPage)
        ->event('updated')
        ->withProperties([
            'old' => ['name' => 'Old other page'],
            'attributes' => ['name' => 'Other page'],
        ])
        ->log('updated other page');

    Livewire::test(ActivityHistoryRelationManager::class, [
        'ownerRecord' => $page,
        'pageClass' => EditPage::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pageActivity])
        ->assertCanNotSeeTableRecords([$otherActivity])
        ->assertSee(__('capell-admin::tab.history'))
        ->assertSee(__('capell-admin::activity.resource_history_description'));
});

it('registers the activity history relation on logged resources', function (): void {
    expect(PageResource::getRelations())->toContain(ActivityHistoryRelationManager::class)
        ->and(LayoutResource::getRelations())->toContain(ActivityHistoryRelationManager::class)
        ->and(SiteResource::getRelations())->toContain(ActivityHistoryRelationManager::class);
});

it('records layout and site activity for the history relation', function (string $modelClass, string $pageClass): void {
    $record = $modelClass::factory()->createOne();
    $record->update(['name' => 'History tracked ' . class_basename($record)]);

    $activity = Activity::query()
        ->whereMorphedTo('subject', $record)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->toBeInstanceOf(Activity::class);

    Livewire::test(ActivityHistoryRelationManager::class, [
        'ownerRecord' => $record,
        'pageClass' => $pageClass,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$activity]);
})->with([
    'layout' => [Layout::class, EditLayout::class],
    'site' => [Site::class, EditSite::class],
]);
