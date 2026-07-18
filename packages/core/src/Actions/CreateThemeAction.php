<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\ModelInterceptors\ThemeInterceptorInterface;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\BlueprintCreator;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Theme run(string $key = 'default', ?string $name = null, array<mixed> $assets = [], ?string $assetsPath = null, bool $defaultColors = false, ?bool $default = null, ?string $activePreset = null)
 */
final class CreateThemeAction
{
    use AsFake;
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
                $name ??= $existingTheme->name
                    ?? $this->getUniqueThemeName($themeModel, $data['name'] ?? __('capell::generic.default'));

                $typeId = $data['blueprint_id'] ?? $existingTheme->blueprint_id ?? $this->resolveThemeTypeId($typeModel);

                $meta = BuildThemeMetadataAction::run(
                    themeKey: $key,
                    existingMeta: $existingTheme->meta ?? [],
                    data: $data,
                    assets: $assets,
                    assetsPath: $assetsPath,
                    defaultColors: $defaultColors,
                    activePreset: $activePreset,
                );

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
