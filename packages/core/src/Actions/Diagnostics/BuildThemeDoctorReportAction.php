<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Data\Diagnostics\DoctorReportData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Manifest\ManifestLoader;
use Capell\Core\Support\Themes\ThemeAssetUrlInspector;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * @method static DoctorReportData run(string $theme, ?string $path = null)
 */
final class BuildThemeDoctorReportAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ManifestLoader $manifestLoader) {}

    public function handle(string $theme, ?string $path = null): DoctorReportData
    {
        $themePath = $this->themePath($theme, $path);
        $checks = collect([
            $this->identified($this->checkManifest($theme, $themePath), 'theme.manifest.valid', DoctorCheckSeverity::Critical),
            $this->identified($this->checkComposerJson($themePath), 'theme.composer.present', DoctorCheckSeverity::Critical),
            $this->identified($this->checkViewsDirectory($themePath), 'theme.views.present', DoctorCheckSeverity::Critical),
            $this->identified($this->checkSafeAssetUrls($themePath), 'theme.asset-urls.safe', DoctorCheckSeverity::Warning),
            $this->identified($this->checkRuntimeRegistry($theme, $themePath), 'theme.runtime.registered', DoctorCheckSeverity::Critical),
        ]);

        return new DoctorReportData(
            status: $checks->every(fn (DoctorCheckResultData $check): bool => $check->passed) ? 'passed' : 'failed',
            checks: $checks->values(),
        );
    }

    private function identified(
        DoctorCheckResultData $check,
        string $id,
        DoctorCheckSeverity $severity,
    ): DoctorCheckResultData {
        return new DoctorCheckResultData(
            label: $check->label,
            passed: $check->passed,
            message: $check->message,
            remediation: $check->remediation,
            id: $id,
            severity: $severity,
            evidence: $check->evidence,
        );
    }

    private function themePath(string $theme, ?string $path): string
    {
        if (is_string($path) && $path !== '') {
            return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        }

        return base_path('packages/' . $theme);
    }

    private function checkManifest(string $theme, string $themePath): DoctorCheckResultData
    {
        $manifestPath = rtrim($themePath, DIRECTORY_SEPARATOR) . '/capell.json';

        try {
            $manifest = $this->manifestLoader->load($manifestPath);
        } catch (Throwable $throwable) {
            return new DoctorCheckResultData(
                label: 'Theme manifest',
                passed: false,
                message: $throwable->getMessage(),
                remediation: 'Run php artisan capell:make-theme ' . $theme . ' --local or fix capell.json.',
            );
        }

        if ($manifest->kind !== 'theme') {
            return new DoctorCheckResultData('Theme manifest', false, 'Manifest kind is not theme.', 'Set "kind" to "theme".');
        }

        if ($manifest->themeKey !== $theme) {
            return new DoctorCheckResultData(
                label: 'Theme manifest',
                passed: false,
                message: sprintf('Manifest themeKey is [%s], expected [%s].', (string) $manifest->themeKey, $theme),
                remediation: 'Update capell.json or run the doctor with the manifest theme key.',
            );
        }

        return new DoctorCheckResultData('Theme manifest', true, 'Manifest is valid for theme [' . $theme . '].');
    }

    private function checkComposerJson(string $themePath): DoctorCheckResultData
    {
        $composerPath = rtrim($themePath, DIRECTORY_SEPARATOR) . '/composer.json';

        if (! File::exists($composerPath)) {
            return new DoctorCheckResultData('Theme composer.json', false, 'Missing composer.json.', 'Theme packages should be Composer path packages.');
        }

        return new DoctorCheckResultData('Theme composer.json', true, 'Composer package metadata exists.');
    }

    private function checkViewsDirectory(string $themePath): DoctorCheckResultData
    {
        $viewsPath = rtrim($themePath, DIRECTORY_SEPARATOR) . '/resources/views';

        if (! File::isDirectory($viewsPath)) {
            return new DoctorCheckResultData('Theme views', false, 'Missing resources/views directory.', 'Add a page wrapper and section views.');
        }

        return new DoctorCheckResultData('Theme views', true, 'Theme views directory exists.');
    }

    private function checkSafeAssetUrls(string $themePath): DoctorCheckResultData
    {
        $viewsPath = rtrim($themePath, DIRECTORY_SEPARATOR) . '/resources/views';

        if (! File::isDirectory($viewsPath)) {
            return new DoctorCheckResultData('Theme asset URLs', true, 'Skipped because resources/views does not exist.');
        }

        $unsafeFiles = Collection::make(File::allFiles($viewsPath))
            ->filter(fn (SplFileInfo $file): bool => Str::endsWith($file->getFilename(), '.blade.php'))
            ->filter(function (SplFileInfo $file): bool {
                $contents = (string) File::get($file->getPathname());

                return ThemeAssetUrlInspector::containsRootRelativeAssetUrl($contents);
            })
            ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
            ->values()
            ->all();

        if ($unsafeFiles !== []) {
            return new DoctorCheckResultData(
                label: 'Theme asset URLs',
                passed: false,
                message: 'Root-relative asset URLs found in: ' . implode(', ', $unsafeFiles) . '.',
                remediation: "Use @frontendAsset('path/from/public.css') for public theme assets.",
            );
        }

        return new DoctorCheckResultData('Theme asset URLs', true, 'No root-relative asset URLs found in Blade views.');
    }

    private function checkRuntimeRegistry(string $theme, string $themePath): DoctorCheckResultData
    {
        if (! app()->bound(ThemeRegistry::class)) {
            return new DoctorCheckResultData('Theme runtime registry', true, 'ThemeRegistry is not bound in this runtime.');
        }

        $registry = resolve(ThemeRegistry::class);

        if (! $registry->has($theme)) {
            $this->bootstrapRuntimeProviders($themePath);
        }

        if (! $registry->has($theme)) {
            return new DoctorCheckResultData(
                label: 'Theme runtime registry',
                passed: false,
                message: 'Theme [' . $theme . '] is not registered at runtime.',
                remediation: 'For local themes, register the ThemeDefinitionData with ThemeRegistry unconditionally from the provider.',
            );
        }

        return new DoctorCheckResultData('Theme runtime registry', true, 'Theme is registered at runtime.');
    }

    private function bootstrapRuntimeProviders(string $themePath): void
    {
        try {
            $manifest = $this->manifestLoader->load(rtrim($themePath, DIRECTORY_SEPARATOR) . '/capell.json');

            foreach ($manifest->providers->runtime as $provider) {
                if (class_exists($provider)) {
                    app()->register($provider);
                }
            }
        } catch (Throwable) {
            // Runtime provider bootstrapping is best-effort; the registry check reports the failed registration.
        }
    }
}
