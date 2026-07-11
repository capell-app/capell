<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\ImageSourcePicker;
use Capell\Core\Contracts\Media\MediaFieldFactory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;

beforeEach(function (): void {
    app()->bind(MediaFieldFactory::class, static fn (): MediaFieldFactory => new class implements MediaFieldFactory
    {
        public function make(string $name): TextInput
        {
            return TextInput::make($name);
        }
    });
});

it('hides the source selector when one source is allowed', function (): void {
    $picker = ImageSourcePicker::make('image')
        ->allowedSources('url_only');

    $children = imageSourcePickerChildren($picker);

    expect($children[0] ?? null)->toBeInstanceOf(Hidden::class)
        ->and($children[1] ?? null)->toBeInstanceOf(TextInput::class);
});

it('shows a segmented selector when multiple sources are allowed', function (): void {
    $picker = ImageSourcePicker::make('image')
        ->allowedSources(['url', 'upload', 'media'])
        ->sourceStatePath('meta.image_source');

    $children = imageSourcePickerChildren($picker);
    $urlField = $children[1] ?? null;
    $uploadField = $children[2] ?? null;
    $mediaField = $children[3] ?? null;

    expect($children[0] ?? null)->toBeInstanceOf(ToggleButtons::class)
        ->and($children)->toHaveCount(4)
        ->and($urlField)->toBeInstanceOf(TextInput::class)
        ->and($urlField->getName())->toBe('meta.image_source.url')
        ->and($urlField->getVisibleJs())->toBe("\$get('meta.image_source.type') === 'url'")
        ->and($uploadField)->toBeInstanceOf(FileUpload::class)
        ->and($uploadField->getName())->toBe('meta.image_source.path')
        ->and($uploadField->getVisibleJs())->toBe("\$get('meta.image_source.type') === 'upload'")
        ->and($mediaField)->toBeInstanceOf(TextInput::class)
        ->and($mediaField->getName())->toBe('image')
        ->and($mediaField->getVisibleJs())->toBe("\$get('meta.image_source.type') === 'media'");
});

it('lets schema image source policy override blueprint policy', function (): void {
    $picker = ImageSourcePicker::make('image')
        ->imageSourcePolicy(schemaSources: 'url_only', blueprintSources: 'media_only');

    $children = imageSourcePickerChildren($picker);

    expect($children[0] ?? null)->toBeInstanceOf(Hidden::class)
        ->and($children[1] ?? null)->toBeInstanceOf(TextInput::class);
});

it('uses blueprint image source policy when schema does not lock the field', function (): void {
    $picker = ImageSourcePicker::make('image')
        ->imageSourcePolicy(blueprintSources: 'upload_only');

    $children = imageSourcePickerChildren($picker);

    expect($children[0] ?? null)->toBeInstanceOf(Hidden::class)
        ->and($children[2] ?? null)->toBeInstanceOf(FileUpload::class);
});

/**
 * @return array<int|string, mixed>
 */
function imageSourcePickerChildren(ImageSourcePicker $picker): array
{
    $reflection = new ReflectionClass($picker);

    while (! $reflection->hasProperty('childComponents')) {
        $parent = $reflection->getParentClass();

        if ($parent === false) {
            return [];
        }

        $reflection = $parent;
    }

    $property = $reflection->getProperty('childComponents');

    return $property->getValue($picker)['default'] ?? [];
}
