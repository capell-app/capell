<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Actions;

use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array<string, mixed> run(string $themeKey, BrandProfileData|array<string, mixed> $brand = [], ?string $presetKey = null, array<int, string> $assets = [], ?string $layoutKey = null)
 */
final class BuildBrandProfileSeedAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  BrandProfileData|array<string, mixed>  $brand
     * @param  array<int, string>  $assets
     * @return array<string, mixed>
     */
    public function handle(
        string $themeKey,
        BrandProfileData|array $brand = [],
        ?string $presetKey = null,
        array $assets = [],
        ?string $layoutKey = null,
    ): array {
        $brandProfile = $brand instanceof BrandProfileData
            ? $brand
            : (new BrandProfileData)->merge($brand);

        return [
            'theme' => [
                'key' => $themeKey,
                'active_preset' => $presetKey,
                'brand_profile' => $brandProfile->toArray(),
                'assets' => array_values($assets),
            ],
            'layout' => [
                'key' => $layoutKey,
            ],
        ];
    }
}
