<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\QueueMarketplaceInstallAttemptAction;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('returns the durable operation when an idempotent browser request is repeated', function (): void {
    Queue::fake();
    config()->set('queue.connections.database.retry_after', 900);

    $arguments = [
        'listing' => new ExtensionListingData(
            slug: 'operation-test',
            name: 'Operation Test',
            composerName: 'capell-app/operation-test',
            kind: 'tool',
            description: null,
            priceCents: 0,
            isPaid: false,
            forkRepoUrl: null,
            productId: null,
        ),
        'acquisition' => new ExtensionAcquisitionData(
            composerName: 'capell-app/operation-test',
            versionConstraint: '^1.0',
            composerCommand: 'composer require capell-app/operation-test:^1.0',
            repositoryUrl: null,
            purchaseUrl: null,
            requiresDeployment: false,
        ),
        'eligibility' => new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
        ),
        'betaAcknowledged' => false,
        'policyEvidence' => new MarketplaceInstallPolicyEvidenceData(
            listingFingerprint: hash('sha256', 'operation-test'),
            listingFetchedAt: CarbonImmutable::now(),
            selectedMaturity: 'stable',
            dependencyMaturity: [],
            entitlementAllowed: true,
            compatibilityAllowed: true,
            consentAllowed: true,
        ),
        'actor' => MarketplaceInstallActorData::system('browser-test'),
        'source' => MarketplaceInstallSource::Programmatic,
        'idempotencyKey' => 'browser-request-123',
    ];

    $first = QueueMarketplaceInstallAttemptAction::run(...$arguments);
    $resumed = QueueMarketplaceInstallAttemptAction::run(...$arguments);

    expect($resumed->is($first))->toBeTrue()
        ->and($first->idempotency_key)->toBe(hash('sha256', 'browser-request-123'))
        ->and(MarketplaceInstallAttempt::query()->count())->toBe(1);

    Queue::assertPushed(RunMarketplaceInstallAttemptJob::class, 1);
});

it('blocks an install before recording it when the operations queue is not ready', function (): void {
    Queue::fake();
    config([
        'capell-marketplace.marketplace.operations_queue_connection' => 'marketplace_sync',
        'queue.connections.marketplace_sync' => ['driver' => 'sync'],
    ]);

    $arguments = [
        'listing' => new ExtensionListingData(
            slug: 'blocked-operation-test',
            name: 'Blocked Operation Test',
            composerName: 'capell-app/blocked-operation-test',
            kind: 'tool',
            description: null,
            priceCents: 0,
            isPaid: false,
            forkRepoUrl: null,
            productId: null,
        ),
        'acquisition' => new ExtensionAcquisitionData(
            composerName: 'capell-app/blocked-operation-test',
            versionConstraint: '^1.0',
            composerCommand: 'composer require capell-app/blocked-operation-test:^1.0',
            repositoryUrl: null,
            purchaseUrl: null,
            requiresDeployment: false,
        ),
        'eligibility' => new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
        ),
        'betaAcknowledged' => false,
        'policyEvidence' => new MarketplaceInstallPolicyEvidenceData(
            listingFingerprint: hash('sha256', 'blocked-operation-test'),
            listingFetchedAt: CarbonImmutable::now(),
            selectedMaturity: 'stable',
            dependencyMaturity: [],
            entitlementAllowed: true,
            compatibilityAllowed: true,
            consentAllowed: true,
        ),
        'actor' => MarketplaceInstallActorData::system('browser-test'),
        'source' => MarketplaceInstallSource::Programmatic,
    ];

    try {
        QueueMarketplaceInstallAttemptAction::run(...$arguments);
        test()->fail('Expected the unavailable operations queue to block the install.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('queue_connection')
            ->and($exception->errors()['queue_connection'][0])->toContain('uses the sync driver');
    }

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
