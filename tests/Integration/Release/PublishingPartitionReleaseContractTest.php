<?php

declare(strict_types=1);

use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

it('persists one authorized publication transition without crossing record boundaries', function (): void {
    app()->instance(AuthorizesPublicationTransition::class, new class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return true;
        }
    });
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');
    $selected = Page::factory()->createOne(['visible_from' => PublishSentinel::draftValue($now)]);
    $unselected = Page::factory()->createOne(['visible_from' => PublishSentinel::draftValue($now)]);

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $selected,
        transition: PublicationTransition::PublishNow,
        actor: new User,
        now: $now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($selected->refresh()->visible_from?->equalTo($now))->toBeTrue()
        ->and($unselected->refresh()->visible_from?->equalTo(PublishSentinel::draftValue($now)))->toBeTrue();
});
