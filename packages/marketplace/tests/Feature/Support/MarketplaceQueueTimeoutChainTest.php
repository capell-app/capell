<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Enums\MarketplaceReadinessStatus;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Support\MarketplaceQueueTimeoutChain;

beforeEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
    config()->set('capell-marketplace.marketplace.operations_queue_connection', 'database');
});

afterEach(function (): void {
    EvaluateMarketplaceEnvironmentReadinessAction::forget();
});

it('takes the job timeout from the job itself rather than a copied literal', function (): void {
    expect(MarketplaceQueueTimeoutChain::resolve()->jobTimeoutSeconds)
        ->toBe(RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
});

it('fits every stage of an install inside one job timeout, not one per composer run', function (): void {
    // The job runs Composer, then replays the application's post-autoload-dump
    // scripts, then finalises the attempt — and the queue kills the worker at
    // $timeout regardless. So no stage may claim a budget of its own: the
    // replay takes what is left, and the reserve is what finalisation keeps.
    // A worker killed after Composer succeeded leaves an install that has been
    // applied and an attempt the backoff chain will re-queue.
    foreach ([null, 1, 600, 7200] as $configuredComposerTimeout) {
        config()->set('capell.process.composer.timeout_seconds', $configuredComposerTimeout);

        $chain = MarketplaceQueueTimeoutChain::resolve();
        $job = new RunMarketplaceInstallAttemptJob(1);

        expect($chain->composerTimeoutSeconds)->toBeLessThan($chain->jobTimeoutSeconds)
            ->and($job->scriptReplayBudgetSeconds() + RunMarketplaceInstallAttemptJob::FINALISATION_RESERVE_SECONDS)
            ->toBeLessThanOrEqual($chain->jobTimeoutSeconds);
    }
});

it('shrinks the script replay budget by the time the run has already spent', function (): void {
    config()->set('capell.process.composer.timeout_seconds', 600);
    config()->set('capell.process.composer.job_timeout_buffer_seconds', 120);

    $halfSpentJob = new RunMarketplaceInstallAttemptJob(1);
    new ReflectionProperty($halfSpentJob, 'startedAtNanoseconds')
        ->setValue($halfSpentJob, hrtime(true) - 300 * 1_000_000_000);

    $exhaustedJob = new RunMarketplaceInstallAttemptJob(1);
    new ReflectionProperty($exhaustedJob, 'startedAtNanoseconds')
        ->setValue($exhaustedJob, hrtime(true) - RunMarketplaceInstallAttemptJob::jobTimeoutSeconds() * 1_000_000_000);

    // A replay that cannot finish inside the job's own window is skipped and
    // reported: being SIGKILLed mid-replay leaves an install already applied
    // and an attempt never finalised.
    expect($halfSpentJob->scriptReplayBudgetSeconds())
        ->toBe(720 - 300 - RunMarketplaceInstallAttemptJob::FINALISATION_RESERVE_SECONDS)
        ->and($exhaustedJob->scriptReplayBudgetSeconds())->toBe(0);
});

it('calls a retry window at or below the job timeout unsafe, and one above it safe', function (): void {
    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeFalse();

    config()->set('queue.connections.database.retry_after', RunMarketplaceInstallAttemptJob::jobTimeoutSeconds() + 1);
    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeTrue();
});

it('treats a connection with no numeric retry_after as safe', function (): void {
    config()->set('queue.connections.database.retry_after', null);

    expect(MarketplaceQueueTimeoutChain::resolve()->retryAfterSeconds)->toBeNull()
        ->and(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeTrue();
});

it('gives readiness and the operations doctor the same verdict for one host', function (): void {
    config()->set('queue.connections.database.retry_after', 90);
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', true);

    $timeoutChain = EvaluateMarketplaceEnvironmentReadinessAction::run()->check('timeout_chain');
    $doctorCheck = BuildMarketplaceOperationsDoctorReportAction::run()
        ->checks
        ->firstWhere('id', 'marketplace.operations.queue-retry-after');

    expect($timeoutChain?->status)->toBe(MarketplaceReadinessStatus::Fail)
        ->and($doctorCheck?->passed)->toBeFalse()
        ->and($doctorCheck?->evidence['job_timeout_seconds'])
        ->toBe(RunMarketplaceInstallAttemptJob::jobTimeoutSeconds());
});

it('derives the job timeout from the configured composer timeout', function (): void {
    config()->set('capell.process.composer.timeout_seconds', 900);
    config()->set('capell.process.composer.job_timeout_buffer_seconds', 120);

    $chain = MarketplaceQueueTimeoutChain::resolve();

    expect($chain->composerTimeoutSeconds)->toBe(900)
        ->and($chain->jobTimeoutSeconds)->toBe(1020)
        ->and(RunMarketplaceInstallAttemptJob::jobTimeoutSeconds())->toBe(1020);
});

it('keeps readiness in step with a raised composer timeout', function (): void {
    // A retry window that was safe against the default 720s job timeout is not
    // safe once the operator gives Composer fifteen minutes.
    config()->set('queue.connections.database.retry_after', 800);
    config()->set('capell.process.composer.timeout_seconds', 600);

    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeTrue();

    config()->set('capell.process.composer.timeout_seconds', 900);

    expect(MarketplaceQueueTimeoutChain::resolve()->isSafe())->toBeFalse();
});

it('ignores a nonsensical configured composer timeout', function (): void {
    config()->set('capell.process.composer.timeout_seconds', 0);

    expect(RunMarketplaceInstallAttemptJob::composerTimeoutSeconds())
        ->toBe(RunMarketplaceInstallAttemptJob::DEFAULT_COMPOSER_TIMEOUT_SECONDS);
});
