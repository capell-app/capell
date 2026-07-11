<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\ColorData;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

class GetThemeColorsAction
{
    use AsObject;

    public function handle(Theme $theme): Collection
    {
        $themeColors = data_get($theme->meta ?? [], 'colors', []);
        $defaultColors = DefaultColorEnum::getKeyValues();

        if (! is_array($themeColors)) {
            $themeColors = [];
        }

        $mergedColors = array_merge($defaultColors, $themeColors);

        return collect($mergedColors)
            ->map(
                fn (?string $color, string $name): ColorData => new ColorData(
                    name: $name,
                    color: $color,
                ),
            );
    }
}
