<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Manifest\Exceptions\InvalidManifestException;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use JsonException;
use UnexpectedValueException;

final class ManifestLoader
{
    /** @var array<string, true> */
    private static array $registeredManifestAutoloadPaths = [];

    public function __construct(
        private readonly ManifestValidator $validator,
    ) {}

    /**
     * Scan all installed composer packages for capell.json manifests.
     *
     * Also scans required composer path repositories so discovery works in
     * monorepo dev setups where sub-packages are not listed in InstalledVersions.
     *
     * @return array<string, CapellManifestData>
     */
    public function discover(): array
    {
        $manifests = [];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            $installPath = InstalledVersions::getInstallPath($packageName);

            if ($installPath === null) {
                continue;
            }

            $manifestPath = rtrim($installPath, '/') . '/capell.json';

            if (! file_exists($manifestPath)) {
                continue;
            }

            $manifest = $this->loadDiscoveredManifest(
                path: $manifestPath,
                packageName: $packageName,
                discoverySource: 'installed package ' . $packageName,
            );

            if (! $manifest instanceof CapellManifestData) {
                continue;
            }

            $manifests[$packageName] = $manifest;
        }

        $this->discoverFromPathRepositories($manifests);
        $this->discoverFromMonorepoPackages($manifests);

        return $manifests;
    }

    public function load(
        string $path,
        ?string $packageName = null,
        ?string $discoverySource = null,
    ): CapellManifestData {
        if (! file_exists($path)) {
            throw InvalidManifestException::fileNotFound($path);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw InvalidManifestException::fileNotFound($path);
        }

        try {
            $data = JsonCodec::decodeObjectOrFail($contents);
        } catch (JsonException|UnexpectedValueException) {
            throw InvalidManifestException::missingField('root object (invalid JSON)');
        }

        $manifestDirectory = dirname($path);
        $realManifestDirectory = realpath($manifestDirectory);
        $composerJson = $this->readComposerJson($manifestDirectory . '/composer.json');
        $resolvedPackageName = $packageName ?? (is_string($composerJson['name'] ?? null) ? $composerJson['name'] : null);

        $this->registerManifestComposerAutoload($manifestDirectory, $composerJson);

        $this->validator->validate(
            data: $data,
            composerJson: $composerJson,
            packageName: $resolvedPackageName,
            discoverySource: $discoverySource ?? 'manifest file ' . $path,
        );

        return CapellManifestData::fromArray(
            $data,
            $realManifestDirectory !== false ? $realManifestDirectory : $manifestDirectory,
            $this->composerDocumentationUrl($composerJson),
        );
    }

    /**
     * Resolve a package's documentation URL from its composer.json `support.docs`
     * entry, used as a fallback when the manifest omits an explicit `documentationUrl`.
     *
     * @param  array<string, mixed>  $composerJson
     */
    private function composerDocumentationUrl(array $composerJson): ?string
    {
        $support = $composerJson['support'] ?? null;

        if (! is_array($support)) {
            return null;
        }

        $documentationUrl = $support['docs'] ?? null;

        if (! is_string($documentationUrl)) {
            return null;
        }

        $documentationUrl = trim($documentationUrl);

        return $documentationUrl !== '' ? $documentationUrl : null;
    }

    /**
     * Scan composer path repositories for capell.json manifests not already found
     * via InstalledVersions (covers monorepo dev setups).
     *
     * @param  array<string, CapellManifestData>  $manifests
     */
    private function discoverFromPathRepositories(array &$manifests): void
    {
        $rootRealPath = $this->rootPackagePath();

        if ($rootRealPath === false) {
            return;
        }

        $composerJsonPath = $rootRealPath . '/composer.json';

        if (! file_exists($composerJsonPath)) {
            return;
        }

        $rootComposerJson = $this->readComposerJson($composerJsonPath);
        $repositories = $rootComposerJson['repositories'] ?? [];
        $requiredPackageNames = $this->requiredPackageNames($rootComposerJson);
        foreach ($repositories as $repository) {
            if (! is_array($repository)) {
                continue;
            }

            if (($repository['type'] ?? '') !== 'path') {
                continue;
            }

            $url = (string) ($repository['url'] ?? '');

            if (str_starts_with($url, './')) {
                $url = substr($url, 2);
            }

            $pattern = str_starts_with($url, '/') ? $url : $rootRealPath . '/' . $url;

            $directories = glob($pattern, GLOB_ONLYDIR);
            foreach ($directories !== false ? $directories : [] as $directory) {
                $manifestPath = rtrim($directory, '/') . '/capell.json';

                if (! file_exists($manifestPath)) {
                    continue;
                }

                $packageComposerJson = $this->readComposerJson(rtrim($directory, '/') . '/composer.json');
                $packageName = (string) ($packageComposerJson['name'] ?? '');

                if (! $this->shouldDiscoverPathRepository($packageName, $requiredPackageNames)) {
                    continue;
                }

                if (isset($manifests[$packageName])) {
                    continue;
                }

                $manifest = $this->loadDiscoveredManifest(
                    path: $manifestPath,
                    packageName: $packageName,
                    discoverySource: 'path repository ' . $directory,
                );

                if (! $manifest instanceof CapellManifestData) {
                    continue;
                }

                // Installed packages take precedence; only fill gaps
                $manifests[$manifest->name] ??= $manifest;
            }
        }
    }

    /**
     * @param  array<string, CapellManifestData>  $manifests
     */
    private function discoverFromMonorepoPackages(array &$manifests): void
    {
        $rootRealPath = $this->rootPackagePath();

        if ($rootRealPath === false) {
            return;
        }

        $manifestPaths = glob($rootRealPath . '/packages/*/capell.json');

        foreach ($manifestPaths !== false ? $manifestPaths : [] as $manifestPath) {
            $packageDirectory = dirname($manifestPath);
            $packageComposerJson = $this->readComposerJson($packageDirectory . '/composer.json');
            $packageName = (string) ($packageComposerJson['name'] ?? '');

            if ($packageName === '') {
                continue;
            }

            if (isset($manifests[$packageName])) {
                continue;
            }

            $manifest = $this->loadDiscoveredManifest(
                path: $manifestPath,
                packageName: $packageName,
                discoverySource: 'monorepo package ' . $packageDirectory,
            );

            if (! $manifest instanceof CapellManifestData) {
                continue;
            }

            $manifests[$manifest->name] ??= $manifest;
        }
    }

    private function rootPackagePath(): string|false
    {
        $testbenchWorkingPath = defined('TESTBENCH_WORKING_PATH') ? constant('TESTBENCH_WORKING_PATH') : null;

        if (is_string($testbenchWorkingPath)) {
            return realpath($testbenchWorkingPath);
        }

        $rootPackage = InstalledVersions::getRootPackage();
        $rootInstallPath = InstalledVersions::getInstallPath($rootPackage['name']);

        if (is_string($rootInstallPath)) {
            return realpath($rootInstallPath);
        }

        return false;
    }

    private function loadDiscoveredManifest(
        string $path,
        string $packageName,
        string $discoverySource,
    ): ?CapellManifestData {
        if (! file_exists($path)) {
            return null;
        }

        if ($this->isLegacyManifest($path)) {
            return null;
        }

        try {
            return $this->load(
                path: $path,
                packageName: $packageName,
                discoverySource: $discoverySource,
            );
        } catch (InvalidManifestException $invalidManifestException) {
            if ($invalidManifestException->getMessage() === InvalidManifestException::fileNotFound($path)->getMessage()) {
                return null;
            }

            throw $invalidManifestException;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readComposerJson(string $path): array
    {
        if (! file_exists($path)) {
            return [];
        }

        return JsonCodec::decodeObject((string) file_get_contents($path));
    }

    /** @param array<string, mixed> $composerJson */
    private function registerManifestComposerAutoload(string $manifestDirectory, array $composerJson): void
    {
        $prefixes = [
            ...$this->composerPsr4Prefixes($manifestDirectory, $composerJson['autoload']['psr-4'] ?? null),
            ...$this->composerPsr4Prefixes($manifestDirectory, $composerJson['autoload-dev']['psr-4'] ?? null),
        ];

        if ($prefixes === []) {
            return;
        }

        $registrationKey = hash('sha256', JsonCodec::encode($prefixes));

        if (isset(self::$registeredManifestAutoloadPaths[$registrationKey])) {
            return;
        }

        $loader = new ClassLoader($manifestDirectory);

        foreach ($prefixes as $prefix => $paths) {
            $loader->setPsr4($prefix, array_values($paths));
        }

        $loader->register(prepend: true);

        self::$registeredManifestAutoloadPaths[$registrationKey] = true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function composerPsr4Prefixes(string $manifestDirectory, mixed $psr4): array
    {
        if (! is_array($psr4)) {
            return [];
        }

        $prefixes = [];

        foreach ($psr4 as $prefix => $paths) {
            if (! is_string($prefix)) {
                continue;
            }

            if ($prefix === '') {
                continue;
            }

            $normalizedPaths = $this->composerPsr4Paths($manifestDirectory, $paths);

            if ($normalizedPaths === []) {
                continue;
            }

            $prefixes[rtrim($prefix, '\\') . '\\'] = $normalizedPaths;
        }

        return $prefixes;
    }

    /**
     * @return array<int, string>
     */
    private function composerPsr4Paths(string $manifestDirectory, mixed $paths): array
    {
        $candidatePaths = is_array($paths) ? $paths : [$paths];

        return array_values(array_filter(array_map(
            function (mixed $path) use ($manifestDirectory): ?string {
                if (! is_string($path) || $path === '') {
                    return null;
                }

                $resolvedPath = str_starts_with($path, DIRECTORY_SEPARATOR)
                    ? $path
                    : $manifestDirectory . DIRECTORY_SEPARATOR . $path;

                $realPath = realpath($resolvedPath);

                return $realPath !== false && is_dir($realPath) ? $realPath : null;
            },
            $candidatePaths,
        )));
    }

    private function isLegacyManifest(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        $fileContents = file_get_contents($path);

        if ($fileContents === false) {
            return false;
        }

        $contents = JsonCodec::decodeObject($fileContents);

        if ($contents === []) {
            return false;
        }

        return ($contents['manifest-version'] ?? null) !== 3;
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @return array<int, string>
     */
    private function requiredPackageNames(array $composerJson): array
    {
        return array_keys(array_merge(
            is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [],
            is_array($composerJson['require-dev'] ?? null) ? $composerJson['require-dev'] : [],
        ));
    }

    /**
     * @param  array<int, string>  $requiredPackageNames
     */
    private function shouldDiscoverPathRepository(
        string $packageName,
        array $requiredPackageNames,
    ): bool {
        if ($packageName === '') {
            return false;
        }

        return in_array($packageName, $requiredPackageNames, true);
    }
}
