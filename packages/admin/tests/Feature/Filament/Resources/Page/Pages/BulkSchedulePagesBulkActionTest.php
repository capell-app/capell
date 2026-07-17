<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Actions\BulkSchedulePagesBulkAction;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Policies\PagePolicy;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    Gate::policy(Page::class, PagePolicy::class);
    Gate::before(fn (mixed $user, string $ability): ?bool => $user->hasRole('super_admin') ? true : null);

    test()->actingAsAdmin();
});

it('schedules selected pages to publish later', function (): void {
    $page = Page::factory()->createOne(['visible_from' => null]);
    $publishAt = CarbonImmutable::now()->addWeek()->setTime(10, 0, 0);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$page])
        ->callAction(
            TestAction::make(BulkSchedulePagesBulkAction::class)->table()->bulk(),
            data: ['publish_at' => $publishAt->toDateTimeString()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::bulk_actions.schedule_pages_done', [
            'scheduled' => 1,
            'skipped' => 0,
        ]));

    expect($page->fresh()->visible_from?->toDateTimeString())->toBe($publishAt->toDateTimeString());
});

it('counts a page already scheduled for the requested time as skipped, not scheduled', function (): void {
    $publishAt = CarbonImmutable::now()->addWeek()->setTime(10, 0, 0);
    $alreadyScheduledPage = Page::factory()->createOne(['visible_from' => $publishAt]);

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords([$alreadyScheduledPage])
        ->callAction(
            TestAction::make(BulkSchedulePagesBulkAction::class)->table()->bulk(),
            data: ['publish_at' => $publishAt->toDateTimeString()],
        )
        ->assertHasNoActionErrors()
        ->assertNotified(__('capell-admin::bulk_actions.schedule_pages_done', [
            'scheduled' => 0,
            'skipped' => 1,
        ]));

    expect($alreadyScheduledPage->fresh()->visible_from?->toDateTimeString())
        ->toBe($publishAt->toDateTimeString());
});
