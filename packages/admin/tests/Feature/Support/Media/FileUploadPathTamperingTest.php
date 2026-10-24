<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\ImageIconUpload;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Site;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

it('rejects client supplied paths in every admin upload adapter', function (bool $media): void {
    $field = $media ? MediaLibraryFileUpload::make('image') : FileUpload::make('icon');
    Schema::make(Livewire::make())->components([$field])->getFlatComponents();
    $validator = Validator::make([$field->getName() => ['../../private/secret.jpg']], [$field->getName() => $field->getValidationRules()]);
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has($field->getName()))->toBeTrue();
})->with([false, true]);

it('retains existing media UUIDs only for the current record and collection', function (): void {
    $record = Site::factory()->createOne();
    $owned = new Media(['uuid' => 'owned-image', 'collection_name' => 'image']);
    $otherCollection = new Media(['uuid' => 'other-collection', 'collection_name' => 'logo']);
    $record->setRelation('media', new MediaCollection([$owned, $otherCollection]));
    $field = MediaLibraryFileUpload::make('image');
    Schema::make(Livewire::make())->record($record)->components([$field])->getFlatComponents();

    foreach (['owned-image' => false, 'other-collection' => true, 'foreign-record' => true, '../../secret.jpg' => true] as $uuid => $fails) {
        $validator = Validator::make(['image' => [$uuid]], ['image' => $field->getValidationRules()]);
        expect($validator->fails())->toBe($fails);
    }
});

it('retains a regular uploads original stored path', function (): void {
    $record = new Site;
    $record->setRawAttributes(['icon' => 'site-icons/existing.png'], true);

    $field = FileUpload::make('icon');
    Schema::make(Livewire::make())->record($record)->components([$field])->getFlatComponents();
    expect(Validator::make(['icon' => ['site-icons/existing.png']], ['icon' => $field->getValidationRules()])->passes())->toBeTrue();
});

it('retains nested site icon paths from the record and rejects replacement paths', function (): void {
    $record = Site::factory()->createOne(['meta' => ['icon' => 'site/existing.png']])->fresh();
    $field = ImageIconUpload::make('icon');
    Schema::make(Livewire::make())->statePath('data')->record($record)->components([
        Group::make([$field])->statePath('meta'),
    ])->getFlatComponents();
    foreach (['site/existing.png' => false, '../../secret.png' => true, 'site/foreign.png' => true] as $file => $fails) {
        expect(Validator::make(['icon' => [$file]], ['icon' => $field->getValidationRules()])->fails())->toBe($fails);
    }
});

it('accepts a real temporary upload through both adapters', function (bool $media): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/security-upload.txt', 'Uploaded content');
    $temporary = new TemporaryUploadedFile('security-upload.txt', 'local');
    $field = $media ? MediaLibraryFileUpload::make('image') : FileUpload::make('icon');
    Schema::make(Livewire::make())->components([$field])->getFlatComponents();
    expect(Validator::make([$field->getName() => [$temporary]], [$field->getName() => $field->getValidationRules()])->passes())->toBeTrue();
})->with([false, true]);

it('retains UUIDs for nested media collection fields on persisted records', function (): void {
    $record = Site::factory()->createOne()->fresh();
    $record->setRelation('media', new MediaCollection([
        new Media(['uuid' => 'owned-nested-image', 'collection_name' => 'admin.editor.image']),
    ]));
    $field = MediaLibraryFileUpload::make('admin.editor.image');
    Schema::make(Livewire::make())->record($record)->components([$field])->getFlatComponents();
    expect(Validator::make(['upload' => ['owned-nested-image']], ['upload' => $field->getValidationRules()])->passes())->toBeTrue();
    expect(Validator::make(['upload' => ['../../secret.jpg']], ['upload' => $field->getValidationRules()])->fails())->toBeTrue();
});

it('limits retained UUIDs to the visible media subset and field multiplicity', function (): void {
    $record = Site::factory()->createOne()->fresh();
    $record->setRelation('media', new MediaCollection([
        new Media(['uuid' => 'hidden', 'collection_name' => 'image', 'order_column' => 0]),
        new Media(['uuid' => 'first', 'collection_name' => 'image', 'order_column' => 1]),
        new Media(['uuid' => 'second', 'collection_name' => 'image', 'order_column' => 2]),
    ]));
    /** @var SpatieMediaLibraryFileUpload $field */
    $field = MediaLibraryFileUpload::make('image');
    $field->multiple()
        ->filterMediaUsing(fn (Collection $media) => $media->reject(fn (Media $item): bool => $item->uuid === 'hidden'));
    Schema::make(Livewire::make())->record($record)->components([$field])->getFlatComponents();
    expect(Validator::make(['image' => ['first', 'second']], ['image' => $field->getValidationRules()])->passes())->toBeTrue();
    expect(Validator::make(['image' => ['hidden']], ['image' => $field->getValidationRules()])->fails())->toBeTrue();

    $field->multiple(false);
    expect(Validator::make(['image' => ['first']], ['image' => $field->getValidationRules()])->passes())->toBeTrue();
    expect(Validator::make(['image' => ['second']], ['image' => $field->getValidationRules()])->fails())->toBeTrue();
});
