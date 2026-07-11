<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Enums\PageWorkflowStatus;
use Capell\Core\EventSourcing\Events\PageApproved;
use Capell\Core\EventSourcing\Events\PageArchived;
use Capell\Core\EventSourcing\Events\PageChangesRequested;
use Capell\Core\EventSourcing\Events\PagePublishedNow;
use Capell\Core\EventSourcing\Events\PageSubmittedForReview;
use Capell\Core\EventSourcing\Exceptions\InvalidAggregateTransition;
use Carbon\CarbonImmutable;

it('records a submit-for-review event from draft', function (): void {
    PageAggregate::fake()
        ->when(fn (PageAggregate $aggregate): PageAggregate => $aggregate->submitForReview())
        ->assertRecorded(new PageSubmittedForReview);
});

it('approves a page that is in review', function (): void {
    PageAggregate::fake()
        ->given([new PageSubmittedForReview])
        ->when(fn (PageAggregate $aggregate): PageAggregate => $aggregate->approve())
        ->assertRecorded(new PageApproved);
});

it('cannot approve a page that is not in review', function (): void {
    expect(fn () => PageAggregate::fake()->approve())
        ->toThrow(InvalidAggregateTransition::class);
});

it('cannot approve a page twice', function (): void {
    expect(fn () => PageAggregate::fake()
        ->given([new PageSubmittedForReview, new PageApproved])
        ->approve())
        ->toThrow(InvalidAggregateTransition::class);
});

it('requires a note when requesting changes', function (): void {
    expect(fn () => PageAggregate::fake()
        ->given([new PageSubmittedForReview])
        ->requestChanges('   '))
        ->toThrow(InvalidAggregateTransition::class);
});

it('records a changes-requested event with its note', function (): void {
    PageAggregate::fake()
        ->given([new PageSubmittedForReview])
        ->when(fn (PageAggregate $aggregate): PageAggregate => $aggregate->requestChanges('Fix the heading'))
        ->assertRecorded(new PageChangesRequested('Fix the heading'));
});

it('cannot schedule a publish in the past', function (): void {
    expect(fn () => PageAggregate::fake()
        ->schedulePublish(CarbonImmutable::now()->subDay()))
        ->toThrow(InvalidAggregateTransition::class);
});

it('cannot publish from archived', function (): void {
    expect(fn () => PageAggregate::fake()
        ->given([new PageArchived])
        ->publishNow())
        ->toThrow(InvalidAggregateTransition::class);
});

it('walks the happy path draft -> review -> approved -> published', function (): void {
    PageAggregate::fake()
        ->when(function (PageAggregate $aggregate): void {
            $aggregate->submitForReview()->approve()->publishNow();
        })
        ->assertRecorded([
            new PageSubmittedForReview,
            new PageApproved,
            new PagePublishedNow,
        ]);
});

it('exposes the current status after replaying events', function (): void {
    $aggregate = PageAggregate::fake()
        ->given([new PageSubmittedForReview, new PageApproved])
        ->aggregateRoot();

    // FakeAggregateRoot::aggregateRoot() is typed to the base AggregateRoot, so
    // narrow back to the concrete aggregate before asserting on its status.
    expect($aggregate)->toBeInstanceOf(PageAggregate::class);

    if (! $aggregate instanceof PageAggregate) {
        return;
    }

    expect($aggregate->currentStatus())->toBe(PageWorkflowStatus::Approved);
});
