<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\QueueMarketplaceInstallAttemptAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallAttemptEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
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
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('queue_connection')
            ->and($validationException->errors()['queue_connection'][0])->toContain('uses the sync driver');
    }

    expect(MarketplaceInstallAttempt::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('preserves supplied deployment audit metadata when preflight fails', function (): void {
    Queue::fake();
    config()->set('queue.connections.database.retry_after', 60);
    $arguments = queueMarketplaceAttemptArguments('preflight-audit-metadata');
    $arguments['deploymentMetadata'] = [
        'authorization' => 'authorized',
        'image' => 'registry.test/capell/preflight-audit-metadata',
        'description' => 'Deployment audit metadata.',
    ];

    $attempt = QueueMarketplaceInstallAttemptAction::run(...$arguments);

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($attempt->deployment)->toMatchArray($arguments['deploymentMetadata']);

    Queue::assertNothingPushed();
});

it('preserves publication evidence without dispatching when cancellation wins during publication', function (): void {
    Queue::fake();
    config()->set('queue.connections.database.retry_after', 900);

    $publisher = new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            $attempt = MarketplaceInstallAttempt::query()->findOrFail((int) $request->operationId);
            CancelMarketplaceInstallAttemptAction::run($attempt);

            return new MarketplaceComposerPublicationResultData(
                pullRequestUrl: 'https://github.test/capell/pulls/4242',
            );
        }
    };
    app()->instance('test.marketplace.queue-cancelling-publisher', $publisher);
    app()->tag(
        ['test.marketplace.queue-cancelling-publisher'],
        MarketplaceComposerChangePublisher::TAG,
    );

    $attempt = QueueMarketplaceInstallAttemptAction::run(
        ...queueMarketplaceAttemptArguments('cancel-during-publication'),
    );

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->deployment)->toMatchArray([
            'status' => 'published',
            'reference' => 'https://github.test/capell/pulls/4242',
            'type' => 'pull_request',
        ])
        ->and($attempt->failure_type)->toBeNull()
        ->and($attempt->resolved_at)->not->toBeNull();

    Queue::assertNothingPushed();
});

it('does not invoke the deployment publisher when cancellation commits before publication is claimed', function (): void {
    Queue::fake();
    config()->set('queue.connections.database.retry_after', 900);

    Event::listen(
        'eloquent.created: ' . MarketplaceInstallAttemptEvent::class,
        function (MarketplaceInstallAttemptEvent $event): void {
            if (($event->context['check'] ?? null) !== 'queue_retry_after') {
                return;
            }

            CancelMarketplaceInstallAttemptAction::run($event->attempt()->firstOrFail());
        },
    );

    $publisher = new class implements MarketplaceComposerChangePublisher
    {
        public int $calls = 0;

        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            $this->calls++;

            return new MarketplaceComposerPublicationResultData(
                pullRequestUrl: 'https://github.test/capell/pulls/never',
            );
        }
    };
    app()->instance('test.marketplace.queue-unclaimed-publisher', $publisher);
    app()->tag(
        ['test.marketplace.queue-unclaimed-publisher'],
        MarketplaceComposerChangePublisher::TAG,
    );

    $attempt = QueueMarketplaceInstallAttemptAction::run(
        ...queueMarketplaceAttemptArguments('cancel-before-publication'),
    );

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($attempt->current_stage)->toBeNull()
        ->and($publisher->calls)->toBe(0);

    Queue::assertNothingPushed();
});

/**
 * @return array<string, mixed>
 */
function queueMarketplaceAttemptArguments(string $slug): array
{
    $composerName = 'capell-app/' . $slug;

    return [
        'listing' => new ExtensionListingData(
            slug: $slug,
            name: str($slug)->headline()->toString(),
            composerName: $composerName,
            kind: 'tool',
            description: null,
            priceCents: 0,
            isPaid: false,
            forkRepoUrl: null,
            productId: null,
        ),
        'acquisition' => new ExtensionAcquisitionData(
            composerName: $composerName,
            versionConstraint: '^1.0',
            composerCommand: 'composer require ' . $composerName . ':^1.0',
            repositoryUrl: 'https://github.test/capell/' . $slug,
            purchaseUrl: null,
            requiresDeployment: true,
        ),
        'eligibility' => new MarketplaceInstallEligibilityData(
            state: MarketplaceInstallState::Authorized,
            canInstall: true,
        ),
        'betaAcknowledged' => false,
        'policyEvidence' => new MarketplaceInstallPolicyEvidenceData(
            listingFingerprint: hash('sha256', $slug),
            listingFetchedAt: CarbonImmutable::now(),
            selectedMaturity: 'stable',
            dependencyMaturity: [],
            entitlementAllowed: true,
            compatibilityAllowed: true,
            consentAllowed: true,
        ),
        'actor' => MarketplaceInstallActorData::system('queue-action-test'),
        'source' => MarketplaceInstallSource::Programmatic,
    ];
}
