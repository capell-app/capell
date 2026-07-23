<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Extensions;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\Manifest\ExtensionHealthCheckData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Support\Extensions\CapellExtensionApi;
use Capell\Core\Support\Manifest\CapellManifestData;
use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * @method static list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}> run(?string $path = null)
 */
final class AuditExtensionContractsAction
{
    use AsFake;
    use AsObject;

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    public function handle(?string $path = null): array
    {
        $results = [];
        $manifestPaths = $this->manifestPaths($path);

        if ($path !== null && $path !== '' && $manifestPaths === []) {
            return [$this->result(
                package: 'unknown',
                manifestPath: $path,
                severity: 'error',
                message: 'No capell.json manifests were found at the supplied path.',
                context: ['remediation' => 'Pass a package directory, a capell.json file, or a directory whose immediate children are packages.'],
            )];
        }

        foreach ($manifestPaths as $manifestPath) {
            $directory = dirname($manifestPath);
            $data = $this->readJsonFile($manifestPath);
            $composerJson = $this->readJsonFile($directory . '/composer.json');
            $packageName = $this->packageName($data, $composerJson);

            if ($data === null) {
                $results[] = $this->result(
                    package: $packageName,
                    manifestPath: $manifestPath,
                    severity: 'error',
                    message: 'Manifest file is missing or invalid JSON.',
                );

                continue;
            }

            $this->registerComposerPsr4Autoload($directory, $composerJson ?? []);

            try {
                ValidateExtensionManifestAction::run(
                    manifest: $data,
                    composerJson: $composerJson,
                    packageName: is_string($composerJson['name'] ?? null) ? $composerJson['name'] : null,
                    discoverySource: 'extension audit ' . $manifestPath,
                );

                $realDirectory = realpath($directory);

                $manifest = CapellManifestData::fromArray($data, $realDirectory !== false ? $realDirectory : $directory);
            } catch (Throwable $exception) {
                $results[] = $this->result(
                    package: $packageName,
                    manifestPath: $manifestPath,
                    severity: 'error',
                    message: $exception->getMessage(),
                );

                continue;
            }

            array_push(
                $results,
                ...$this->derivedResults($manifest, $manifestPath, $composerJson ?? []),
            );
        }

        return $results;
    }

    /** @return list<string> */
    private function publicCacheVariants(): array
    {
        return [
            'auth',
            'locale',
            'preview-token',
            'role',
            'site',
            'user',
            'workspace',
        ];
    }

    /**
     * @return list<string>
     */
    private function manifestPaths(?string $path): array
    {
        if ($path !== null && $path !== '') {
            return $this->manifestPathsForExplicitPath($path);
        }

        $paths = [];

        foreach (InstalledVersions::getInstalledPackages() as $packageName) {
            $installPath = InstalledVersions::getInstallPath($packageName);

            if ($installPath === null) {
                continue;
            }

            $paths[] = rtrim($installPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'capell.json';
        }

        $localManifestPaths = glob(getcwd() . '/packages/*/capell.json');

        foreach ($localManifestPaths !== false ? $localManifestPaths : [] as $manifestPath) {
            $paths[] = $manifestPath;
        }

        return array_values(array_unique(array_filter(
            $paths,
            file_exists(...),
        )));
    }

    /**
     * @return list<string>
     */
    private function manifestPathsForExplicitPath(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        if (! is_dir($path)) {
            return [$path];
        }

        $directManifest = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'capell.json';

        if (file_exists($directManifest)) {
            return [$directManifest];
        }

        $manifestPaths = glob(rtrim($path, DIRECTORY_SEPARATOR) . '/*/capell.json');

        return $manifestPaths !== false ? $manifestPaths : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @param  array<string, mixed>|null  $composerJson
     */
    private function packageName(?array $manifest, ?array $composerJson): string
    {
        if (is_string($manifest['name'] ?? null) && $manifest['name'] !== '') {
            return $manifest['name'];
        }

        if (is_string($composerJson['name'] ?? null) && $composerJson['name'] !== '') {
            return $composerJson['name'];
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $composerJson
     */
    private function registerComposerPsr4Autoload(string $directory, array $composerJson): void
    {
        $autoload = is_array($composerJson['autoload']['psr-4'] ?? null)
            ? $composerJson['autoload']['psr-4']
            : [];

        foreach ($autoload as $namespace => $relativePaths) {
            if (! is_string($namespace)) {
                continue;
            }

            $paths = is_string($relativePaths) ? [$relativePaths] : $relativePaths;

            if (! is_array($paths)) {
                continue;
            }

            $prefix = rtrim($namespace, '\\') . '\\';

            foreach ($paths as $relativePath) {
                if (! is_string($relativePath)) {
                    continue;
                }

                $basePath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($relativePath, DIRECTORY_SEPARATOR);

                spl_autoload_register(static function (string $class) use ($basePath, $prefix): void {
                    if (! str_starts_with($class, $prefix)) {
                        return;
                    }

                    $relativeClass = substr($class, strlen($prefix));
                    $file = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

                    if (file_exists($file)) {
                        require_once $file;
                    }
                });
            }
        }
    }

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function derivedResults(CapellManifestData $manifest, string $manifestPath, array $composerJson): array
    {
        return [
            ...$this->packageContractResults($manifest, $manifestPath, $composerJson),
            ...$this->capabilityResults($manifest, $manifestPath),
            ...$this->cacheSafetyResults($manifest, $manifestPath),
            ...$this->declarationResults($manifest, $manifestPath),
            ...$this->apiCompatibilityResults($manifest, $manifestPath),
        ];
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function packageContractResults(CapellManifestData $manifest, string $manifestPath, array $composerJson): array
    {
        $results = [];
        $composerName = is_string($composerJson['name'] ?? null) ? $composerJson['name'] : '';
        $composerSlug = str_contains($composerName, '/') ? substr($composerName, strrpos($composerName, '/') + 1) : '';

        if ($composerSlug !== '' && $composerSlug !== $manifest->slug) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'error',
                message: 'Manifest slug must match the package segment of composer.json name.',
                context: ['slug' => $manifest->slug, 'composerPackageSegment' => $composerSlug],
            );
        }

        try {
            (new VersionParser)->normalize($manifest->version);
        } catch (Throwable) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'error',
                message: 'Manifest version must be a valid Composer version such as 1.0.0 or 1.x-dev.',
                context: ['version' => $manifest->version],
            );
        }

        $packageDirectory = dirname($manifestPath);

        foreach ($manifest->marketplaceScreenshots as $screenshot) {
            if ($screenshot->path === '') {
                continue;
            }

            $relativePath = ltrim($screenshot->path, '/');
            $resolvedPath = realpath($packageDirectory . DIRECTORY_SEPARATOR . $relativePath);
            $packageRoot = realpath($packageDirectory);

            if ($packageRoot === false || $resolvedPath === false || ! str_starts_with($resolvedPath, $packageRoot . DIRECTORY_SEPARATOR)) {
                $results[] = $this->result(
                    package: $manifest->name,
                    manifestPath: $manifestPath,
                    severity: 'error',
                    message: 'Marketplace screenshot path must reference an existing file inside the package.',
                    context: ['path' => $screenshot->path],
                );
            }
        }

        if ($manifest->kind === 'theme' && $manifest->extends !== null && $manifest->extends !== '') {
            array_push($results, ...$this->themeAssetResults($manifest, $manifestPath, $composerJson));
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function themeAssetResults(CapellManifestData $manifest, string $manifestPath, array $composerJson): array
    {
        $results = [];
        $packageDirectory = dirname($manifestPath);
        $themeKey = $manifest->themeKey ?? '';
        $expectedCondition = 'theme-css:' . $themeKey;
        $registeredCssSources = $this->registeredThemeCssSources($packageDirectory, $composerJson, $expectedCondition);

        if ($themeKey === '' || $registeredCssSources === []) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'error',
                message: sprintf('Theme CSS must be registered as a conditional Tailwind import with condition "%s".', $expectedCondition),
                context: [
                    'expectedCondition' => $expectedCondition,
                    'remediation' => 'Register the CSS source with VendorAssetData type TailwindImport and this exact condition.',
                ],
            );

            return $results;
        }

        $missingSources = array_values(array_filter(
            $registeredCssSources,
            static fn (string $source): bool => ! file_exists($packageDirectory . DIRECTORY_SEPARATOR . ltrim($source, DIRECTORY_SEPARATOR)),
        ));

        if ($missingSources !== []) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'error',
                message: 'Theme Tailwind registration must reference an existing package CSS source.',
                context: [
                    'missingSources' => $missingSources,
                    'remediation' => 'Set VendorAssetData value (or CSS_SOURCE) to a committed package-relative CSS file.',
                ],
            );
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $composerJson
     * @return list<string>
     */
    private function registeredThemeCssSources(string $packageDirectory, array $composerJson, string $expectedCondition): array
    {
        $registeredSources = [];
        $psr4Paths = is_array($composerJson['autoload']['psr-4'] ?? null)
            ? array_values($composerJson['autoload']['psr-4'])
            : ['src/'];
        $sourcePaths = collect($psr4Paths)
            ->flatMap(static fn (mixed $paths): array => is_array($paths) ? $paths : [$paths])
            ->filter(static fn (mixed $path): bool => is_string($path))
            ->values()
            ->all();

        foreach ($sourcePaths as $sourcePath) {
            if (! is_string($sourcePath)) {
                continue;
            }

            $directory = $packageDirectory . DIRECTORY_SEPARATOR . trim($sourcePath, DIRECTORY_SEPARATOR);

            if (! is_dir($directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                array_push(
                    $registeredSources,
                    ...$this->registeredThemeCssSourcesInPhp((string) file_get_contents($file->getPathname()), $expectedCondition),
                );
            }
        }

        return array_values(array_unique($registeredSources));
    }

    /** @return list<string> */
    private function registeredThemeCssSourcesInPhp(string $php, string $expectedCondition): array
    {
        $php = $this->phpWithoutCommentsOrEmbeddedRegistrationStrings($php);
        $constants = [];

        if (preg_match_all('/const(?:\\s+string)?\\s+([A-Z][A-Z0-9_]*)\\s*=\\s*([\'\"])(.*?)\\2/', $php, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $constants[$match[1]] = $match[3];
            }
        }

        $registeredSources = [];

        if (preg_match_all('/[\\\\A-Za-z_][\\\\A-Za-z0-9_]*::registerVendorAsset\\s*\\(\\s*new\\s+[\\\\A-Za-z_][\\\\A-Za-z0-9_]*\\s*\\((.*?)\\)\\s*\\)\\s*;/s', $php, $matches) > 0) {
            foreach ($matches[1] as $arguments) {
                if (! preg_match('/type:\\s*[\\\\A-Za-z_][\\\\A-Za-z0-9_]*::TailwindImport/', $arguments)) {
                    continue;
                }

                $condition = $this->namedStringArgument($arguments, 'condition', $constants);
                $source = $this->namedStringArgument($arguments, 'value', $constants);

                if ($condition === $expectedCondition && $source !== null) {
                    $registeredSources[] = $source;
                }
            }
        }

        if (preg_match_all('/bootLayoutNativeThemeDefaults\\s*\\((.*?)\\);/s', $php, $matches) > 0) {
            foreach ($matches[1] as $arguments) {
                $condition = $this->namedStringArgument($arguments, 'cssCondition', $constants);
                $source = $this->namedStringArgument($arguments, 'cssSource', $constants);

                if ($condition === $expectedCondition && $source !== null) {
                    $registeredSources[] = $source;
                }
            }
        }

        return array_values(array_unique($registeredSources));
    }

    private function phpWithoutCommentsOrEmbeddedRegistrationStrings(string $php): string
    {
        return collect(token_get_all($php))
            ->map(static function (array|string $token): string {
                if (is_string($token)) {
                    return $token;
                }

                [$type, $text] = $token;

                if (in_array($type, [T_COMMENT, T_DOC_COMMENT], true)) {
                    return str_repeat(' ', strlen($text));
                }

                if (in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && (str_contains($text, 'registerVendorAsset') || str_contains($text, 'bootLayoutNativeThemeDefaults'))) {
                    return "''";
                }

                return $text;
            })
            ->implode('');
    }

    /** @param array<string, string> $constants */
    private function namedStringArgument(string $arguments, string $name, array $constants): ?string
    {
        if (preg_match(sprintf('/%s:\\s*([\'\"])(.*?)\\1/', preg_quote($name, '/')), $arguments, $matches) === 1) {
            return $matches[2];
        }

        if (preg_match(sprintf('/%s:\\s*(?:self|static)::([A-Z][A-Z0-9_]*)/', preg_quote($name, '/')), $arguments, $matches) === 1) {
            return $constants[$matches[1]] ?? null;
        }

        return null;
    }

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function capabilityResults(CapellManifestData $manifest, string $manifestPath): array
    {
        $results = [];
        $knownCapabilities = array_map(
            static fn (PackageCapability $capability): string => $capability->value,
            PackageCapability::cases(),
        );
        $unknownCapabilities = array_values(array_diff($manifest->capabilities, $knownCapabilities));

        if ($unknownCapabilities !== []) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'warning',
                message: 'Manifest declares capability strings outside the typed package capability graph.',
                context: ['capabilities' => $unknownCapabilities],
            );
        }

        if ($this->hasFrontendContribution($manifest) && array_intersect($manifest->capabilities, $knownCapabilities) === []) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'warning',
                message: 'Frontend package contribution is missing typed package capabilities.',
                context: ['expectedCapabilities' => $knownCapabilities],
            );
        }

        return $results;
    }

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function cacheSafetyResults(CapellManifestData $manifest, string $manifestPath): array
    {
        $results = [];
        $hasFrontendContribution = $this->hasFrontendContribution($manifest);
        $cacheSafety = $manifest->performance->cacheSafety;

        if (! $hasFrontendContribution || ! $cacheSafety->cacheable) {
            return [];
        }

        $unsafeVariants = array_values(array_intersect($cacheSafety->variesBy, $this->publicCacheVariants()));

        if ($unsafeVariants !== []) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'error',
                message: 'Frontend contribution declares unsafe public cache variance.',
                context: ['variesBy' => $unsafeVariants],
            );
        }

        if ($manifest->performance->cacheTags === []) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'warning',
                message: 'Cacheable frontend contribution is missing cache tags.',
            );
        }

        return $results;
    }

    private function hasFrontendContribution(CapellManifestData $manifest): bool
    {
        foreach ($manifest->contributes as $contribution) {
            if (in_array($contribution->type, [
                ExtensionContributionType::FrontendComponent,
                ExtensionContributionType::ContentWidget,
                ExtensionContributionType::RenderHook,
            ], true)) {
                return true;
            }

            if (($contribution->metadata['surface'] ?? null) === 'frontend') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function declarationResults(CapellManifestData $manifest, string $manifestPath): array
    {
        $results = [];
        $healthCheckClasses = array_values(array_filter(
            array_map(static fn (ExtensionHealthCheckData $healthCheck): ?string => $healthCheck->class, $manifest->healthChecks),
            is_string(...),
        ));

        foreach ($manifest->contributes as $contribution) {
            $permission = $contribution->metadata['permission'] ?? null;

            if (is_string($permission) && $permission !== '' && ! in_array($permission, $manifest->permissions, true)) {
                $results[] = $this->result(
                    package: $manifest->name,
                    manifestPath: $manifestPath,
                    severity: 'warning',
                    message: 'Contribution permission is missing from manifest permissions.',
                    context: ['permission' => $permission],
                );
            }

            if ($contribution->type === ExtensionContributionType::Setting) {
                // A settings contribution may either be the settings class itself or a
                // wrapper that declares the concrete settings classes via metadata.
                $settingsClasses = $this->contributionTargetClasses($contribution, 'settingsClass', 'settingsClasses');

                foreach ($settingsClasses as $settingsClass) {
                    if (! in_array($settingsClass, $manifest->settings, true)) {
                        $results[] = $this->result(
                            package: $manifest->name,
                            manifestPath: $manifestPath,
                            severity: 'warning',
                            message: 'Settings contribution is missing from manifest settings.',
                            context: ['class' => $settingsClass],
                        );
                    }
                }
            }

            if ($contribution->type === ExtensionContributionType::HealthCheck) {
                // A health-check contribution may either be the check class itself or a
                // wrapper that declares the concrete check class via the checkClass metadata.
                $checkClasses = $this->contributionTargetClasses($contribution, 'checkClass', 'checkClasses');

                foreach ($checkClasses as $checkClass) {
                    if (! in_array($checkClass, $healthCheckClasses, true)) {
                        $results[] = $this->result(
                            package: $manifest->name,
                            manifestPath: $manifestPath,
                            severity: 'warning',
                            message: 'Health-check contribution is missing from manifest healthChecks.',
                            context: ['class' => $checkClass],
                        );
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function apiCompatibilityResults(CapellManifestData $manifest, string $manifestPath): array
    {
        $results = [];

        if (! $this->constraintAllowsCurrentApi($manifest->capellApiVersion)) {
            $results[] = $this->result(
                package: $manifest->name,
                manifestPath: $manifestPath,
                severity: 'error',
                message: 'Manifest capellApiVersion does not allow the current Capell API.',
                context: [
                    'capellApiVersion' => $manifest->capellApiVersion,
                    'currentApiVersion' => CapellExtensionApi::CURRENT_VERSION,
                ],
            );
        }

        foreach ($this->contributionClasses($manifest) as $class) {
            if (! is_subclass_of($class, ExtensionContribution::class)) {
                continue;
            }

            $constraint = $class::compatibleCapellApiVersion();

            if (! $this->constraintAllowsCurrentApi($constraint)) {
                $results[] = $this->result(
                    package: $manifest->name,
                    manifestPath: $manifestPath,
                    severity: 'error',
                    message: 'Contribution compatibleCapellApiVersion does not allow the current Capell API.',
                    context: ['class' => $class, 'compatibleCapellApiVersion' => $constraint],
                );
            }
        }

        return $results;
    }

    /**
     * @return list<class-string>
     */
    private function contributionClasses(CapellManifestData $manifest): array
    {
        $classes = [
            ...$manifest->providers->all(),
            ...array_values(array_filter(
                array_map(static fn (ExtensionContributionData $contribution): ?string => $contribution->class, $manifest->contributes),
                is_string(...),
            )),
            ...array_values(array_filter(
                array_map(static fn (ExtensionHealthCheckData $healthCheck): ?string => $healthCheck->class, $manifest->healthChecks),
                is_string(...),
            )),
        ];

        /** @var list<class-string> $classes */
        $classes = array_values(array_unique($classes));

        return $classes;
    }

    private function constraintAllowsCurrentApi(string $constraint): bool
    {
        try {
            return Semver::satisfies(CapellExtensionApi::CURRENT_VERSION, $constraint);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve the concrete target classes a contribution declares (e.g. the real
     * settings or health-check classes). Wrapper contributions expose these via a
     * metadata key; direct contributions fall back to the contribution class itself.
     *
     * @return list<string>
     */
    private function contributionTargetClasses(ExtensionContributionData $contribution, string ...$metadataKeys): array
    {
        $classes = [];

        foreach ($metadataKeys as $metadataKey) {
            $declared = $contribution->metadata[$metadataKey] ?? null;

            if (is_string($declared) && $declared !== '') {
                $classes[] = $declared;

                continue;
            }

            if (is_array($declared)) {
                foreach ($declared as $value) {
                    if (is_string($value) && $value !== '') {
                        $classes[] = $value;
                    }
                }
            }
        }

        $classes = array_values(array_unique($classes));

        if ($classes !== []) {
            return $classes;
        }

        return $contribution->class !== null ? [$contribution->class] : [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}
     */
    private function result(
        string $package,
        string $manifestPath,
        string $severity,
        string $message,
        array $context = [],
    ): array {
        return [
            'package' => $package,
            'manifest_path' => $manifestPath,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ];
    }
}
