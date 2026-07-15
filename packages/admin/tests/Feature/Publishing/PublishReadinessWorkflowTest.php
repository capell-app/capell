<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildPublishingWorkflowEntryAction;
use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Publishing\PublishSentinel;
use Carbon\CarbonImmutable;

it('builds one typed readiness view with blockers schedules and public effect', function (): void {
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');
    CarbonImmutable::setTestNow($now);
    $page = Page::factory()->createOne([
        'visible_from' => $now->addDay(),
        'visible_until' => $now->addDays(2),
    ]);

    $readiness = BuildPublishReadinessAction::run($page);

    expect($readiness->currentState)->toBe(PublishVisibilityStateEnum::scheduled)
        ->and($readiness->scheduledPublishAt?->equalTo($now->addDay()))->toBeTrue()
        ->and($readiness->scheduledUnpublishAt?->equalTo($now->addDays(2)))->toBeTrue()
        ->and($readiness->publicEligible)->toBeFalse()
        ->and($readiness->allowedTransitions)->toContain('publish-now', 'revert-to-draft')
        ->and($readiness->blockingCheckIds)->toContain('publishing.translation.missing', 'publishing.url.active-missing');
});

it('attaches the normalized transition preview without mutating the record', function (): void {
    test()->actingAsAdmin();
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');
    $page = new Page;
    $page->visible_from = PublishSentinel::draftValue($now);
    $request = new PublicationTransitionRequestData(
        record: $page,
        transition: PublicationTransition::PublishNow,
        actor: test()->authenticatedUser(),
        now: $now,
    );

    $readiness = BuildPublishReadinessAction::run($page, $request);

    expect($readiness->preview?->outcome)->toBe(PublicationTransitionOutcome::Changed)
        ->and($readiness->preview?->visibleFrom?->equalTo($now))->toBeTrue()
        ->and($page->visible_from?->equalTo(PublishSentinel::draftValue($now)))->toBeTrue();
});

it('builds mixed bulk readiness from the same action', function (): void {
    $now = CarbonImmutable::parse('2026-07-14 12:00:00');
    $draft = new Page;
    $draft->visible_from = PublishSentinel::draftValue($now);
    $published = new Page;
    $published->visible_from = $now->subDay();

    $readiness = resolve(BuildPublishReadinessAction::class)->handleMany([$draft, $published]);

    expect(array_column($readiness, 'currentState'))->toBe([
        PublishVisibilityStateEnum::draft,
        PublishVisibilityStateEnum::published,
    ]);
});

it('lets the dashboard derive its fallback attention count from readiness', function (): void {
    test()->actingAsAdmin();
    $manifest = CapellManifestData::fromArray(capellManifestV3Array(
        name: 'vendor/readiness-dashboard',
        overrides: [
            'contributes' => [[
                'type' => 'workflow-attention',
                'label' => 'Publishing readiness',
                'managementUrl' => '/admin/readiness',
            ]],
        ],
    ));
    resolve(CapellPackageRegistry::class)->fill([$manifest->name => $manifest]);
    CapellCore::registerManifestPackage($manifest);
    CapellCore::forcePackageInstalled($manifest->name);

    $page = new Page;
    $page->visible_from = PublishSentinel::draftValue();

    $entry = BuildPublishingWorkflowEntryAction::run(test()->authenticatedUser(), $page);

    expect($entry)->not->toBeNull()
        ->and($entry->count)->toBe(2);
});
