<?php

declare(strict_types=1);

use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Exceptions\InvalidPublicationTransitionRequest;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

it('defines every stable publication transition value', function (): void {
    expect(array_column(PublicationTransition::cases(), 'value'))->toBe([
        'cancel-schedule',
        'publish-now',
        'revert-to-draft',
        'schedule-publish',
        'schedule-unpublish',
        'unpublish',
    ]);
});

it('requires an explicit actor and frozen clock', function (): void {
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');
    $actor = new User;
    $request = new PublicationTransitionRequestData(
        record: new Page,
        transition: PublicationTransition::PublishNow,
        actor: $actor,
        now: $now,
    );

    expect($request->actor)->toBe($actor)
        ->and($request->now)->toBe($now)
        ->and($request->requestedTime)->toBeNull();
});

it('requires a time only for scheduled transitions', function (PublicationTransition $transition): void {
    new PublicationTransitionRequestData(
        record: new Page,
        transition: $transition,
        actor: new User,
        now: CarbonImmutable::parse('2026-07-14 12:00:00'),
    );
})->with([PublicationTransition::SchedulePublish, PublicationTransition::ScheduleUnpublish])
    ->throws(InvalidPublicationTransitionRequest::class);

it('forbids a time for immediate transitions', function (PublicationTransition $transition): void {
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');

    new PublicationTransitionRequestData(
        record: new Page,
        transition: $transition,
        actor: new User,
        now: $now,
        requestedTime: $now->addHour(),
    );
})->with([
    PublicationTransition::PublishNow,
    PublicationTransition::RevertToDraft,
    PublicationTransition::Unpublish,
])->throws(InvalidPublicationTransitionRequest::class);
