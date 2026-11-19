<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\QueueMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallDeploymentAction;
use Capell\Marketplace\Actions\ResumeMarketplaceAttemptsAwaitingDeploymentAction;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\TransitionMarketplaceInstallAttemptAction;
use Capell\Marketplace\Contracts\MarketplaceComposerChangePublisher;
use Capell\Marketplace\Data\ExtensionAcquisitionData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationRequestData;
use Capell\Marketplace\Data\MarketplaceComposerPublicationResultData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Data\MarketplaceInstallDeploymentData;
use Capell\Marketplace\Data\MarketplaceInstallEligibilityData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Queue::fake();
    config()->set('queue.connections.database.retry_after', 900);

    app()->instance('test.marketplace.deployment-wait-publisher', new class implements MarketplaceComposerChangePublisher
    {
        public function publish(MarketplaceComposerPublicationRequestData $request): MarketplaceComposerPublicationResultData
        {
            return new MarketplaceComposerPublicationResultData(
                pullRequestUrl: 'https://github.test/capell/pulls/' . $request->operationId,
            );
        }
    });
    app()->tag(['test.marketplace.deployment-wait-publisher'], MarketplaceComposerChangePublisher::TAG);
});

it('waits for the deploy instead of running Composer on the site when the handoff is published', function (): void {

    $attempt = QueueMarketplaceInstallAttemptAction::run(...deploymentWaitArguments('handoff-wait'));

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::AwaitingDeployment)
        ->and($attempt->current_stage)->toBe(MarketplaceInstallFailureStage::DeploymentHandoff->value)
        ->and($attempt->deployment)->toMatchArray(['status' => 'published', 'type' => 'pull_request'])
        ->and($attempt->events->last()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_deployment_awaiting'));

    // Holding the package: a second install of it is refused while the deploy is pending.
    expect($attempt->status->isActiveInstallOperation())->toBeTrue();
    Queue::assertNothingPushed();
});

it('continues the operation once the deploy has put the package on this release', function (): void {
    $attempt = QueueMarketplaceInstallAttemptAction::run(...deploymentWaitArguments('handoff-arrives'));

    expect(Artisan::call('capell:marketplace:resume-deployed'))->toBe(0);

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::AwaitingDeployment);
    Queue::assertNothingPushed();

    // The deploy is live: this release can now discover the package.
    CapellCore::partialMock()->shouldReceive('hasPackage')->with('capell-app/handoff-arrives')->andReturnTrue();
    CapellCore::partialMock()->shouldReceive('isPackageAvailable')->with('capell-app/handoff-arrives')->andReturnTrue();
    CapellCore::partialMock()->shouldReceive('isPackageInstalled')->with('capell-app/handoff-arrives')->andReturnFalse();
    expect(Artisan::call('capell:marketplace:resume-deployed'))->toBe(0);

    $attempt->refresh();
    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->context[ResumeMarketplaceAttemptsAwaitingDeploymentAction::DELIVERED_CONTEXT_KEY] ?? null)->toBeTrue()
        ->and($attempt->events->last()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_deployment_arrived'));
    Queue::assertPushed(
        RunMarketplaceInstallAttemptJob::class,
        fn (RunMarketplaceInstallAttemptJob $job): bool => $job->uniqueId() === (string) $attempt->getKey(),
    );
});

it('fails a waiting operation when its deploy is reported failed or never arrives', function (): void {
    $reported = QueueMarketplaceInstallAttemptAction::run(...deploymentWaitArguments('handoff-rejected'));
    $abandoned = QueueMarketplaceInstallAttemptAction::run(...deploymentWaitArguments('handoff-abandoned'));

    $reported = RecordMarketplaceInstallDeploymentAction::run($reported, new MarketplaceInstallDeploymentData([
        'status' => 'failed',
        'failure_reason' => 'The pull request was closed without merging.',
    ]));

    expect($reported->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($reported->failure_stage)->toBe(MarketplaceInstallFailureStage::DeploymentHandoff->value)
        ->and($reported->failure_reason)->toContain('The pull request was closed without merging.');

    $this->travel(73)->hours();
    expect(Artisan::call('capell:marketplace:resume-deployed'))->toBe(0);

    $abandoned->refresh();
    expect($abandoned->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($abandoned->failure_stage)->toBe(MarketplaceInstallFailureStage::DeploymentHandoff->value)
        ->and($abandoned->failure_reason)->toBe((string) __('capell-marketplace::marketplace.operations.deployment_wait_expired', ['hours' => 72]));

    // Retrying either in place would run Composer on this site instead of the pipeline.
    expect(resolve(RetryMarketplaceInstallAttemptAction::class)->canRetry($reported))->toBeFalse()
        ->and(fn (): MarketplaceInstallAttempt => RetryMarketplaceInstallAttemptAction::run($abandoned))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
});

it('lets an operator retry a delivered operation that failed later, still without running Composer here', function (): void {
    $attempt = QueueMarketplaceInstallAttemptAction::run(...deploymentWaitArguments('handoff-delivered-retry'));
    CapellCore::partialMock()->shouldReceive('hasPackage')->with('capell-app/handoff-delivered-retry')->andReturnTrue();
    CapellCore::partialMock()->shouldReceive('isPackageAvailable')->with('capell-app/handoff-delivered-retry')->andReturnTrue();
    CapellCore::partialMock()->shouldReceive('isPackageInstalled')->with('capell-app/handoff-delivered-retry')->andReturnFalse();
    expect(Artisan::call('capell:marketplace:resume-deployed'))->toBe(0);

    // The deploy arrived, then setup failed on this site (a migration, say).
    $running = TransitionMarketplaceInstallAttemptAction::run($attempt->refresh(), new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Running));
    $failed = TransitionMarketplaceInstallAttemptAction::run($running, new MarketplaceInstallAttemptTransitionData(
        toStatus: MarketplaceInstallIntentStatus::Failed,
        failureReason: 'Migration failed.',
        failureStage: MarketplaceInstallFailureStage::Migration,
    ));

    $retry = RetryMarketplaceInstallAttemptAction::run($failed);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($retry->retry_of_id)->toBe($failed->getKey())
        ->and($retry->context[ResumeMarketplaceAttemptsAwaitingDeploymentAction::DELIVERED_CONTEXT_KEY] ?? null)->toBeTrue();
    Queue::assertPushed(
        RunMarketplaceInstallAttemptJob::class,
        fn (RunMarketplaceInstallAttemptJob $job): bool => $job->uniqueId() === (string) $retry->getKey(),
    );
});

it('warns that the published change is still open when a waiting operation is cancelled', function (): void {
    $attempt = QueueMarketplaceInstallAttemptAction::run(...deploymentWaitArguments('handoff-cancelled'));

    $cancelled = CancelMarketplaceInstallAttemptAction::run($attempt);

    expect($cancelled->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($cancelled->status->isActiveInstallOperation())->toBeFalse()
        ->and($cancelled->events->last()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_cancelled_awaiting_deployment'));

    expect(Artisan::call('capell:marketplace:resume-deployed'))->toBe(0);
    Queue::assertNothingPushed();
});

/**
 * @return array<string, mixed>
 */
function deploymentWaitArguments(string $slug): array
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
        'actor' => MarketplaceInstallActorData::system('deployment-wait-test'),
        'source' => MarketplaceInstallSource::Programmatic,
    ];
}
