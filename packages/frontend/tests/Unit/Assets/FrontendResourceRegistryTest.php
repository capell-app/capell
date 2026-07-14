<?php

declare(strict_types=1);

use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;

it('registers immutable typed resource groups', function (): void {
    $group = new FrontendResourceGroupData(
        key: 'gallery',
        label: 'Gallery',
        package: 'capell-app/gallery',
        resources: [
            FrontendResourceData::style(
                handle: 'capell-app/gallery:styles',
                package: 'capell-app/gallery',
                source: new PublicResourceSourceData('vendor/gallery/gallery.css'),
            ),
        ],
    );
    $registry = new FrontendResourceRegistry;

    $registry->register($group);

    expect($registry->has('gallery'))->toBeTrue()
        ->and($registry->get('gallery'))->toBe($group)
        ->and($registry->all())->toBe(['gallery' => $group])
        ->and($registry->resource('capell-app/gallery:styles'))->toBe($group->resources[0]);
});

it('rejects duplicate group keys', function (): void {
    $group = new FrontendResourceGroupData('gallery', 'Gallery', 'capell-app/gallery');
    $registry = new FrontendResourceRegistry;
    $registry->register($group);

    $registry->register($group);
})->throws(InvalidArgumentException::class, 'already registered');

it('rejects duplicate handles across groups', function (): void {
    $resource = FrontendResourceData::style(
        'capell-app/gallery:styles',
        'capell-app/gallery',
        new PublicResourceSourceData('vendor/gallery/gallery.css'),
    );
    $registry = new FrontendResourceRegistry;
    $registry->register(new FrontendResourceGroupData('gallery', 'Gallery', 'capell-app/gallery', [$resource]));

    $registry->register(new FrontendResourceGroupData('carousel', 'Carousel', 'capell-app/gallery', [$resource]));
})->throws(InvalidArgumentException::class, 'handle is already registered');
