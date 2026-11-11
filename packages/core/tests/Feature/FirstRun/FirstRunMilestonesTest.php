<?php

declare(strict_types=1);

use Capell\Core\Actions\FirstRun\RecordSitePublishedAction;
use Capell\Core\Actions\Publishing\TransitionPublicationAction;
use Capell\Core\Contracts\Publishing\AuthorizesPublicationTransition;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Settings\FirstRunSettings;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\Upgrade\RecordPublishedSitesUpgradeStep;
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

function firstRunMilestones(): SitePublicationMilestones
{
    app()->forgetInstance(FirstRunSettings::class);

    return resolve(SitePublicationMilestones::class);
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

it('starts a new install with no site recorded as live', function (): void {
    $site = Site::factory()->createOne();

    expect(firstRunMilestones()->publishedSiteIds())->toBe([])
        ->and(firstRunMilestones()->hasPublished($site->id))->toBeFalse()
        ->and(firstRunMilestones()->publishedAt($site->id))->toBeNull();
});

it('records each site once and never moves its first go-live time', function (): void {
    $first = CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC');

    expect(RecordSitePublishedAction::run(7, $first))->toBeTrue()
        ->and(RecordSitePublishedAction::run(7, $first->addWeek()))->toBeFalse()
        ->and(RecordSitePublishedAction::run(9, $first->addDay()))->toBeTrue()
        ->and(RecordSitePublishedAction::run(0, $first))->toBeFalse();

    expect(firstRunMilestones()->publishedSiteIds())->toBe([7, 9])
        ->and(firstRunMilestones()->publishedAt(7)?->equalTo($first))->toBeTrue()
        ->and(firstRunMilestones()->publishedAt(9)?->equalTo($first->addDay()))->toBeTrue()
        ->and(firstRunMilestones()->hasPublished(8))->toBeFalse();
});

it('records only the site whose home page is made public', function (): void {
    $now = CarbonImmutable::parse('2026-11-03 11:00:00', 'UTC');
    $site = Site::factory()->createOne();
    $other = Site::factory()->createOne();
    $about = Page::factory()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    $home = Page::factory()->home()->site($site)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    Page::factory()->home()->site($other)->createOne(['visible_from' => PublishSentinel::draftValue()]);

    publishForFirstRun($about, $now);

    expect(firstRunMilestones()->publishedSiteIds())->toBe([]);

    publishForFirstRun($home, $now);

    expect(firstRunMilestones()->publishedSiteIds())->toBe([$site->id])
        ->and(firstRunMilestones()->publishedAt($site->id)?->equalTo($now))->toBeTrue()
        ->and(firstRunMilestones()->hasPublished($other->id))->toBeFalse();
});

it('does not record a home page published but hidden from search engines', function (): void {
    $now = CarbonImmutable::parse('2026-11-03 11:00:00', 'UTC');
    $site = Site::factory()->createOne();
    $home = Page::factory()->home()->site($site)->createOne([
        'visible_from' => PublishSentinel::draftValue(),
        'meta' => ['robots' => 'noindex'],
    ]);

    publishForFirstRun($home, $now);

    expect(firstRunMilestones()->hasPublished($site->id))->toBeFalse();
});

it('does not record publication when the home page was already public', function (): void {
    $now = CarbonImmutable::parse('2026-11-04 12:00:00', 'UTC');
    $site = Site::factory()->createOne();
    $home = Page::factory()->home()->site($site)->createOne(['visible_from' => $now->subDay()]);

    publishForFirstRun($home, $now);

    expect(firstRunMilestones()->publishedSiteIds())->toBe([]);
});

it('records sites that were already live when the upgrade runs, and only those', function (): void {
    $live = Site::factory()->createOne();
    $draft = Site::factory()->createOne();
    $hidden = Site::factory()->createOne();
    Page::factory()->home()->site($live)->createOne(['visible_from' => now()->subMonth()]);
    Page::factory()->home()->site($draft)->createOne(['visible_from' => PublishSentinel::draftValue()]);
    Page::factory()->home()->site($hidden)->createOne(['visible_from' => now()->subMonth(), 'meta' => ['noindex' => true]]);
    $step = new RecordPublishedSitesUpgradeStep;
    $context = new UpgradeContext([], [], []);

    expect($step->id())->toBe('core.record-published-sites')
        ->and(collect(app()->tagged('capell.upgrade-steps'))->map(fn (object $tagged): string => $tagged::class)->all())->toContain(RecordPublishedSitesUpgradeStep::class)
        ->and($step->shouldRun($context))->toBeTrue()
        ->and($step->run($context))->toBeTrue()
        ->and($step->run($context))->toBeTrue()
        ->and($step->rollback($context))->toBeTrue();

    expect(firstRunMilestones()->publishedSiteIds())->toBe([$live->id])
        // The draft and search-hidden sites have not gone live, so the step still has work to offer later.
        ->and($step->shouldRun($context))->toBeTrue();

    RecordSitePublishedAction::run($draft->id);
    RecordSitePublishedAction::run($hidden->id);
    app()->forgetInstance(FirstRunSettings::class);

    expect($step->shouldRun($context))->toBeFalse();
});
