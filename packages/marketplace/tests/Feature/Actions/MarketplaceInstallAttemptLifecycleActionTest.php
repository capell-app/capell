<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\ClaimMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\CreateMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\DispatchMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FinalizeMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallDeploymentAction;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\TransitionMarketplaceInstallAttemptAction;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Data\MarketplaceInstallDeploymentData;
use Capell\Marketplace\Data\MarketplaceInstallPolicyEvidenceData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallSource;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Models\MarketplaceInstallAttemptEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

it('creates queued attempts from typed data with timestamps and timeline atomically', function (): void {
    $policyEvidence = lifecyclePolicyEvidence();

    $attempt = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
        extensionSlug: 'typed-suite',
        extensionName: 'Typed Suite',
        composerName: 'capell-app/typed-suite',
        kind: 'tool',
        status: MarketplaceInstallIntentStatus::Queued,
        betaAcknowledged: true,
        policyEvidence: $policyEvidence,
        actor: MarketplaceInstallActorData::system('lifecycle-test'),
        source: MarketplaceInstallSource::Programmatic,
        requestedOptions: ['mode' => 'safe'],
        eligibility: ['canInstall' => true],
        context: ['request_id' => 'request-42'],
        idempotencyKey: 'typed-attempt-42',
        timelineMessage: 'Typed attempt created.',
        timelineLevel: MarketplaceInstallAttemptEventLevel::Info,
        timelineStage: MarketplaceInstallFailureStage::Preflight,
    ));

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->queued_at)->not->toBeNull()
        ->and($attempt->resolved_at)->toBeNull()
        ->and($attempt->idempotency_key)->toBe(hash('sha256', 'typed-attempt-42'))
        ->and($attempt->policy_evidence['listingFingerprint'])->toBe($policyEvidence->listingFingerprint)
        ->and($attempt->context['install_actor']['identifier'])->toBe('lifecycle-test')
        ->and($attempt->context['install_source'])->toBe(MarketplaceInstallSource::Programmatic->value)
        ->and($attempt->events)->toHaveCount(1)
        ->and($attempt->events->first()?->message)->toBe('Typed attempt created.');
});

it('retains the deprecated recorder public signature as a create adapter', function (): void {
    $parameterNames = collect(new ReflectionMethod(RecordMarketplaceInstallAttemptAction::class, 'handle')
        ->getParameters())
        ->map(fn (ReflectionParameter $parameter): string => $parameter->getName())
        ->all();

    $attempt = RecordMarketplaceInstallAttemptAction::run(
        extensionSlug: 'adapter-suite',
        extensionName: 'Adapter Suite',
        composerName: 'capell-app/adapter-suite',
        kind: 'tool',
        status: MarketplaceInstallIntentStatus::Blocked,
        betaAcknowledged: false,
        policyEvidence: lifecyclePolicyEvidence(),
        actor: MarketplaceInstallActorData::system('adapter-test'),
        source: MarketplaceInstallSource::Cli,
        failureReason: 'blocked',
    );
    $legacyQueuedAttempt = RecordMarketplaceInstallAttemptAction::run(
        extensionSlug: 'adapter-queued-suite',
        extensionName: 'Adapter Queued Suite',
        composerName: 'capell-app/adapter-queued-suite',
        kind: 'tool',
        status: MarketplaceInstallIntentStatus::Queued,
        betaAcknowledged: false,
        policyEvidence: lifecyclePolicyEvidence(),
        actor: MarketplaceInstallActorData::system('adapter-test'),
        source: MarketplaceInstallSource::Cli,
    );

    expect($parameterNames)->toBe([
        'extensionSlug',
        'extensionName',
        'composerName',
        'kind',
        'status',
        'betaAcknowledged',
        'policyEvidence',
        'actor',
        'source',
        'composerCommand',
        'versionConstraint',
        'requestedOptions',
        'eligibility',
        'context',
        'deployment',
        'failureReason',
        'telemetryStatus',
        'user',
        'idempotencyKey',
    ])
        ->and(new ReflectionClass(RecordMarketplaceInstallAttemptAction::class)->getDocComment())
        ->toContain('@deprecated')
        ->and($attempt->status)->toBe(MarketplaceInstallIntentStatus::Blocked)
        ->and($attempt->failure_reason)->toBe('blocked')
        ->and($attempt->resolved_at)->not->toBeNull()
        ->and($attempt->events)->toHaveCount(0);

    expect($legacyQueuedAttempt->queued_at)->toBeNull()
        ->and($legacyQueuedAttempt->resolved_at)->toBeNull()
        ->and($legacyQueuedAttempt->events)->toHaveCount(0);
});

it('allows every declared install attempt lifecycle transition', function (
    MarketplaceInstallIntentStatus $from,
    MarketplaceInstallIntentStatus $to,
): void {
    $attempt = lifecycleAttempt($from);
    $transition = new MarketplaceInstallAttemptTransitionData(
        toStatus: $to,
        failureReason: in_array($to, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true) ? 'Network timeout while installing.' : null,
        failureStage: in_array($to, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true) ? MarketplaceInstallFailureStage::Composer : null,
    );

    $transitioned = TransitionMarketplaceInstallAttemptAction::run($attempt, $transition);

    expect($transitioned->status)->toBe($to)
        ->and($transitioned->events)->toHaveCount(1);
})->with([
    'queued to running' => [MarketplaceInstallIntentStatus::Queued, MarketplaceInstallIntentStatus::Running],
    'queued to failed' => [MarketplaceInstallIntentStatus::Queued, MarketplaceInstallIntentStatus::Failed],
    'queued to cancelled' => [MarketplaceInstallIntentStatus::Queued, MarketplaceInstallIntentStatus::Cancelled],
    'running to succeeded' => [MarketplaceInstallIntentStatus::Running, MarketplaceInstallIntentStatus::Succeeded],
    'running to failed' => [MarketplaceInstallIntentStatus::Running, MarketplaceInstallIntentStatus::Failed],
    'running to timed out' => [MarketplaceInstallIntentStatus::Running, MarketplaceInstallIntentStatus::TimedOut],
    'running to cancel requested' => [MarketplaceInstallIntentStatus::Running, MarketplaceInstallIntentStatus::CancelRequested],
    'cancel requested to cancelled' => [MarketplaceInstallIntentStatus::CancelRequested, MarketplaceInstallIntentStatus::Cancelled],
    'cancel requested to failed' => [MarketplaceInstallIntentStatus::CancelRequested, MarketplaceInstallIntentStatus::Failed],
]);

it('rejects undeclared lifecycle transitions without changing the attempt', function (
    MarketplaceInstallIntentStatus $from,
    MarketplaceInstallIntentStatus $to,
): void {
    $attempt = lifecycleAttempt($from);

    expect(fn (): MarketplaceInstallAttempt => TransitionMarketplaceInstallAttemptAction::run(
        $attempt,
        new MarketplaceInstallAttemptTransitionData(toStatus: $to),
    ))->toThrow(
        RuntimeException::class,
        sprintf('Cannot transition Marketplace install attempt from [%s] to [%s].', $from->value, $to->value),
    );

    expect($attempt->refresh()->status)->toBe($from)
        ->and($attempt->events)->toHaveCount(0);
})->with([
    'queued cannot skip to success' => [MarketplaceInstallIntentStatus::Queued, MarketplaceInstallIntentStatus::Succeeded],
    'running cannot cancel immediately' => [MarketplaceInstallIntentStatus::Running, MarketplaceInstallIntentStatus::Cancelled],
    'cancel requested cannot resume running' => [MarketplaceInstallIntentStatus::CancelRequested, MarketplaceInstallIntentStatus::Running],
]);

it('keeps terminal attempts immutable', function (MarketplaceInstallIntentStatus $terminal): void {
    $attempt = lifecycleAttempt($terminal, [
        'failure_reason' => 'terminal evidence',
        'completed_at' => now()->subMinute(),
    ]);
    $updatedAt = $attempt->updated_at;

    expect(fn (): MarketplaceInstallAttempt => TransitionMarketplaceInstallAttemptAction::run(
        $attempt,
        new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Running),
    ))->toThrow(RuntimeException::class);

    expect($attempt->refresh()->status)->toBe($terminal)
        ->and($attempt->failure_reason)->toBe('terminal evidence')
        ->and($attempt->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and($attempt->events)->toHaveCount(0);
})->with([
    MarketplaceInstallIntentStatus::Succeeded,
    MarketplaceInstallIntentStatus::Failed,
    MarketplaceInstallIntentStatus::TimedOut,
    MarketplaceInstallIntentStatus::Cancelled,
]);

it('rolls back state when its atomic transition timeline cannot be recorded', function (): void {
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);

    expect(DB::transactionLevel())->toBeGreaterThan(0);
    Event::listen(
        'eloquent.creating: ' . MarketplaceInstallAttemptEvent::class,
        fn (): never => throw new RuntimeException('Timeline recording failed.'),
    );

    expect(fn (): MarketplaceInstallAttempt => TransitionMarketplaceInstallAttemptAction::run(
        $attempt,
        new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Running),
    ))->toThrow(RuntimeException::class, 'Timeline recording failed.');

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($attempt->started_at)->toBeNull();
});

it('records deployment evidence classification and timeline atomically', function (): void {
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);

    $recorded = RecordMarketplaceInstallDeploymentAction::run(
        $attempt,
        new MarketplaceInstallDeploymentData([
            'status' => 'failed',
            'failure_reason' => 'Publisher rejected the source update.',
            'reference' => 'deploy-42',
        ]),
    );

    expect($recorded->deployment)->toMatchArray([
        'status' => 'failed',
        'reference' => 'deploy-42',
    ])
        ->and($recorded->failure_type)->toBe(MarketplaceInstallFailureType::DeploymentFailed->value)
        ->and($recorded->failure_stage)->toBe(MarketplaceInstallFailureStage::DeploymentHandoff->value)
        ->and($recorded->events)->toHaveCount(1)
        ->and($recorded->events->first()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_deployment_failed'));
});

it('rolls back deployment evidence and classification when its timeline cannot be recorded', function (): void {
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);

    expect(DB::transactionLevel())->toBeGreaterThan(0);
    Event::listen(
        'eloquent.creating: ' . MarketplaceInstallAttemptEvent::class,
        fn (): never => throw new RuntimeException('Timeline recording failed.'),
    );

    expect(fn (): MarketplaceInstallAttempt => RecordMarketplaceInstallDeploymentAction::run(
        $attempt,
        new MarketplaceInstallDeploymentData([
            'status' => 'failed',
            'failure_reason' => 'Publisher rejected the source update.',
        ]),
    ))->toThrow(RuntimeException::class, 'Timeline recording failed.');

    expect($attempt->refresh()->deployment)->toBeNull()
        ->and($attempt->failure_reason)->toBeNull()
        ->and($attempt->failure_type)->toBeNull()
        ->and($attempt->failure_stage)->toBeNull();
});

it('preserves completed deployment evidence when cancellation wins during publication', function (): void {
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);
    $cancelled = CancelMarketplaceInstallAttemptAction::run($attempt);

    $recorded = RecordMarketplaceInstallDeploymentAction::run(
        $attempt,
        new MarketplaceInstallDeploymentData([
            'status' => 'published',
            'reference' => 'pull-request-42',
        ]),
    );

    expect($recorded->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($recorded->deployment)->toMatchArray([
            'status' => 'published',
            'reference' => 'pull-request-42',
        ])
        ->and($recorded->failure_type)->toBeNull()
        ->and($recorded->resolved_at?->equalTo($cancelled->resolved_at))->toBeTrue()
        ->and($recorded->events->last()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_deployment_published'));
});

it('does not dispatch local work when cancellation commits after deployment recording', function (): void {
    Queue::fake();
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);
    $recorded = RecordMarketplaceInstallDeploymentAction::run(
        $attempt,
        new MarketplaceInstallDeploymentData([
            'status' => 'published',
            'reference' => 'pull-request-43',
        ]),
    );
    CancelMarketplaceInstallAttemptAction::run($recorded->fresh());

    $dispatchDecision = DispatchMarketplaceInstallAttemptAction::run(
        attempt: $recorded,
        queueConnection: 'database',
        queue: 'capell-marketplace',
    );

    expect($dispatchDecision->status)->toBe(MarketplaceInstallIntentStatus::Cancelled);
    Queue::assertNotPushed(RunMarketplaceInstallAttemptJob::class);
});

it('derives lifecycle timestamps and resolution state', function (): void {
    $attempt = CreateMarketplaceInstallAttemptAction::run(new MarketplaceInstallAttemptData(
        extensionSlug: 'timestamp-suite',
        extensionName: 'Timestamp Suite',
        composerName: 'capell-app/timestamp-suite',
        kind: 'tool',
        status: MarketplaceInstallIntentStatus::Queued,
        betaAcknowledged: false,
    ));

    $running = TransitionMarketplaceInstallAttemptAction::run(
        $attempt,
        new MarketplaceInstallAttemptTransitionData(
            toStatus: MarketplaceInstallIntentStatus::Running,
            attemptCount: 2,
            progressTotal: 5,
        ),
    );
    $succeeded = TransitionMarketplaceInstallAttemptAction::run(
        $running,
        new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Succeeded),
    );

    expect($attempt->queued_at)->not->toBeNull()
        ->and($running->started_at)->not->toBeNull()
        ->and($running->heartbeat_at)->not->toBeNull()
        ->and($running->attempt_count)->toBe(2)
        ->and($running->current_stage)->toBe(MarketplaceInstallFailureStage::Queue->value)
        ->and($succeeded->completed_at)->not->toBeNull()
        ->and($succeeded->resolved_at)->not->toBeNull()
        ->and($succeeded->failure_type)->toBeNull();
});

it('derives cancellation state for queued and running attempts', function (): void {
    $queued = CancelMarketplaceInstallAttemptAction::run(lifecycleAttempt(MarketplaceInstallIntentStatus::Queued));
    $running = CancelMarketplaceInstallAttemptAction::run(lifecycleAttempt(MarketplaceInstallIntentStatus::Running));
    $cancelled = TransitionMarketplaceInstallAttemptAction::run(
        $running,
        new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Cancelled),
    );

    expect($queued->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($queued->cancel_requested_at)->not->toBeNull()
        ->and($queued->cancelled_at)->not->toBeNull()
        ->and($queued->completed_at)->not->toBeNull()
        ->and($queued->resolved_at)->not->toBeNull()
        ->and($running->status)->toBe(MarketplaceInstallIntentStatus::CancelRequested)
        ->and($running->cancel_requested_at)->not->toBeNull()
        ->and($cancelled->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->resolved_at)->not->toBeNull();
});

it('serializes stale claim and cancellation decisions under the row lock', function (): void {
    $staleQueuedForCancellation = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);
    TransitionMarketplaceInstallAttemptAction::run(
        $staleQueuedForCancellation->fresh(),
        new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Running),
    );

    $cancelRequested = CancelMarketplaceInstallAttemptAction::run($staleQueuedForCancellation);

    $staleQueuedForClaim = lifecycleAttempt(MarketplaceInstallIntentStatus::Queued);
    CancelMarketplaceInstallAttemptAction::run($staleQueuedForClaim->fresh());
    $claimed = ClaimMarketplaceInstallAttemptAction::run(
        attempt: $staleQueuedForClaim,
        attemptCount: 1,
        progressTotal: 5,
    );

    expect($cancelRequested->status)->toBe(MarketplaceInstallIntentStatus::CancelRequested)
        ->and($cancelRequested->cancel_requested_at)->not->toBeNull()
        ->and($claimed)->toBeNull()
        ->and($staleQueuedForClaim->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($staleQueuedForClaim->started_at)->toBeNull();
});

it('classifies timeout and composer failures while redacting stored evidence', function (): void {
    $timedOut = TransitionMarketplaceInstallAttemptAction::run(
        lifecycleAttempt(MarketplaceInstallIntentStatus::Running),
        new MarketplaceInstallAttemptTransitionData(
            toStatus: MarketplaceInstallIntentStatus::TimedOut,
            failureStage: MarketplaceInstallFailureStage::Composer,
            composerResult: new MarketplaceComposerResultData(124, '', 'Timed out with token=secret', true),
            errorExcerpt: 'Timed out with token=secret',
            timelineOutputExcerpt: 'Timed out with token=secret',
        ),
    );
    $networkFailure = TransitionMarketplaceInstallAttemptAction::run(
        lifecycleAttempt(MarketplaceInstallIntentStatus::Running),
        new MarketplaceInstallAttemptTransitionData(
            toStatus: MarketplaceInstallIntentStatus::Failed,
            failureReason: 'curl error: could not resolve host with password=hunter2',
            failureStage: MarketplaceInstallFailureStage::Composer,
        ),
    );

    expect($timedOut->failure_type)->toBe(MarketplaceInstallFailureType::Timeout->value)
        ->and($timedOut->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        ->and($timedOut->completed_at)->not->toBeNull()
        ->and($timedOut->resolved_at)->toBeNull()
        ->and($timedOut->error_excerpt)->not->toContain('secret')
        ->and($networkFailure->failure_type)->toBe(MarketplaceInstallFailureType::Network->value)
        ->and($networkFailure->failure_reason)->not->toContain('hunter2');
});

it('keeps cancel-after-composer classification retryable despite composer diagnostics', function (): void {
    $cancelled = TransitionMarketplaceInstallAttemptAction::run(
        lifecycleAttempt(MarketplaceInstallIntentStatus::CancelRequested),
        new MarketplaceInstallAttemptTransitionData(
            toStatus: MarketplaceInstallIntentStatus::Cancelled,
            failureReason: (string) __('capell-marketplace::marketplace.operations.cancelled_after_composer'),
            failureStage: MarketplaceInstallFailureStage::Composer,
            composerResult: new MarketplaceComposerResultData(124, '', 'HTTP 401 timed out', true),
        ),
    );

    expect($cancelled->failure_type)->toBe(MarketplaceInstallFailureType::CancelledAfterComposer->value)
        ->and($cancelled->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        ->and($cancelled->resolved_at)->toBeNull()
        ->and((new RetryMarketplaceInstallAttemptAction)->canRetry($cancelled))->toBeTrue();
});

it('finalizes a late cancellation instead of converting it to a failed attempt', function (): void {
    $staleRunning = lifecycleAttempt(MarketplaceInstallIntentStatus::Running);
    CancelMarketplaceInstallAttemptAction::run($staleRunning->fresh());

    $finalized = FinalizeMarketplaceInstallAttemptAction::run(
        $staleRunning,
        new MarketplaceComposerResultData(0, 'Composer and lifecycle completed.', ''),
    );

    expect($finalized->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($finalized->failure_type)->toBe(MarketplaceInstallFailureType::Unknown->value)
        ->and($finalized->failure_stage)->toBe(MarketplaceInstallFailureStage::Lifecycle->value)
        ->and($finalized->resolved_at)->toBeNull()
        ->and((new RetryMarketplaceInstallAttemptAction)->canRetry($finalized))->toBeFalse();
});

it('keeps successful local installs unresolved when deployment needs attention', function (
    string $deploymentStatus,
    MarketplaceInstallFailureType $expectedType,
): void {
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Running, [
        'deployment' => [
            'status' => $deploymentStatus,
            'failure_reason' => 'Deployment publisher could not update the source.',
        ],
    ]);

    $succeeded = TransitionMarketplaceInstallAttemptAction::run(
        $attempt,
        new MarketplaceInstallAttemptTransitionData(toStatus: MarketplaceInstallIntentStatus::Succeeded),
    );

    expect($succeeded->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and($succeeded->failure_type)->toBe($expectedType->value)
        ->and($succeeded->failure_stage)->toBe(MarketplaceInstallFailureStage::DeploymentHandoff->value)
        ->and($succeeded->failure_reason)->toContain('Deployment publisher')
        ->and($succeeded->resolved_at)->toBeNull()
        ->and($succeeded->events->last()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_succeeded_deployment_attention'));
})->with([
    'failed deployment' => ['failed', MarketplaceInstallFailureType::DeploymentFailed],
    'unavailable deployment' => ['unavailable', MarketplaceInstallFailureType::DeploymentUnavailable],
]);

it('keeps a local Composer failure classification despite earlier deployment failure evidence', function (): void {
    $attempt = lifecycleAttempt(MarketplaceInstallIntentStatus::Running, [
        'deployment' => [
            'status' => 'failed',
            'failure_reason' => 'Deployment publisher could not update the source.',
        ],
    ]);

    $failed = TransitionMarketplaceInstallAttemptAction::run(
        $attempt,
        new MarketplaceInstallAttemptTransitionData(
            toStatus: MarketplaceInstallIntentStatus::Failed,
            failureReason: 'Composer exited with code 2.',
            failureStage: MarketplaceInstallFailureStage::Composer,
        ),
    );

    expect($failed->failure_type)->toBe(MarketplaceInstallFailureType::Unknown->value)
        ->and($failed->failure_stage)->toBe(MarketplaceInstallFailureStage::Composer->value)
        ->and($failed->events->last()?->stage)->toBe(MarketplaceInstallFailureStage::Composer);
});

it('creates retries as fresh queued attempts without mutating terminal history', function (): void {
    Queue::fake();
    $source = lifecycleAttempt(MarketplaceInstallIntentStatus::Failed, [
        'failure_reason' => 'Composer failed.',
        'failure_type' => MarketplaceInstallFailureType::Unknown->value,
        'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
        'completed_at' => now()->subMinute(),
    ]);
    $sourceUpdatedAt = $source->updated_at;

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->getKey())->not->toBe($source->getKey())
        ->and($retry->retry_of_id)->toBe($source->getKey())
        ->and($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and($retry->queued_at)->not->toBeNull()
        ->and($retry->failure_reason)->toBeNull()
        ->and($retry->events->first()?->message)
        ->toBe((string) __('capell-marketplace::marketplace.operations.timeline_retry_created'))
        ->and($source->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Failed)
        ->and($source->failure_reason)->toBe('Composer failed.')
        ->and($source->updated_at?->equalTo($sourceUpdatedAt))->toBeTrue();
});

it('does not create a retry while another process holds the shared composer operation lock', function (): void {
    Queue::fake();
    $source = lifecycleAttempt(MarketplaceInstallIntentStatus::Failed);
    $lockKey = 'capell-marketplace:queue-install:' . hash('sha256', $source->composer_name);
    $originalCacheDriver = (string) config('cache.default', 'array');
    $signalDirectory = sys_get_temp_dir() . '/capell-marketplace-retry-lock-' . uniqid();
    mkdir($signalDirectory);
    config(['cache.default' => 'file']);
    resolve(Factory::class)->setDefaultDriver('file');
    $processId = pcntl_fork();

    throw_if($processId === -1, RuntimeException::class, 'Could not fork the retry lock contention worker.');

    if ($processId === 0) {
        $childLock = Cache::lock($lockKey, 10);

        if (! $childLock->get()) {
            pcntl_exec('/usr/bin/false');

            throw new RuntimeException('Could not report the failed child lock acquisition.');
        }

        file_put_contents($signalDirectory . '/ready', 'ready');
        $deadline = microtime(true) + 10;

        while (! is_file($signalDirectory . '/release') && microtime(true) < $deadline) {
            Sleep::usleep(10_000);
        }

        $released = is_file($signalDirectory . '/release');
        $childLock->release();

        pcntl_exec($released ? '/usr/bin/true' : '/usr/bin/false');

        throw new RuntimeException('Could not report the child lock result.');
    }

    try {
        $deadline = microtime(true) + 5;

        while (! is_file($signalDirectory . '/ready') && microtime(true) < $deadline) {
            Sleep::usleep(10_000);
        }

        expect(is_file($signalDirectory . '/ready'))->toBeTrue();

        expect(fn (): MarketplaceInstallAttempt => RetryMarketplaceInstallAttemptAction::run($source))
            ->toThrow(ValidationException::class);
    } finally {
        file_put_contents($signalDirectory . '/release', 'release');
        pcntl_waitpid($processId, $processStatus);
        @unlink($signalDirectory . '/ready');
        @unlink($signalDirectory . '/release');
        @rmdir($signalDirectory);
        config(['cache.default' => $originalCacheDriver]);
        resolve(Factory::class)->setDefaultDriver($originalCacheDriver);
    }

    expect(pcntl_wifexited($processStatus))->toBeTrue()
        ->and(pcntl_wexitstatus($processStatus))->toBe(0);

    expect(MarketplaceInstallAttempt::query()
        ->where('composer_name', $source->composer_name)
        ->count())->toBe(1);
});

it('allows only one active retry for a terminal attempt', function (): void {
    Queue::fake();
    $source = lifecycleAttempt(MarketplaceInstallIntentStatus::Failed);
    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect(fn (): MarketplaceInstallAttempt => RetryMarketplaceInstallAttemptAction::run($source))
        ->toThrow(ValidationException::class);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Queued)
        ->and(MarketplaceInstallAttempt::query()
            ->where('composer_name', $source->composer_name)
            ->count())->toBe(2);
});

it('does not dispatch a retry when cancellation commits after retry preflight', function (): void {
    Queue::fake();
    $source = lifecycleAttempt(MarketplaceInstallIntentStatus::Failed);

    Event::listen(
        'eloquent.created: ' . MarketplaceInstallAttemptEvent::class,
        function (MarketplaceInstallAttemptEvent $event): void {
            if (($event->context['check'] ?? null) !== 'queue_ready') {
                return;
            }

            $attempt = $event->attempt()->firstOrFail();

            if ($attempt->retry_of_id !== null) {
                CancelMarketplaceInstallAttemptAction::run($attempt);
            }
        },
    );

    $retry = RetryMarketplaceInstallAttemptAction::run($source);

    expect($retry->status)->toBe(MarketplaceInstallIntentStatus::Cancelled)
        ->and($retry->retry_of_id)->toBe($source->getKey());
    Queue::assertNotPushed(RunMarketplaceInstallAttemptJob::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function lifecycleAttempt(
    MarketplaceInstallIntentStatus $status,
    array $overrides = [],
): MarketplaceInstallAttempt {
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/lifecycle-suite-' . str()->uuid(),
        'extension_slug' => 'lifecycle-suite',
        'extension_name' => 'Lifecycle Suite',
        'kind' => 'tool',
        'status' => $status,
        'queued_at' => now()->subMinutes(2),
        ...$overrides,
    ]);
}

function lifecyclePolicyEvidence(): MarketplaceInstallPolicyEvidenceData
{
    return new MarketplaceInstallPolicyEvidenceData(
        listingFingerprint: hash('sha256', 'lifecycle-test'),
        listingFetchedAt: CarbonImmutable::parse('2026-07-29 10:00:00'),
        selectedMaturity: 'stable',
        dependencyMaturity: [],
        entitlementAllowed: true,
        compatibilityAllowed: true,
        consentAllowed: true,
    );
}
