<?php

declare(strict_types=1);

namespace Capell\Core\Testing;

use AssertionError;
use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Manifest\ManifestValidator;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;

final class ExtensionTestHarness
{
    private ?CapellManifestData $manifest = null;

    /**
     * @var list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>|null
     */
    private ?array $auditResults = null;

    private function __construct(
        private readonly string $manifestPath,
    ) {}

    public static function forPath(string $path): self
    {
        return new self(self::manifestPathForPath($path));
    }

    public static function forPackageOrPath(string $extension, ?string $packagesDirectory = null): self
    {
        if (is_file($extension) || is_dir($extension)) {
            return self::forPath($extension);
        }

        $packagePath = InstalledVersions::getInstallPath($extension);

        if ($packagePath !== null) {
            return self::forPath($packagePath);
        }

        $basePath = $packagesDirectory;

        if ($basePath === null || $basePath === '') {
            $basePath = getcwd() . '/packages';
        } elseif (! str_starts_with($basePath, DIRECTORY_SEPARATOR)) {
            $basePath = getcwd() . DIRECTORY_SEPARATOR . $basePath;
        }

        $candidate = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $extension));

        return self::forPath($candidate);
    }

    public function assertManifestValid(): self
    {
        $errors = array_values(array_filter(
            $this->auditResults(),
            static fn (array $result): bool => $result['severity'] === 'error',
        ));

        if ($errors !== []) {
            throw new AssertionError('Extension manifest has validation errors: ' . $this->messages($errors));
        }

        return $this;
    }

    public function assertContributionRegistered(string $type, string $class): self
    {
        foreach ($this->manifest()->contributes as $contribution) {
            if ($contribution->type->value === $type && $contribution->class === $class) {
                return $this;
            }
        }

        throw new AssertionError(sprintf('Contribution [%s] %s is not declared in capell.json.', $type, $class));
    }

    public function assertRoutesOwnedByPackage(): self
    {
        foreach ($this->contributionsOfType(ExtensionContributionType::Route) as $contribution) {
            $namePrefix = $contribution->metadata['namePrefix'] ?? null;

            if (! is_string($namePrefix) || $namePrefix === '') {
                throw new AssertionError(sprintf('Route contribution %s must declare a namePrefix.', (string) $contribution->class));
            }
        }

        return $this;
    }

    public function assertScheduledJobsRegistered(): self
    {
        foreach ($this->contributionsOfType(ExtensionContributionType::ScheduledJob) as $contribution) {
            $schedule = $contribution->metadata['schedule'] ?? null;

            if (! is_string($schedule) || $schedule === '') {
                throw new AssertionError(sprintf('Scheduled job contribution %s must declare a schedule.', (string) $contribution->class));
            }
        }

        return $this;
    }

    public function assertNoUnsafePublicCache(): self
    {
        $errors = array_values(array_filter(
            $this->auditResults(),
            static fn (array $result): bool => $result['severity'] === 'error'
                && str_contains($result['message'], 'unsafe public cache'),
        ));

        if ($errors !== []) {
            throw new AssertionError('Extension declares unsafe public cache: ' . $this->messages($errors));
        }

        return $this;
    }

    public function assertThemeManifest(?string $themeKey = null): self
    {
        $manifest = $this->manifest();

        if ($manifest->kind !== 'theme') {
            throw new AssertionError(sprintf('Manifest kind is [%s], expected [theme].', $manifest->kind));
        }

        throw_if($manifest->themeKey === null || $manifest->themeKey === '', AssertionError::class, 'Theme manifest must declare themeKey.');

        if ($themeKey !== null && $manifest->themeKey !== $themeKey) {
            throw new AssertionError(sprintf('Theme manifest key is [%s], expected [%s].', $manifest->themeKey, $themeKey));
        }

        return $this;
    }

    public function assertThemeUsesSafeAssetUrls(): self
    {
        $viewsPath = dirname($this->manifestPath) . '/resources/views';

        if (! File::isDirectory($viewsPath)) {
            return $this;
        }

        $unsafeFiles = collect(File::allFiles($viewsPath))
            ->filter(fn (SplFileInfo $file): bool => Str::endsWith($file->getFilename(), '.blade.php'))
            ->filter(function (SplFileInfo $file): bool {
                $contents = (string) File::get($file->getPathname());

                return preg_match('/(?:src|href)=["\']\/(?!\/)/', $contents) === 1;
            })
            ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
            ->values()
            ->all();

        if ($unsafeFiles !== []) {
            throw new AssertionError('Theme Blade contains root-relative asset URLs: ' . implode(', ', $unsafeFiles));
        }

        return $this;
    }

    /**
     * @return array{
     *     package: string,
     *     manifestPath: string,
     *     surfaces: list<string>,
     *     providers: int,
     *     routes: int,
     *     migrations: bool,
     *     settings: int,
     *     scheduledJobs: int,
     *     contributions: int
     * }
     */
    public function summary(): array
    {
        $manifest = $this->manifest();

        return [
            'package' => $manifest->name,
            'manifestPath' => $this->manifestPath,
            'surfaces' => $manifest->surfaces,
            'providers' => count($manifest->providers->all()),
            'routes' => count($this->contributionsOfType(ExtensionContributionType::Route)),
            'migrations' => (bool) ($manifest->database['migrations'] ?? false),
            'settings' => count($manifest->settings),
            'scheduledJobs' => count($this->contributionsOfType(ExtensionContributionType::ScheduledJob)),
            'contributions' => count($manifest->contributes),
        ];
    }

    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    public function auditResults(): array
    {
        return $this->auditResults ??= AuditExtensionContractsAction::run($this->manifestPath);
    }

    private static function manifestPathForPath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $manifestPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'capell.json';

        if (! file_exists($manifestPath)) {
            throw new RuntimeException(sprintf('No capell.json manifest found at [%s].', $manifestPath));
        }

        return $manifestPath;
    }

    private function manifest(): CapellManifestData
    {
        $this->auditResults();

        return $this->manifest ??= new ManifestLoader(new ManifestValidator)->load($this->manifestPath);
    }

    /**
     * @return list<ExtensionContributionData>
     */
    private function contributionsOfType(ExtensionContributionType $type): array
    {
        return array_values(array_filter(
            $this->manifest()->contributes,
            static fn (ExtensionContributionData $contribution): bool => $contribution->type === $type,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function messages(array $results): string
    {
        return implode('; ', array_map(
            static fn (array $result): string => (string) ($result['message'] ?? ''),
            $results,
        ));
    }
}
