<?php

declare(strict_types=1);

use Capell\Core\Actions\FirstRun\CompleteGuidedSetupAction;
use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Settings\FirstRunSettings;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User;

beforeEach(function (): void {
    app()->instance(AuthorizesPublicationTransition::class, new class implements AuthorizesPublicationTransition
    {
        public function allows(PublicationTransitionRequestData $request): bool
        {
            return true;
        }
    });
});

function firstRunMilestones(): FirstRunSettings
{
    app()->forgetInstance(FirstRunSettings::class);

    return resolve(FirstRunSettings::class);
}

function publishForFirstRun(Page $page, CarbonImmutable $now): void
{
    TransitionPublicationAction::run(new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: new User,
        now: $now,
    ));
}

it('starts a new install with no milestones recorded', function (): void {
    $settings = firstRunMilestones();

    expect($settings->setup_completed_at)->toBeNull()
        ->and($settings->published_at)->toBeNull()
        ->and($settings->suite_key)->toBeNull()
        ->and($settings->business_type)->toBeNull()
        ->and($settings->preset_key)->toBeNull();
});

it('records guided setup once and keeps the first completion', function (): void {
    $first = CarbonImmutable::parse('2026-11-01 09:00:00', 'UTC');

    expect(CompleteGuidedSetupAction::run('dentist', ' family-practice ', $first))->toBeTrue()
        ->and(CompleteGuidedSetupAction::run('bakery', 'bakery', $first->addDay()))->toBeFalse();

    $settings = firstRunMilestones();

    expect($settings->setup_completed_at?->equalTo($first))->toBeTrue()
        ->and($settings->business_type)->toBe('dentist')
        ->and($settings->preset_key)->toBe('family-practice');
});

it('records the first publication once and never moves it', function (): void {
    $first = CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC');

    expect(RecordSitePublishedAction::run($first))->toBeTrue()
        ->and(RecordSitePublishedAction::run($first->addWeek()))->toBeFalse()
        ->and(firstRunMilestones()->published_at?->equalTo($first))->toBeTrue();
});

it('records the site as published when its home page is made public', function (): void {
    $now = CarbonImmutable::parse('2026-11-03 11:00:00', 'UTC');
    $site = Site::factory()->createOne();
    $about = Page::factory()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    $home = Page::factory()->home()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);

    publishForFirstRun($about, $now);

    expect(firstRunMilestones()->published_at)->toBeNull();

    publishForFirstRun($home, $now);

    expect(firstRunMilestones()->published_at?->equalTo($now))->toBeTrue();
});

it('does not record publication when the home page was already public', function (): void {
    $now = CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC');
    $site = Site::factory()->createOne();
    $home = Page::factory()->home()->site($site)->createOne(['visible_from' => $now->subDay()]);

    publishForFirstRun($home, $now);

    expect(firstRunMilestones()->published_at)->toBeNull();
});
