<?php

declare(strict_types=1);

use Capell\Core\Data\AssetData;
use Capell\Core\Enums\MediaConversionEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Page;

it('resolves asset presentation callbacks and counts active attachment usage', function (): void {
    $relatedPage = Page::factory()->create();
    $assetPage = Page::factory()->create();

    AssetAttachment::factory()
        ->related($relatedPage)
        ->create([
            'asset_type' => Page::class,
            'asset_id' => $assetPage->getKey(),
        ]);

    AssetAttachment::factory()->create([
        'asset_type' => 'page',
        'asset_id' => $assetPage->getKey(),
    ]);

    $asset = new AssetData(
        name: 'Page',
        model: Page::class,
        label: fn (): string => 'Related pages',
        icon: fn (): MediaConversionEnum => MediaConversionEnum::Thumbnail,
    );

    expect($asset->getKey())->toBe('page')
        ->and($asset->getLabel())->toBe('Related pages')
        ->and($asset->getIcon())->toBe(MediaConversionEnum::Thumbnail)
        ->and($asset->getActiveIcon())->toBe(MediaConversionEnum::Thumbnail)
        ->and($asset->getTitleKey())->toBe('name')
        ->and($asset->usages())->toBe(1);

    $explicit = new AssetData(
        name: 'Media',
        model: 'media',
        icon: 'heroicon-o-photo',
        activeIcon: fn (): string => 'heroicon-s-photo',
    );

    expect($explicit->getLabel())->toBe('Media')
        ->and($explicit->getIcon())->toBe('heroicon-o-photo')
        ->and($explicit->getActiveIcon())->toBe('heroicon-s-photo');
});
