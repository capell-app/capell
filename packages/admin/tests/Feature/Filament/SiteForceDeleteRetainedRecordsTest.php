<?php

declare(strict_types=1);

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Actions\ForceDeleteAction;
use Capell\Admin\Filament\Actions\ForceDeleteBulkAction;
use Capell\Admin\Filament\Resources\Sites\Pages\EditSite;
use Capell\Admin\Filament\Resources\Sites\Pages\ListSites;
use Capell\Core\Exceptions\SiteHasRetainedRecordsException;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Site;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    test()->actingAsUser();
    Blueprint::factory()->theme()->default()->createOne();
});

/**
 * Make a package's own deletion hook refuse the site, as Form Builder does when held or undeletable
 * submissions remain. The policy cannot see this refusal in advance, so the exception reaches the action.
 */
function holdSiteRecordsForForceDeleteTest(?Site $only = null): void
{
    app()->instance('site-force-delete-test.hold', $only?->getKey() ?? true);

    Site::deleting(function (Site $site): void {
        $hold = app()->bound('site-force-delete-test.hold') ? resolve('site-force-delete-test.hold') : null;

        if ($site->isForceDeleting() && ($hold === true || $hold === $site->getKey())) {
            throw SiteHasRetainedRecordsException::forRecords(['form submissions under legal hold' => 2]);
        }
    });
}

/** Give an ordinary author permission to permanently delete sites, so the retainer is the only thing in the way. */
function allowSiteForceDeleteForTest(): void
{
    $actor = test()->authenticatedUser();
    $actor->assignedSiteIds = Site::withTrashed()->pluck('id');
    $actor->givePermissionTo(array_map(
        fn (string $affix): PermissionContract => Permission::findOrCreate(ResourceEnum::Site->permission($affix), 'web'),
        ['view_any', 'view', 'update', 'force_delete', 'force_delete_any'],
    ));
}

it('explains why the edit page cannot permanently delete a site a package must keep records for', function (): void {
    $site = Site::factory()->createOne();
    $site->delete();
    allowSiteForceDeleteForTest();
    holdSiteRecordsForForceDeleteTest();

    Livewire::test(EditSite::class, ['record' => $site->getKey()])
        ->callAction(TestAction::make(ForceDeleteAction::class))
        ->assertNotified(__('capell-admin::message.site_force_delete_retained_title'))
        ->assertNoRedirect();

    expect(Site::withTrashed()->whereKey($site->getKey())->exists())->toBeTrue();
});

it('explains why the sites table cannot permanently delete a site a package must keep records for', function (): void {
    $held = Site::factory()->createOne();
    $kept = Site::factory()->createOne();
    $kept->delete();

    $held->delete();
    allowSiteForceDeleteForTest();
    // Only one site is held, so the other is deleted first and must be restored by the rollback.
    holdSiteRecordsForForceDeleteTest($held);

    Livewire::test(ListSites::class)
        ->filterTable('trashed', true)
        ->selectTableRecords([$kept->getKey(), $held->getKey()])
        ->callAction(TestAction::make(ForceDeleteBulkAction::class)->table()->bulk())
        ->assertNotified(__('capell-admin::message.site_force_delete_retained_title'));

    expect(Site::withTrashed()->whereKey([$kept->getKey(), $held->getKey()])->count())->toBe(2);
});

it('names the blocking records and the next step in the notification', function (): void {
    $site = Site::factory()->createOne();
    $site->delete();
    allowSiteForceDeleteForTest();
    holdSiteRecordsForForceDeleteTest();

    Livewire::test(EditSite::class, ['record' => $site->getKey()])
        ->callAction(TestAction::make(ForceDeleteAction::class));

    $notifications = new Notifications;
    $notifications->mount();

    $notification = $notifications->notifications->first(fn (Notification $sent): bool => $sent->getTitle() === __('capell-admin::message.site_force_delete_retained_title'));

    expect($notification)->not->toBeNull()
        ->and($notification?->getStatus())->toBe('danger')
        ->and($notification?->getBody())->toContain('2 form submissions under legal hold')
        ->and($notification?->getBody())->toContain('Delete it again');
});
