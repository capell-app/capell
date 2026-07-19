<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Enums\PublishPanelStatusEnum;
use Capell\Admin\Filament\Livewire\PublishStatusPanel;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function panelPagePermission(string $affix): string
{
    $permissions = Utils::getConfig()->permissions;

    return FilamentShield::defaultPermissionKeyBuilder(
        affix: $affix,
        separator: $permissions->separator,
        subject: 'Page',
        case: $permissions->case,
    );
}

function panelFor(Page $page): Testable
{
    return Livewire::test(PublishStatusPanel::class, ['recordClass' => Page::class, 'recordId' => $page->getKey()]);
}

it('reports the published status for a live page', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    $status = panelFor($page)->instance()->viewData()->status;

    expect($status)->toBe(PublishPanelStatusEnum::published);
});

it('reports the scheduled status for a future publish date within the sentinel boundary', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->addWeek()]);

    expect(panelFor($page)->instance()->viewData()->status)->toBe(PublishPanelStatusEnum::scheduled);
});

it('reports the draft status for the far-future sentinel, not scheduled', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue()]);

    expect(panelFor($page)->instance()->viewData()->status)->toBe(PublishPanelStatusEnum::draft);
});

it('reports the expired status for a past unpublish date', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subMonth(), 'visible_until' => now()->subDay()]);

    expect(panelFor($page)->instance()->viewData()->status)->toBe(PublishPanelStatusEnum::expired);
});

it('publishes immediately via the publishNow action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue()]);

    panelFor($page)->callAction('publishNow');

    expect($page->fresh()->isPending())->toBeFalse()
        ->and($page->fresh()->visible_from?->isFuture())->toBeFalse();
});

it('schedules a future publish via the schedulePublish action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);
    $when = now()->addWeek()->startOfMinute();

    panelFor($page)->callAction('schedulePublish', ['publish_at' => $when->toDateTimeString()]);

    expect($page->fresh()->visible_from?->isFuture())->toBeTrue();
});

it('reverts to draft via the revertToDraft action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    panelFor($page)->callAction('revertToDraft');

    expect(PublishSentinel::isDraftValue($page->fresh()->visible_from))->toBeTrue();
});

it('unpublishes a live page via the unpublish action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    panelFor($page)->callAction('unpublish');

    expect($page->fresh()->isExpired())->toBeTrue();
});

it('shows unpublish only for live pages', function (): void {
    test()->actingAsAdmin();
    $live = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $expired = Page::factory()->create(['visible_from' => now()->subWeek(), 'visible_until' => now()->subDay()]);

    panelFor($live)->assertActionVisible('unpublish');
    panelFor($expired)->assertActionHidden('unpublish');
});

it('shows cancel scheduled unpublish only when a future unpublish date is set', function (): void {
    test()->actingAsAdmin();
    $scheduled = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => now()->addWeek()]);
    $live = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    panelFor($scheduled)->assertActionVisible('cancelScheduledUnpublish');
    panelFor($live)->assertActionHidden('cancelScheduledUnpublish');
});

it('cancels a scheduled unpublish via the cancelScheduledUnpublish action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => now()->addWeek()]);

    panelFor($page)->callAction('cancelScheduledUnpublish');

    expect($page->fresh()->visible_until)->toBeNull();
});

it('schedules a future unpublish via the setExpiry action', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $when = now()->addWeek()->startOfMinute();

    panelFor($page)->callAction('setExpiry', ['unpublish_at' => $when->toDateTimeString()]);

    expect($page->fresh()->visible_until?->isFuture())->toBeTrue();
});

it('hides every management action from a user who cannot update the page', function (): void {
    Permission::findOrCreate(panelPagePermission('update'));
    test()->actingAs(test()->createUser());

    $page = Page::factory()->create(['visible_from' => now()->subDay()]);

    panelFor($page)
        ->assertActionHidden('publishNow')
        ->assertActionHidden('unpublish')
        ->assertActionHidden('revertToDraft');
});
