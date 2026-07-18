<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Assets;

use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\ThemeStudio\Data\BrandProfileData;

class ThemeAssetKey
{
    public static function make(string $themeKey, string $presetKey, BrandProfileData $brand): string
    {
        $hash = substr(hash('sha256', JsonCodec::encode([
            'theme' => $themeKey,
            'preset' => $presetKey,
            'tokens' => $brand->tokens(),
        ])), 0, 20);

        return implode(':', ['theme', $hash]);
    }
}
