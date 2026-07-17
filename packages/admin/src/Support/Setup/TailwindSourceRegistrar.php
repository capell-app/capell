<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Setup;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use OutOfBoundsException;
use ReflectionClass;

final class TailwindSourceRegistrar
{
    /**
     * @param  Closure(string): void  $writeLine
     */
    public function register(Closure $writeLine): void
    {
        $themeCss = resource_path('css/filament/admin/theme.css');

        if (! File::exists($themeCss)) {
            return;
        }

        $contents = $this->ensureTailwindFourThemeCssCompatibility($themeCss, File::get($themeCss), $writeLine);
        $sources = [
            ...$this->installedPackageTailwindSources($themeCss),
            ...$this->firstPartyProviderTailwindSources($themeCss),
        ];
        $sources[] = "@source '../../../../storage/capell/tailwind-classes.txt';";

        $missingSources = array_values(array_filter(
            array_unique($sources),
            fn (string $source): bool => ! str_contains($contents, $source),
        ));

        if ($missingSources === []) {
            return;
        }

        File::append($themeCss, PHP_EOL . implode(PHP_EOL, $missingSources) . PHP_EOL);

        $writeLine('Added Capell Tailwind sources to theme.css');
    }

    /**
     * @param  Closure(string): void  $writeLine
     */
    private function ensureTailwindFourThemeCssCompatibility(string $themeCss, string $contents, Closure $writeLine): string
    {
        $updatedContents = $contents;

        if (! str_contains($updatedContents, "@import 'tailwindcss';")
            && ! str_contains($updatedContents, '@import "tailwindcss";')
            && str_contains($updatedContents, '@tailwind base;')
        ) {
            $updatedContents = preg_replace(
                '/(?:@tailwind\s+(?:base|components|utilities|variants);\s*)+/m',
                "@import 'tailwindcss';\n",
                $updatedContents,
                1,
            ) ?? $updatedContents;
        }

        $updatedContents = str_replace(
            ["@config 'tailwind.config.js';", '@config "tailwind.config.js";'],
            ["@config './tailwind.config.js';", '@config "./tailwind.config.js";'],
            $updatedContents,
        );

        if ($updatedContents === $contents) {
            return $contents;
        }

        File::put($themeCss, $updatedContents);
        $writeLine('Updated theme.css for Tailwind 4 compatibility');

        return $updatedContents;
    }

    /** @return array<int, string> */
    private function installedPackageTailwindSources(string $themeCss): array
    {
        return collect($this->installedCapellPackagePaths())
            ->sortKeys()
            ->values()
            ->map(fn (string $packagePath): ?string => $this->tailwindSourceForPackagePath($packagePath, $themeCss))
            ->filter()
            ->all();
    }

    /** @return array<string, string> */
    private function installedCapellPackagePaths(): array
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            if (! str_starts_with($packageName, 'capell-app/')) {
                continue;
            }

            $installPath = InstalledVersions::getInstallPath($packageName);

            if ($installPath !== null) {
                $packages[$packageName] = rtrim($installPath, DIRECTORY_SEPARATOR);
            }
        }

        return [
            ...$packages,
            ...$this->pathRepositoryCapellPackagePaths(),
            ...$this->monorepoCapellPackagePaths(),
            ...$this->registeredCapellPackagePaths(),
            ...$this->firstPartyProviderCapellPackagePaths(),
            ...$this->sourceTreeCapellPackagePaths(),
        ];
    }

    /** @return array<string, string> */
    private function registeredCapellPackagePaths(): array
    {
        return CapellCore::getPackages(withoutCore: false)
            ->filter(
                fn (PackageData $package): bool => str_starts_with($package->name, 'capell-app/')
                    && is_string($package->path)
                    && $package->path !== '',
            )
            ->mapWithKeys(
                fn (PackageData $package): array => [
                    $package->name => rtrim((string) $package->path, DIRECTORY_SEPARATOR),
                ],
            )
            ->all();
    }

    /** @return array<string, string> */
    private function firstPartyProviderCapellPackagePaths(): array
    {
        /** @var list<class-string> $providerClasses */
        $providerClasses = [AdminServiceProvider::class, CapellServiceProvider::class];

        return collect($providerClasses)
            ->mapWithKeys(function (string $serviceProviderClass): array {
                $packagePath = $this->packagePathFromClass($serviceProviderClass);

                if ($packagePath === null) {
                    return [];
                }

                $composerJson = $this->readComposerJson($packagePath . DIRECTORY_SEPARATOR . 'composer.json');
                $packageName = (string) ($composerJson['name'] ?? '');

                if (! str_starts_with($packageName, 'capell-app/')) {
                    return [];
                }

                return [$packageName => $packagePath];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function firstPartyProviderTailwindSources(string $themeCss): array
    {
        /** @var list<class-string> $providerClasses */
        $providerClasses = [AdminServiceProvider::class, CapellServiceProvider::class];

        return collect($providerClasses)
            ->map(fn (string $serviceProviderClass): ?string => $this->packagePathFromClass($serviceProviderClass))
            ->filter()
            ->map(fn (string $packagePath): ?string => $this->tailwindSourceForPackagePath($packagePath, $themeCss, requireViews: false))
            ->filter(fn (?string $source): bool => $source !== null)
            ->values()
            ->all();
    }

    /** @param class-string $class */
    private function packagePathFromClass(string $class): ?string
    {
        $fileName = new ReflectionClass($class)->getFileName();

        if (! is_string($fileName)) {
            return null;
        }

        $path = dirname($fileName);

        while (dirname($path) !== $path) {
            if (File::exists($path . DIRECTORY_SEPARATOR . 'composer.json')) {
                return rtrim($path, DIRECTORY_SEPARATOR);
            }

            $path = dirname($path);
        }

        return null;
    }

    /** @return array<string, string> */
    private function sourceTreeCapellPackagePaths(): array
    {
        return collect($this->sourceTreePackageRootCandidates())
            ->flatMap(fn (string $packagesPath): array => collect(['admin', 'core', 'frontend'])
                ->mapWithKeys(function (string $packageDirectory) use ($packagesPath): array {
                    $packagePath = $packagesPath . DIRECTORY_SEPARATOR . $packageDirectory;
                    $composerJson = $this->readComposerJson($packagePath . DIRECTORY_SEPARATOR . 'composer.json');
                    $packageName = (string) ($composerJson['name'] ?? '');

                    if (! str_starts_with($packageName, 'capell-app/')) {
                        return [];
                    }

                    return [$packageName => rtrim($packagePath, DIRECTORY_SEPARATOR)];
                })
                ->all())
            ->all();
    }

    /** @return array<int, string> */
    private function sourceTreePackageRootCandidates(): array
    {
        $paths = [];
        $path = __DIR__;

        while (dirname($path) !== $path) {
            $packageRoot = $path . DIRECTORY_SEPARATOR . 'packages';

            if (File::isDirectory($packageRoot)) {
                $realPackageRoot = realpath($packageRoot);
                $paths[] = $realPackageRoot !== false ? $realPackageRoot : $packageRoot;
            }

            if (File::isDirectory($path . DIRECTORY_SEPARATOR . 'admin')
                && File::isDirectory($path . DIRECTORY_SEPARATOR . 'core')
            ) {
                $realPath = realpath($path);
                $paths[] = $realPath !== false ? $realPath : $path;
            }

            $path = dirname($path);
        }

        return array_values(array_unique($paths));
    }

    private function tailwindSourceForPackagePath(string $packagePath, string $themeCss, bool $requireViews = true): ?string
    {
        $viewsPath = $packagePath . '/resources/views';

        if ($requireViews && ! File::isDirectory($viewsPath)) {
            return null;
        }

        $relativePath = $this->relativePath(dirname($themeCss), $viewsPath);

        return sprintf("@source '%s/**/*.blade.php';", $relativePath);
    }

    /** @return array<string, string> */
    private function pathRepositoryCapellPackagePaths(): array
    {
        $rootPath = $this->composerRootPath();

        if ($rootPath === null) {
            return [];
        }

        $composerJson = $this->readComposerJson($rootPath . '/composer.json');
        $repositories = is_array($composerJson['repositories'] ?? null) ? $composerJson['repositories'] : [];
        $requiredPackageNames = array_keys(array_merge(
            is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [],
            is_array($composerJson['require-dev'] ?? null) ? $composerJson['require-dev'] : [],
        ));

        $packages = [];

        foreach ($repositories as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = (string) ($repository['url'] ?? '');
            $url = str_starts_with($url, './') ? substr($url, 2) : $url;
            $pattern = str_starts_with($url, '/') ? $url : $rootPath . '/' . $url;
            $directories = glob($pattern, GLOB_ONLYDIR);

            foreach ($directories !== false ? $directories : [] as $directory) {
                $packageComposerJson = $this->readComposerJson(rtrim($directory, DIRECTORY_SEPARATOR) . '/composer.json');
                $packageName = (string) ($packageComposerJson['name'] ?? '');

                if (! str_starts_with($packageName, 'capell-app/') || ! in_array($packageName, $requiredPackageNames, true)) {
                    continue;
                }

                $packages[$packageName] = rtrim($directory, DIRECTORY_SEPARATOR);
            }
        }

        return $packages;
    }

    /** @return array<string, string> */
    private function monorepoCapellPackagePaths(): array
    {
        $rootPath = $this->composerRootPath();

        if ($rootPath === null) {
            return [];
        }

        $this->readComposerJson($rootPath . '/composer.json');
        $manifestPaths = glob($rootPath . '/packages/*/capell.json');
        $packages = [];

        foreach ($manifestPaths !== false ? $manifestPaths : [] as $manifestPath) {
            $packagePath = dirname($manifestPath);
            $packageComposerJson = $this->readComposerJson($packagePath . '/composer.json');
            $packageName = (string) ($packageComposerJson['name'] ?? '');

            if (! str_starts_with($packageName, 'capell-app/')) {
                continue;
            }

            $packages[$packageName] = rtrim($packagePath, DIRECTORY_SEPARATOR);
        }

        return $packages;
    }

    private function composerRootPath(): ?string
    {
        try {
            $rootPath = InstalledVersions::getInstallPath(InstalledVersions::getRootPackage()['name']);
        } catch (OutOfBoundsException) {
            $rootPath = null;
        }

        if ($rootPath === null) {
            return null;
        }

        $rootPath = realpath($rootPath);

        return $rootPath !== false ? $rootPath : null;
    }

    /** @return array<string, mixed> */
    private function readComposerJson(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $contents = json_decode(File::get($path), associative: true);

        return is_array($contents) ? $contents : [];
    }

    private function relativePath(string $from, string $to): string
    {
        $fromParts = explode('/', trim($this->normalisePath($from), '/'));
        $toParts = explode('/', trim($this->normalisePath($to), '/'));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return implode('/', [...array_fill(0, count($fromParts), '..'), ...$toParts]);
    }

    private function normalisePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
