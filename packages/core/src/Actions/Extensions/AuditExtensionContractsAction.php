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
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
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

        foreach ($this->manifestPaths($path) as $manifestPath) {
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
                ...$this->derivedResults($manifest, $manifestPath),
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

        foreach ($autoload as $namespace => $relativePath) {
            if (! is_string($namespace)) {
                continue;
            }

            if (! is_string($relativePath)) {
                continue;
            }

            $prefix = rtrim($namespace, '\\') . '\\';
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

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    private function derivedResults(CapellManifestData $manifest, string $manifestPath): array
    {
        return [
            ...$this->capabilityResults($manifest, $manifestPath),
            ...$this->cacheSafetyResults($manifest, $manifestPath),
            ...$this->declarationResults($manifest, $manifestPath),
            ...$this->apiCompatibilityResults($manifest, $manifestPath),
        ];
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
