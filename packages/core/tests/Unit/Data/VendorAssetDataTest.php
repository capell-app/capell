<?php

declare(strict_types=1);

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;

it('builds typed vendor asset descriptors for frontend tooling', function (): void {
    expect(VendorAssetData::tailwindImport('@source "./vendor/capell"', 'capell/core'))
        ->type->toBe(VendorAssetEnum::TailwindImport)
        ->value->toBe('@source "./vendor/capell"')
        ->packageName->toBe('capell/core');

    expect(VendorAssetData::tailwindPlugin('@tailwindcss/forms'))
        ->type->toBe(VendorAssetEnum::TailwindPlugin)
        ->value->toBe('@tailwindcss/forms');

    expect(VendorAssetData::tailwindSource('../vendor/capell/**/*.blade.php'))
        ->type->toBe(VendorAssetEnum::TailwindSource)
        ->path()->toBe('../vendor/capell/**/*.blade.php');

    expect(VendorAssetData::tailwindThemeColor('primary', '#0369a1'))
        ->type->toBe(VendorAssetEnum::TailwindThemeColor)
        ->colorName()->toBe('primary')
        ->colorValue()->toBe('#0369a1');

});
