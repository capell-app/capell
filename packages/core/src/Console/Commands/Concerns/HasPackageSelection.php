<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

use function Laravel\Prompts\multiselect;

/**
 * @mixin Command
 */
trait HasPackageSelection
{
    /** @var 'core' */
    private const PACKAGE_MODE_CORE = 'core';

    /** @var 'all' */
    private const PACKAGE_MODE_ALL = 'all';

    /** @var 'custom' */
    private const PACKAGE_MODE_CUSTOM = 'custom';

    protected function shouldInstallAllPackages(): bool
    {
        return false;
    }

    protected function supportsPackageSelectionMode(): bool
    {
        return false;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return array<int, string>
     */
    protected function installableExtraPackageNames(Collection $packages): array
    {
        return [];
    }

    /**
     * Get selected packages from option or auto-select all.
     *
     * @return Collection<string, PackageData>
     */
    private function getSelectedPackages(): Collection
    {
        $availablePackages = CapellCore::getPackages(sortByDependencies: true);

        $packageMode = $this->resolvePackageSelectionMode();
        $this->logPackageSelectionDebug('resolved package selection mode', [
            'mode' => $packageMode,
            'available_packages' => $availablePackages->keys()->values()->all(),
        ]);

        /** @var mixed $packages */
        $packages = $this->option('packages');

        if ($packages !== null && $packages !== false) {
            if (is_string($packages)) {
                $packageOptions = explode(',', $packages);
            } elseif (is_array($packages)) {
                $packageOptions = collect($packages);
            } elseif ($packages instanceof Collection) {
                $packageOptions = $packages->keys();
            } else {
                throw new InvalidArgumentException('The --packages option must be a string, array or collection.');
            }
        } elseif ($packageMode === self::PACKAGE_MODE_ALL) {
            $packageOptions = $availablePackages
                ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue())
                ->keys();
        } elseif ($packageMode === self::PACKAGE_MODE_CORE) {
            $packageOptions = $this->corePromptPackages($availablePackages)->keys();
        } else {
            $this->requireInteractiveOrFail('Package selection', 'Pass --packages=<package-name,package-name>.');

            $packageOptions = $this->promptForPackages($availablePackages);
        }

        if (! ($packageOptions instanceof Collection)) {
            $packageOptions = collect($packageOptions)
                ->map(fn (string $packageName): string => trim($packageName))
                ->filter(fn (string $packageName): bool => $packageName !== '');
        }

        $selectedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            $availablePackages,
            $packageOptions->values()->all(),
            $this->shouldIncludeInstalledPackageRequirements(),
        );

        $this->logPackageSelectionDebug('expanded selected packages', [
            'requested_packages' => $packageOptions->values()->all(),
            'selected_packages' => $selectedPackages->keys()->values()->all(),
            'include_installed_requirements' => $this->shouldIncludeInstalledPackageRequirements(),
        ]);

        $this->validateSelectedPackageRequirements($selectedPackages);

        return $selectedPackages;
    }

    private function shouldIncludeInstalledPackageRequirements(): bool
    {
        if (! $this->input->hasOption('fresh')) {
            return false;
        }

        $freshOption = $this->input->getOption('fresh');

        if (($freshOption === null || $freshOption === false) && $this->input->hasParameterOption('--fresh')) {
            return true;
        }

        return in_array($freshOption, [true, '', '1', 'true', 'force'], true);
    }

    private function resolvePackageSelectionMode(): string
    {
        if (! $this->supportsPackageSelectionMode() || ! $this->input->hasOption('package-mode')) {
            return self::PACKAGE_MODE_CUSTOM;
        }

        /** @var mixed $packageMode */
        $packageMode = $this->input->getOption('package-mode');

        if ($packageMode !== null && $packageMode !== false) {
            $packageMode = trim((string) $packageMode);

            throw_unless(in_array($packageMode, [
                self::PACKAGE_MODE_CORE,
                self::PACKAGE_MODE_ALL,
                self::PACKAGE_MODE_CUSTOM,
            ], true), InvalidArgumentException::class, 'The --package-mode option must be core, all, or custom.');

            return $packageMode;
        }

        if ($this->shouldInstallAllPackages()) {
            return self::PACKAGE_MODE_ALL;
        }

        /** @var mixed $packages */
        $packages = $this->option('packages');

        if ($packages !== null && $packages !== false) {
            return self::PACKAGE_MODE_CUSTOM;
        }

        if (! $this->input->isInteractive()) {
            return self::PACKAGE_MODE_CORE;
        }

        return self::PACKAGE_MODE_CUSTOM;
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return string[]
     */
    private function promptForPackages(Collection $packages): array
    {
        $selectablePackages = $this->packageSelectionPromptPackages($packages);

        if ($selectablePackages->isEmpty()) {
            return [];
        }

        $corePackages = $this->corePromptPackages($selectablePackages);
        $extraPackages = $this->extraPromptPackages($selectablePackages);

        $selectedPackageNames = [];

        if ($corePackages->isNotEmpty()) {
            $this->logPackageSelectionDebug('prompting for core packages', [
                'options' => $corePackages->keys()->values()->all(),
                'default' => $corePackages->keys()->values()->all(),
            ]);

            $selectedPackageNames = multiselect(
                label: 'What core Capell packages should be installed?',
                options: $this->packagePromptOptions($corePackages),
                default: $corePackages->keys()->all(),
            );
        }

        if ($extraPackages->isNotEmpty()) {
            $extraPackageDefaults = $this->installableExtraPackageNames($extraPackages);

            $this->logPackageSelectionDebug('prompting for extra packages', [
                'options' => $extraPackages->keys()->values()->all(),
                'default' => $extraPackageDefaults,
            ]);

            $selectedPackageNames = [
                ...$selectedPackageNames,
                ...multiselect(
                    label: 'Would you like to install any extra extensions?',
                    options: $this->packagePromptOptions($extraPackages),
                    default: $extraPackageDefaults,
                    hint: $this->extraPackagePromptHint(),
                ),
            ];
        }

        $this->logPackageSelectionDebug('interactive package prompt completed', [
            'selected_packages' => array_values(array_unique($selectedPackageNames)),
        ]);

        return array_values(array_unique(array_map(
            static fn (int|string $packageName): string => (string) $packageName,
            $selectedPackageNames,
        )));
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    private function corePromptPackages(Collection $packages): Collection
    {
        return $packages->filter(
            fn (PackageData $package): bool => TrustedCorePackages::isDefaultInstallSelection($package->name),
        );
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    private function extraPromptPackages(Collection $packages): Collection
    {
        return $packages->reject(
            fn (PackageData $package): bool => TrustedCorePackages::isDefaultInstallSelection($package->name),
        );
    }

    private function extraPackagePromptHint(): string
    {
        return 'Use space to select options, Ctrl+A to select or unselect all.';
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return Collection<string, PackageData>
     */
    private function packageSelectionPromptPackages(Collection $packages): Collection
    {
        return $packages
            ->filter(fn (PackageData $package): bool => $package->isVisibleInCatalogue())
            ->reject(fn (PackageData $package): bool => $package->getThemeKey() !== null)
            ->reject(fn (PackageData $package): bool => in_array($package->name, [
                'capell-app/capell',
                'capell-app/core',
                'capell-app/installer',
            ], true));
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     */
    private function validateSelectedPackageRequirements(Collection $packages): void
    {
        $selectedPackageNames = $packages->keys();

        $missingRequirements = $packages
            ->mapWithKeys(function (PackageData $package) use ($selectedPackageNames): array {
                $missingPackageNames = collect($package->getRequirements())
                    ->reject(fn (string $packageName): bool => TrustedCorePackages::contains($packageName))
                    ->reject(fn (string $packageName): bool => $selectedPackageNames->contains($packageName))
                    ->reject(fn (string $packageName): bool => CapellCore::isPackageInstalled($packageName))
                    ->values()
                    ->all();

                return $missingPackageNames === []
                    ? []
                    : [$package->name => $missingPackageNames];
            });

        if ($missingRequirements->isEmpty()) {
            return;
        }

        $details = $missingRequirements
            ->map(fn (array $packageNames, string $packageName): string => sprintf(
                '%s requires %s',
                $packageName,
                implode(', ', $packageNames),
            ))
            ->implode('; ');

        throw new InvalidArgumentException('Selected packages have missing requirements: ' . $details . '.');
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return array<string, string>
     */
    private function packagePromptOptions(Collection $packages): array
    {
        return $packages
            ->mapWithKeys(fn (PackageData $package): array => [$package->name => $package->getLabel()])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logPackageSelectionDebug(string $event, array $context = []): void
    {
        if (config('capell.install.debug') !== true
            && config('capell.install.debug_package_selection') !== true) {
            return;
        }

        Log::debug('capell.install.package-selection: ' . $event, [
            ...$context,
            'interactive' => $this->input->isInteractive(),
            'package_mode_option' => $this->input->hasOption('package-mode')
                ? $this->input->getOption('package-mode')
                : null,
            'packages_option' => $this->input->hasOption('packages')
                ? $this->input->getOption('packages')
                : null,
            'all_packages_option' => $this->input->hasOption('all-packages')
                ? $this->input->getOption('all-packages')
                : null,
            'demo_option' => $this->input->hasOption('demo')
                ? $this->input->getOption('demo')
                : null,
            'fresh_option' => $this->input->hasOption('fresh')
                ? $this->input->getOption('fresh')
                : null,
        ]);
    }
}
