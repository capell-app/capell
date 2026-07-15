<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Actions\AfterInstallPackageAction;
use Capell\Core\Actions\DemoPackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\SetupPackageAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\PackageDemoLifecycle;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsObject;

final class InstallPackagesAction
{
    use AsObject;

    public function handle(InstallInputData $inputData, ?Authenticatable $user, ProgressReporter $reporter): void
    {
        $availablePackages = CapellCore::getPackages();
        $packageNames = $inputData->packages;

        $selectedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            $availablePackages,
            array_values(array_unique([
                ...$packageNames,
                ...$inputData->extraPackages,
            ])),
            $inputData->freshInstall,
        );

        if ($selectedPackages->isEmpty()) {
            return;
        }

        $reporter->step('Installing packages…');

        $this->buildParams($inputData, $user);

        $selectedPackages->each(function (PackageData $package) use ($inputData, $user, $reporter): void {
            $this->installSelectedPackage($inputData, $user, $package, $reporter);
        });

        if ($inputData->seedDefaultData) {
            $reporter->step('Setting up packages…');

            $selectedPackages
                ->reject(fn (PackageData $package): bool => $this->setupCommandIsCoveredByDemoCommand($inputData, $package))
                ->each(function (PackageData $package) use ($inputData, $user, $reporter): void {
                    $this->setupSelectedPackage($inputData, $user, $package, $reporter);
                });
        }

        if ($inputData->demoContent) {
            $reporter->step('Installing demo content…');

            $selectedPackages->each(function (PackageData $package) use ($inputData, $user, $reporter): void {
                $this->demoSelectedPackage($inputData, $user, $package, $reporter);
            });
        }

        $reporter->step('Running post-install hooks…');

        $selectedPackages->each(function (PackageData $package) use ($inputData, $user, $reporter): void {
            $this->afterInstallSelectedPackage($inputData, $user, $package, $reporter);
        });
    }

    public function installPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        string $packageName,
        ProgressReporter $reporter,
    ): void {
        $package = $this->selectedPackage($inputData, $packageName);

        if (! $package instanceof PackageData) {
            return;
        }

        if (TrustedCorePackages::isCoreRuntimePackage($package->name)) {
            return;
        }

        $this->installSelectedPackage($inputData, $user, $package, $reporter);
    }

    public function setupPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        string $packageName,
        ProgressReporter $reporter,
    ): void {
        $package = $this->selectedPackage($inputData, $packageName);

        if (! $package instanceof PackageData || ! $this->packageHasSetupLifecycle($package)) {
            return;
        }

        if ($this->setupCommandIsCoveredByDemoCommand($inputData, $package)) {
            return;
        }

        $this->setupSelectedPackage($inputData, $user, $package, $reporter);
    }

    public function afterInstallPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        string $packageName,
        ProgressReporter $reporter,
    ): void {
        $package = $this->selectedPackage($inputData, $packageName);

        if (! $package instanceof PackageData || ! $this->packageHasAfterInstallLifecycle($package)) {
            return;
        }

        $this->afterInstallSelectedPackage($inputData, $user, $package, $reporter);
    }

    public function demoPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        string $packageName,
        ProgressReporter $reporter,
    ): void {
        $package = $this->selectedPackage($inputData, $packageName);

        if (! $package instanceof PackageData || $package->getDemoCommand() === null || $package->getDemoCommand() === '') {
            return;
        }

        if (! PackageDemoLifecycle::shouldRunDemo($inputData, $package)) {
            return;
        }

        $this->demoSelectedPackage($inputData, $user, $package, $reporter);
    }

    private function installSelectedPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        PackageData $package,
        ProgressReporter $reporter,
    ): void {
        if (TrustedCorePackages::isCoreRuntimePackage($package->name)) {
            return;
        }

        $reporter->step('Installing ' . $package->name . '…');
        InstallPackageAction::run(
            package: $package,
            arguments: $this->filterParams($package->getInstallParams(), $this->buildParams($inputData, $user)),
            reporter: $reporter,
            freshLifecycleProcess: TrustedCorePackages::isAdminPackage($package->name)
                && $package->getInstallCommand() === 'capell:admin-install',
        );
    }

    private function setupSelectedPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        PackageData $package,
        ProgressReporter $reporter,
    ): void {
        if (! $this->packageHasSetupLifecycle($package)) {
            return;
        }

        $reporter->step('Setting up ' . $package->name . '…');
        SetupPackageAction::run(
            package: $package,
            arguments: $this->filterParams($package->getSetupParams(), $this->buildParams($inputData, $user)),
            reporter: $reporter,
        );
    }

    private function setupCommandIsCoveredByDemoCommand(InstallInputData $inputData, PackageData $package): bool
    {
        return $inputData->demoContent
            && $package->getSetupCommand() !== null
            && $package->getSetupCommand() !== ''
            && $package->getSetupCommand() === $package->getDemoCommand();
    }

    private function afterInstallSelectedPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        PackageData $package,
        ProgressReporter $reporter,
    ): void {
        if (! $this->packageHasAfterInstallLifecycle($package)) {
            return;
        }

        $reporter->step('Post-install ' . $package->name . '…');
        AfterInstallPackageAction::run(
            package: $package,
            arguments: $this->filterParams($package->getAfterInstallParams(), $this->buildParams($inputData, $user)),
            reporter: $reporter,
        );
    }

    private function demoSelectedPackage(
        InstallInputData $inputData,
        ?Authenticatable $user,
        PackageData $package,
        ProgressReporter $reporter,
    ): void {
        if (! PackageDemoLifecycle::shouldRunDemo($inputData, $package)) {
            return;
        }

        $reporter->step('Demo content for ' . $package->name . '…');
        DemoPackageAction::run(
            package: $package,
            arguments: $this->filterParams($package->getDemoParams(), $this->buildParams($inputData, $user)),
            reporter: $reporter,
        );
    }

    private function packageHasSetupLifecycle(PackageData $package): bool
    {
        if ($package->getSetupCommand() !== null && $package->getSetupCommand() !== '') {
            return true;
        }

        return $package->getSetupAction() !== null && $package->getSetupAction() !== '';
    }

    private function packageHasAfterInstallLifecycle(PackageData $package): bool
    {
        if ($package->getAfterInstallCommand() !== null && $package->getAfterInstallCommand() !== '') {
            return true;
        }

        return $package->getAfterInstallAction() !== null && $package->getAfterInstallAction() !== '';
    }

    private function selectedPackage(InstallInputData $inputData, string $packageName): ?PackageData
    {
        return resolve(PackageWorkflowPlanner::class)
            ->expandAndOrder(
                CapellCore::getPackages(),
                array_values(array_unique([
                    ...$inputData->packages,
                    ...$inputData->extraPackages,
                ])),
                $inputData->freshInstall,
            )
            ->get($packageName);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParams(InstallInputData $inputData, ?Authenticatable $user): array
    {
        return [
            'url' => $inputData->siteUrl,
            'languages' => $inputData->demoContent ? $inputData->demoLanguages : $inputData->languages,
            'sites' => $inputData->demoContent ? $inputData->demoSites : [$this->defaultSiteName()],
            'user' => $user?->getAuthIdentifier() !== null ? (string) $user->getAuthIdentifier() : null,
            'assets' => $inputData->assets,
            'theme' => $inputData->selectedThemeKey,
            'skip-panel-integration' => ! $inputData->integrateAdminPanel,
            'panel' => $inputData->adminPanel,
            'schemas' => $inputData->adminDiscoverSchemas === []
                ? 'auto'
                : $this->formatAdminDiscoverSchemas($inputData->adminDiscoverSchemas),
            'no-colors' => ! $inputData->adminAddColors,
            'no-widgets' => ! $inputData->adminAddWidgets,
            'no-navigation' => ! $inputData->adminAddNavigation,
            'skip-permission-sync' => true,
            'force' => true,
        ];
    }

    /**
     * @param  array<int, array{in: string, for: string}>  $schemas
     */
    private function formatAdminDiscoverSchemas(array $schemas): string
    {
        return collect($schemas)
            ->map(fn (array $schema): string => $schema['in'] . '=' . $schema['for'])
            ->implode(',');
    }

    /**
     * @param  array<string>  $allowedParams
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function filterParams(array $allowedParams, array $params): array
    {
        $result = [];

        if (in_array('url', $allowedParams, true) && isset($params['url'])) {
            $result['--url'] = $params['url'];
        }

        if (in_array('languages', $allowedParams, true) && is_array($params['languages'])) {
            $result['--languages'] = $params['languages'];
        }

        if (in_array('sites', $allowedParams, true) && is_array($params['sites'])) {
            $result['--sites'] = $params['sites'];
        }

        if (in_array('user', $allowedParams, true) && $params['user'] !== null) {
            $result['--user'] = $params['user'];
        }

        if (in_array('assets', $allowedParams, true) && is_array($params['assets'])) {
            $result['--assets'] = $params['assets'];
        }

        foreach (['skip-panel-integration', 'no-colors', 'no-widgets', 'no-navigation', 'skip-permission-sync', 'force'] as $flag) {
            if (in_array($flag, $allowedParams, true) && (bool) ($params[$flag] ?? false)) {
                $result['--' . $flag] = true;
            }
        }

        foreach (['panel', 'schemas', 'theme'] as $optionName) {
            if (in_array($optionName, $allowedParams, true) && filled($params[$optionName] ?? null)) {
                $result['--' . $optionName] = $params[$optionName];
            }
        }

        return $result;
    }

    private function defaultSiteName(): string
    {
        $appName = config('app.name');

        if (! is_string($appName)) {
            return 'Capell';
        }

        $appName = trim($appName);

        if ($appName === '' || $appName === 'Laravel') {
            return 'Capell';
        }

        return $appName;
    }
}
