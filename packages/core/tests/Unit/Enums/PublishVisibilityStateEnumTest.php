<?php

declare(strict_types=1);

use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('derives every visibility state from dates at a frozen time', function (): void {
    $now = CarbonImmutable::parse('2026-07-01 12:00:00');
    CarbonImmutable::setTestNow($now);

    expect(PublishVisibilityStateEnum::fromDates(null, null, false))->toBe(PublishVisibilityStateEnum::published)
        ->and(PublishVisibilityStateEnum::fromDates($now->subDay(), null, false))->toBe(PublishVisibilityStateEnum::published)
        ->and(PublishVisibilityStateEnum::fromDates($now->addWeek(), null, false))->toBe(PublishVisibilityStateEnum::scheduled)
        ->and(PublishVisibilityStateEnum::fromDates($now->addYears(100), null, false))->toBe(PublishVisibilityStateEnum::draft)
        ->and(PublishVisibilityStateEnum::fromDates($now->addYears(100), $now->subDay(), false))->toBe(PublishVisibilityStateEnum::expired)
        ->and(PublishVisibilityStateEnum::fromDates($now->subMonth(), $now->subDay(), false))->toBe(PublishVisibilityStateEnum::expired)
        ->and(PublishVisibilityStateEnum::fromDates($now->subMonth(), $now->subDay(), true))->toBe(PublishVisibilityStateEnum::deleted)
        ->and(PublishVisibilityStateEnum::fromDates(null, $now->addWeek(), false))->toBe(PublishVisibilityStateEnum::published);
});

it('accepts an explicit reference time instead of the frozen clock', function (): void {
    $reference = CarbonImmutable::parse('2026-07-01 12:00:00');

    expect(PublishVisibilityStateEnum::fromDates($reference->addWeek(), null, false, $reference))
        ->toBe(PublishVisibilityStateEnum::scheduled)
        ->and(PublishVisibilityStateEnum::fromDates($reference->addYears(100), null, false, $reference))
        ->toBe(PublishVisibilityStateEnum::draft);
});

it('treats a visible_from exactly at the draft boundary as scheduled', function (): void {
    $now = CarbonImmutable::parse('2026-07-01 12:00:00');
    CarbonImmutable::setTestNow($now);

    $boundary = PublishSentinel::draftBoundary($now);

    expect(PublishVisibilityStateEnum::fromDates($boundary, null, false))->toBe(PublishVisibilityStateEnum::scheduled)
        ->and(PublishVisibilityStateEnum::fromDates($boundary->addSecond(), null, false))->toBe(PublishVisibilityStateEnum::draft);
});

it('exposes the sentinel write value beyond the boundary', function (): void {
    $now = CarbonImmutable::parse('2026-07-01 12:00:00');

    expect(PublishSentinel::DRAFT_BOUNDARY_YEARS)->toBe(50)
        ->and(PublishSentinel::draftBoundary($now)->toDateTimeString())->toBe($now->addYears(50)->toDateTimeString())
        ->and(PublishSentinel::draftValue($now)->greaterThan(PublishSentinel::draftBoundary($now)))->toBeTrue()
        ->and(PublishSentinel::isDraftValue(PublishSentinel::draftValue($now), $now))->toBeTrue()
        ->and(PublishSentinel::isDraftValue($now->addWeek(), $now))->toBeFalse()
        ->and(PublishSentinel::isDraftValue($now->subDay(), $now))->toBeFalse()
        ->and(PublishSentinel::isDraftValue(null, $now))->toBeFalse();
});

it('maps visibility states onto publish statuses with drafts and schedules collapsing to pending', function (): void {
    expect(PublishStatusEnum::fromVisibilityState(PublishVisibilityStateEnum::draft))->toBe(PublishStatusEnum::pending)
        ->and(PublishStatusEnum::fromVisibilityState(PublishVisibilityStateEnum::scheduled))->toBe(PublishStatusEnum::pending)
        ->and(PublishStatusEnum::fromVisibilityState(PublishVisibilityStateEnum::published))->toBe(PublishStatusEnum::published)
        ->and(PublishStatusEnum::fromVisibilityState(PublishVisibilityStateEnum::expired))->toBe(PublishStatusEnum::expired)
        ->and(PublishStatusEnum::fromVisibilityState(PublishVisibilityStateEnum::deleted))->toBe(PublishStatusEnum::deleted);
});

it('provides translated labels for every visibility state', function (): void {
    expect(PublishVisibilityStateEnum::draft->getLabel())->toBe(__('capell::generic.draft'))->toBe('Draft')
        ->and(PublishVisibilityStateEnum::scheduled->getLabel())->toBe(__('capell::generic.scheduled'))->toBe('Scheduled')
        ->and(PublishVisibilityStateEnum::published->getLabel())->toBe(__('capell::generic.published'))
        ->and(PublishVisibilityStateEnum::expired->getLabel())->toBe(__('capell::generic.expired'))
        ->and(PublishVisibilityStateEnum::deleted->getLabel())->toBe(__('capell::generic.deleted'));
});
