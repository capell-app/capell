<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Data\Install\ThemeInstallOptionData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Composer\InstalledVersions;
use Illuminate\Support\Collection;
use Throwable;

final class ThemePackageCandidates
{
    public const string NONE_KEY = 'none';

    public const string DEFAULT_KEY = 'default';

    public const string FOUNDATION_KEY = self::DEFAULT_KEY;

    public const string LEGACY_FOUNDATION_KEY = 'foundation';

    private const string FOUNDATION_PACKAGE = 'capell-app/foundation-theme';

    /** @var array<string, array{name: string, description: string, package: string|null, preview: string|null}> */
    private const array STATIC_OPTIONS = [
        self::NONE_KEY => [
            'name' => 'No theme',
            'description' => 'Install Capell without a starter theme.',
            'package' => null,
            'preview' => null,
        ],
        self::DEFAULT_KEY => [
            'name' => 'Default',
            'description' => 'Built-in starter theme provided by Capell Frontend.',
            'package' => null,
            'preview' => null,
        ],
        'corporate' => [
            'name' => 'Corporate',
            'description' => 'Editorial business theme for company and service websites.',
            'package' => 'capell-app/theme-corporate',
            'preview' => null,
        ],
        'saas' => [
            'name' => 'SaaS',
            'description' => 'Product-focused theme for software and subscription sites.',
            'package' => 'capell-app/theme-saas',
            'preview' => null,
        ],
    ];

    public function __construct(
        private readonly PackageWorkflowPlanner $planner,
        private readonly ?LocalAppThemeDefinitionRepository $localAppThemes = null,
    ) {}

    /**
     * @param  array<int, string>  $selectedPackageNames
     * @param  array<int, string>  $extraPackageNames
     * @return array<string, string>
     */
    public function optionsForSelection(array $selectedPackageNames, array $extraPackageNames = []): array
    {
        return collect($this->optionDataForSelection($selectedPackageNames, $extraPackageNames))
            ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [$option->key => $option->name])
            ->all();
    }

    /**
     * @param  array<int, string>  $selectedPackageNames
     * @param  array<int, string>  $extraPackageNames
     * @return array<string, ThemeInstallOptionData>
     */
    public function optionDataForSelection(array $selectedPackageNames, array $extraPackageNames = []): array
    {
        $registeredPackages = CapellCore::getPackages(sortByDependencies: true);
        $selectedPackages = $this->planner->expandAndOrder($registeredPackages, $selectedPackageNames);

        $packages = $registeredPackages
            ->filter(fn (PackageData $package): bool => $package->isInstalled())
            ->merge($selectedPackages)
            ->merge($this->downloadablePackages()->only($extraPackageNames));

        return array_replace(
            $this->staticOptions(),
            $this->localAppThemeOptions(),
            $this->optionDataFromPackages($packages),
        );
    }

    /** @return array<string, string> */
    public function optionsForInstalledPackages(): array
    {
        return collect($this->optionDataForInstalledPackages())
            ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [$option->key => $option->name])
            ->all();
    }

    /** @return array<string, ThemeInstallOptionData> */
    public function optionDataForInstalledPackages(): array
    {
        return array_replace(
            $this->staticOptions(),
            $this->localAppThemeOptions(),
            $this->optionDataFromPackages(
                CapellCore::getPackages(sortByDependencies: true)
                    ->filter(fn (PackageData $package): bool => $package->isInstalled()),
            ),
        );
    }

    /** @return array<string, ThemeInstallOptionData> */
    public function optionDataForCatalogue(): array
    {
        return array_replace(
            $this->staticOptions(),
            $this->optionDataFromPackages($this->downloadablePackages()),
            $this->localAppThemeOptions(),
            $this->localThemeOptions(),
            $this->optionDataFromPackages(
                CapellCore::getPackages(sortByDependencies: true)
                    ->filter(fn (PackageData $package): bool => $package->getThemeKey() !== null),
            ),
        );
    }

    public function defaultThemeKeyForCatalogue(): string
    {
        $options = $this->optionDataForCatalogue();

        foreach (array_keys($this->localAppThemeOptions()) as $themeKey) {
            if (array_key_exists($themeKey, $options)) {
                return $themeKey;
            }
        }

        // `array_key_first` cannot return null here: `optionDataForCatalogue()`
        // always merges in `staticOptions()`, which seeds both NONE_KEY and
        // DEFAULT_KEY. In practice the DEFAULT_KEY branch above always wins, so
        // the fallback only exists for type-completeness — `$options` is never empty.
        return array_key_exists(self::DEFAULT_KEY, $options)
            ? self::DEFAULT_KEY
            : (string) array_key_first($options);
    }

    /**
     * @param  array<int, string>  $selectedPackageNames
     * @param  array<int, string>  $extraPackageNames
     */
    public function containsThemeKey(string $themeKey, array $selectedPackageNames = [], array $extraPackageNames = []): bool
    {
        return array_key_exists($this->normaliseInputThemeKey($themeKey), $this->optionDataForSelection($selectedPackageNames, $extraPackageNames));
    }

    public function packageNameForThemeKey(?string $themeKey): ?string
    {
        if ($themeKey === null || $themeKey === self::NONE_KEY) {
            return null;
        }

        return $this->optionDataForCatalogue()[$this->normaliseInputThemeKey($themeKey)]->packageName ?? null;
    }

    public function inputThemeKey(?string $themeKey): ?string
    {
        return match ($themeKey) {
            self::NONE_KEY => null,
            self::LEGACY_FOUNDATION_KEY => self::DEFAULT_KEY,
            default => $themeKey === null ? null : $this->normaliseInputThemeKey($themeKey),
        };
    }

    private function normaliseInputThemeKey(string $themeKey): string
    {
        return $themeKey === self::LEGACY_FOUNDATION_KEY ? self::DEFAULT_KEY : $themeKey;
    }

    /** @return array<string, ThemeInstallOptionData> */
    private function staticOptions(): array
    {
        return collect(self::STATIC_OPTIONS)
            ->mapWithKeys(fn (array $option, string $key): array => [
                $key => new ThemeInstallOptionData(
                    key: $key,
                    name: $option['name'],
                    description: $option['description'],
                    packageName: $option['package'],
                    previewImageUrl: $option['preview'],
                    static: true,
                ),
            ])
            ->all();
    }

    /**
     * @param  Collection<string, PackageData>  $packages
     * @return array<string, ThemeInstallOptionData>
     */
    private function optionDataFromPackages(Collection $packages): array
    {
        return $packages
            ->filter(fn (PackageData $package): bool => $package->getThemeKey() !== null)
            ->reject(fn (PackageData $package): bool => $package->name === self::FOUNDATION_PACKAGE)
            ->mapWithKeys(fn (PackageData $package): array => [
                (string) $package->getThemeKey() => new ThemeInstallOptionData(
                    key: (string) $package->getThemeKey(),
                    name: $package->getLabel(),
                    description: $package->getDescription(),
                    packageName: $package->name,
                    previewImageUrl: $package->getPreviewImageUrl(),
                ),
            ])
            ->all();
    }

    /** @return Collection<string, PackageData> */
    private function downloadablePackages(): Collection
    {
        try {
            return GetPluginsAction::run('download')
                ->filter(fn (PackageData $package): bool => $package->getThemeKey() !== null);
        } catch (Throwable) {
            return collect();
        }
    }

    /** @return array<string, ThemeInstallOptionData> */
    private function localAppThemeOptions(): array
    {
        $localAppThemes = $this->localAppThemes ?? (app()->bound(LocalAppThemeDefinitionRepository::class)
            ? resolve(LocalAppThemeDefinitionRepository::class)
            : null);

        if (! $localAppThemes instanceof LocalAppThemeDefinitionRepository) {
            return [];
        }

        return collect($localAppThemes->all())
            ->mapWithKeys(fn (ThemeDefinitionData $definition): array => [
                $definition->key => new ThemeInstallOptionData(
                    key: $definition->key,
                    name: $definition->name,
                    description: $definition->description,
                    packageName: null,
                    previewImageUrl: $definition->previewImage,
                ),
            ])
            ->all();
    }

    /** @return array<string, ThemeInstallOptionData> */
    private function localThemeOptions(): array
    {
        return collect($this->localPackageDirectories())
            ->map(fn (string $directory): ?ThemeInstallOptionData => $this->themeOptionFromLocalDirectory($directory))
            ->filter()
            ->mapWithKeys(fn (ThemeInstallOptionData $option): array => [$option->key => $option])
            ->all();
    }

    /** @return array<int, string> */
    private function localPackageDirectories(): array
    {
        $rootPackage = InstalledVersions::getRootPackage();
        $rootInstallPath = InstalledVersions::getInstallPath($rootPackage['name']);

        if ($rootInstallPath === null) {
            return [];
        }

        $rootRealPath = realpath($rootInstallPath);

        if ($rootRealPath === false) {
            return [];
        }

        $composerJson = $this->readJsonFile($rootRealPath . '/composer.json');
        $repositories = $composerJson['repositories'] ?? [];

        if (! is_array($repositories)) {
            return [];
        }

        return collect($repositories)
            ->filter(fn (mixed $repository): bool => is_array($repository) && ($repository['type'] ?? null) === 'path')
            ->flatMap(fn (array $repository): array => $this->directoriesForPathRepository($repository, $rootRealPath))
            ->map(fn (string $directory): string => dirname($directory))
            ->unique()
            ->flatMap(fn (string $parent): array => glob($parent . '/*', GLOB_ONLYDIR) ?: [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $repository
     * @return array<int, string>
     */
    private function directoriesForPathRepository(array $repository, string $rootRealPath): array
    {
        $url = (string) ($repository['url'] ?? '');

        if ($url === '') {
            return [];
        }

        if (str_starts_with($url, './')) {
            $url = substr($url, 2);
        }

        $pattern = str_starts_with($url, '/') ? $url : $rootRealPath . '/' . $url;

        return glob($pattern, GLOB_ONLYDIR) ?: [];
    }

    private function themeOptionFromLocalDirectory(string $directory): ?ThemeInstallOptionData
    {
        $manifest = $this->readJsonFile(rtrim($directory, '/') . '/capell.json');
        $composerJson = $this->readJsonFile(rtrim($directory, '/') . '/composer.json');

        if (($manifest['manifest-version'] ?? null) !== 3 || ($manifest['kind'] ?? null) !== 'theme') {
            return null;
        }

        $packageName = is_string($manifest['name'] ?? null)
            ? $manifest['name']
            : (is_string($composerJson['name'] ?? null) ? $composerJson['name'] : null);
        $themeKey = is_string($manifest['themeKey'] ?? null) ? $manifest['themeKey'] : null;

        if ($packageName === null || $themeKey === null || $themeKey === '') {
            return null;
        }

        if ($packageName === self::FOUNDATION_PACKAGE) {
            return null;
        }

        return new ThemeInstallOptionData(
            key: $themeKey,
            name: is_string($manifest['displayName'] ?? null) ? $manifest['displayName'] : $packageName,
            description: is_string($manifest['description'] ?? null) ? $manifest['description'] : null,
            packageName: $packageName,
            previewImageUrl: $this->previewImageUrl($manifest),
        );
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function previewImageUrl(array $manifest): ?string
    {
        $screenshots = $manifest['marketplace']['screenshots'] ?? null;

        if (! is_array($screenshots)) {
            return null;
        }

        $firstScreenshot = $screenshots[0] ?? null;

        if (! is_array($firstScreenshot)) {
            return null;
        }

        return is_string($firstScreenshot['url'] ?? null)
            ? $firstScreenshot['url']
            : (is_string($firstScreenshot['path'] ?? null) ? $firstScreenshot['path'] : null);
    }
}
