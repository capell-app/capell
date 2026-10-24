<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\ImageIconUpload;
use Capell\Admin\Filament\Components\Forms\MediaLibraryFileUpload;
use Capell\Admin\Tests\Fixtures\Forms\NativeWidgetFileUpload;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Media\SpatieMediaFieldFactory;
use Capell\Tests\Fixtures\Models\User;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

it('retries stored uploads after a later validation failure without trusting forged state', function (bool $native, bool $halt, int $transactions): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/retry-upload.txt', 'Uploaded content');
    $temporary = new TemporaryUploadedFile('retry-upload.txt', 'local');
    $record = Site::factory()->createOne(['meta' => ['icon' => 'site/existing.txt']])->fresh();
    $livewire = Livewire::make()->data(['meta' => ['icon' => ['temporary' => $temporary]]]);
    $livewire->setId('upload-retry-form');

    $makeSchema = function () use ($native, $record, &$livewire): Schema {
        $field = ($native ? NativeWidgetFileUpload::make('icon') : Capell\Admin\Filament\Components\Forms\FileUpload::make('icon'))
            ->disk('local')->directory('site');

        return Schema::make($livewire)->statePath('data')->record($record)->components([
            Group::make([$field])->statePath('meta'),
        ]);
    };
    $schema = $makeSchema();
    for ($level = 0; $level < $transactions; $level++) {
        DB::beginTransaction();
    }

    expect(fn (): array => $schema->getState(afterValidate: function () use ($halt): void {
        throw_if($halt, Halt::class);

        throw ValidationException::withMessages(['title' => 'Correct the title.']);
    }))->toThrow($halt ? Halt::class : ValidationException::class);
    $stored = array_values($livewire->data['meta']['icon'])[0];
    expect($stored)->toBeString()
        ->and($temporary->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($stored))->toBeTrue()
        ->and($record->fresh()->meta['icon'])->toBe('site/existing.txt');

    for ($level = 0; $level < $transactions; $level++) {
        DB::rollBack();
    }

    // Recreate the component and field as on the corrected Livewire request.
    $livewire = Livewire::make()->data($livewire->data);
    $livewire->setId('upload-retry-form');

    $state = $makeSchema()->getState();
    $record->update($state);
    expect($record->fresh()->meta['icon'])->toBe($stored);

    $livewire->data['meta']['icon'] = ['forged' => 'site/foreign.txt'];
    expect(fn (): array => $makeSchema()->getState())->toThrow(ValidationException::class);
})->with([false, true])->with([false, true])->with([0, 1, 2]);

it('limits failed-save upload ownership to its form scope and existing unexpired files', function (string $boundary, bool $native): void {
    Storage::fake('local');
    Storage::fake('other');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/scoped-upload.txt', 'Uploaded content');
    $record = Site::factory()->createOne()->fresh();
    $livewire = Livewire::make()->data(['icon' => ['temporary' => new TemporaryUploadedFile('scoped-upload.txt', 'local')]]);
    $livewire->setId('scoped-upload-form');

    $field = ($native ? NativeWidgetFileUpload::make('icon') : Capell\Admin\Filament\Components\Forms\FileUpload::make('icon'))->disk('local');
    $schema = Schema::make($livewire)->statePath('data')->record($record)->components([$field]);
    expect(fn (): array => $schema->getState(afterValidate: function (): void {
        throw ValidationException::withMessages(['title' => 'Correct the title.']);
    }))->toThrow(ValidationException::class);
    $stored = array_values($livewire->data['icon'])[0];
    expect($stored)->toBeString();
    $disk = 'local';
    $name = 'icon';
    switch ($boundary) {
        case 'form':
            $livewire->setId('other-form');
            break;
        case 'record':
            $record = Site::factory()->createOne()->fresh();
            break;
        case 'field':
            $name = 'other';
            $livewire->data[$name] = $livewire->data['icon'];
            break;
        case 'disk':
            $disk = 'other';
            Storage::disk($disk)->put($stored, 'Foreign content');
            break;
        case 'actor':
            test()->actingAs(User::factory()->createOne());
            break;
        case 'session':
            session()->flush();
            break;
        case 'expired':
            $this->travel((int) config('session.lifetime') + 1)->minutes();
            break;
        case 'deleted':
            Storage::disk('local')->delete($stored);
            break;
    }

    $field = ($native ? NativeWidgetFileUpload::make($name) : Capell\Admin\Filament\Components\Forms\FileUpload::make($name))->disk($disk);
    $schema = Schema::make($livewire)->statePath('data')->record($record)->components([$field]);
    expect(fn (): array => $schema->getState())->toThrow(ValidationException::class);
})->with(['form', 'record', 'field', 'disk', 'actor', 'session', 'expired', 'deleted'])->with([false, true]);

it('retains upload ownership when a state-update hook fails after storage', function (bool $native): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/hook-upload.txt', 'Uploaded content');
    $record = Site::factory()->createOne()->fresh();
    $livewire = Livewire::make()->data(['icon' => ['temporary' => new TemporaryUploadedFile('hook-upload.txt', 'local')]]);
    $field = ($native ? NativeWidgetFileUpload::make('icon') : Capell\Admin\Filament\Components\Forms\FileUpload::make('icon'))->disk('local');
    $field->afterStateUpdated(function (): void {
        throw ValidationException::withMessages(['title' => 'Correct the title.']);
    });
    $schema = Schema::make($livewire)->statePath('data')->record($record)->components([$field]);
    expect(fn (): array => $schema->getState())->toThrow(ValidationException::class);
    $stored = array_values($livewire->data['icon'])[0];
    expect($stored)->toBeString();
    $field->clearAfterStateUpdatedHooks();
    expect($schema->getState()['icon'])->toBe($stored);
})->with([false, true]);

it('retains numeric stored filenames across a failed save', function (bool $native): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/numeric-upload.txt', 'Uploaded content');
    $livewire = Livewire::make()->data(['icon' => ['temporary' => new TemporaryUploadedFile('numeric-upload.txt', 'local')]]);
    $field = ($native ? NativeWidgetFileUpload::make('icon') : Capell\Admin\Filament\Components\Forms\FileUpload::make('icon'))
        ->disk('local')->getUploadedFileNameForStorageUsing(fn (): string => '123');
    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    expect(fn (): array => $schema->getState(afterValidate: function (): void {
        throw ValidationException::withMessages(['title' => 'Correct the title.']);
    }))->toThrow(ValidationException::class);
    expect($schema->getState()['icon'])->toBe('123');
})->with([false, true]);

it('binds failed-save uploads to every enclosing builder block type', function (bool $native, string $changed, string $boundary): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/document.txt', 'A document, not an image');
    $temporary = new TemporaryUploadedFile('document.txt', 'local');
    $livewire = Livewire::make()->data(['widgets' => ['outer-uuid' => [
        'type' => 'section', 'data' => ['children' => ['same-uuid' => [
            'type' => 'document', 'data' => ['file' => ['temporary' => $temporary]],
        ]]],
    ]]]);
    $livewire->setId('builder-retry-form');

    $makeSchema = function () use (&$livewire, $native, $boundary): Schema {
        $makeChildren = function () use ($native, $boundary): Builder {
            $makeFile = function (bool $image = false) use ($native, $boundary): BaseFileUpload|Group {
                $file = ($native ? NativeWidgetFileUpload::make('file') : Capell\Admin\Filament\Components\Forms\FileUpload::make('file'))
                    ->disk('local')->directory('docs');
                if ($image) {
                    $file->image();
                }

                return match ($boundary) {
                    'upload-array' => $file->model([]),
                    'group-array' => Group::make([$file])->model([]),
                    'group-record' => Group::make([$file])->model(new Site),
                    default => $file,
                };
            };

            return Builder::make('children')->blocks([
                Block::make('document')->schema([$makeFile()]),
                Block::make('image')->schema([$makeFile(true)]),
            ]);
        };

        return Schema::make($livewire)->statePath('data')->components([
            Builder::make('widgets')->blocks([
                Block::make('section')->schema([$makeChildren()]),
                Block::make('other')->schema([$makeChildren()]),
            ]),
        ]);
    };
    expect(fn (): array => $makeSchema()->getState(afterValidate: function (): void {
        throw new Halt;
    }))->toThrow(Halt::class);
    $stored = array_values($livewire->data['widgets']['outer-uuid']['data']['children']['same-uuid']['data']['file'])[0];
    expect($stored)->toBeString()
        ->and($temporary->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($stored))->toBeTrue();

    $livewire = Livewire::make()->data($livewire->data);
    $livewire->setId('builder-retry-form');
    // An unchanged nested type chain can retry the stored upload.
    expect($makeSchema()->getState()['widgets'][0]['data']['children'][0]['data']['file'])->toBe($stored);

    // Keep both UUIDs and paths, changing only a submitted enclosing type.
    if ($changed === 'inner') {
        $livewire->data['widgets']['outer-uuid']['data']['children']['same-uuid']['type'] = 'image';
    } else {
        $livewire->data['widgets']['outer-uuid']['type'] = 'other';
    }

    expect(fn (): array => $makeSchema()->getState())->toThrow(ValidationException::class);
})->with([false, true])->with(['inner', 'outer'])->with(['none', 'upload-array', 'group-array', 'group-record']);

it('binds retry grants to enclosing and local records across model boundaries', function (bool $native, string $boundary, string $changed): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/boundary.txt', 'Upload');
    $record = Site::factory()->createOne();
    $localRecord = Site::factory()->createOne();
    $livewire = Livewire::make()->data(['icon' => ['tmp' => new TemporaryUploadedFile('boundary.txt', 'local')]]);
    $livewire->setId('record-boundary');

    $makeSchema = function () use ($native, $boundary, &$record, &$localRecord, $livewire): Schema {
        $field = ($native ? NativeWidgetFileUpload::make('icon') : Capell\Admin\Filament\Components\Forms\FileUpload::make('icon'))->disk('local');
        $component = match ($boundary) {
            'upload-array' => $field->model([]),
            'group-array' => Group::make([$field])->model([]),
            'changed-model' => Group::make([$field])->model($localRecord),
            default => throw new InvalidArgumentException('Unknown model boundary.'),
        };

        return Schema::make($livewire)->statePath('data')->record($record)->components([$component]);
    };
    expect(fn (): array => $makeSchema()->getState(afterValidate: function (): void {
        throw ValidationException::withMessages(['title' => 'Failed save']);
    }))->toThrow(ValidationException::class);
    $stored = array_values($livewire->data['icon'])[0];
    expect($makeSchema()->getState()['icon'])->toBe($stored);
    if ($changed === 'enclosing') {
        $record = Site::factory()->createOne();
    } else {
        $localRecord = Site::factory()->createOne();
    }

    expect(fn (): array => $makeSchema()->getState())->toThrow(ValidationException::class);
})->with([false, true])->with([
    ['upload-array', 'enclosing'], ['group-array', 'enclosing'],
    ['changed-model', 'enclosing'], ['changed-model', 'local'],
]);

it('revokes a deleted upload before its path can be recreated and replayed', function (bool $native, bool $throwAfterDeletion, bool $rollback): void {
    Storage::fake('local');
    config()->set('livewire.temporary_file_upload.disk', 'local');
    Storage::disk('local')->put('livewire-tmp/delete.txt', 'Upload');
    $livewire = Livewire::make()->data(['icon' => ['tmp' => new TemporaryUploadedFile('delete.txt', 'local')]]);
    $field = ($native ? NativeWidgetFileUpload::make('icon') : Capell\Admin\Filament\Components\Forms\FileUpload::make('icon'))
        ->disk('local')->deleteUploadedFileUsing(function (string $file) use ($throwAfterDeletion): void {
            Storage::disk('local')->delete($file);
            throw_if($throwAfterDeletion, RuntimeException::class, 'After deletion');
        });
    $schema = Schema::make($livewire)->statePath('data')->components([$field]);
    expect(fn (): array => $schema->getState(afterValidate: function (): void {
        throw ValidationException::withMessages(['title' => 'Failed save']);
    }))->toThrow(ValidationException::class);
    $stored = array_values($livewire->data['icon'])[0];
    if ($rollback) {
        DB::beginTransaction();
    }

    if ($throwAfterDeletion) {
        expect(fn (): BaseFileUpload => $field->deleteUploadedFile(array_key_first($field->getRawState())))->toThrow(RuntimeException::class, 'After deletion');
    } else {
        $field->deleteUploadedFile(array_key_first($field->getRawState()));
    }

    if ($rollback) {
        DB::rollBack();
    }

    expect(Storage::disk('local')->exists($stored))->toBeFalse();
    Storage::disk('local')->put($stored, 'Replacement');
    $livewire->data['icon'] = ['replay' => $stored];
    expect(fn (): array => $schema->getState())->toThrow(ValidationException::class);
})->with([false, true])->with([false, true])->with([false, true]);
