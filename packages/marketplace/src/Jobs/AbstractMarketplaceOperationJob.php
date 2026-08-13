<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Actions\BuildPackageCacheAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerAutoloaderReloader;
use Capell\Core\Support\Composer\ComposerStateSnapshot;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Marketplace\Actions\ClaimMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Actions\FinalizeMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FinalizeMarketplaceInstallOperationTelemetryAction;
use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\PackageIsAvailableForLifecycleAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptEventAction;
use Capell\Marketplace\Actions\RedactMarketplaceDiagnosticContextAction;
use Capell\Marketplace\Actions\RestoreComposerStateAction;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Actions\SnapshotComposerStateAction;
use Capell\Marketplace\Actions\TransitionMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\UpdateMarketplaceInstallOperationProgressAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Exceptions\MarketplaceHealthCheckFailedException;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceOperationVocabulary;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Everything a queued Composer operation on this release has to get right,
 * regardless of whether it is installing a package or updating one.
 *
 * Installs and updates differ in exactly three places: whether an
 * already-downloaded package can be reused, what happens between "Composer
 * succeeded" and "the health check runs", and what is announced afterwards.
 * Everything else — the multi-node guard, the global Composer lock, the
 * snapshot, the timeout budget chain, the rollback, the telemetry, the
 * never-claimed-by-a-worker diagnosis — is one implementation, because a second
 * copy of it is a second thing to keep correct and the first place a subtle
 * divergence would hide.
 */
abstract class AbstractMarketplaceOperationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const int DEFAULT_COMPOSER_TIMEOUT_SECONDS = 600;

    /**
     * How much longer the job may live than the Composer run inside it. The job
     * still has an attempt to finalise and telemetry to record after Composer
     * returns, and a job that is killed between those two is exactly the stuck
     * operation the doctor reports.
     */
    public const int DEFAULT_JOB_TIMEOUT_BUFFER_SECONDS = 120;

    /**
     * The tail of the job that has to survive whatever ran before it: finalising
     * the attempt, recording telemetry, releasing the composer-install lock. It
     * is carved out of the job budget rather than added to it, because a job
     * killed between a successful Composer run and its finalisation is exactly
     * the stuck operation the doctor reports.
     */
    public const int FINALISATION_RESERVE_SECONDS = 30;

    /**
     * The slice of the job budget the post-operation health check spends.
     *
     * It is carved out of the same budget as everything else rather than added
     * to it, for the same reason the script replay is: $timeout is what the
     * queue kills the worker at and what retry_after is sized against. A probe
     * with a budget of its own would put the real worst case above the declared
     * one, and a worker killed during the probe leaves an operation that has
     * been applied but never confirmed.
     *
     * A fresh `artisan` boot is seconds on a healthy host, so this is generous
     * rather than tight — and being generous costs nothing, because the reserve
     * is only spent when the probe actually runs.
     */
    public const int HEALTH_CHECK_RESERVE_SECONDS = 45;

    public int $timeout;

    /**
     * Unlimited attempts, bounded by retryUntil(). This is deliberate: a job that
     * cannot take the composer-install lock calls release() and must be free to
     * keep waiting for the holder to finish. Capping $tries would fail an operation
     * merely because another one was running.
     */
    public int $tries = 0;

    /**
     * Attempts that actually threw, however, must be bounded — otherwise a
     * reproducibly failing composer run repeats for the whole retryUntil() window,
     * re-taking the lock each time. release() does not count towards this.
     */
    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    /**
     * When this run of the job started, in hrtime nanoseconds. Set in handle()
     * rather than the constructor: the job is serialised onto the queue, so
     * construction time says nothing about when a worker picked it up.
     */
    protected ?int $startedAtNanoseconds = null;

    public function __construct(
        protected readonly int $installAttemptId,
    ) {
        $this->timeout = static::jobTimeoutSeconds();
    }

    /**
     * How many stages this operation reports, and where each one sits.
     *
     * Deliberately per-operation rather than shared: an install runs the
     * package lifecycle where an update runs migrations, so they are different
     * sequences and a single mapping would have to lie about one of them.
     */
    abstract protected function progressTotal(): int;

    abstract protected function stageProgress(MarketplaceInstallFailureStage $stage): int;

    /**
     * Everything between a successful Composer run and the health check.
     *
     * Runs inside the try that can still fail the attempt and roll it back, so
     * throwing from here is the supported way to abort.
     */
    abstract protected function applyOperation(MarketplaceInstallAttempt $attempt): void;

    /**
     * Everything that happens *after* an attempt has already succeeded, and
     * deliberately outside the try that can mark it failed.
     *
     * Succeeded is a terminal status: TransitionMarketplaceInstallAttemptAction
     * rejects succeeded → failed with a RuntimeException, which would escape
     * handle() and have the queue retry a completed operation. So no side effect
     * in an implementation of this may propagate.
     */
    abstract protected function announceSucceededAttempt(MarketplaceInstallAttempt $attempt): void;

    /**
     * Public so environment readiness, the install preflight, and the doctor can
     * check the composer/job/retry_after chain against the real numbers instead
     * of a second copy of them.
     */
    public static function composerTimeoutSeconds(): int
    {
        return self::positiveConfiguredSeconds(
            'capell.process.composer.timeout_seconds',
            self::DEFAULT_COMPOSER_TIMEOUT_SECONDS,
        );
    }

    public static function jobTimeoutSeconds(): int
    {
        return self::composerTimeoutSeconds() + self::positiveConfiguredSeconds(
            'capell.process.composer.job_timeout_buffer_seconds',
            self::DEFAULT_JOB_TIMEOUT_BUFFER_SECONDS,
        );
    }

    /**
     * What the script replay has to leave behind for the stages after it.
     *
     * Named rather than summed at the call site so the timeout-chain tests can
     * pin the rule instead of a literal, and so adding a stage to the tail is a
     * change in one place.
     *
     * The replay is the stage that yields when a new one needs time, and the
     * ordering is by consequence, not by convenience. A truncated replay
     * surfaces as a reported hook, which replayHostComposerScripts() already
     * treats as non-fatal. A health check that never got to run means an
     * unverified operation is announced as good and nothing is left to trigger a
     * rollback — the failure this whole design exists to prevent.
     */
    public static function scriptReplayReserveSeconds(): int
    {
        return self::FINALISATION_RESERVE_SECONDS + self::HEALTH_CHECK_RESERVE_SECONDS;
    }

    /**
     * How long the replay of the application's post-autoload-dump scripts may
     * run: whatever is left of this job's own timeout once Composer has had its
     * turn, minus the finalisation reserve.
     *
     * It deliberately does not get a budget of its own. $timeout is what the
     * queue kills the worker at and what retry_after is sized against, so a
     * second independent Composer-sized timeout here could put the real worst
     * case at roughly double the declared one — and a worker killed during the
     * replay leaves an operation that has already been applied, which the backoff
     * chain would then re-queue.
     *
     * Truncating a genuinely slow replay is the accepted cost. Its failure mode
     * is a reported hook, which is what replayHostComposerScripts() already
     * treats as non-fatal; being SIGKILLed is not recoverable at all.
     *
     * Public so the timeout-chain tests can pin the whole-job budget rather than
     * one composer consumer.
     */
    public function scriptReplayBudgetSeconds(): int
    {
        return $this->remainingBudgetSeconds(self::scriptReplayReserveSeconds());
    }

    /**
     * What the health check may spend: its reserve, or whatever is left if the
     * Composer run and the script replay already ate into it.
     *
     * Capped at the reserve rather than handed the whole remainder, because the
     * probe running long is not a reason to leave nothing for the rollback that
     * a failing probe is about to trigger.
     */
    public function healthCheckBudgetSeconds(): int
    {
        return min(
            self::HEALTH_CHECK_RESERVE_SECONDS,
            $this->remainingBudgetSeconds(self::FINALISATION_RESERVE_SECONDS),
        );
    }

    /**
     * What a rollback may spend: everything left except the finalisation
     * reserve, because by the time a rollback runs there is no script replay,
     * no health check and no notification still to pay for.
     *
     * This can be small, and on a Composer run that consumed its whole timeout
     * it can be nothing. That is a real limit and not one this job can design
     * away — which is precisely why a rollback that does not complete is
     * reported to the operator as needing manual recovery rather than retried.
     */
    public function rollbackBudgetSeconds(): int
    {
        return $this->remainingBudgetSeconds(self::FINALISATION_RESERVE_SECONDS);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->installAttemptId;
    }

    public function handle(MarketplaceComposerRunner $composer): void
    {
        // Reaching handle() at all is the only proof that a worker is consuming
        // this queue, so it is recorded before anything that can fail.
        MarketplaceWorkerHeartbeat::record();

        // Before the lock, not after it. On a node-local cache store
        // Cache::lock() succeeds on every node at once, so two workers would run
        // Composer against the same release root believing they hold it — the
        // worst outcome this system has. A loud failure is strictly better.
        new MultiNodeTopologyGuard()->assertCacheStoreIsShared(
            EvaluateMarketplaceEnvironmentReadinessAction::OPERATION,
        );

        $startedAt = hrtime(true);
        $this->startedAtNanoseconds = $startedAt;
        $peakMemoryBefore = memory_get_peak_usage(true);
        $connection = DB::connection();
        $wasLoggingQueries = $connection->logging();
        $queryCountBefore = count($connection->getQueryLog());

        if (! $wasLoggingQueries) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $queryCountBefore = 0;
        }

        // One lock for every Composer operation on this release, shared by
        // installs, updates and whatever comes next: they all rewrite the same
        // composer.json against the same vendor/ directory. Naming it after
        // installs is history, not scope.
        $lock = Cache::lock('capell-marketplace:composer-install', static::jobTimeoutSeconds());

        try {
            if (! $lock->get()) {
                $this->release(30);

                return;
            }

            try {
                $this->runWithLock($composer);
            } finally {
                $lock->release();
            }
        } finally {
            $queryCount = max(0, count($connection->getQueryLog()) - $queryCountBefore);

            if (! $wasLoggingQueries) {
                $connection->disableQueryLog();
                $connection->flushQueryLog();
            }

            $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

            if ($attempt instanceof MarketplaceInstallAttempt && ! $attempt->status->isActiveInstallOperation()) {
                FinalizeMarketplaceInstallOperationTelemetryAction::run(
                    attempt: $attempt,
                    runtimeMilliseconds: max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                    peakMemoryBytes: max($peakMemoryBefore, memory_get_peak_usage(true)),
                    queryCount: $queryCount,
                );
            }
        }
    }

    /**
     * Execute the queued operation inline while preserving queue failure semantics.
     *
     * The lifecycle QA command deliberately creates the same queued attempt and
     * runs the same job implementation, but there is no queue payload available
     * for release() to retry when the Composer lock is busy. Any early exception
     * similarly has no worker wrapper that would invoke failed(). Inline callers
     * therefore need one terminal result before they return.
     */
    public function handleSynchronously(MarketplaceComposerRunner $composer): void
    {
        try {
            $this->handle($composer);
        } catch (Throwable $throwable) {
            $this->failed($throwable);

            throw $throwable;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

        if ($attempt instanceof MarketplaceInstallAttempt && $attempt->status->isActiveInstallOperation()) {
            $this->failed(new RuntimeException(
                (string) __('capell-marketplace::marketplace.qa.lifecycle.synchronous_operation_incomplete'),
            ));
        }
    }

    /**
     * now() returns whichever class the host application registered with
     * Date::use(), so an application that opts into immutable dates gets a
     * Carbon\CarbonImmutable back. That is not a subclass of the mutable facade
     * class, so declaring the narrower type breaks the retry path at runtime.
     */
    public function retryUntil(): CarbonInterface
    {
        return now()->addHour();
    }

    public function failed(?Throwable $throwable): void
    {
        $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        if (! $attempt->status->isActiveInstallOperation()) {
            return;
        }

        $thrownMessage = trim($throwable?->getMessage() ?? '');
        $neverClaimed = $this->wasNeverClaimedByAWorker($attempt);
        $reason = $neverClaimed
            ? (string) __('capell-marketplace::marketplace.operations.queue_worker_missing')
            : ($thrownMessage ?: (string) __('capell-marketplace::marketplace.operations.queue_failed'));

        // Naming the missing worker replaces the reason but must never cost the
        // operator the error that actually arrived, so it travels alongside.
        $timelineContext = ['reason' => $reason];

        if ($neverClaimed && $thrownMessage !== '') {
            $timelineContext['error'] = $thrownMessage;
        }

        $attempt = TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: MarketplaceInstallIntentStatus::Failed,
                failureReason: $reason,
                failureStage: MarketplaceInstallFailureStage::Queue,
                failureType: $neverClaimed ? MarketplaceInstallFailureType::QueueWorkerMissing : null,
                errorExcerpt: $thrownMessage !== '' ? $thrownMessage : null,
                timelineContext: $timelineContext,
            ),
        );

        FinalizeMarketplaceInstallOperationTelemetryAction::run($attempt);
    }

    /**
     * Whether a package already sitting in vendor/ makes the Composer run
     * unnecessary.
     *
     * True for an install, where the package being present is the whole goal.
     * Never true for an update, where the version already on disk is precisely
     * the one being replaced.
     */
    protected function mayReuseDownloadedPackage(): bool
    {
        return false;
    }

    /**
     * Everything this operation has to do *before* Composer runs.
     *
     * Empty for an install and an update, where Composer bringing the code onto
     * disk is the first thing that can happen. Not empty for an uninstall,
     * where the extension has to be given the chance to tear itself down while
     * its own code is still loadable — running `composer remove` first would
     * delete the very lifecycle hook that is supposed to run.
     *
     * Runs after the snapshot and inside a try that can still fail the attempt,
     * so throwing from here is the supported way to abort.
     */
    protected function prepareOperation(MarketplaceInstallAttempt $attempt): void
    {
        unset($attempt);
    }

    /**
     * Whether prepareOperation() does anything a cancel would want to stop
     * after.
     *
     * Stated rather than inferred from prepareOperation() being overridden,
     * because the question a cancel asks is not "did anything run" but "is what
     * ran irreversible enough that continuing into Composer would make it
     * worse".
     */
    protected function preparesBeforeComposer(): bool
    {
        return false;
    }

    /**
     * Whether this operation needs no Composer run at all.
     *
     * True for an install whose package is already downloaded, and for an
     * uninstall the operator asked to keep the package files for. Both are
     * successes rather than shortcuts, so the timeline says which one happened
     * — hence the companion key rather than a single shared sentence.
     */
    protected function shouldSkipComposerStage(MarketplaceInstallAttempt $attempt): bool
    {
        return $this->mayReuseDownloadedPackage()
            && PackageIsAvailableForLifecycleAction::run($attempt->composer_name);
    }

    protected function composerSkippedTranslationKey(): string
    {
        return 'timeline_composer_skipped_downloaded';
    }

    /**
     * The stage a failed preparation is attributed to.
     *
     * Composer has not run at this point, so the base class's throwable reading
     * — which is about what a post-Composer failure means — would name the
     * wrong stage and send the operator to the wrong part of the timeline.
     */
    protected function prepareFailureStage(): MarketplaceInstallFailureStage
    {
        return MarketplaceInstallFailureStage::Lifecycle;
    }

    protected function failureStageFor(Throwable $throwable): MarketplaceInstallFailureStage
    {
        if ($throwable instanceof MarketplaceHealthCheckFailedException) {
            return MarketplaceInstallFailureStage::HealthCheck;
        }

        return str_contains(strtolower($throwable->getMessage()), 'not discovered')
            ? MarketplaceInstallFailureStage::PackageDiscovery
            : MarketplaceInstallFailureStage::Lifecycle;
    }

    /**
     * @param  array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}  $rollback
     */
    protected function resolveFailureType(
        array $rollback,
        MarketplaceInstallFailureStage $stage,
    ): ?MarketplaceInstallFailureType {
        // A rollback that did not complete outranks whatever caused it: the
        // operator's next action is no longer "read the error", it is "put
        // this application back by hand". The original cause is never lost —
        // it travels in the reason, the timeline and the attempt context.
        return match (true) {
            ! $rollback['rolled_back'] => MarketplaceInstallFailureType::RollbackFailed,
            $stage === MarketplaceInstallFailureStage::HealthCheck => MarketplaceInstallFailureType::HealthCheckFailed,
            default => null,
        };
    }

    /**
     * A last chance to correct what the operator is told about the rollback.
     *
     * The base rollback speaks only about composer.json, composer.lock and
     * vendor/, which is the whole truth for an install and only part of it for
     * an update.
     *
     * @param  array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}  $rollback
     * @return array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}
     */
    protected function decorateRollbackOutcome(
        MarketplaceInstallAttempt $attempt,
        array $rollback,
        MarketplaceInstallFailureStage $stage,
    ): array {
        unset($attempt, $stage);

        return $rollback;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function recordEvent(
        MarketplaceInstallAttempt $attempt,
        MarketplaceInstallAttemptEventLevel $level,
        string $translationKey,
        ?MarketplaceInstallFailureStage $stage = null,
        array $context = [],
        ?string $outputExcerpt = null,
    ): void {
        if ($stage instanceof MarketplaceInstallFailureStage) {
            UpdateMarketplaceInstallOperationProgressAction::run(
                attempt: $attempt,
                stage: $stage,
                progressCurrent: $this->stageProgress($stage),
                progressTotal: $this->progressTotal(),
                attemptCount: $this->attempts(),
            );
        }

        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $attempt,
            level: $level,
            message: MarketplaceOperationVocabulary::translate($attempt->operation, $translationKey),
            stage: $stage,
            context: $context,
            outputExcerpt: $outputExcerpt,
        );
    }

    /**
     * Rebuild Laravel's package manifest and Capell's own registry for whatever
     * Composer just wrote.
     *
     * Composer runs with --no-scripts, which is what keeps a third-party
     * package's own scripts from executing as the web user — and which also
     * suppresses the post-autoload-dump hook that normally runs
     * `artisan package:discover`. Without this, the extension's service provider
     * is absent from bootstrap/cache/packages.php and never boots on the next
     * request. Doing it in-process is both cheaper and more reliable than
     * shelling out a second time.
     */
    protected function reloadPackageRegistry(): void
    {
        ComposerAutoloaderReloader::reload();

        $this->rediscoverLaravelPackages();
        $this->replayHostComposerScripts();

        CapellCore::clearExtensionCache();

        $registry = resolve(CapellPackageRegistry::class);
        $manifests = new ManifestLoader(new ManifestValidator)->discover();
        BuildPackageCacheAction::run($manifests);
        $registry->fill($manifests);

        foreach ($manifests as $manifest) {
            CapellCore::registerManifestPackage(
                $manifest,
                CapellCore::getInstalledPrettyVersion($manifest->name),
            );
        }
    }

    /**
     * The Composer run itself.
     *
     * Overridable because "the Composer stage" is not always `composer require`
     * — an uninstall removes a package instead — while everything wrapped
     * around it here (the snapshot, the interruption restore, the timeout
     * reading, the cancel check) is identical either way.
     */
    protected function runComposer(MarketplaceComposerRunner $composer, MarketplaceInstallAttempt $attempt): MarketplaceComposerResultData
    {
        $composerAuth = $this->composerAuth($attempt);

        if ($composerAuth !== null) {
            throw_unless(
                $composer instanceof MarketplaceAuthenticatedComposerRunner,
                RuntimeException::class,
                'Marketplace Composer authentication is available but the configured composer runner does not support authentication.',
            );

            return $composer->requireWithComposerAuth(
                composerName: $attempt->composer_name,
                versionConstraint: $attempt->version_constraint ?: '*',
                timeoutSeconds: static::composerTimeoutSeconds(),
                composerAuth: $composerAuth,
            );
        }

        return $composer->require(
            composerName: $attempt->composer_name,
            versionConstraint: $attempt->version_constraint ?: '*',
            timeoutSeconds: static::composerTimeoutSeconds(),
        );
    }

    private static function positiveConfiguredSeconds(string $key, int $default): int
    {
        $configured = config($key, $default);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : $default;
    }

    private function remainingBudgetSeconds(int $reserveSeconds): int
    {
        $elapsedSeconds = $this->startedAtNanoseconds === null
            ? 0
            : (int) floor((hrtime(true) - $this->startedAtNanoseconds) / 1_000_000_000);

        return max(0, static::jobTimeoutSeconds() - $elapsedSeconds - $reserveSeconds);
    }

    /**
     * The attempt is still Queued at the point the job failed, and it has been
     * queued for longer than an unclaimed operation is allowed to wait. Nothing
     * ever claimed it, so no worker ever took it — which is the one failure this
     * system can otherwise only describe as "it just never happened".
     *
     * The age alone cannot say that, because it measures how long the attempt
     * waited, not whether anything was there to take it: a queue backlog longer
     * than the stale window, or a job released for lock contention until its
     * attempts ran out, both leave a long-queued attempt that a worker really
     * did handle. Only the heartbeat is evidence of a worker, so the absence of
     * a fresh one is what makes "no worker is running" true rather than merely
     * plausible.
     */
    private function wasNeverClaimedByAWorker(MarketplaceInstallAttempt $attempt): bool
    {
        return $attempt->status === MarketplaceInstallIntentStatus::Queued
            && FindStuckMarketplaceInstallOperationsAction::isQueuedStale($attempt)
            && ! MarketplaceWorkerHeartbeat::isFresh();
    }

    private function runWithLock(MarketplaceComposerRunner $composer): void
    {
        $attempt = MarketplaceInstallAttempt::query()->find($this->installAttemptId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        $attempt = ClaimMarketplaceInstallAttemptAction::run(
            attempt: $attempt,
            attemptCount: $this->attempts(),
            progressTotal: $this->progressTotal(),
        );

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        // Before anything is allowed to touch composer.json, composer.lock or
        // vendor/ — which includes the lifecycle and migration actions further
        // down, not just Composer itself. A snapshot taken any later would be a
        // snapshot of a half-changed application.
        $snapshot = SnapshotComposerStateAction::run();
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_snapshot_captured', MarketplaceInstallFailureStage::Composer);

        try {
            $this->prepareOperation($attempt);
        } catch (Throwable $throwable) {
            $this->failPreparedOperation($attempt, $snapshot, $throwable);

            return;
        }

        $attempt->refresh();

        // Between the preparation and the Composer run, and only for an
        // operation that has a preparation worth stopping after. An uninstall's
        // lifecycle has already torn the extension down by this point, so it
        // must not then start rewriting composer.json on behalf of an operator
        // who has changed their mind. An install has done nothing yet, and
        // stopping it here rather than at the existing post-Composer check
        // would report a lifecycle that never ran.
        if ($this->preparesBeforeComposer() && $attempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
            $this->markCancelledBeforeComposer($attempt);

            return;
        }

        if ($this->shouldSkipComposerStage($attempt)) {
            $skipKey = $this->composerSkippedTranslationKey();
            $result = new MarketplaceComposerResultData(
                exitCode: 0,
                output: MarketplaceOperationVocabulary::translate($attempt->operation, $skipKey),
                errorOutput: '',
            );

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, $skipKey, MarketplaceInstallFailureStage::Composer, [
                'composer_name' => $attempt->composer_name,
            ], $result->output);
        } else {
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_composer_started', MarketplaceInstallFailureStage::Composer, [
                'composer_name' => $attempt->composer_name,
                'version_constraint' => $attempt->version_constraint ?: '*',
            ]);

            try {
                $result = $this->runComposer($composer, $attempt);
            } catch (Throwable $throwable) {
                // A Composer run that died by throwing did not get to tell us
                // whether it had already rewritten composer.json. Nothing here
                // reverted it on our behalf, so the snapshot has to.
                $this->restoreInterruptedComposerState($attempt, $snapshot, $throwable->getMessage());
                $this->markComposerThrowable($attempt, $throwable);

                return;
            }

            $attempt->refresh();

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_composer_completed', MarketplaceInstallFailureStage::Composer, outputExcerpt: $result->output);
        }

        if ($attempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
            // A cancel taken after Composer started means the require may have
            // been interrupted part-way, which is the one Composer outcome that
            // does not clean up after itself.
            $this->restoreInterruptedComposerState(
                $attempt,
                $snapshot,
                (string) __('capell-marketplace::marketplace.operations.cancelled_after_composer'),
            );
            $this->markCancelledAfterComposer($attempt, $result);

            return;
        }

        if (! $result->successful()) {
            // A clean `composer require` failure reverts composer.json itself,
            // and matchesDisk() makes the restore a no-op in exactly that case.
            // A timeout does not: the process was killed, so composer.json can
            // be left rewritten with vendor/ half-populated.
            if ($result->timedOut) {
                $this->restoreInterruptedComposerState(
                    $attempt,
                    $snapshot,
                    (string) __('capell-marketplace::marketplace.operations.composer_timed_out'),
                );
            }

            $this->markComposerFailure($attempt, $result);

            return;
        }

        try {
            $this->applyOperation($attempt);

            // Inside the try, and before success is declared. This is the last
            // moment at which a bad operation can still be undone: once the
            // attempt reaches Succeeded, ALLOWED_TRANSITIONS has no way out of
            // it, and a throw would turn a completed operation into an
            // illegal-transition crash that the queue then retries.
            $this->runHealthCheck($attempt);

            $attempt = FinalizeMarketplaceInstallAttemptAction::run($attempt, $result);
        } catch (Throwable $throwable) {
            $failureStage = $this->failureStageFor($throwable);
            $rollback = $this->decorateRollbackOutcome(
                $attempt,
                $this->rollBackComposerState($attempt, $snapshot, $throwable, $failureStage),
                $failureStage,
            );

            TransitionMarketplaceInstallAttemptAction::run(
                $attempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: $rollback['failure_reason'],
                    failureStage: $failureStage,
                    failureType: $this->resolveFailureType($rollback, $failureStage),
                    outputExcerpt: $result->output,
                    errorExcerpt: $result->errorOutput,
                    timelineContext: $rollback['timeline_context'],
                ),
            );

            return;
        }

        if ($attempt->status !== MarketplaceInstallIntentStatus::Succeeded) {
            return;
        }

        $this->announceSucceededAttempt($attempt);
    }

    /**
     * Ask a fresh process whether the site still boots, before anyone is told
     * the operation worked.
     *
     * Throws on failure so it lands in the same catch as a lifecycle failure and
     * takes the same rollback path. That is the whole design: a site the health
     * check cannot confirm is treated exactly like an operation that threw.
     */
    private function runHealthCheck(MarketplaceInstallAttempt $attempt): void
    {
        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_health_check_started', MarketplaceInstallFailureStage::HealthCheck);

        $result = RunPostOperationHealthCheckAction::run($this->healthCheckBudgetSeconds());

        if ($result->passed()) {
            // A skip is not a pass, and the timeline must not read like one. The
            // operation is allowed to complete — nothing looked at it and found a
            // problem — but the operator can see that nothing looked.
            if ($result->unverified()) {
                $this->recordEvent(
                    $attempt,
                    MarketplaceInstallAttemptEventLevel::Warning,
                    'timeline_health_check_skipped',
                    MarketplaceInstallFailureStage::HealthCheck,
                    [
                        ...$result->timelineContext(),
                        ...($result->skipReason === null ? [] : ['reason' => $result->skipReason]),
                    ],
                );

                return;
            }

            $this->recordEvent(
                $attempt,
                MarketplaceInstallAttemptEventLevel::Success,
                'timeline_health_check_completed',
                MarketplaceInstallFailureStage::HealthCheck,
                $result->timelineContext(),
            );

            return;
        }

        $reason = $result->failureReason ?? (string) __('capell-marketplace::marketplace.operations.health_check_failed');

        // Recorded separately from the failure the transition will write: this
        // one carries which probe said what, which is the part an operator needs
        // to tell "the site is broken" from "the probe could not run".
        $this->recordEvent(
            $attempt,
            MarketplaceInstallAttemptEventLevel::Error,
            'timeline_health_check_probe_failed',
            MarketplaceInstallFailureStage::HealthCheck,
            [...$result->timelineContext(), 'reason' => $reason],
            $result->bootProbeOutput !== '' ? $result->bootProbeOutput : null,
        );

        throw new MarketplaceHealthCheckFailedException($reason);
    }

    /**
     * Put composer.json, composer.lock and vendor/ back where they were, and be
     * honest about whether it worked.
     *
     * The rollback failing is the worst outcome this system has, and the way it
     * usually gets worse is by replacing the original error with its own. Both
     * are therefore recorded — in the reason, on the timeline, and on the
     * attempt context — and the operator is told plainly that manual recovery is
     * needed.
     *
     * @return array{rolled_back: bool, failure_reason: string, timeline_context: array<string, mixed>}
     */
    private function rollBackComposerState(
        MarketplaceInstallAttempt $attempt,
        ComposerStateSnapshot $snapshot,
        Throwable $throwable,
        MarketplaceInstallFailureStage $stage,
    ): array {
        $originalReason = $throwable->getMessage();

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_rollback_started', $stage, [
            'reason' => $originalReason,
        ]);

        try {
            $restored = RestoreComposerStateAction::run($snapshot, $this->rollbackBudgetSeconds());

            if ($restored) {
                $this->rediscoverRestoredLaravelPackages();
            }
        } catch (Throwable $rollbackThrowable) {
            report($rollbackThrowable);

            $reason = (string) __('capell-marketplace::marketplace.operations.rollback_failed', [
                'error' => $originalReason,
                'rollback_error' => $rollbackThrowable->getMessage(),
            ]);
            $context = [
                'reason' => $reason,
                'error' => $originalReason,
                'rollback_error' => $rollbackThrowable->getMessage(),
                'rolled_back' => false,
            ];

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Error, 'timeline_rollback_failed', $stage, $context);
            $this->recordRollbackOutcome($attempt, false, $originalReason, $rollbackThrowable->getMessage());

            return [
                'rolled_back' => false,
                'failure_reason' => $reason,
                'timeline_context' => $context,
            ];
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_rollback_completed', $stage, [
            'rolled_back' => true,
        ]);
        $this->recordRollbackOutcome($attempt, true, $originalReason, null);

        return [
            'rolled_back' => true,
            'failure_reason' => $originalReason,
            'timeline_context' => [
                'reason' => $originalReason,
                'rolled_back' => true,
            ],
        ];
    }

    /**
     * Put the Composer state back after a run that was interrupted rather than
     * one that failed cleanly.
     *
     * `composer require` reverts composer.json itself when it decides it cannot
     * satisfy a constraint, so the common failure needs nothing from us — and
     * ComposerStateSnapshot::matchesDisk() recognises that and skips the rebuild
     * entirely, which is what makes calling this on every interrupted path
     * cheap. The paths that do need it are the ones where nothing got to clean
     * up: a timeout, a cancellation taken mid-require, a runner that threw.
     *
     * This never throws and never changes the failure being reported. The
     * operator is being told about the Composer failure; a rollback problem is
     * recorded alongside it rather than in place of it.
     */
    private function restoreInterruptedComposerState(
        MarketplaceInstallAttempt $attempt,
        ComposerStateSnapshot $snapshot,
        string $originalReason,
    ): void {
        try {
            $restored = RestoreComposerStateAction::run($snapshot, $this->rollbackBudgetSeconds());
        } catch (Throwable $throwable) {
            report($throwable);

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Error, 'timeline_rollback_failed', MarketplaceInstallFailureStage::Composer, [
                'reason' => $originalReason,
                'rollback_error' => $throwable->getMessage(),
                'rolled_back' => false,
            ]);
            $this->recordRollbackOutcome($attempt, false, $originalReason, $throwable->getMessage());

            return;
        }

        // Nothing was written, so nothing was undone. Claiming a rollback here
        // would be a claim about work that never happened.
        if (! $restored) {
            return;
        }

        try {
            $this->rediscoverRestoredLaravelPackages();
        } catch (Throwable $throwable) {
            report($throwable);

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Error, 'timeline_rollback_failed', MarketplaceInstallFailureStage::Composer, [
                'reason' => $originalReason,
                'rollback_error' => $throwable->getMessage(),
                'rolled_back' => false,
            ]);
            $this->recordRollbackOutcome($attempt, false, $originalReason, $throwable->getMessage());

            return;
        }

        $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_rollback_completed', MarketplaceInstallFailureStage::Composer, [
            'rolled_back' => true,
        ]);
        $this->recordRollbackOutcome($attempt, true, $originalReason, null);
    }

    /**
     * Composer restoration runs with --no-scripts, so Laravel's generated
     * package manifest still describes the failed state until it is rebuilt.
     * Treat a rebuild failure as a rollback failure: the next Artisan or HTTP
     * boot may otherwise load a provider that Composer just removed.
     */
    private function rediscoverRestoredLaravelPackages(): void
    {
        ComposerAutoloaderReloader::reload();
        resolve(PackageManifest::class)->build();

        $manifests = new ManifestLoader(new ManifestValidator)->discover();
        BuildPackageCacheAction::run($manifests);
        $this->synchronizePackageRegistry($manifests);
    }

    /** @param array<string, CapellManifestData> $manifests */
    private function synchronizePackageRegistry(array $manifests): void
    {
        resolve(CapellPackageRegistry::class)->synchronizeDiscoveredManifests(
            $manifests,
            fn (string $packageName): ?string => CapellCore::getInstalledPrettyVersion($packageName),
        );
    }

    /**
     * The durable record of what the rollback did, kept on the attempt rather
     * than only on the timeline: failure_context is rebuilt from the failure
     * columns when telemetry is finalised, so anything written there would be
     * discarded, and a retry decision made a day later still needs to know
     * whether this host was left consistent.
     */
    private function recordRollbackOutcome(
        MarketplaceInstallAttempt $attempt,
        bool $rolledBack,
        string $originalError,
        ?string $rollbackError,
    ): void {
        $redacted = RedactMarketplaceDiagnosticContextAction::run(array_filter([
            'error' => $originalError,
            'rollback_error' => $rollbackError,
        ], static fn (mixed $value): bool => $value !== null));

        $attempt->forceFill([
            'context' => [
                ...($attempt->context ?? []),
                'rollback' => [
                    'rolled_back' => $rolledBack,
                    ...$redacted,
                ],
            ],
        ])->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function composerAuth(MarketplaceInstallAttempt $attempt): ?array
    {
        $context = $attempt->context ?? [];
        $encrypted = $context['composer_auth_encrypted'] ?? null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Marketplace Composer authentication payload could not be decoded.', $jsonException->getCode(), previous: $jsonException);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function markComposerFailure(MarketplaceInstallAttempt $attempt, MarketplaceComposerResultData $result): void
    {
        $status = $result->timedOut
            ? MarketplaceInstallIntentStatus::TimedOut
            : MarketplaceInstallIntentStatus::Failed;
        $reason = $result->timedOut
            ? (string) __('capell-marketplace::marketplace.operations.composer_timed_out')
            : (trim($result->errorOutput) ?: trim($result->output) ?: (string) __('capell-marketplace::marketplace.operations.composer_failed'));
        TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: $status,
                failureReason: $reason,
                failureStage: MarketplaceInstallFailureStage::Composer,
                composerResult: $result,
                outputExcerpt: $result->output,
                errorExcerpt: $result->errorOutput,
                timelineContext: ['reason' => $reason],
                timelineOutputExcerpt: $result->errorOutput !== '' ? $result->errorOutput : $result->output,
            ),
        );
    }

    private function markComposerThrowable(MarketplaceInstallAttempt $attempt, Throwable $throwable): void
    {
        TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: MarketplaceInstallIntentStatus::Failed,
                failureReason: $throwable->getMessage(),
                failureStage: MarketplaceInstallFailureStage::Composer,
                timelineContext: ['reason' => $throwable->getMessage()],
            ),
        );
    }

    /**
     * A preparation that threw, reported with the same honesty a post-Composer
     * failure gets.
     *
     * The Composer restore still runs, and is expected to be a no-op —
     * ComposerStateSnapshot::matchesDisk() sees nothing changed and skips the
     * rebuild. That is deliberate rather than wasteful: a preparation is free to
     * touch composer.json (a bundle promotion does), and paying an is-it-changed
     * check on every failure is cheaper than discovering the one path where it
     * did.
     *
     * decorateRollbackOutcome() gets the last word, so an operation whose
     * preparation cannot be undone can correct the sentence rather than let the
     * operator read "rolled back" and believe nothing happened.
     */
    private function failPreparedOperation(
        MarketplaceInstallAttempt $attempt,
        ComposerStateSnapshot $snapshot,
        Throwable $throwable,
    ): void {
        $failureStage = $this->prepareFailureStage();
        $rollback = $this->decorateRollbackOutcome(
            $attempt,
            $this->rollBackComposerState($attempt, $snapshot, $throwable, $failureStage),
            $failureStage,
        );

        TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: MarketplaceInstallIntentStatus::Failed,
                failureReason: $rollback['failure_reason'],
                failureStage: $failureStage,
                failureType: $this->resolveFailureType($rollback, $failureStage),
                errorExcerpt: $throwable->getMessage(),
                timelineContext: $rollback['timeline_context'],
            ),
        );
    }

    /**
     * A cancel taken while the preparation was running.
     *
     * Composer never started, so there is nothing on disk to put back — but for
     * an operation whose preparation is the irreversible half, "cancelled" on
     * its own would read as "nothing happened". The stage carries which half
     * did run, and the failure type is derived from it.
     */
    private function markCancelledBeforeComposer(MarketplaceInstallAttempt $attempt): void
    {
        $stage = $this->prepareFailureStage();
        $reason = MarketplaceOperationVocabulary::translate($attempt->operation, 'cancelled_after_lifecycle');

        TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: MarketplaceInstallIntentStatus::Cancelled,
                failureReason: $reason,
                failureStage: $stage,
                failureType: MarketplaceInstallFailureType::CancelledAfterLifecycle,
                timelineContext: ['reason' => $reason],
            ),
        );
    }

    private function markCancelledAfterComposer(MarketplaceInstallAttempt $attempt, MarketplaceComposerResultData $result): void
    {
        $reason = (string) __('capell-marketplace::marketplace.operations.cancelled_after_composer');

        TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: MarketplaceInstallIntentStatus::Cancelled,
                failureReason: $reason,
                failureStage: MarketplaceInstallFailureStage::Composer,
                composerResult: $result,
                outputExcerpt: $result->output,
                errorExcerpt: $result->errorOutput,
                timelineContext: ['reason' => $reason],
            ),
        );
    }

    private function rediscoverLaravelPackages(): void
    {
        try {
            resolve(PackageManifest::class)->build();
        } catch (Throwable $throwable) {
            // Capell's own registry, rebuilt alongside this, is what the
            // operation is judged on. A manifest that could not be written is
            // worth reporting but must not fail an otherwise complete operation.
            report($throwable);
        }
    }

    /**
     * Put the application back into the state a scripted Composer run would have
     * left it in.
     *
     * Capell cannot enumerate the application's post-autoload-dump hooks: the
     * application is a different repository and may declare asset publishing,
     * cache warming, or anything else alongside package:discover. So rather than
     * reproducing a list of hooks, the application's own chain is replayed. The
     * in-process manifest rebuild above still happens, because the subprocess
     * only fixes the files on disk — this worker's already-booted container
     * needs the fresh manifest too.
     *
     * A hook that fails must not fail an operation whose package is already on
     * disk and registered, so this reports rather than throws. The same applies
     * when Composer left no time for the replay at all: skipping it and letting
     * the attempt finalise beats being killed mid-replay with the package
     * already installed and the attempt still marked running.
     */
    private function replayHostComposerScripts(): void
    {
        $budgetSeconds = $this->scriptReplayBudgetSeconds();

        if ($budgetSeconds <= 0) {
            report(new RuntimeException(
                'Skipped replaying the application post-autoload-dump scripts after a Marketplace install: '
                . 'the Composer run consumed the whole job timeout. Raise capell.process.composer.job_timeout_buffer_seconds.',
            ));

            return;
        }

        try {
            $result = resolve(MarketplaceComposerScriptRunner::class)->replayRootScript(
                MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP,
                $budgetSeconds,
            );

            if (! $result instanceof MarketplaceComposerResultData || $result->successful()) {
                return;
            }

            // The runner has already redacted this; a replayed hook prints
            // whatever the application told it to.
            report(new RuntimeException(sprintf(
                'Replaying the application post-autoload-dump scripts after a Marketplace install exited %d: %s',
                $result->exitCode,
                trim($result->errorOutput) ?: trim($result->output),
            )));
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
