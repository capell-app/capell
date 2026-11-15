<?php

declare(strict_types=1);

use Capell\Core\Actions\Ai\ConfirmAiProvenanceAction;
use Capell\Core\Actions\Ai\RecordAiProvenanceAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Ai\AiProvenanceRecordData;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\AiDisclosure;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-15 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->now);
    app()->instance(AuthorizesPublicationTransition::class, new class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return true;
        }
    });
});

afterEach(fn () => CarbonImmutable::setTestNow());

function publishAiGatePage(Page $page, PublicationTransition $transition = PublicationTransition::PublishNow): PublicationTransitionOutcome
{
    return publishAiGateResult($page, $transition)->outcome;
}

function publishAiGateResult(Page $page, PublicationTransition $transition = PublicationTransition::PublishNow): PublicationTransitionResultData
{
    return TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: $transition,
        actor: new User,
        now: CarbonImmutable::now(),
        requestedTime: $transition === PublicationTransition::SchedulePublish ? CarbonImmutable::now()->addDay() : null,
    ));
}

function draftAiPage(AiDisclosure $disclosure): Page
{
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue(CarbonImmutable::now())]);
    RecordAiProvenanceAction::run(new AiProvenanceRecordData($page, 'content', $disclosure));

    return $page;
}

it('blocks publishing and scheduling a page with unreviewed AI-written fields', function (PublicationTransition $transition): void {
    $page = draftAiPage(AiDisclosure::Generated);

    $result = publishAiGateResult($page, $transition);

    expect($result->outcome)->toBe(PublicationTransitionOutcome::InvalidTransition)
        ->and($result->reasonKey)->toBe('publication.transition.ai_review_required')
        ->and(Page::query()->whereKey($page->getKey())->sole()->isDraftSentinel())->toBeTrue();
})->with([PublicationTransition::PublishNow, PublicationTransition::SchedulePublish]);

it('publishes once a person has reviewed the AI-written fields', function (): void {
    $page = draftAiPage(AiDisclosure::Assisted);
    ConfirmAiProvenanceAction::run($page, reviewerId: 1);

    expect(publishAiGatePage($page))->toBe(PublicationTransitionOutcome::Changed);
});

it('does not hold back a field marked as written by a person', function (): void {
    expect(publishAiGatePage(draftAiPage(AiDisclosure::None)))->toBe(PublicationTransitionOutcome::Changed);
});
