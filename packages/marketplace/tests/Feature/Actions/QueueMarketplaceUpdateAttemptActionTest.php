<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\QueueMarketplaceBulkUpdateAction;
use Capell\Marketplace\Actions\QueueMarketplaceUpdateAttemptAction;
use Capell\Marketplace\Actions\UpdateMarketplaceExtensionAction;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function updateListing(): ExtensionListingData
{
    return new ExtensionListingData(
        slug: 'seo-suite',
        name: 'SEO Suite',
        composerName: 'capell-app/seo-suite',
        kind: 'plugin',
        description: null,
        priceCents: 0,
        isPaid: false,
        forkRepoUrl: null,
        productId: null,
        latestVersion: '2.4.0',
    );
}

function updateAcquisition(): ExtensionAcquisitionData
{
    return new ExtensionAcquisitionData(
        composerName: 'capell-app/seo-suite',
        versionConstraint: '^2.4.0',
        composerCommand: 'composer require capell-app/seo-suite:^2.4.0',
        repositoryUrl: null,
        purchaseUrl: null,
        requiresDeployment: false,
    );
}

function queueUpdateAttempt(?string $idempotencyKey = null): MarketplaceInstallAttempt
{
    CapellCore::registerPackage('capell-app/seo-suite', version: '2.1.0');
    CapellCore::forcePackageInstalled('capell-app/seo-suite');

    return QueueMarketplaceUpdateAttemptAction::run(
        listing: updateListing(),
        acquisition: updateAcquisition(),
        actor: MarketplaceInstallActorData::system('update-test'),
        source: MarketplaceInstallSource::TableHelper,
        currentVersion: '2.1.0',
        idempotencyKey: $idempotencyKey,
    );
}

it('records the operation as an update and dispatches the update job', function (): void {
    Queue::fake();

    $attempt = queueUpdateAttempt();

    expect($attempt->operation)->toBe(MarketplaceOperationType::Update)
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->version_constraint)->toBe('^2.4.0')
        ->and($attempt->context['update_from_version'] ?? null)->toBe('2.1.0');

    Queue::assertPushed(
        RunMarketplaceUpdateAttemptJob::class,
        fn (RunMarketplaceUpdateAttemptJob $job): bool => $job->uniqueId() === (string) $attempt->getKey(),
    );
});

it('refuses to queue a second operation for a package that is already busy', function (): void {
    Queue::fake();

    queueUpdateAttempt();

    expect(fn (): mixed => queueUpdateAttempt())->toThrow(ValidationException::class);

    expect(MarketplaceInstallAttempt::query()->where('composer_name', 'capell-app/seo-suite')->count())->toBe(1);
});

it('returns the existing attempt rather than queueing twice for the same idempotency key', function (): void {
    Queue::fake();

    $first = queueUpdateAttempt('operator-double-click');
    $second = queueUpdateAttempt('operator-double-click');

    expect($second->getKey())->toBe($first->getKey());

    Queue::assertPushed(RunMarketplaceUpdateAttemptJob::class, 1);
});

it('queues each selected extension separately and reports the ones it skipped', function (): void {
    Queue::fake();

    // Neither package is installed on this test site, so both are refused — and
    // the point of the assertion is that the second refusal is still reached and
    // still reported rather than the first one aborting the whole bulk action.
    $result = QueueMarketplaceBulkUpdateAction::run(
        composerNames: ['capell-app/seo-suite', 'capell-app/forms'],
        actor: MarketplaceInstallActorData::system('bulk-update-test'),
    );

    expect($result->requestedCount)->toBe(2)
        ->and($result->queuedCount())->toBe(0)
        ->and($result->queuedAnything())->toBeFalse()
        ->and(array_keys($result->skipped))->toBe(['capell-app/seo-suite', 'capell-app/forms'])
        ->and($result->skipped['capell-app/forms'])->toContain('is not installed');

    Queue::assertNothingPushed();
});

it('counts a repeated selection once', function (): void {
    Queue::fake();

    $result = QueueMarketplaceBulkUpdateAction::run(
        composerNames: ['capell-app/seo-suite', 'capell-app/seo-suite'],
        actor: MarketplaceInstallActorData::system('bulk-update-test'),
    );

    expect($result->requestedCount)->toBe(1)
        ->and($result->skipped)->toHaveCount(1);
});

it('refuses a one-click update for an extension that is not installed', function (): void {
    expect(fn (): mixed => UpdateMarketplaceExtensionAction::run(
        composerName: 'capell-app/definitely-not-installed',
        actor: MarketplaceInstallActorData::system('update-test'),
    ))->toThrow(ValidationException::class);
});

it('summarises a partially queued bulk update for the operator', function (): void {
    Queue::fake();

    $result = QueueMarketplaceBulkUpdateAction::run(
        composerNames: ['capell-app/seo-suite'],
        actor: MarketplaceInstallActorData::system('bulk-update-test'),
    );

    expect($result->summaryBody())
        ->toContain((string) __('capell-marketplace::marketplace.updates.bulk_queued_body', [
            'queued' => 0,
            'requested' => 1,
        ]))
        ->toContain('capell-app/seo-suite');
});
