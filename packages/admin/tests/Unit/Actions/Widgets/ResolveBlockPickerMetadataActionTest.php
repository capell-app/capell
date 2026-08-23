<?php

declare(strict_types=1);

use Capell\Admin\Actions\Widgets\ResolveBlockPickerMetadataAction;
use Capell\Admin\Contracts\Widgets\BlockPickerMetadataProvider;
use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;
use Capell\Admin\Tests\Fixtures\Widgets\FirstStubBlockPickerMetadataProvider;
use Capell\Admin\Tests\Fixtures\Widgets\InvalidKeyBlockPickerMetadataProvider;
use Capell\Admin\Tests\Fixtures\Widgets\SecondStubBlockPickerMetadataProvider;

it('returns no metadata when no provider is tagged', function (): void {
    expect(ResolveBlockPickerMetadataAction::run())->toBe([]);
});

it('resolves metadata contributed by a single tagged provider', function (): void {
    app()->tag([FirstStubBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    $metadata = ResolveBlockPickerMetadataAction::run();

    expect($metadata)->toHaveKey('hero')
        ->and($metadata['hero'])->toBeInstanceOf(BlockPickerItemMetadataData::class)
        ->and($metadata['hero']->label)->toBe('Hero')
        ->and($metadata['hero']->category)->toBe('Foundation')
        ->and($metadata['hero']->searchTerms)->toBe(['banner', 'intro']);
});

it('merges metadata from multiple tagged providers contributing different blocks', function (): void {
    app()->tag([FirstStubBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);
    app()->tag([SecondStubBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    $metadata = ResolveBlockPickerMetadataAction::run();

    expect($metadata)->toHaveKeys(['hero', 'testimonial'])
        ->and($metadata['testimonial']->label)->toBe('Testimonial');
});

it('keeps the first contribution and ignores a duplicate contribution for the same block name', function (): void {
    app()->tag([FirstStubBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);
    app()->tag([SecondStubBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    $metadata = ResolveBlockPickerMetadataAction::run();

    expect($metadata['hero']->label)->toBe('Hero')
        ->and($metadata['hero']->category)->toBe('Foundation')
        ->and($metadata['hero']->label)->not->toBe('Hero (duplicate)');
});

it('ignores a contribution keyed by an empty block name', function (): void {
    app()->tag([InvalidKeyBlockPickerMetadataProvider::class], BlockPickerMetadataProvider::TAG);

    expect(ResolveBlockPickerMetadataAction::run())->toBe([]);
});
