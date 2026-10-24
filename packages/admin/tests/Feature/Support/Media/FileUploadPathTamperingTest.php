<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\ImageIconUpload;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Tests\Fixtures\Forms\NativeWidgetFileUpload;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Media\SpatieMediaFieldFactory;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

it('authorises owned UUIDs through native and legacy Spatie fields', function (bool $legacy, string $name): void {
    $record = Site::factory()->createOne()->fresh();
    $record->setRelation('media', new MediaCollection([
        new Media(['uuid' => 'owned-native-image', 'collection_name' => $name]),
    ]));
    $field = $legacy
        ? resolve(SpatieMediaFieldFactory::class)->make($name)
        : SpatieMediaLibraryFileUpload::make($name)->collection($name);
    Schema::make(Livewire::make())->record($record)->components([$field])->getFlatComponents();
    expect($field->shouldPreventFilePathTampering())->toBeTrue();
    expect(Validator::make(['image' => ['owned-native-image']], ['image' => $field->getValidationRules()])->passes())->toBeTrue();
    expect(Validator::make(['image' => ['foreign-image']], ['image' => $field->getValidationRules()])->fails())->toBeTrue();
})->with([false, true])->with(['image', 'extension.image']);

it('retains persisted repeater font paths independently of client item order', function (bool $reverse, bool $native): void {
    $fonts = [
        ['name' => 'First', 'files' => ['theme/fonts/first.woff2']],
        ['name' => 'Second', 'files' => ['theme/fonts/second.woff2']],
    ];
    $record = Theme::factory()->createOne(['meta' => ['fonts' => $fonts]])->fresh();
    $livewire = Livewire::make()->data(['meta' => ['fonts' => [
        'first-item' => $fonts[0], 'second-item' => $fonts[1],
    ]]]);
    if ($reverse) {
        $livewire->data['meta']['fonts'] = array_reverse($livewire->data['meta']['fonts'], true);
    }

    $repeater = Repeater::make('fonts')->schema([
        ($native ? FileUpload::make('files') : Capell\Admin\Filament\Components\Forms\FileUpload::make('files'))->multiple(),
    ]);
    Schema::make($livewire)->statePath('data')->record($record)->components([
        Group::make([$repeater])->statePath('meta'),
    ])->getFlatComponents();
    foreach ($repeater->getItems() as $item) {
        $field = $item->getComponent('files');
        expect($field)->toBeInstanceOf(BaseFileUpload::class);
        assert($field instanceof BaseFileUpload);
        $paths = $field->getRawState();
        expect($paths)->toBeArray()->not->toBeEmpty();
        assert(is_array($paths));
        $paths = array_values($paths);
        expect(Validator::make(['files' => $paths], ['files' => $field->getValidationRules()])->passes())->toBeTrue();
    }

    // Rebuild the item schema from forged Livewire data as on a subsequent request.
    $livewire->data['meta']['fonts']['first-item']['files'] = ['theme/fonts/foreign.woff2'];
    $repeater->clearCachedChildSchemas();
    $item = $repeater->getItems()['first-item'];
    $field = $item->getComponent('files');
    expect($field)->toBeInstanceOf(BaseFileUpload::class);
    assert($field instanceof BaseFileUpload);
    expect(Validator::make(['files' => ['theme/fonts/foreign.woff2']], ['files' => $field->getValidationRules()])->fails())->toBeTrue();

})->with([false, true])->with([false, true]);

it('retains nested native subclass uploads without losing widget state normalisation', function (): void {
    $record = Site::factory()->createOne(['meta' => ['icon' => 'site/existing.png']])->fresh();

    $livewire = Livewire::make()->data(['meta' => ['icon' => 'site/existing.png']]);
    $field = NativeWidgetFileUpload::make('icon');
    Schema::make($livewire)->statePath('data')->record($record)->components([
        Group::make([$field])->statePath('meta'),
    ])->getFlatComponents();

    expect(Validator::make(['icon' => array_values($field->getRawState())], ['icon' => $field->getValidationRules()])->passes())->toBeTrue();
    $field->saveUploadedFiles();
    expect(array_values($field->getRawState()))->toBe(['site/existing.png']);

    $livewire->data['meta']['icon'] = 'site/foreign.png';
    expect(fn (): array => $field->getValidationRules())->toThrow(ValidationException::class);

    $reads = [];
    $deletions = [];
    $field->getUploadedFileUsing(function (string $file) use (&$reads): array {
        $reads[] = $file;

        return ['name' => basename($file), 'size' => 0, 'type' => 'image/png', 'url' => '/files/' . $file];
    })->deleteUploadedFileUsing(function (string $file) use (&$deletions): void {
        $deletions[] = $file;
    });
    expect(fn (): ?array => $field->getUploadedFiles())->toThrow(ValidationException::class);
    $key = array_key_first($field->getRawState());
    expect(fn (): NativeWidgetFileUpload => $field->deleteUploadedFile($key))->toThrow(ValidationException::class);
    expect($reads)->toBe([])->and($deletions)->toBe([])
        ->and($record->fresh()->meta['icon'])->toBe('site/existing.png');

    $livewire->data['meta']['icon'] = 'site/existing.png';
    expect($field->getUploadedFiles())->toHaveCount(1);
    $field->deleteUploadedFile(array_key_first($field->getRawState()));
    expect($reads)->toBe(['site/existing.png'])->and($deletions)->toBe(['site/existing.png']);
});

it('retains builder widget paths through transient item keys and rejects forged state', function (bool $native): void {
    $blocks = [
        ['type' => 'image', 'data' => ['image' => 'widgets/first.png']],
        ['type' => 'image', 'data' => ['image' => 'widgets/second.png']],
    ];
    $record = Site::factory()->createOne(['meta' => ['widgets' => $blocks]])->fresh();
    $livewire = Livewire::make()->data(['meta' => ['widgets' => [
        'second-uuid' => $blocks[1], 'first-uuid' => $blocks[0],
    ]]]);
    $builder = Builder::make('widgets')->blocks([
        Block::make('image')->schema([
            $native ? NativeWidgetFileUpload::make('image') : FileUpload::make('image'),
        ]),
    ]);
    $schema = Schema::make($livewire)->statePath('data')->record($record)->components([
        Group::make([$builder])->statePath('meta'),
    ]);
    $schema->getFlatComponents();

    foreach ($builder->getItems() as $item) {
        $field = $item->getComponent('image');
        assert($field instanceof BaseFileUpload);
        if (! $native) {
            $field->state($field->getRawState());
        }

        $expected = array_values($field->getRawState());
        expect(Validator::make(['image' => array_values($field->getRawState())], ['image' => $field->getValidationRules()])->passes())->toBeTrue();
        $field->saveUploadedFiles();
        expect(array_values($field->getRawState()))->toBe($expected);
    }

    $state = $schema->getState();
    $record->update($state);
    // MySQL JSON columns reorder object keys, so compare structure rather than key order.
    expect($record->fresh()->meta['widgets'])->toEqual(array_reverse($blocks));

    // Filament dehydrates Builder item UUIDs into a list when saving.
    $livewire->data['meta']['widgets'] = ['first-uuid' => $blocks[0]];
    $livewire->data['meta']['widgets']['first-uuid']['data']['image'] = 'widgets/foreign.png';
    $builder->clearCachedChildSchemas();
    $field = $builder->getItems()['first-uuid']->getComponent('image');
    assert($field instanceof BaseFileUpload);
    if ($native) {
        expect(fn (): array => $field->getValidationRules())->toThrow(ValidationException::class);
    } else {
        expect(Validator::make(['image' => ['widgets/foreign.png']], ['image' => $field->getValidationRules()])->fails())->toBeTrue();
    }
})->with([false, true]);

it('guards empty native subclasses and media subclasses using their persisted data', function (bool $media): void {
    $record = Site::factory()->createOne(['meta' => ['image' => 'site/existing.png']])->fresh();
    $record->setRelation('media', new MediaCollection([
        new Media(['uuid' => 'owned', 'collection_name' => 'image']),
        new Media(['uuid' => 'foreign-collection', 'collection_name' => 'other']),
    ]));
    $field = $media
        ? (new class('image') extends SpatieMediaLibraryFileUpload {})::make('image')->collection('image')
        : (new class('image') extends FileUpload {})::make('image');
    $owned = $media ? 'owned' : 'site/existing.png';
    $livewire = Livewire::make()->data(['meta' => ['image' => ['item' => $owned]]]);
    Schema::make($livewire)->statePath('data')->record($record)->components([
        Group::make([$field])->statePath('meta'),
    ])->getFlatComponents();
    expect(Validator::make(['image' => [$owned]], ['image' => $field->getValidationRules()])->passes())->toBeTrue();
    foreach (['../../private.png', 'foreign-collection'] as $forged) {
        $livewire->data['meta']['image'] = ['item' => $forged];
        expect(fn (): array => $field->getValidationRules())->toThrow(ValidationException::class)
            ->and(fn (): ?array => $field->getUploadedFiles())->toThrow(ValidationException::class)
            ->and(fn (): string|TemporaryUploadedFile|null => $field->removeUploadedFile('item'))->toThrow(ValidationException::class);
    }
})->with([false, true]);

it('does not authorise a persisted path from another builder block type', function (): void {
    $record = Site::factory()->createOne(['meta' => ['widgets' => [
        ['type' => 'image', 'data' => ['image' => ['widgets/public.png']]],
        ['type' => 'private', 'data' => ['image' => ['widgets/private.png']]],
    ]]])->fresh();
    $livewire = Livewire::make()->data(['meta' => ['widgets' => [
        'new-uuid' => ['type' => 'image', 'data' => ['image' => ['widgets/private.png']]],
    ]]]);
    $builder = Builder::make('widgets')->blocks([Block::make('image')->schema([FileUpload::make('image')])]);
    Schema::make($livewire)->statePath('data')->record($record)->components([
        Group::make([$builder])->statePath('meta'),
    ])->getFlatComponents();
    $field = $builder->getItems()['new-uuid']->getComponent('image');
    assert($field instanceof BaseFileUpload);
    expect(Validator::make(['image' => ['widgets/private.png']], ['image' => $field->getValidationRules()])->fails())->toBeTrue();
});

it('accepts temporary uploads through a native subclass guard', function (): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/native-security-upload.txt', 'Uploaded content');
    $temporary = new TemporaryUploadedFile('native-security-upload.txt', 'local');
    $field = NativeWidgetFileUpload::make('file');
    $livewire = Livewire::make()->data(['file' => ['temporary' => $temporary]]);
    Schema::make($livewire)->statePath('data')->components([$field])->getFlatComponents();

    expect(Validator::make(['file' => [$temporary]], ['file' => $field->getValidationRules()])->passes())->toBeTrue();
    expect($field->getUploadedFiles())->toBe(['temporary' => null]);
});
