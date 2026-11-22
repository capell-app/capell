<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Admin\Filament\Components\Forms\ImageSourcePicker;
use Capell\Admin\Tests\Fixtures\ImageSourceUploadForm;
use Capell\Core\Settings\CoreSettings;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    app()->bind(AdminMediaFieldFactory::class, static fn (): AdminMediaFieldFactory => new class implements AdminMediaFieldFactory
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
        ->defaultSource('media')
        ->sourceStatePath('meta.image_source');

    $children = imageSourcePickerChildren($picker);
    $urlField = $children[1] ?? null;
    $uploadField = $children[2] ?? null;
    $mediaField = $children[3] ?? null;

    expect($children[0] ?? null)->toBeInstanceOf(ToggleButtons::class)
        ->and($children[0]->getOptions())->toBe([
            'media' => __('capell-admin::form.image_source_option_media'),
            'upload' => __('capell-admin::form.image_source_option_upload'),
            'url' => __('capell-admin::form.image_source_option_url'),
        ])
        ->and($children)->toHaveCount(4)
        ->and($urlField)->toBeInstanceOf(TextInput::class)
        ->and($urlField->getName())->toBe('meta.image_source.url')
        ->and($urlField->getVisibleJs())->toBe("(\$get('meta.image_source.type') ?? 'media') === 'url'")
        ->and($uploadField)->toBeInstanceOf(FileUpload::class)
        ->and($uploadField->getName())->toBe('meta.image_source.path')
        ->and($uploadField->getVisibleJs())->toBe("(\$get('meta.image_source.type') ?? 'media') === 'upload'")
        ->and($mediaField)->toBeInstanceOf(TextInput::class)
        ->and($mediaField->getName())->toBe('image')
        ->and($mediaField->getVisibleJs())->toBe("(\$get('meta.image_source.type') ?? 'media') === 'media'");
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

it('uses a compatible global default and safely falls back for legacy or field restrictions', function (string $policy, string $default, string $fieldPolicy, string $expected): void {
    $settings = resolve(CoreSettings::class);
    $settings->allowed_image_sources = $policy;
    $settings->default_image_source = $default;
    $settings->save();

    $picker = ImageSourcePicker::make('image')->allowedSources($fieldPolicy);
    $children = imageSourcePickerChildren($picker);
    expect($children[0]->getDefaultState())->toBe($expected);
})->with([
    ['all', 'upload', 'all', 'upload'],
    ['upload_only', 'media', 'upload_only', 'upload'],
    ['url_media', 'media', 'url_only', 'url'],
]);

it('refuses a scripted SVG in the image upload source and accepts a static one', function (): void {
    Storage::fake('public');

    Livewire::test(ImageSourceUploadForm::class)
        ->set('data.meta.image_source.path', [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("/admin/users")</script></svg>')])
        ->call('save')
        ->assertHasErrors(['data.meta.image_source.path']);

    expect(Storage::disk('public')->allFiles())->toBe([]);

    Livewire::test(ImageSourceUploadForm::class)
        ->set('data.meta.image_source.path', [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>')])
        ->call('save')
        ->assertHasNoErrors();

    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});
