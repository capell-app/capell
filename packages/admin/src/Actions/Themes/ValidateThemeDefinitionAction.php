<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Themes;

use Capell\Admin\Data\Themes\ThemeDiagnosticsData;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeFrontendBuildAssetsData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Composer\InstalledVersions;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ThemeDiagnosticsData run(string $themeKey, ?ThemeDefinitionData $definition = null, ?Theme $theme = null)
 */
final class ValidateThemeDefinitionAction
{
    use AsFake;
    use AsObject;

    public function handle(string $themeKey, ?ThemeDefinitionData $definition = null, ?Theme $theme = null): ThemeDiagnosticsData
    {
        $registry = app()->bound(ThemeRegistry::class) ? resolve(ThemeRegistry::class) : null;
        $definition ??= ResolveThemeDefinitionsAction::run()[$themeKey] ?? null;

        $warnings = [];
        $errors = [];

        if (! $theme instanceof Theme) {
            $warnings[] = __('capell-admin::theme-library.diagnostics.not_installed');
        }

        if (! $definition instanceof ThemeDefinitionData) {
            $errors[] = __('capell-admin::theme-library.diagnostics.missing_definition');

            return new ThemeDiagnosticsData(
                themeKey: $themeKey,
                installed: $theme instanceof Theme,
                hasDefinition: false,
                extendsResolved: false,
                hasPresets: false,
                hasPreviewImage: false,
                warnings: $this->stringList($warnings),
                errors: $this->stringList($errors),
            );
        }

        $extendsResolved = $definition->extends === null || ($registry instanceof ThemeRegistry && $registry->has($definition->extends));
        $hasPresets = $definition->presets !== [];
        $hasPreviewImage = trim($definition->previewImage) !== '';
        $missingAssets = $this->missingAssets($definition);

        if (! $extendsResolved) {
            $errors[] = __('capell-admin::theme-library.diagnostics.missing_parent', ['theme' => $definition->extends]);
        }

        if (! $hasPresets) {
            $errors[] = __('capell-admin::theme-library.diagnostics.missing_presets');
        }

        if (! $hasPreviewImage) {
            $warnings[] = __('capell-admin::theme-library.diagnostics.missing_preview_image');
        }

        if ($missingAssets !== []) {
            $warnings[] = __('capell-admin::theme-library.diagnostics.missing_assets', [
                'assets' => implode(', ', $missingAssets),
            ]);
        }

        return new ThemeDiagnosticsData(
            themeKey: $themeKey,
            installed: $theme instanceof Theme,
            hasDefinition: true,
            extendsResolved: $extendsResolved,
            hasPresets: $hasPresets,
            hasPreviewImage: $hasPreviewImage,
            warnings: $this->stringList($warnings),
            errors: $this->stringList($errors),
            missingAssets: $missingAssets,
        );
    }

    /**
     * @return list<string>
     */
    private function missingAssets(ThemeDefinitionData $definition): array
    {
        $buildAssets = $definition->frontendBuildAssets();

        if (! $buildAssets instanceof ThemeFrontendBuildAssetsData) {
            return $definition->assets === [] ? ['frontend'] : [];
        }

        $missingAssets = [];
        $packagePath = InstalledVersions::isInstalled($definition->package)
            ? InstalledVersions::getInstallPath($definition->package)
            : null;

        if (! is_string($packagePath) || ! $this->isFileWithin($packagePath, $buildAssets->cssSource)) {
            $missingAssets[] = 'frontend source';
        }

        if (! $this->isFileWithin(base_path(), $buildAssets->cssBuildInput)) {
            $missingAssets[] = 'frontend build input';
        }

        if ($buildAssets->condition !== 'theme-css:' . $definition->key) {
            $missingAssets[] = 'frontend condition';
        }

        return $missingAssets;
    }

    private function isFileWithin(string $basePath, string $relativePath): bool
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/')) {
            return false;
        }

        $basePath = realpath($basePath);

        if (! is_string($basePath)) {
            return false;
        }

        $filePath = realpath($basePath . DIRECTORY_SEPARATOR . $relativePath);

        return is_string($filePath)
            && str_starts_with($filePath, $basePath . DIRECTORY_SEPARATOR)
            && is_file($filePath);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<string>
     */
    private function stringList(array $items): array
    {
        return array_values(collect($items)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->values()
            ->all());
    }
}
