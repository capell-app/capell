<?php

declare(strict_types=1);

use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Support\Activity\ActivityRevertHandlerResolver;
use Capell\Admin\Support\Activity\EventSourcedActivityRevertHandler;
use Capell\Core\EventSourcing\Events\PageRolledBack;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Contracts\Activity;

uses(CreatesAdminUser::class)
    ->group('admin');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

function selectionForPage(Page $page, int|string $activityId): ActivityRevertSelectionData
{
    return new ActivityRevertSelectionData(
        activityId: $activityId,
        selectedPaths: [],
        beforeValues: [],
        actorId: null,
        subjectMorphType: $page->getMorphClass(),
        subjectClass: Page::class,
        subjectId: $page->getKey(),
        stableIdentifier: null,
        workspaceId: null,
    );
}

it('resolves the event-sourced handler for an event-sourced subject', function (): void {
    $page = Page::factory()->createOne();

    $handler = resolve(ActivityRevertHandlerResolver::class)
        ->resolve(selectionForPage($page, 1));

    expect($handler)->toBeInstanceOf(EventSourcedActivityRevertHandler::class);
});

it('reverts a page through event-sourcing rollback', function (): void {
    $page = Page::factory()->createOne();
    $page->load(['translations', 'pageUrls']);
    $page->save();

    $activity = activity()->performedOn($page)->event('updated')->log('edited page');

    expect($activity)->not->toBeNull();

    if (! $activity instanceof Activity) {
        return;
    }

    $result = resolve(EventSourcedActivityRevertHandler::class)
        ->revert(selectionForPage($page, $activity->id));

    expect($result->successful)->toBeTrue();
    expect(DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRolledBack::class)
        ->count())->toBe(1);
});

it('refuses to revert for a user without the page rollback permission', function (): void {
    // A plain user (no role) reaches the handler but lacks page.rollback — the
    // handler must refuse rather than rely on the upstream activity-log gate.
    test()->actingAsUser();

    $page = Page::factory()->createOne();
    $page->load(['translations', 'pageUrls']);
    $page->save();

    // The permission guard refuses before any version is resolved, so the
    // activity id is never read — passing a placeholder keeps the test focused.
    $result = resolve(EventSourcedActivityRevertHandler::class)
        ->revert(selectionForPage($page, 1));

    expect($result->successful)->toBeFalse();
    expect(DB::table('stored_events')
        ->where('aggregate_uuid', $page->uuid)
        ->where('event_class', PageRolledBack::class)
        ->count())->toBe(0);
});
