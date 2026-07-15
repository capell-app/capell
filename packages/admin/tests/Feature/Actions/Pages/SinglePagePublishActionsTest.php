<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Publishing\PublishRecordAction;
use Capell\Admin\Actions\Publishing\RevertRecordToDraftAction;
use Capell\Admin\Actions\Publishing\ScheduleRecordPublishAction;
use Capell\Admin\Actions\Publishing\ScheduleRecordUnpublishAction;
use Capell\Admin\Actions\Publishing\UnpublishRecordAction;
use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function singleActionPagePermission(string $affix): string
{
    $permissions = Utils::getConfig()->permissions;

    return FilamentShield::defaultPermissionKeyBuilder(
        affix: $affix,
        separator: $permissions->separator,
        subject: 'Page',
        case: $permissions->case,
    );
}

it('publishes a scheduled page immediately and emits page saved', function (): void {
    Event::fake([PageSaved::class]);

    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->addWeek(), 'visible_until' => null]);

    $result = PublishRecordAction::run($page, $actor);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->fresh()->visible_from?->isFuture())->toBeFalse()
        ->and($page->fresh()->isPending())->toBeFalse();

    Event::assertDispatched(PageSaved::class);
});

it('clears a past expiry when publishing an expired page', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subMonth(), 'visible_until' => now()->subDay()]);

    $result = PublishRecordAction::run($page, $actor);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->fresh()->visible_until)->toBeNull()
        ->and($page->fresh()->isExpired())->toBeFalse();
});

it('skips publishing a page that is already live', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    $result = PublishRecordAction::run($page, $actor);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::AlreadyCorrect)
        ->and($result->reasonKey)->toBe('publication.transition.already-correct');
});

it('schedules a future publish date', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $publishAt = CarbonImmutable::now()->addWeek();

    $result = ScheduleRecordPublishAction::run($page, $actor, $publishAt);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->fresh()->visible_from?->isFuture())->toBeTrue();
});

it('rejects scheduling a publish in the past', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subDay()]);

    $result = ScheduleRecordPublishAction::run($page, $actor, CarbonImmutable::now()->subHour());

    expect($result->outcome)->toBe(PublicationTransitionOutcome::InvalidTransition)
        ->and($result->reasonKey)->toBe('publication.transition.requested-time-not-future');
});

it('reverts a published page to the draft sentinel', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    $result = RevertRecordToDraftAction::run($page, $actor);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and(PagePublishSentinel::isDraftValue($page->fresh()->visible_from))->toBeTrue();
});

it('skips reverting a page already in draft', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => PagePublishSentinel::draftValue()]);

    $result = RevertRecordToDraftAction::run($page, $actor);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::AlreadyCorrect)
        ->and($result->reasonKey)->toBe('publication.transition.already-correct');
});

it('schedules a future unpublish date', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);
    $unpublishAt = CarbonImmutable::now()->addWeek();

    $result = ScheduleRecordUnpublishAction::run($page, $actor, $unpublishAt);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->fresh()->visible_until?->isFuture())->toBeTrue();
});

it('rejects a scheduled unpublish that precedes the publish date', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->addMonth()]);

    $result = ScheduleRecordUnpublishAction::run($page, $actor, CarbonImmutable::now()->addWeek());

    expect($result->outcome)->toBe(PublicationTransitionOutcome::InvalidTransition)
        ->and($result->reasonKey)->toBe('publication.transition.unpublish-not-after-publish');
});

it('unpublishes immediately through the core transition', function (): void {
    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create(['visible_from' => now()->subDay(), 'visible_until' => null]);

    $result = UnpublishRecordAction::run($page, $actor);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->fresh()->isExpired())->toBeTrue();
});

it('skips all single actions when the actor cannot update', function (): void {
    Permission::findOrCreate(singleActionPagePermission('update'));

    $actor = test()->createUser();
    $page = Page::factory()->create(['visible_from' => now()->addWeek()]);

    expect(PublishRecordAction::run($page, $actor)->outcome)->toBe(PublicationTransitionOutcome::Unauthorized)
        ->and(ScheduleRecordPublishAction::run($page, $actor, CarbonImmutable::now()->addWeek())->outcome)->toBe(PublicationTransitionOutcome::Unauthorized)
        ->and(RevertRecordToDraftAction::run($page, $actor)->outcome)->toBe(PublicationTransitionOutcome::Unauthorized)
        ->and(ScheduleRecordUnpublishAction::run($page, $actor, CarbonImmutable::now()->addWeek())->outcome)->toBe(PublicationTransitionOutcome::Unauthorized);
});
