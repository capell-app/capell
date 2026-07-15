<?php

declare(strict_types=1);

use Capell\Core\Actions\Publishing\EvaluatePublicationTransitionAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-07-14 12:00:00');
    CarbonImmutable::setTestNow($this->now);
    $this->actor = new User;
    app()->instance(AuthorizesPublicationTransition::class, new class implements AuthorizesPublicationTransition
    {
        public bool $allowed = true;

        public function allows(PublicationTransitionRequestData $request): bool
        {
            return $this->allowed;
        }
    });
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('evaluates the complete normalized transition matrix without persistence', function (
    PublicationTransition $transition,
    ?CarbonImmutable $from,
    ?CarbonImmutable $until,
    ?CarbonImmutable $requested,
    PublicationTransitionOutcome $outcome,
    ?CarbonImmutable $expectedFrom,
    ?CarbonImmutable $expectedUntil,
    PublishVisibilityStateEnum $expectedState,
): void {
    $page = new Page;
    $page->setAttribute('visible_from', $from);
    $page->setAttribute('visible_until', $until);

    $request = new PublicationTransitionRequestData(
        record: $page,
        transition: $transition,
        actor: $this->actor,
        now: $this->now,
        requestedTime: $requested,
    );

    $result = EvaluatePublicationTransitionAction::run($request);

    expect($result->outcome)->toBe($outcome)
        ->and($result->visibleFrom?->equalTo($expectedFrom) ?? ! $expectedFrom instanceof CarbonImmutable)->toBeTrue()
        ->and($result->visibleUntil?->equalTo($expectedUntil) ?? ! $expectedUntil instanceof CarbonImmutable)->toBeTrue()
        ->and($result->afterState)->toBe($expectedState)
        ->and(publicationTestDate($page->getAttribute('visible_from'))?->equalTo($from) ?? ! $from instanceof CarbonImmutable)->toBeTrue()
        ->and(publicationTestDate($page->getAttribute('visible_until'))?->equalTo($until) ?? ! $until instanceof CarbonImmutable)->toBeTrue();
})->with(function (): array {
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');
    $draft = PublishSentinel::draftValue($now);

    return [
        'scheduled publishes now' => [PublicationTransition::PublishNow, $now->addDay(), null, null, PublicationTransitionOutcome::Changed, $now, null, PublishVisibilityStateEnum::published],
        'expired publishes now and clears expiry' => [PublicationTransition::PublishNow, $now->subMonth(), $now->subDay(), null, PublicationTransitionOutcome::Changed, $now, null, PublishVisibilityStateEnum::published],
        'published is already correct' => [PublicationTransition::PublishNow, $now->subDay(), null, null, PublicationTransitionOutcome::AlreadyCorrect, $now->subDay(), null, PublishVisibilityStateEnum::published],
        'draft sentinel with expiry reverts cleanly' => [PublicationTransition::RevertToDraft, $draft, $now->addDay(), null, PublicationTransitionOutcome::Changed, $draft, null, PublishVisibilityStateEnum::draft],
        'future publish is scheduled' => [PublicationTransition::SchedulePublish, $draft, null, $now->addDay(), PublicationTransitionOutcome::Changed, $now->addDay(), null, PublishVisibilityStateEnum::scheduled],
        'past publish schedule is rejected' => [PublicationTransition::SchedulePublish, $draft, null, $now->subSecond(), PublicationTransitionOutcome::InvalidTransition, $draft, null, PublishVisibilityStateEnum::draft],
        'publish at expiry is rejected' => [PublicationTransition::SchedulePublish, $draft, $now->addDay(), $now->addDay(), PublicationTransitionOutcome::InvalidTransition, $draft, $now->addDay(), PublishVisibilityStateEnum::draft],
        'unpublish after scheduled publish' => [PublicationTransition::ScheduleUnpublish, $now->addDay(), null, $now->addDays(2), PublicationTransitionOutcome::Changed, $now->addDay(), $now->addDays(2), PublishVisibilityStateEnum::scheduled],
        'unpublish before scheduled publish is rejected' => [PublicationTransition::ScheduleUnpublish, $now->addDays(2), null, $now->addDay(), PublicationTransitionOutcome::InvalidTransition, $now->addDays(2), null, PublishVisibilityStateEnum::scheduled],
        'unpublish is effective immediately' => [PublicationTransition::Unpublish, $now->subDay(), null, null, PublicationTransitionOutcome::Changed, $now->subDay(), $now, PublishVisibilityStateEnum::expired],
    ];
});

it('persists exactly one evaluated change', function (): void {
    $page = Page::factory()->createOne([
        'visible_from' => PublishSentinel::draftValue($this->now),
        'visible_until' => $this->now->subDay(),
    ]);
    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->refresh()->visible_from->equalTo($this->now))->toBeTrue()
        ->and($page->visible_until)->toBeNull();
});

it('does not evaluate or persist an unauthorized transition', function (): void {
    $authorizer = resolve(AuthorizesPublicationTransition::class);
    $authorizer->allowed = false;

    $page = Page::factory()->createOne(['visible_from' => PublishSentinel::draftValue($this->now)]);

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Unauthorized)
        ->and($page->refresh()->publishVisibilityState($this->now))->toBe(PublishVisibilityStateEnum::draft);
});

it('returns a safe failed result and rolls back persistence failures', function (): void {
    $page = Page::factory()->createOne(['visible_from' => PublishSentinel::draftValue($this->now)]);
    Page::saving(static function (Page $saving) use ($page): void {
        throw_if($saving->is($page), RuntimeException::class, 'Sensitive database failure');
    });

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Failed)
        ->and($result->reasonKey)->toBe('publication.transition.persistence-failed')
        ->and($page->fresh()->publishVisibilityState($this->now))->toBe(PublishVisibilityStateEnum::draft);
});

function publicationTestDate(mixed $value): ?CarbonImmutable
{
    return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : null;
}
