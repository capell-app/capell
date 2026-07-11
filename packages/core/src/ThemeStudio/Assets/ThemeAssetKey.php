<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Assets;

use Capell\Core\ThemeStudio\Data\BrandProfileData;

class ThemeAssetKey
{
    public static function make(string $themeKey, string $presetKey, BrandProfileData $brand): string
    {
        $hash = substr(hash('sha256', json_encode([
            'theme' => $themeKey,
            'preset' => $presetKey,
            'tokens' => $brand->tokens(),
        ], JSON_THROW_ON_ERROR)), 0, 20);

        return implode(':', ['theme', $hash]);
    }
}
