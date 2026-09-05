<?php

declare(strict_types=1);

use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Actions\Properties\SetPagePropertyValuesAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-07-14 12:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->now);
    $this->actor = new User;
    app()->instance(AuthorizesPublicationTransition::class, new class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return true;
        }
    });
});

afterEach(fn () => CarbonImmutable::setTestNow());

function attachGatePropertySet(Page $page, PropertyRequirement $requirement): PropertyDefinition
{
    $blueprint = Blueprint::query()->findOrFail($page->blueprint_id);
    $set = PropertySet::factory()->create(['key' => 'test.gate']);
    $definition = PropertyDefinition::factory()->create([
        'property_set_id' => $set->id,
        'key' => 'critical',
        'type' => PropertyType::Text,
        'requirement' => $requirement,
        'locked' => true,
    ]);
    BlueprintPropertySet::factory()->create(['blueprint_id' => $blueprint->id, 'property_set_id' => $set->id]);

    return $definition;
}

it('blocks publishing when a required:publish property has no value', function (): void {
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue($this->now)]);
    attachGatePropertySet($page, PropertyRequirement::Publish);

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::InvalidTransition)
        ->and($page->fresh()->isDraftSentinel())->toBeTrue();
});

it('allows publishing when a required:contract property has no value, but marks the page agent-incomplete', function (): void {
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue($this->now)]);
    attachGatePropertySet($page, PropertyRequirement::Contract);

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed);

    $completeness = EvaluatePropertyCompletenessAction::run($page->fresh());

    expect($completeness->isAgentComplete())->toBeFalse()
        ->and($completeness->blocksPublish())->toBeFalse()
        ->and($completeness->missingContractRequired)->toBe(['test.gate.critical']);
});

it('allows publishing once the required:publish value is set', function (): void {
    $page = Page::factory()->create(['visible_from' => PublishSentinel::draftValue($this->now)]);
    attachGatePropertySet($page, PropertyRequirement::Publish);

    SetPagePropertyValuesAction::run($page, [
        new PropertyValueData(propertyKey: 'critical', type: PropertyType::Text, value: 'filled in'),
    ]);

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page->fresh(),
        transition: PublicationTransition::PublishNow,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($page->fresh()->getAttribute('visible_from'))->not->toBeNull();
});

it('never blocks a transition that narrows visibility, regardless of completeness', function (): void {
    $page = Page::factory()->create(['visible_from' => $this->now->subDay()]);
    attachGatePropertySet($page, PropertyRequirement::Publish);

    $result = TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::Unpublish,
        actor: $this->actor,
        now: $this->now,
    ));

    expect($result->outcome)->toBe(PublicationTransitionOutcome::Changed);
});
