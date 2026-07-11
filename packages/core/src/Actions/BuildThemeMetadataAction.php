<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run(string $themeKey, array<string, mixed> $existingMeta = [], array<string, mixed> $data = [], array<int, string> $assets = [], ?string $assetsPath = null, bool $defaultColors = false, ?string $activePreset = null)
 */
class BuildThemeMetadataAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $existingMeta
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $assets
     * @return array<string, mixed>
     */
    public function handle(
        string $themeKey,
        array $existingMeta = [],
        array $data = [],
        array $assets = [],
        ?string $assetsPath = null,
        bool $defaultColors = false,
        ?string $activePreset = null,
    ): array {
        $dataMeta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $assets = $this->resolveAssets($themeKey, $assets, $data, $dataMeta, $existingMeta);
        $assetsPath ??= $data['assets_build_path'] ?? $existingMeta['assets_path'] ?? null;

        $meta = [
            ...$existingMeta,
            'footer' => true,
            'header' => true,
            ...$dataMeta,
            'assets' => $assets,
            'assets_path' => $assetsPath,
        ];

        $editor = is_array($meta['editor'] ?? null) ? $meta['editor'] : [];
        $editor['assets'] = [
            ...(is_array($editor['assets'] ?? null) ? $editor['assets'] : []),
            'paths' => $assets,
            'buildPath' => $assetsPath,
        ];

        if ($defaultColors) {
            $meta['colors'] = DefaultColorEnum::getKeyValues();
        }

        $preset = is_array($editor['preset'] ?? null) ? $editor['preset'] : [];

        if (is_string($activePreset) && $activePreset !== '') {
            $meta['active_preset'] = $activePreset;
            $preset['active'] = $activePreset;
        }

        $editor['preset'] = [
            ...$preset,
            'active' => is_string($preset['active'] ?? null) && $preset['active'] !== ''
                ? $preset['active']
                : 'default',
        ];
        $editor['header'] = [
            ...(is_array($editor['header'] ?? null) ? $editor['header'] : []),
            'enabled' => (bool) data_get($editor, 'header.enabled', true),
        ];
        $editor['footer'] = [
            ...(is_array($editor['footer'] ?? null) ? $editor['footer'] : []),
            'enabled' => (bool) data_get($editor, 'footer.enabled', true),
        ];

        $meta['editor'] = $editor;

        return $meta;
    }

    /**
     * @param  array<int, string>  $assets
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $dataMeta
     * @param  array<string, mixed>  $existingMeta
     * @return array<int, string>
     */
    private function resolveAssets(string $themeKey, array $assets, array $data, array $dataMeta, array $existingMeta): array
    {
        if ($assets !== []) {
            return $assets;
        }

        $definitionAssets = $this->definitionAssets($themeKey);

        foreach ([$data, $dataMeta, $existingMeta] as $source) {
            if (! array_key_exists('assets', $source)) {
                continue;
            }

            $sourceAssets = $source['assets'];

            if ($this->shouldReplaceLegacySharedAssets($sourceAssets, $definitionAssets)) {
                return $definitionAssets;
            }

            return is_array($sourceAssets) ? array_values($sourceAssets) : [];
        }

        return $definitionAssets;
    }

    /**
     * @return array<int, string>
     */
    private function definitionAssets(string $themeKey): array
    {
        if (! app()->bound(ThemeRegistry::class)) {
            return [];
        }

        $registry = resolve(ThemeRegistry::class);

        if (! $registry->has($themeKey)) {
            return [];
        }

        return array_values($registry->definition($themeKey)->assets);
    }

    /**
     * @param  array<int, string>  $definitionAssets
     */
    private function shouldReplaceLegacySharedAssets(mixed $existingAssets, array $definitionAssets): bool
    {
        return $definitionAssets !== []
            && $existingAssets === ['resources/css/capell/frontend.css'];
    }
}
