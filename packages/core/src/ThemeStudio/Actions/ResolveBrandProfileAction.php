<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Actions;

use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeOverrideData;

class ResolveBrandProfileAction
{
    public static function run(
        BrandProfileData $brand,
        ThemeDefinitionData $definition,
        ThemeOverrideData $override,
    ): BrandProfileData {
        return (new self)->handle($brand, $definition, $override);
    }

    public function handle(
        BrandProfileData $brand,
        ThemeDefinitionData $definition,
        ThemeOverrideData $override,
    ): BrandProfileData {
        $presetValues = [];

        if ($override->presetKey !== null) {
            $preset = $definition->preset($override->presetKey);
            $presetValues = $preset?->values ?? [];
        }

        return $brand
            ->merge($presetValues)
            ->merge($override->values);
    }
}
