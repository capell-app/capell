<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Controllers;

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Actions\BuildInstallerPageDataAction;
use Capell\Installer\Actions\RemoveSetupPackageAction;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallerOptions;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InstallController
{
    private const string REMOVE_INSTALLER_SESSION_KEY = 'capell.installer.can_remove_setup_package';

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerOptions $options,
    ) {}

    public function show(Request $request): Response
    {
        $hasActiveInstallLock = $this->sessions->hasActiveInstallLock();
        $capellAlreadyInstalled = $request->attributes->get('capellAlreadyInstalled') === true
            || (! $hasActiveInstallLock && $this->capellIsInstalled());
        $canReinstall = $request->attributes->get('capellCanReinstall') === true;

        $request->session()->regenerateToken();

        $pageData = BuildInstallerPageDataAction::run(
            capellAlreadyInstalled: $capellAlreadyInstalled,
            canReinstall: $canReinstall,
        );
        $viewData = $pageData->toViewData();
        $viewData['canRemoveInstaller'] = $this->canRemoveInstaller($request);

        $installId = $viewData['installId'] ?? null;
        if (is_string($installId) && ! $this->canAccessInstall($request, $installId)) {
            $viewData['installId'] = null;
            $viewData['installStatus'] = 'idle';
            $viewData['cancelUrl'] = null;
        }

        return response()
            ->view('capell-installer::install', $viewData)
            ->withHeaders($this->installerSessionHeaders());
    }

    public function store(Request $request): Response
    {
        try {
            $validated = $this->validateInput($request);
        } catch (ValidationException $validationException) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $validationException->getMessage(),
                    'errors' => $validationException->errors(),
                ], 422);
            }

            throw $validationException;
        }

        $inputData = resolve(InstallInputFactory::class)->fromWebInput(
            $this->normaliseLanguageInput($validated),
            allowWelcomeRoute: true,
            defaultPackageNames: $this->options->configuredDefaultPackageNames(),
        );

        $installId = $validated['install_id'] ?? (string) Str::uuid();
        $runAsJob = (bool) ($validated['run_as_job'] ?? false);

        if (! $this->sessions->cacheStoreIsUsable()) {
            return $this->cacheStoreUnavailableResponse($request);
        }

        if (! $this->canReplaceActiveInstall($request, $installId)) {
            return $this->activeInstallLockedResponse($request);
        }

        $this->grantInstallAccess($request, $installId);

        if ($runAsJob) {
            $queueReporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));
            try {
                if ($this->hasInstalledAdminPackageSelection($inputData)) {
                    $this->ensureUserModelSupportsAdminPackage($inputData, $queueReporter);
                }
            } catch (Throwable $throwable) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $throwable->getMessage(),
                        'errors' => ['user_model' => [$throwable->getMessage()]],
                    ], 422);
                }

                return back()->withErrors(['user_model' => $throwable->getMessage()])->withInput();
            }

            $this->sessions->cancelActiveInstallBeforeStarting($installId);

            $this->sessions->lock($installId, queued: true);
            $this->sessions->putStatus($installId, 'queued');
            $this->cacheSuccessSummary($installId, $inputData);

            dispatch(new RunCapellInstallJob($inputData, $installId));

            if ($request->expectsJson()) {
                return response()->json([
                    'installId' => $installId,
                    'status' => 'queued',
                    'progressUrl' => route('capell-installer.progress', ['installId' => $installId]),
                    'progressDataUrl' => route('capell-installer.progress.data', ['installId' => $installId]),
                    'reportUrl' => route('capell-installer.progress.download', ['installId' => $installId]),
                    'redirectUrl' => route('capell-installer.progress', ['installId' => $installId]),
                ]);
            }

            return to_route('capell-installer.progress', ['installId' => $installId]);
        }

        if ($request->expectsJson()) {
            try {
                return $this->prepareStepBasedInstall($installId, $inputData);
            } catch (Throwable $throwable) {
                return response()->json([
                    'message' => $throwable->getMessage(),
                    'errors' => ['user_model' => [$throwable->getMessage()]],
                ], 422);
            }
        }

        // Non-AJAX, non-job: run synchronously and redirect to progress page
        $this->sessions->cancelActiveInstallBeforeStarting($installId);

        $this->sessions->lock($installId);
        $this->sessions->putStatus($installId, 'running');

        $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));
        $reporter->markRunning();

        $installCompleted = false;

        try {
            if ($this->hasInstalledAdminPackageSelection($inputData)) {
                $this->ensureUserModelSupportsAdminPackage($inputData, $reporter);
            }

            RunInstallAction::run($inputData, $reporter);
            $reporter->markComplete();
            $this->cacheSuccessSummary($installId, $inputData);
            $installCompleted = true;
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable->getMessage());
            $reporter->markFailed();
            $this->sessions->clearActiveLock();
        }

        return to_route($installCompleted ? 'capell-installer.success' : 'capell-installer.progress', ['installId' => $installId]);
    }

    public function runStep(Request $request): JsonResponse
    {

        try {
            $validated = Validator::make($request->all(), [
                'install_id' => ['required', 'uuid'],
                'step' => ['required', 'string'],
            ])->validate();
        } catch (ValidationException $validationException) {
            return response()->json([
                'message' => $validationException->getMessage(),
                'errors' => $validationException->errors(),
            ], 422);
        }

        $installId = (string) $validated['install_id'];
        $stepKey = (string) $validated['step'];

        abort_unless($this->canAccessInstall($request, $installId), 404);

        $inputArray = $this->sessions->input($installId);
        if (! is_array($inputArray)) {
            return response()->json([
                'installId' => $installId,
                'status' => 'failed',
                'error' => 'Install session not found or expired. Please restart the installer.',
                'csrfToken' => csrf_token(),
            ], 410);
        }

        $inputData = InstallInputData::from($inputArray);
        /** @var array<int, array{key: string, label: string}> $plan */
        $plan = $this->sessions->plan($installId);
        $resolvedUserId = $this->sessions->resolvedUserId($installId);

        $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));

        if ($this->sessions->status($installId, 'pending') === 'complete') {
            return response()->json([
                'installId' => $installId,
                'currentStep' => $stepKey,
                'nextStep' => null,
                'status' => 'complete',
                'lines' => $this->sessions->lines($installId),
                'logPath' => $reporter->logPath(),
                'redirectUrl' => route('capell-installer.success', ['installId' => $installId]),
                'csrfToken' => csrf_token(),
            ]);
        }

        $expectedStepKey = $this->sessions->expectedStepKey($installId, $plan);
        if ($expectedStepKey === null) {
            return response()->json([
                'installId' => $installId,
                'status' => 'failed',
                'error' => 'Install plan not found or expired. Please restart the installer.',
                'csrfToken' => csrf_token(),
            ], 410);
        }

        if ($stepKey !== $expectedStepKey) {
            return $this->outOfSequenceStepResponse($installId, $stepKey, $expectedStepKey, $reporter);
        }

        $reporter->markRunning();

        try {
            $reporter->step(InstallPlan::labelForStep($plan, $stepKey) . '…');
            if ($stepKey === InstallPlan::STEP_PREFLIGHT_CHECKS) {
                $preflight = resolve(InstallerPreflight::class)->run($inputData);
                $this->sessions->putPreflightReport($installId, $preflight);
                $this->reportPreflight($preflight, $reporter);

                if (InstallerPreflight::hasBlockingFailures($preflight['checks'])) {
                    $reporter->markFailed();
                    $this->sessions->clearActiveLock();

                    return response()->json([
                        'installId' => $installId,
                        'currentStep' => $stepKey,
                        'nextStep' => null,
                        'status' => 'failed',
                        'lines' => $this->sessions->lines($installId),
                        'logPath' => $reporter->logPath(),
                        'error' => 'Preflight checks failed.',
                        'remediation' => $this->preflightRemediation($preflight),
                        'preflight' => $preflight,
                        'csrfToken' => csrf_token(),
                    ]);
                }

                $nextStep = InstallPlan::findNextStep($plan, $stepKey);
                $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

                return response()->json([
                    'installId' => $installId,
                    'currentStep' => $stepKey,
                    'nextStep' => $nextStep,
                    'status' => 'running',
                    'lines' => $this->sessions->lines($installId),
                    'logPath' => $reporter->logPath(),
                    'preflight' => $preflight,
                    'csrfToken' => csrf_token(),
                ]);
            }

            if (($stepKey === InstallPlan::STEP_RESOLVE_USER && $this->hasInstalledAdminPackageSelection($inputData))
                || $stepKey === InstallPlan::STEP_INSTALL_PACKAGES
                || InstallPlan::packageNameFromStep($stepKey) === 'capell-app/admin') {
                $this->ensureUserModelSupportsAdminPackage($inputData, $reporter);
            }

            $newUserId = RunInstallStepAction::run($stepKey, $inputData, $reporter, $resolvedUserId);
            if (is_int($newUserId) && $newUserId !== $resolvedUserId) {
                $this->sessions->putResolvedUserId($installId, $newUserId);
            }
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable::class . ': ' . $throwable->getMessage());
            $reporter->error(sprintf('  at %s:%d', $throwable->getFile(), $throwable->getLine()));
            $reporter->markFailed();
            $this->sessions->clearActiveLock();

            return response()->json([
                'installId' => $installId,
                'currentStep' => $stepKey,
                'nextStep' => null,
                'status' => 'failed',
                'lines' => $this->sessions->lines($installId),
                'logPath' => $reporter->logPath(),
                'error' => $throwable->getMessage(),
                'errorClass' => $throwable::class,
                'remediation' => $this->remediationFor($throwable->getMessage()),
                'csrfToken' => csrf_token(),
            ]);
        }

        $nextStep = InstallPlan::findNextStep($plan, $stepKey);
        $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

        if ($nextStep === null) {
            $reporter->markComplete();
            $this->cacheSuccessSummary($installId, $inputData);
            $this->sessions->clearActiveLock();

            return response()->json([
                'installId' => $installId,
                'currentStep' => $stepKey,
                'nextStep' => null,
                'status' => 'complete',
                'lines' => $this->sessions->lines($installId),
                'logPath' => $reporter->logPath(),
                'redirectUrl' => route('capell-installer.success', ['installId' => $installId]),
                'csrfToken' => csrf_token(),
            ]);
        }

        return response()->json([
            'installId' => $installId,
            'currentStep' => $stepKey,
            'nextStep' => $nextStep,
            'status' => 'running',
            'lines' => $this->sessions->lines($installId),
            'logPath' => $reporter->logPath(),
            'csrfToken' => csrf_token(),
        ]);
    }

    public function progress(Request $request, string $installId): View
    {
        abort_unless($this->canAccessInstall($request, $installId) && $this->sessions->hasInstallSessionState($installId), 404);

        $status = $this->sessions->status($installId, 'running');
        /** @var view-string $progressView */
        $progressView = 'capell-installer::progress';

        return view($progressView, [
            'installId' => $installId,
            'installStatus' => $status,
            'reportDownloadFilename' => $this->reportDownloadFilename($installId),
            'reportUrl' => route('capell-installer.progress.download', ['installId' => $installId]),
        ]);
    }

    public function success(Request $request, string $installId): Response
    {
        abort_unless($this->canAccessInstall($request, $installId), 404);

        abort_if($this->sessions->status($installId) !== 'complete'
            || ! $this->sessions->hasSuccessSummary($installId), 404);

        $successSummary = $this->sessions->pullSuccessSummary($installId);
        $this->allowInstallerRemoval($request);

        return response()->view('capell-installer::success', [
            'installId' => $installId,
            'primaryAdmin' => $successSummary['primaryAdmin'] ?? null,
            'roleUsersCreated' => ($successSummary['roleUsersCreated'] ?? false) === true,
            'canRemoveInstaller' => true,
        ])->withHeaders($this->installerSessionHeaders());
    }

    public function progressData(Request $request, string $installId): JsonResponse
    {
        abort_unless($this->canAccessInstall($request, $installId) && $this->sessions->hasInstallSessionState($installId), 404);

        $lines = $this->sessions->lines($installId);
        $status = $this->sessions->status($installId, 'running');

        if (in_array($status, ['complete', 'failed', 'cancelled'], true)) {
            $this->sessions->clearActiveLock();
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $this->sessions->forgetSuccessSummary($installId);
        }

        return response()->json([
            'installId' => $installId,
            'status' => $status,
            'lines' => $lines,
            'redirectUrl' => $status === 'complete'
                ? route('capell-installer.success', ['installId' => $installId])
                : null,
        ]);
    }

    public function destroy(Request $request): Response
    {
        abort_unless($this->canRemoveInstaller($request), 404);

        $request->session()->forget(self::REMOVE_INSTALLER_SESSION_KEY);

        return redirect()->to(RemoveSetupPackageAction::run());
    }

    public function report(Request $request, string $installId): Response
    {
        if (! $this->canAccessInstall($request, $installId) || ! $this->sessions->hasInstallSessionState($installId)) {
            return response()->json(['error' => 'Install report not found.'], 404);
        }

        try {
            $inputArray = $this->sessions->input($installId);
            $inputData = is_array($inputArray) ? InstallInputData::from($inputArray) : null;
            $preflight = $this->sessions->preflightReport($installId);

            if (! is_array($preflight)) {
                $preflight = resolve(InstallerPreflight::class)->run($inputData);
            }

            $status = $this->sessions->status($installId);
            $lines = $this->sessions->lines($installId);

            $payload = [
                'installId' => $installId,
                'status' => $status,
                'environment' => $preflight['environment'] ?? [],
                'preflight' => $preflight,
                'plan' => $this->sessions->plan($installId),
                'selected' => [
                    'packages' => $inputData->packages ?? [],
                    'extraPackages' => $inputData->extraPackages ?? [],
                    'languages' => $inputData->languages ?? [],
                    'seedDefaultData' => $inputData->seedDefaultData ?? null,
                    'demoContent' => $inputData->demoContent ?? null,
                    'generateSitemap' => $inputData->generateSitemap ?? null,
                    'generateStaticSite' => $inputData->generateStaticSite ?? null,
                    'installFilamentPanel' => $inputData->installFilamentPanel ?? null,
                    'integrateAdminPanel' => $inputData->integrateAdminPanel ?? null,
                    'rebuildResources' => $inputData->rebuildResources ?? null,
                    'installDeveloperTooling' => $inputData->installDeveloperTooling ?? null,
                    'configureBoostDeveloperTooling' => $inputData->configureBoostDeveloperTooling ?? null,
                    'additionalUsers' => collect($inputData->additionalUsers ?? [])
                        ->map(fn (NewUserData $user): array => [
                            'name' => $user->name,
                            'email' => $user->email,
                            'roleName' => $user->roleName,
                        ])
                        ->all(),
                ],
                'lines' => $lines,
                'remediations' => $this->remediationsForLines($lines),
            ];
        } catch (Throwable $throwable) {
            return response()->json(['error' => $throwable->getMessage()], 500);
        }

        return response()->json($payload, 200, [
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->reportDownloadFilename($installId)),
        ]);
    }

    public function cancel(Request $request, string $installId): Response
    {
        abort_unless(Str::isUuid($installId), 404);
        abort_unless($this->canAccessInstall($request, $installId), 404);

        $this->sessions->clearActiveLock($installId);

        $this->sessions->clearInstallSession($installId);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'cancelled']);
        }

        return to_route('capell-installer.show');
    }

    private function reportDownloadFilename(string $installId): string
    {
        return sprintf('capell-install-%s.json', $installId);
    }

    private function prepareStepBasedInstall(string $installId, InstallInputData $inputData): JsonResponse
    {
        $plan = InstallPlan::build($inputData);
        $firstStepKey = $plan[0]['key'] ?? null;
        $installStatus = is_string($firstStepKey) ? 'pending' : 'complete';

        $logPath = storage_path(sprintf('logs/capell-install-%s.log', $installId));
        $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));

        if ($this->hasInstalledAdminPackageSelection($inputData)) {
            $this->ensureUserModelSupportsAdminPackage($inputData, $reporter);
        }

        $this->sessions->cancelActiveInstallBeforeStarting($installId);

        $this->sessions->startStepInstallSession(
            installId: $installId,
            inputData: $inputData,
            plan: $plan,
            installStatus: $installStatus,
            firstStepKey: $firstStepKey,
            preflight: resolve(InstallerPreflight::class)->run($inputData),
        );

        return response()->json([
            'installId' => $installId,
            'status' => $installStatus,
            'plan' => $plan,
            'nextStep' => $firstStepKey,
            'progressUrl' => route('capell-installer.progress', ['installId' => $installId]),
            'progressDataUrl' => route('capell-installer.progress.data', ['installId' => $installId]),
            'reportUrl' => route('capell-installer.progress.download', ['installId' => $installId]),
            'successUrl' => route('capell-installer.success', ['installId' => $installId]),
            'runStepUrl' => route('capell-installer.run-step'),
            'cancelUrl' => route('capell-installer.cancel', ['installId' => $installId]),
            'logPath' => $logPath,
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function installerSessionHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    private function grantInstallAccess(Request $request, string $installId): void
    {
        if (! Str::isUuid($installId)) {
            return;
        }

        $request->session()->put($this->installAccessSessionKey($installId), true);
    }

    private function canReplaceActiveInstall(Request $request, string $installId): bool
    {
        $activeInstallId = $this->sessions->activeInstallId();

        if ($activeInstallId === null) {
            return true;
        }

        if ($activeInstallId === $installId) {
            return true;
        }

        return $this->canAccessInstall($request, $activeInstallId);
    }

    private function activeInstallLockedResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Another install is already running in a different browser session.',
            ], 409);
        }

        return back()->withErrors([
            'install' => 'Another install is already running in a different browser session.',
        ]);
    }

    private function canAccessInstall(Request $request, string $installId): bool
    {
        return Str::isUuid($installId)
            && $request->session()->get($this->installAccessSessionKey($installId)) === true;
    }

    private function outOfSequenceStepResponse(
        string $installId,
        string $stepKey,
        string $expectedStepKey,
        FileLogProgressReporter $reporter,
    ): JsonResponse {
        if (in_array($stepKey, $this->sessions->completedSteps($installId), true)) {
            return response()->json([
                'installId' => $installId,
                'currentStep' => $stepKey,
                'nextStep' => $expectedStepKey,
                'status' => 'running',
                'lines' => $this->sessions->lines($installId),
                'logPath' => $reporter->logPath(),
                'csrfToken' => csrf_token(),
            ]);
        }

        return response()->json([
            'installId' => $installId,
            'currentStep' => $stepKey,
            'nextStep' => $expectedStepKey,
            'expectedStep' => $expectedStepKey,
            'status' => 'failed',
            'lines' => $this->sessions->lines($installId),
            'logPath' => $reporter->logPath(),
            'error' => sprintf(
                'Install step "%s" is out of sequence. Expected "%s". Refresh the installer progress page and continue from the current step.',
                $stepKey,
                $expectedStepKey,
            ),
            'csrfToken' => csrf_token(),
        ], 409);
    }

    private function allowInstallerRemoval(Request $request): void
    {
        $request->session()->put(self::REMOVE_INSTALLER_SESSION_KEY, true);
    }

    private function canRemoveInstaller(Request $request): bool
    {
        return $request->session()->get(self::REMOVE_INSTALLER_SESSION_KEY) === true;
    }

    private function installAccessSessionKey(string $installId): string
    {
        return sprintf('capell.install.%s.access', $installId);
    }

    private function cacheSuccessSummary(string $installId, InstallInputData $inputData): void
    {
        $this->sessions->putSuccessSummary($installId, [
            'primaryAdmin' => $this->primaryAdminSummary($inputData),
            'roleUsersCreated' => $inputData->additionalUsers !== [],
        ]);
    }

    private function primaryAdminSummary(InstallInputData $inputData): ?string
    {
        if ($inputData->newUser instanceof NewUserData) {
            return sprintf('%s <%s>', $inputData->newUser->name, $inputData->newUser->email);
        }

        if ($inputData->userId === null || ! $this->options->usersTableExists()) {
            return null;
        }

        try {
            $userModel = $this->options->userModel();
            $user = $userModel::query()->find($inputData->userId, ['id', 'name', 'email']);

            if (! $user instanceof Model) {
                return null;
            }

            return sprintf('%s <%s>', (string) $user->getAttribute('name'), (string) $user->getAttribute('email'));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $preflight */
    private function reportPreflight(array $preflight, ProgressReporter $reporter): void
    {
        foreach (($preflight['checks'] ?? []) as $check) {
            if (! is_array($check)) {
                continue;
            }

            $status = (string) ($check['status'] ?? 'warning');
            $label = (string) ($check['label'] ?? 'Check');
            $message = (string) ($check['message'] ?? '');
            $line = sprintf('%s %s: %s', $this->preflightMarker($status), $label, $message);

            if ($status === 'fail') {
                $reporter->error($line);
                $remediation = (string) ($check['remediation'] ?? '');
                if ($remediation !== '') {
                    $reporter->error('  Fix: ' . $remediation);
                }

                continue;
            }

            $reporter->report($line);
        }
    }

    private function preflightMarker(string $status): string
    {
        return match ($status) {
            'pass' => '✓',
            'fail' => '✗',
            default => '!',
        };
    }

    /** @param array<string, mixed> $preflight */
    private function preflightRemediation(array $preflight): string
    {
        $checks = $preflight['checks'] ?? [];

        if (! is_array($checks)) {
            return '';
        }

        /** @var array<int, array<string, mixed>> $checks */
        return collect($checks)
            ->filter(fn (array $check): bool => ($check['status'] ?? null) === 'fail')
            ->map(fn (array $check): string => (string) ($check['remediation'] ?? 'Review the failed preflight check.'))
            ->filter()
            ->unique()
            ->implode(' ');
    }

    private function remediationFor(string $message): ?string
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'assignrole') => 'Update the application User model to use Spatie Permission HasRoles before installing the admin package.',
            str_contains($message, 'proc_open') => 'Enable proc_open for the web PHP runtime so Composer and Artisan subprocesses can run.',
            str_contains($message, 'git@github.com'), str_contains($message, 'publickey') => 'Composer attempted an SSH GitHub clone. Use HTTPS repository URLs or configure GitHub SSH access for the web user.',
            str_contains($message, 'access denied'), str_contains($message, 'permission denied') => 'Check database credentials and filesystem permissions for the web PHP user.',
            str_contains($message, 'unknown database') => 'Grant CREATE DATABASE permission or create the configured database manually.',
            str_contains($message, 'settings') && str_contains($message, 'base table') => 'Publish and run vendor migrations for spatie/laravel-settings before running Capell settings migrations.',
            default => null,
        };
    }

    private function ensureUserModelSupportsAdminPackage(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        if (! in_array('capell-app/admin', [
            ...$inputData->packages,
            ...$inputData->extraPackages,
        ], true)) {
            return;
        }

        $patch = new UserModelPatch;
        $status = $patch->probe();

        if ($status === PatchStatus::AlreadyApplied) {
            $reporter->report('✓ User model supports Capell admin roles.');

            return;
        }

        if ($status !== PatchStatus::Applicable) {
            throw new RuntimeException(sprintf(
                'The installer could not automatically update app/Models/User.php for Capell admin roles because the user model patch status is "%s". Apply the user model install guide patch, then rerun the installer.',
                $status->value,
            ));
        }

        $reporter->step('Patching user model for Capell admin roles…');
        $patch->apply();
        $reporter->report('✓ User model supports Capell admin roles.');
    }

    private function hasInstalledAdminPackageSelection(InstallInputData $inputData): bool
    {
        return in_array('capell-app/admin', $inputData->packages, true);
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return array<int, string>
     */
    private function remediationsForLines(array $lines): array
    {
        return collect($lines)
            ->map(fn (mixed $line): ?string => is_array($line) ? $this->remediationFor((string) ($line['line'] ?? '')) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function capellIsInstalled(): bool
    {
        return InstallerInstallationState::capellIsInstalled();
    }

    /** @return array<string, mixed> */
    private function validateInput(Request $request): array
    {
        $input = $this->options->withDefaultAdminUserInput($request->all(), (string) $request->input('admin_user_mode', 'create'));
        $packageKeys = CapellCore::getPackages(sortByDependencies: true)->keys()->all();
        $downloadablePackageKeys = collect($this->options->downloadablePackages())
            ->pluck('name')
            ->filter(fn (mixed $packageName): bool => is_string($packageName) && $packageName !== '')
            ->values()
            ->all();
        $userRules = $this->options->adminUserValidationRules((string) ($input['admin_user_mode'] ?? 'create'));

        return Validator::make($input, [
            'site_url' => ['required', 'url'],
            'language' => ['required', 'string', Rule::in(array_merge(array_keys($this->options->languageOptions()), ['__custom']))],
            'custom_language_code' => [
                Rule::requiredIf(($input['language'] ?? null) === '__custom'),
                'nullable',
                'string',
                'regex:/^[a-z]{2,3}$/',
            ],
            'package_selection_mode' => ['nullable', 'string', Rule::in(['core', 'all', 'custom'])],
            'packages' => ['array'],
            'packages.*' => ['string', 'in:' . implode(',', $packageKeys)],
            'theme' => [
                'nullable',
                'string',
                Rule::in(array_keys($this->options->themeNamesForSelection(
                    (array) ($input['packages'] ?? []),
                    (array) ($input['extra_packages'] ?? []),
                ))),
            ],
            'extra_packages' => ['array'],
            'extra_packages.*' => ['string', 'regex:/^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?\/[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$/', Rule::in($downloadablePackageKeys)],
            'install_developer_tooling' => ['nullable', 'boolean'],
            'configure_boost_developer_tooling' => ['nullable', 'boolean'],
            'admin_user_mode' => ['nullable', 'string', Rule::in(['create', 'existing'])],
            'existing_user_id' => $userRules['existing_user_id'],
            'new_user_name' => $userRules['new_user_name'],
            'new_user_email' => $userRules['new_user_email'],
            'new_user_password' => $userRules['new_user_password'],
            'create_role_users' => ['nullable', 'boolean'],
            'role_user_password' => ['nullable', 'string', 'min:8'],
            'demo_content' => ['nullable', 'boolean'],
            'seed_default_data' => ['nullable', 'boolean'],
            'install_filament_panel' => ['nullable', 'boolean'],
            'install_welcome_route' => ['nullable', 'boolean'],
            'admin_panel_changes_mode' => ['nullable', 'string', Rule::in(['auto', 'manual'])],
            'integrate_admin_panel' => ['nullable', 'boolean'],
            'admin_add_colors' => ['nullable', 'boolean'],
            'admin_add_widgets' => ['nullable', 'boolean'],
            'admin_add_navigation' => ['nullable', 'boolean'],
            'generate_sitemap' => ['nullable', 'boolean'],
            'rebuild_resources' => ['nullable', 'boolean'],
            'fresh_install' => ['nullable', 'boolean'],
            'run_as_job' => ['nullable', 'boolean'],
            'install_id' => ['nullable', 'uuid'],
        ], [], [
            'existing_user_id' => 'existing user',
            'new_user_name' => 'name',
            'new_user_email' => 'email',
            'new_user_password' => 'password',
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normaliseLanguageInput(array $validated): array
    {
        if (($validated['language'] ?? null) !== '__custom') {
            return $validated;
        }

        $validated['language'] = $this->options->normaliseLanguageCode((string) $validated['custom_language_code']);

        return $validated;
    }

    private function cacheStoreUnavailableResponse(Request $request): Response
    {
        $message = 'CACHE_STORE=database requires the cache table before the web installer can track progress.';
        $remediation = 'Run php artisan cache:table && php artisan migrate, or temporarily set CACHE_STORE=file or CACHE_STORE=array until migrations have run.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['cache_store' => [$remediation]],
            ], 422);
        }

        return back()->withErrors(['cache_store' => $message . ' ' . $remediation])->withInput();
    }
}
