<?php

declare(strict_types=1);

use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Site;
use Capell\Tests\Support\LegacyAdapter;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('authorises owned UUIDs through legacy Spatie fields', function (string $name): void {
    $record = Site::factory()->createOne()->fresh();
    $record->setRelation('media', new MediaCollection([
        new Media(['uuid' => 'owned-native-image', 'collection_name' => $name]),
    ]));
    $field = LegacyAdapter::invoke('Capell\\Core\\Support\\Media\\SpatieMediaFieldFactory', 'make', $name);
    throw_unless($field instanceof SpatieMediaLibraryFileUpload, RuntimeException::class, 'The media factory did not return a Spatie upload field.');
    Schema::make(Livewire::make())->record($record)->components([$field])->getFlatComponents();
    expect($field->shouldPreventFilePathTampering())->toBeTrue();
    expect(Validator::make(['image' => ['owned-native-image']], ['image' => $field->getValidationRules()])->passes())->toBeTrue();
    expect(Validator::make(['image' => ['foreign-image']], ['image' => $field->getValidationRules()])->fails())->toBeTrue();
})->with(['image', 'extension.image']);
