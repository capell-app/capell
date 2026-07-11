<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\ModelInterceptors\ThemeInterceptorInterface;
use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Theme run(string $key = 'default', ?string $name = null, array<mixed> $assets = [], ?string $assetsPath = null, bool $defaultColors = false, ?bool $default = null, ?string $activePreset = null)
 */
class CreateThemeAction
{
    use AsObject;

    /**
     * @param  array<mixed>  $assets
     */
    public function handle(string $key = 'default', ?string $name = null, array $assets = [], ?string $assetsPath = null, bool $defaultColors = false, ?bool $default = null, ?string $activePreset = null): Theme
    {
        /** @var class-string<Theme> $themeModel */
        $themeModel = Theme::class;

        /** @var class-string<Blueprint> $typeModel */
        $typeModel = Blueprint::class;

        /** @var Theme|null $existingTheme */
        $existingTheme = Theme::query()
            ->where('key', $key)
            ->first();

        return CapellCore::createOrUpdateModel(
            $themeModel,
            $key,
            function (array $data) use ($themeModel, $typeModel, $key, $name, $assets, $assetsPath, $defaultColors, $default, $activePreset, $existingTheme): array {
                $existingMeta = $existingTheme->meta ?? [];
                $dataMeta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

                $name ??= $existingTheme->name
                    ?? $this->getUniqueThemeName($themeModel, $data['name'] ?? __('capell::generic.default'));

                $typeId = $data['blueprint_id'] ?? $existingTheme->blueprint_id ?? $this->resolveThemeTypeId($typeModel);

                if ($assets === [] && array_key_exists('assets', $data)) {
                    $definitionAssets = $this->definitionAssets($key);
                    $assets = $this->shouldReplaceLegacySharedAssets($data['assets'], $definitionAssets)
                        ? $definitionAssets
                        : $data['assets'];
                } elseif ($assets === [] && array_key_exists('assets', $dataMeta)) {
                    $definitionAssets = $this->definitionAssets($key);
                    $assets = $this->shouldReplaceLegacySharedAssets($dataMeta['assets'], $definitionAssets)
                        ? $definitionAssets
                        : $dataMeta['assets'];
                } elseif ($assets === [] && array_key_exists('assets', $existingMeta)) {
                    $definitionAssets = $this->definitionAssets($key);
                    $assets = $this->shouldReplaceLegacySharedAssets($existingMeta['assets'], $definitionAssets)
                        ? $definitionAssets
                        : $existingMeta['assets'];
                } elseif ($assets === []) {
                    $assets = $this->definitionAssets($key);
                }

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

                return [
                    'name' => $name,
                    'key' => $data['key'] ?? $key,
                    'blueprint_id' => $typeId,
                    'default' => $data['default'] ?? $default ?? $existingTheme->default ?? ! $themeModel::query()->default()->exists(),
                    'meta' => $meta,
                ];
            },
            ThemeInterceptorInterface::class,
        );
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

    /**
     * @param  class-string<Theme>  $themeModel
     */
    private function getUniqueThemeName(string $themeModel, string $name): string
    {
        while ($themeModel::query()->where('name', $name)->exists()) {
            $name = IncrementNameAction::run($name);
        }

        return $name;
    }

    /**
     * @param  class-string<Blueprint>  $typeModel
     */
    private function resolveThemeTypeId(string $typeModel): int
    {
        $type = $typeModel::query()->themeType()->default()->first();

        if ($type === null) {
            $type = resolve(BlueprintCreator::class)->createThemeType();
        }

        return $type->id;
    }
}
