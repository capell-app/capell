<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Data\InstallerRunStepData;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Capell\Installer\Support\AdminUserModelGuard;
use Capell\Installer\Support\InstallerRemediation;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class AdvanceInstallerRunAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerRemediation $remediation,
        private readonly AdminUserModelGuard $adminUserModelGuard,
    ) {}

    public function handle(string $installId, string $stepKey): InstallerRunStepData
    {
        $inputArray = $this->sessions->input($installId);
        if (! is_array($inputArray)) {
            return new InstallerRunStepData(
                installId: $installId,
                currentStep: $stepKey,
                code: InstallerRunStepResultCode::SessionNotFound,
            );
        }

        $inputData = InstallInputData::from($inputArray);
        /** @var array<int, array{key: string, label: string}> $plan */
        $plan = $this->sessions->plan($installId);
        $reporter = $this->reporter($installId);

        if ($this->sessions->status($installId, 'pending') === 'complete') {
            return $this->result($installId, $stepKey, InstallerRunStepResultCode::Complete, $reporter);
        }

        $expectedStepKey = $this->sessions->expectedStepKey($installId, $plan);
        if ($expectedStepKey === null) {
            return new InstallerRunStepData(
                installId: $installId,
                currentStep: $stepKey,
                code: InstallerRunStepResultCode::PlanNotFound,
            );
        }

        if ($stepKey !== $expectedStepKey) {
            return $this->outOfSequenceResult($installId, $stepKey, $expectedStepKey, $reporter);
        }

        $reporter->markRunning();

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        try {
            $reporter->step(InstallPlan::labelForStep($plan, $stepKey) . '…');

            if ($stepKey === InstallPlan::STEP_PREFLIGHT_CHECKS) {
                return $this->runPreflightStep($installId, $stepKey, $inputData, $plan, $reporter);
            }

            $this->ensureAdminUserModelIsReady($stepKey, $inputData, $reporter);

            $resolvedUserId = $this->sessions->resolvedUserId($installId);
            $packageMetadataRefreshed = $this->sessions->packageMetadataRefreshed($installId);
            $stepResult = RunInstallStepAction::run($stepKey, $inputData, $reporter, $resolvedUserId, $packageMetadataRefreshed);

            if (is_int($stepResult->resolvedUserId) && $stepResult->resolvedUserId !== $resolvedUserId) {
                $this->sessions->putResolvedUserId($installId, $stepResult->resolvedUserId);
            }

            if ($stepResult->packageMetadataRefreshed && ! $packageMetadataRefreshed) {
                $this->sessions->putPackageMetadataRefreshed($installId, true);
            }
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable::class . ': ' . $throwable->getMessage());
            $reporter->error(sprintf('  at %s:%d', $throwable->getFile(), $throwable->getLine()));
            $reporter->markFailed();
            $this->sessions->clearActiveLock($installId);

            return $this->result(
                installId: $installId,
                stepKey: $stepKey,
                code: InstallerRunStepResultCode::ExecutionFailed,
                reporter: $reporter,
                exceptionClass: $throwable::class,
                exceptionMessage: $throwable->getMessage(),
                remediation: $this->remediation->remediationFor($throwable->getMessage()),
            );
        } finally {
            $this->sessions->recordStepPeakMemory($installId, $stepKey, memory_get_peak_usage(true));
        }

        $nextStep = InstallPlan::findNextStep($plan, $stepKey);
        $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

        if ($nextStep === null) {
            $reporter->markComplete();
            CacheInstallerSuccessSummaryAction::run($installId, $inputData);
            $this->sessions->clearActiveLock($installId);

            return $this->result($installId, $stepKey, InstallerRunStepResultCode::Complete, $reporter);
        }

        return $this->result($installId, $stepKey, InstallerRunStepResultCode::Running, $reporter, nextStep: $nextStep);
    }

    /**
     * @param  array<int, array{key: string, label: string}>  $plan
     */
    private function runPreflightStep(
        string $installId,
        string $stepKey,
        InstallInputData $inputData,
        array $plan,
        FileLogProgressReporter $reporter,
    ): InstallerRunStepData {
        $preflight = resolve(InstallerPreflight::class)->run($inputData);
        $this->sessions->putPreflightReport($installId, $preflight);
        $this->remediation->reportPreflight($preflight, $reporter);

        if (InstallerPreflight::hasBlockingFailures($preflight['checks'])) {
            $reporter->markFailed();
            $this->sessions->clearActiveLock($installId);

            return $this->result(
                installId: $installId,
                stepKey: $stepKey,
                code: InstallerRunStepResultCode::PreflightFailed,
                reporter: $reporter,
                remediation: $this->remediation->preflightRemediation($preflight),
                preflight: $preflight,
            );
        }

        $nextStep = InstallPlan::findNextStep($plan, $stepKey);
        $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

        return $this->result(
            installId: $installId,
            stepKey: $stepKey,
            code: InstallerRunStepResultCode::Running,
            reporter: $reporter,
            nextStep: $nextStep,
            preflight: $preflight,
        );
    }

    private function outOfSequenceResult(
        string $installId,
        string $stepKey,
        string $expectedStepKey,
        FileLogProgressReporter $reporter,
    ): InstallerRunStepData {
        if (in_array($stepKey, $this->sessions->completedSteps($installId), true)) {
            return $this->result(
                installId: $installId,
                stepKey: $stepKey,
                code: InstallerRunStepResultCode::Running,
                reporter: $reporter,
                nextStep: $expectedStepKey,
            );
        }

        return $this->result(
            installId: $installId,
            stepKey: $stepKey,
            code: InstallerRunStepResultCode::OutOfSequence,
            reporter: $reporter,
            nextStep: $expectedStepKey,
            expectedStep: $expectedStepKey,
        );
    }

    private function result(
        string $installId,
        string $stepKey,
        InstallerRunStepResultCode $code,
        FileLogProgressReporter $reporter,
        ?string $nextStep = null,
        ?string $expectedStep = null,
        ?string $exceptionClass = null,
        ?string $exceptionMessage = null,
        ?string $remediation = null,
        ?array $preflight = null,
    ): InstallerRunStepData {
        return new InstallerRunStepData(
            installId: $installId,
            currentStep: $stepKey,
            code: $code,
            lines: $this->sessions->lines($installId),
            nextStep: $nextStep,
            logPath: $reporter->logPath(),
            expectedStep: $expectedStep,
            exceptionClass: $exceptionClass,
            exceptionMessage: $exceptionMessage,
            remediation: $remediation,
            preflight: $preflight,
        );
    }

    private function ensureAdminUserModelIsReady(
        string $stepKey,
        InstallInputData $inputData,
        FileLogProgressReporter $reporter,
    ): void {
        if (($stepKey === InstallPlan::STEP_RESOLVE_USER && $this->adminUserModelGuard->hasInstalledAdminPackageSelection($inputData))
            || InstallPlan::packageNameFromStep($stepKey) === 'capell-app/admin') {
            $this->adminUserModelGuard->ensureUserModelSupportsAdminPackage($inputData, $reporter);
        }
    }

    private function reporter(string $installId): FileLogProgressReporter
    {
        return new FileLogProgressReporter($installId, new CacheProgressReporter($installId));
    }
}
