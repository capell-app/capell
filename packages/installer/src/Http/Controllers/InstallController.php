<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Controllers;

use Capell\Core\Actions\Install\ResolveInstallRecommendationAction;
use Capell\Core\Enums\InstallRecommendationAction;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Hosting\MultiNodeTopologyGuard;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Installer\Actions\AdvanceInstallerRunAction;
use Capell\Installer\Actions\BuildInstallerPageDataAction;
use Capell\Installer\Actions\BuildInstallerRunReportAction;
use Capell\Installer\Actions\CancelInstallerRunAction;
use Capell\Installer\Actions\ReadInstallerRunProgressAction;
use Capell\Installer\Actions\RemoveSetupPackageAction;
use Capell\Installer\Actions\StartInstallerRunAction;
use Capell\Installer\Data\InstallerRunStartData;
use Capell\Installer\Enums\InstallerRunMode;
use Capell\Installer\Http\Requests\RunInstallStepRequest;
use Capell\Installer\Http\Requests\StoreInstallRequest;
use Capell\Installer\Http\Responses\InstallStepResponse;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallerOptions;
use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        private readonly InstallStepResponse $stepResponse,
        private readonly MultiNodeTopologyGuard $topologyGuard,
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
            $viewData['recommendationSelection'] = null;
        }

        return response()
            ->view('capell-installer::install', $viewData)
            ->withHeaders($this->installerSessionHeaders());
    }

    public function store(StoreInstallRequest $request): Response
    {
        $validated = $request->validated();

        $recommendationAction = InstallRecommendationAction::tryFrom((string) ($validated['recommendation_action'] ?? ''));
        $recommendationKey = is_string($validated['recommendation_key'] ?? null)
            ? $validated['recommendation_key']
            : null;

        if ($recommendationAction instanceof InstallRecommendationAction) {
            try {
                $recommendationPackages = ResolveInstallRecommendationAction::run(
                    $recommendationAction,
                    $recommendationKey,
                    array_values(array_filter([
                        ...(array) ($validated['recommendation_packages'] ?? []),
                        ...(array) ($validated['packages'] ?? []),
                        ...(array) ($validated['extra_packages'] ?? []),
                    ], is_string(...))),
                );
                $installedPackages = CapellCore::getPackages()->keys()->all();
                $validated['packages'] = array_values(array_filter(
                    $recommendationPackages,
                    fn (string $package): bool => in_array($package, $installedPackages, true),
                ));
                $validated['extra_packages'] = array_values(array_filter(
                    $recommendationPackages,
                    fn (string $package): bool => ! in_array($package, $installedPackages, true),
                ));
            } catch (Throwable $throwable) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $throwable->getMessage(),
                        'errors' => ['recommendation_key' => [$throwable->getMessage()]],
                    ], 422);
                }

                return back()->withErrors(['recommendation_key' => $throwable->getMessage()])->withInput();
            }
        }

        $inputData = resolve(InstallInputFactory::class)->fromWebInput(
            $this->normalisedInputWithRecommendation($request, $validated),
            allowWelcomeRoute: true,
            defaultPackageNames: $this->options->configuredDefaultPackageNames(),
        );

        $installId = $validated['install_id'] ?? (string) Str::uuid();
        $runAsJob = (bool) ($validated['run_as_job'] ?? false);

        try {
            $this->topologyGuard->assertCacheStoreIsShared('The web installer');
        } catch (RuntimeException $runtimeException) {
            return $this->installerStateUnavailableResponse($request, $runtimeException);
        }

        if (! $this->sessions->cacheStoreIsUsable()) {
            return $this->cacheStoreUnavailableResponse($request);
        }

        if (! $this->canReplaceActiveInstall($request, $installId)) {
            return $this->activeInstallLockedResponse($request);
        }

        $this->grantInstallAccess($request, $installId);

        if ($recommendationAction instanceof InstallRecommendationAction) {
            $this->sessions->putRecommendation($installId, [
                'action' => $recommendationAction->value,
                'key' => $recommendationKey,
                'packages' => array_values(array_unique([
                    ...(array) ($validated['packages'] ?? []),
                    ...(array) ($validated['extra_packages'] ?? []),
                ])),
            ]);
        }

        if ($runAsJob) {
            try {
                $run = StartInstallerRunAction::run($installId, $inputData, InstallerRunMode::Queued);
            } catch (QueueConnectionNotReadyException $exception) {
                return $this->queueConnectionUnavailableResponse($request, $exception);
            } catch (Throwable $throwable) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $throwable->getMessage(),
                        'errors' => ['user_model' => [$throwable->getMessage()]],
                    ], 422);
                }

                return back()->withErrors(['user_model' => $throwable->getMessage()])->withInput();
            }

            if ($request->expectsJson()) {
                return response()->json($this->queuedRunPayload($run));
            }

            return to_route('capell-installer.progress', ['installId' => $installId]);
        }

        if ($request->expectsJson()) {
            try {
                $run = StartInstallerRunAction::run($installId, $inputData, InstallerRunMode::BrowserSteps);

                return response()->json($this->browserStepRunPayload($run));
            } catch (Throwable $throwable) {
                return response()->json([
                    'message' => $throwable->getMessage(),
                    'errors' => ['user_model' => [$throwable->getMessage()]],
                ], 422);
            }
        }

        $run = StartInstallerRunAction::run($installId, $inputData, InstallerRunMode::Synchronous);

        return to_route($run->completed ? 'capell-installer.success' : 'capell-installer.progress', ['installId' => $installId]);
    }

    public function runStep(RunInstallStepRequest $request): JsonResponse
    {
        $installId = (string) $request->validated('install_id');
        $stepKey = (string) $request->validated('step');

        abort_unless($this->canAccessInstall($request, $installId), 404);

        return $this->stepResponse->fromResult(AdvanceInstallerRunAction::run($installId, $stepKey));
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

        $progress = ReadInstallerRunProgressAction::run($installId);

        return response()->json([
            'installId' => $progress->installId,
            'status' => $progress->status,
            'lines' => $progress->lines,
            'redirectUrl' => $progress->shouldRedirectToSuccess
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
            $payload = BuildInstallerRunReportAction::run($installId)->toPayload();
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

        CancelInstallerRunAction::run($installId);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'cancelled']);
        }

        return to_route('capell-installer.show');
    }

    private function reportDownloadFilename(string $installId): string
    {
        return sprintf('capell-install-%s.json', $installId);
    }

    /** @param array<string, mixed> $validated */
    private function normalisedInputWithRecommendation(StoreInstallRequest $request, array $validated): array
    {
        if (! array_key_exists('recommendation_action', $validated)) {
            return $request->normalisedInput();
        }

        $normalised = $request->normalisedInput();
        $normalised['packages'] = $validated['packages'] ?? [];
        $normalised['extra_packages'] = $validated['extra_packages'] ?? [];

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedRunPayload(InstallerRunStartData $run): array
    {
        return [
            'installId' => $run->installId,
            'status' => $run->status,
            'progressUrl' => route('capell-installer.progress', ['installId' => $run->installId]),
            'progressDataUrl' => route('capell-installer.progress.data', ['installId' => $run->installId]),
            'reportUrl' => route('capell-installer.progress.download', ['installId' => $run->installId]),
            'redirectUrl' => route('capell-installer.progress', ['installId' => $run->installId]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function browserStepRunPayload(InstallerRunStartData $run): array
    {
        return [
            'installId' => $run->installId,
            'status' => $run->status,
            'plan' => $run->plan,
            'nextStep' => $run->nextStep,
            'progressUrl' => route('capell-installer.progress', ['installId' => $run->installId]),
            'progressDataUrl' => route('capell-installer.progress.data', ['installId' => $run->installId]),
            'reportUrl' => route('capell-installer.progress.download', ['installId' => $run->installId]),
            'successUrl' => route('capell-installer.success', ['installId' => $run->installId]),
            'runStepUrl' => route('capell-installer.run-step'),
            'cancelUrl' => route('capell-installer.cancel', ['installId' => $run->installId]),
            'logPath' => $run->logPath,
            'csrfToken' => csrf_token(),
        ];
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

    private function capellIsInstalled(): bool
    {
        return InstallerInstallationState::capellIsInstalled();
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

    private function installerStateUnavailableResponse(
        Request $request,
        RuntimeException $exception,
    ): Response {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['cache_store' => [$exception->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['cache_store' => $exception->getMessage()])->withInput();
    }

    private function queueConnectionUnavailableResponse(
        Request $request,
        QueueConnectionNotReadyException $exception,
    ): Response {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['queue_connection' => [$exception->getMessage()]],
            ], 422);
        }

        return back()->withErrors(['queue_connection' => $exception->getMessage()])->withInput();
    }
}
