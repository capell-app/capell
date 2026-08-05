<?php

declare(strict_types=1);

namespace Capell\Marketplace\Jobs;

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\ComposerAutoloaderReloader;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Marketplace\Actions\ClaimMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FinalizeMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FinalizeMarketplaceInstallOperationTelemetryAction;
use Capell\Marketplace\Actions\NotifyMarketplaceInstallCompletedAction;
use Capell\Marketplace\Actions\PackageIsAvailableForLifecycleAction;
use Capell\Marketplace\Actions\RecordMarketplaceInstallAttemptEventAction;
use Capell\Marketplace\Actions\TransitionMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\UpdateMarketplaceInstallOperationProgressAction;
use Capell\Marketplace\Contracts\MarketplaceAuthenticatedComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceInstallAttemptTransitionData;
use Capell\Marketplace\Enums\MarketplaceInstallAttemptEventLevel;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
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

final class RunMarketplaceInstallAttemptJob implements ShouldBeUnique, ShouldQueue
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

    public int $timeout;

    /**
     * Unlimited attempts, bounded by retryUntil(). This is deliberate: a job that
     * cannot take the composer-install lock calls release() and must be free to
     * keep waiting for the holder to finish. Capping $tries would fail an install
     * merely because another install was running.
     */
    public int $tries = 0;

    /**
     * Attempts that actually threw, however, must be bounded — otherwise a
     * reproducibly failing composer run repeats for the whole retryUntil() window,
     * re-taking the lock each time. release() does not count towards this.
     */
    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        private readonly int $installAttemptId,
    ) {
        $this->timeout = self::jobTimeoutSeconds();
    }

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
        $startedAt = hrtime(true);
        $peakMemoryBefore = memory_get_peak_usage(true);
        $connection = DB::connection();
        $wasLoggingQueries = $connection->logging();
        $queryCountBefore = count($connection->getQueryLog());

        if (! $wasLoggingQueries) {
            $connection->flushQueryLog();
            $connection->enableQueryLog();
            $queryCountBefore = 0;
        }

        $lock = Cache::lock('capell-marketplace:composer-install', self::jobTimeoutSeconds());

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

        $reason = $throwable?->getMessage() ?: (string) __('capell-marketplace::marketplace.operations.queue_failed');
        $attempt = TransitionMarketplaceInstallAttemptAction::run(
            $attempt,
            new MarketplaceInstallAttemptTransitionData(
                toStatus: MarketplaceInstallIntentStatus::Failed,
                failureReason: $reason,
                failureStage: MarketplaceInstallFailureStage::Queue,
                timelineContext: ['reason' => $reason],
            ),
        );

        FinalizeMarketplaceInstallOperationTelemetryAction::run($attempt);
    }

    private static function positiveConfiguredSeconds(string $key, int $default): int
    {
        $configured = config($key, $default);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : $default;
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
            progressTotal: 5,
        );

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        if (PackageIsAvailableForLifecycleAction::run($attempt->composer_name)) {
            $result = new MarketplaceComposerResultData(
                exitCode: 0,
                output: (string) __('capell-marketplace::marketplace.operations.timeline_composer_skipped_downloaded'),
                errorOutput: '',
            );

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_composer_skipped_downloaded', MarketplaceInstallFailureStage::Composer, [
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
                $this->markComposerThrowable($attempt, $throwable);

                return;
            }

            $attempt->refresh();

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_composer_completed', MarketplaceInstallFailureStage::Composer, outputExcerpt: $result->output);
        }

        if ($attempt->status === MarketplaceInstallIntentStatus::CancelRequested) {
            $this->markCancelledAfterComposer($attempt, $result);

            return;
        }

        if (! $result->successful()) {
            $this->markComposerFailure($attempt, $result);

            return;
        }

        try {
            $this->reloadPackageRegistry();
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_registry_reloaded', MarketplaceInstallFailureStage::PackageDiscovery);

            if (! CapellCore::hasPackage($attempt->composer_name)) {
                throw new RuntimeException(sprintf('Installed package [%s] was not discovered by Capell.', $attempt->composer_name));
            }

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_package_discovered', MarketplaceInstallFailureStage::PackageDiscovery);

            $package = CapellCore::getPackage($attempt->composer_name);

            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Info, 'timeline_lifecycle_started', MarketplaceInstallFailureStage::Lifecycle);
            InstallPackageAction::run($package, [], null, false);
            $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_lifecycle_completed', MarketplaceInstallFailureStage::Lifecycle);

            $attempt = FinalizeMarketplaceInstallAttemptAction::run($attempt, $result);

            if ($attempt->status !== MarketplaceInstallIntentStatus::Succeeded) {
                return;
            }

            try {
                NotifyMarketplaceInstallCompletedAction::run($attempt->refresh());
                $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Success, 'timeline_notification_sent', MarketplaceInstallFailureStage::Notification);
            } catch (Throwable $throwable) {
                report($throwable);
                $this->recordEvent($attempt, MarketplaceInstallAttemptEventLevel::Warning, 'timeline_notification_failed', MarketplaceInstallFailureStage::Notification, [
                    'reason' => $throwable->getMessage(),
                ]);
            }
        } catch (Throwable $throwable) {
            TransitionMarketplaceInstallAttemptAction::run(
                $attempt,
                new MarketplaceInstallAttemptTransitionData(
                    toStatus: MarketplaceInstallIntentStatus::Failed,
                    failureReason: $throwable->getMessage(),
                    failureStage: str_contains(strtolower($throwable->getMessage()), 'not discovered')
                        ? MarketplaceInstallFailureStage::PackageDiscovery
                        : MarketplaceInstallFailureStage::Lifecycle,
                    outputExcerpt: $result->output,
                    errorExcerpt: $result->errorOutput,
                    timelineContext: ['reason' => $throwable->getMessage()],
                ),
            );
        }
    }

    private function runComposer(MarketplaceComposerRunner $composer, MarketplaceInstallAttempt $attempt): MarketplaceComposerResultData
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
                timeoutSeconds: self::composerTimeoutSeconds(),
                composerAuth: $composerAuth,
            );
        }

        return $composer->require(
            composerName: $attempt->composer_name,
            versionConstraint: $attempt->version_constraint ?: '*',
            timeoutSeconds: self::composerTimeoutSeconds(),
        );
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

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordEvent(
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
                progressTotal: 5,
                attemptCount: $this->attempts(),
            );
        }

        RecordMarketplaceInstallAttemptEventAction::run(
            attempt: $attempt,
            level: $level,
            message: __('capell-marketplace::marketplace.operations.' . $translationKey),
            stage: $stage,
            context: $context,
            outputExcerpt: $outputExcerpt,
        );
    }

    /**
     * Rebuild Laravel's package manifest for the newly installed package.
     *
     * Composer runs with --no-scripts, which is what keeps a third-party
     * package's own scripts from executing as the web user — and which also
     * suppresses the post-autoload-dump hook that normally runs
     * `artisan package:discover`. Without this, the extension's service provider
     * is absent from bootstrap/cache/packages.php and never boots on the next
     * request. Doing it in-process is both cheaper and more reliable than
     * shelling out a second time.
     */
    private function rediscoverLaravelPackages(): void
    {
        try {
            resolve(PackageManifest::class)->build();
        } catch (Throwable $throwable) {
            // Capell's own registry, rebuilt below, is what this install is
            // judged on. A manifest that could not be written is worth reporting
            // but must not fail an otherwise complete install.
            report($throwable);
        }
    }

    private function stageProgress(MarketplaceInstallFailureStage $stage): int
    {
        return match ($stage) {
            MarketplaceInstallFailureStage::Preflight,
            MarketplaceInstallFailureStage::Queue => 0,
            MarketplaceInstallFailureStage::Composer => 1,
            MarketplaceInstallFailureStage::PackageDiscovery => 2,
            MarketplaceInstallFailureStage::Lifecycle => 3,
            MarketplaceInstallFailureStage::Notification,
            MarketplaceInstallFailureStage::DeploymentHandoff => 4,
        };
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
     * A hook that fails must not fail an install whose package is already on
     * disk and registered, so this reports rather than throws.
     */
    private function replayHostComposerScripts(): void
    {
        try {
            $result = resolve(MarketplaceComposerScriptRunner::class)->replayRootScript(
                MarketplaceComposerScriptRunner::POST_AUTOLOAD_DUMP,
                self::composerTimeoutSeconds(),
            );

            if ($result instanceof MarketplaceComposerResultData && ! $result->successful()) {
                report(new RuntimeException(sprintf(
                    'Replaying the application post-autoload-dump scripts after a Marketplace install exited %d: %s',
                    $result->exitCode,
                    trim($result->errorOutput) ?: trim($result->output),
                )));
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function reloadPackageRegistry(): void
    {
        ComposerAutoloaderReloader::reload();

        $this->rediscoverLaravelPackages();
        $this->replayHostComposerScripts();

        CapellCore::clearExtensionCache();

        $registry = resolve(CapellPackageRegistry::class);
        $manifests = new ManifestLoader(new ManifestValidator)->discover();
        $registry->fill($manifests);

        foreach ($manifests as $manifest) {
            CapellCore::registerManifestPackage(
                $manifest,
                CapellCore::getInstalledPrettyVersion($manifest->name),
            );
        }
    }
}
