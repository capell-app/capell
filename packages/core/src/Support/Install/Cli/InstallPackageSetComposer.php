<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install\Cli;

use Capell\Core\Data\Install\ThemeInstallOptionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Closure;
use Illuminate\Support\Collection;

use function Laravel\Prompts\select;

use Symfony\Component\Console\Command\Command as CommandAlias;

final class InstallPackageSetComposer
{
    public function __construct(
        private readonly PackageWorkflowPlanner $packageWorkflowPlanner,
        private readonly ThemePackageCandidates $themePackageCandidates,
    ) {}

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    public function includeDemoPackages(Collection $packages, bool $includeInstalledRequirements): Collection
    {
        $demoPackageNames = CapellCore::getPackages(sortByDependencies: true)
            ->filter(fn (PackageData $package): bool => $package->isDemo())
            ->keys();

        if ($demoPackageNames->isEmpty()) {
            return $packages;
        }

        $withDemoPackages = $packages->keys()
            ->merge($demoPackageNames)
            ->unique()
            ->values()
            ->all();

        if ($packages->keys()->all() === $withDemoPackages) {
            return $packages;
        }

        return $this->packageWorkflowPlanner->expandAndOrder(
            CapellCore::getPackages(sortByDependencies: true),
            $withDemoPackages,
            $includeInstalledRequirements,
        );
    }

    public function shouldIncludeDemoPackagesAfterSelection(
        bool $interactive,
        mixed $packagesOption,
        mixed $packageModeOption,
        bool $allPackages,
        bool $useFreshDemoPackageDefaults,
    ): bool {
        if (! $interactive) {
            return true;
        }

        if ($packagesOption !== null) {
            return true;
        }

        if ($packageModeOption !== null) {
            return true;
        }

        if ($allPackages) {
            return true;
        }

        return $useFreshDemoPackageDefaults;
    }

    /**
     * @param  array<int, string>  $selectedPackageNames
     * @return array<int, string>
     */
    public function installTimePackageNames(
        array $selectedPackageNames,
        mixed $packageMode,
        bool $allPackages,
        bool $useFreshDemoPackageDefaults,
    ): array {
        $packageNames = collect($selectedPackageNames);

        if ($packageMode === 'all' || $allPackages || $useFreshDemoPackageDefaults) {
            $packageNames = $packageNames->merge(TrustedCorePackages::defaultInstallSelectionNames());
        }

        return $packageNames
            ->filter(fn (string $packageName): bool => TrustedCorePackages::contains($packageName))
            ->reject(fn (string $packageName): bool => CapellCore::hasPackage($packageName))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Closure(string): void  $writeError
     * @return array{?string, ?int}
     */
    public function resolveThemeSelection(
        mixed $themeOption,
        bool $interactive,
        bool $useFreshDemoDefaults,
        Closure $writeError,
    ): array {
        $themeCandidates = $this->themeCandidates();

        if (is_string($themeOption) && $themeOption !== '') {
            $normalisedThemeOption = $this->themePackageCandidates->inputThemeKey($themeOption);
            $themeCandidateKey = $normalisedThemeOption ?? $themeOption;

            if (! array_key_exists($themeCandidateKey, $themeCandidates)) {
                $writeError(sprintf(
                    'Unknown theme [%s]. Available themes: %s.',
                    $themeOption,
                    $this->formatThemeCandidatesForConsole($themeCandidates),
                ));

                return [null, CommandAlias::FAILURE];
            }

            return [$normalisedThemeOption, null];
        }

        $defaultThemeKey = $this->themePackageCandidates->defaultThemeKeyForCatalogue();

        if ($interactive && ! $useFreshDemoDefaults) {
            return [
                (string) select(
                    label: 'Which starter theme should be installed?',
                    options: $themeCandidates,
                    default: $defaultThemeKey,
                ),
                null,
            ];
        }

        return [$defaultThemeKey, null];
    }

    /** @return array<string, string> */
    public function themeCandidates(): array
    {
        return collect($this->themePackageCandidates->optionDataForCatalogue())
            ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [$option->key => $option->consoleLabel()])
            ->all();
    }

    /**
     * @param  array<string, string>  $themeCandidates
     */
    public function formatThemeCandidatesForConsole(array $themeCandidates): string
    {
        return collect($themeCandidates)
            ->map(fn (string $label, string $themeKey): string => sprintf('%s (%s)', $themeKey, $label))
            ->implode(', ');
    }

    /**
     * @param  Collection<string, PackageData>  $selectedPackages
     * @return array{Collection<string, PackageData>, array<int, string>}
     */
    public function includeSelectedThemePackage(
        Collection $selectedPackages,
        ?string $selectedThemeKey,
        bool $includeInstalledRequirements,
    ): array {
        if ($selectedThemeKey === null || $selectedThemeKey === ThemePackageCandidates::NONE_KEY) {
            return [$selectedPackages, []];
        }

        $packageName = $this->themePackageCandidates
            ->optionDataForCatalogue()[$selectedThemeKey]
            ->packageName ?? null;

        if ($packageName === null || $selectedPackages->has($packageName)) {
            return [$selectedPackages, []];
        }

        if (! CapellCore::hasPackage($packageName)) {
            return [$selectedPackages, [$packageName]];
        }

        $packageNames = $selectedPackages->keys()
            ->push($packageName)
            ->unique()
            ->values()
            ->all();

        return [
            $this->packageWorkflowPlanner->expandAndOrder(
                CapellCore::getPackages(sortByDependencies: true),
                $packageNames,
                $includeInstalledRequirements,
            ),
            [],
        ];
    }
}
