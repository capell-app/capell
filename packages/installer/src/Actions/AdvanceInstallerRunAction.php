<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Data\InstallerRunStepData;
use Capell\Installer\Support\AdminUserModelGuard;
use Capell\Installer\Support\InstallerRemediation;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class AdvanceInstallerRunAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerRemediation $remediation,
        private readonly AdminUserModelGuard $adminUserModelGuard,
        private readonly CacheInstallerSuccessSummaryAction $cacheSuccessSummary,
    ) {}

    public function handle(string $installId, string $stepKey): InstallerRunStepData
    {
        $inputArray = $this->sessions->input($installId);
        if (! is_array($inputArray)) {
            return new InstallerRunStepData(
                installId: $installId,
                currentStep: $stepKey,
                status: 'failed',
                error: 'Install session not found or expired. Please restart the installer.',
                statusCode: 410,
            );
        }

        $inputData = InstallInputData::from($inputArray);
        /** @var array<int, array{key: string, label: string}> $plan */
        $plan = $this->sessions->plan($installId);
        $reporter = $this->reporter($installId);

        if ($this->sessions->status($installId, 'pending') === 'complete') {
            return $this->result($installId, $stepKey, 'complete', $reporter);
        }

        $expectedStepKey = $this->sessions->expectedStepKey($installId, $plan);
        if ($expectedStepKey === null) {
            return new InstallerRunStepData(
                installId: $installId,
                currentStep: $stepKey,
                status: 'failed',
                error: 'Install plan not found or expired. Please restart the installer.',
                statusCode: 410,
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
            $newUserId = RunInstallStepAction::run($stepKey, $inputData, $reporter, $resolvedUserId);
            if (is_int($newUserId) && $newUserId !== $resolvedUserId) {
                $this->sessions->putResolvedUserId($installId, $newUserId);
            }
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable::class . ': ' . $throwable->getMessage());
            $reporter->error(sprintf('  at %s:%d', $throwable->getFile(), $throwable->getLine()));
            $reporter->markFailed();
            $this->sessions->clearActiveLock();

            return $this->result(
                installId: $installId,
                stepKey: $stepKey,
                status: 'failed',
                reporter: $reporter,
                error: $throwable->getMessage(),
                errorClass: $throwable::class,
                remediation: $this->remediation->remediationFor($throwable->getMessage()),
            );
        } finally {
            $this->sessions->recordStepPeakMemory($installId, $stepKey, memory_get_peak_usage(true));
        }

        $nextStep = InstallPlan::findNextStep($plan, $stepKey);
        $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

        if ($nextStep === null) {
            $reporter->markComplete();
            $this->cacheSuccessSummary->handle($installId, $inputData);
            $this->sessions->clearActiveLock();

            return $this->result($installId, $stepKey, 'complete', $reporter);
        }

        return $this->result($installId, $stepKey, 'running', $reporter, nextStep: $nextStep);
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
            $this->sessions->clearActiveLock();

            return $this->result(
                installId: $installId,
                stepKey: $stepKey,
                status: 'failed',
                reporter: $reporter,
                error: 'Preflight checks failed.',
                remediation: $this->remediation->preflightRemediation($preflight),
                preflight: $preflight,
            );
        }

        $nextStep = InstallPlan::findNextStep($plan, $stepKey);
        $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

        return $this->result(
            installId: $installId,
            stepKey: $stepKey,
            status: 'running',
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
                status: 'running',
                reporter: $reporter,
                nextStep: $expectedStepKey,
            );
        }

        return $this->result(
            installId: $installId,
            stepKey: $stepKey,
            status: 'failed',
            reporter: $reporter,
            nextStep: $expectedStepKey,
            error: sprintf(
                'Install step "%s" is out of sequence. Expected "%s". Refresh the installer progress page and continue from the current step.',
                $stepKey,
                $expectedStepKey,
            ),
            expectedStep: $expectedStepKey,
            statusCode: 409,
        );
    }

    private function result(
        string $installId,
        string $stepKey,
        string $status,
        FileLogProgressReporter $reporter,
        ?string $nextStep = null,
        ?string $error = null,
        ?string $expectedStep = null,
        ?string $errorClass = null,
        ?string $remediation = null,
        ?array $preflight = null,
        int $statusCode = 200,
    ): InstallerRunStepData {
        return new InstallerRunStepData(
            installId: $installId,
            currentStep: $stepKey,
            status: $status,
            lines: $this->sessions->lines($installId),
            nextStep: $nextStep,
            logPath: $reporter->logPath(),
            error: $error,
            expectedStep: $expectedStep,
            errorClass: $errorClass,
            remediation: $remediation,
            preflight: $preflight,
            statusCode: $statusCode,
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
