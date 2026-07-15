<?php

declare(strict_types=1);

use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;

it('requires a stable key label and composer package owner', function (string $key, string $label, string $package): void {
    new FrontendResourceGroupData($key, $label, $package);
})->with([
    'invalid key' => ['Gallery Group', 'Gallery', 'capell-app/gallery'],
    'blank label' => ['gallery', '', 'capell-app/gallery'],
    'invalid package' => ['gallery', 'Gallery', 'gallery'],
])->throws(InvalidArgumentException::class);

it('rejects resources owned by a different package', function (): void {
    new FrontendResourceGroupData(
        key: 'gallery',
        label: 'Gallery',
        package: 'capell-app/gallery',
        resources: [
            FrontendResourceData::style(
                'capell-app/other:styles',
                'capell-app/other',
                new PublicResourceSourceData('vendor/other/styles.css'),
            ),
        ],
    );
})->throws(InvalidArgumentException::class, 'must own every resource');
