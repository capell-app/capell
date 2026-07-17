<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Theme\AssetsRepeater;
use Capell\Admin\Filament\Components\Forms\Theme\AssetsSchema;
use Capell\Admin\Filament\Components\Forms\Theme\ColorsRepeater;
use Capell\Admin\Filament\Components\Forms\Theme\ColorsSchema;
use Capell\Admin\Filament\Components\Forms\Theme\FontsSchema;
use Capell\Admin\Filament\Components\Forms\Theme\FooterFieldset;
use Capell\Admin\Filament\Components\Forms\Theme\HeaderFieldset;
use Capell\Admin\Filament\Components\Forms\Theme\MainFieldset;
use Capell\Admin\Support\Themes\ThemeFontUploadPolicy;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Enums\DefaultColorEnum;
use Capell\Core\Enums\FontStyleEnum;
use Capell\Core\Enums\FontTypeEnum;
use Capell\Core\Enums\FontWeightEnum;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\In;

it('builds header and footer chrome fieldsets from the registered theme components', function (): void {
    $registry = resolve(ThemeChromeRegistry::class);
    $registry->registerHeader('vendor-theme::header', 'Vendor header');
    $registry->registerFooter('vendor-theme::footer', 'Vendor footer');

    $components = mountedThemeFormComponents([
        HeaderFieldset::make('header_options'),
        FooterFieldset::make('footer_options'),
    ]);

    $header = $components[0];
    $footer = $components[1];
    $headerFile = themeFormComponentByName($components, 'header_file');
    $footerFile = themeFormComponentByName($components, 'footer_file');
    assert($header instanceof Fieldset);
    assert($footer instanceof Fieldset);
    assert($headerFile instanceof Select);
    assert($footerFile instanceof Select);

    expect($header)->toBeInstanceOf(Fieldset::class)
        ->and($header->getLabel())->toBe(__('capell-admin::form.header'))
        ->and($footer)->toBeInstanceOf(Fieldset::class)
        ->and($footer->getLabel())->toBe(__('capell-admin::form.footer'))
        ->and(themeFormComponentByName($components, 'header'))->toBeInstanceOf(Checkbox::class)
        ->and(themeFormComponentByName($components, 'header_background_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'header_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'header_position'))->toBeInstanceOf(Select::class)
        ->and(themeFormComponentByName($components, 'header_menu_alignment'))->toBeInstanceOf(Select::class)
        ->and(themeFormComponentByName($components, 'header_height'))->toBeInstanceOf(TextInput::class)
        ->and(themeFormComponentByName($components, 'footer'))->toBeInstanceOf(Checkbox::class)
        ->and(themeFormComponentByName($components, 'footer_background_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'footer_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'footer_spacing'))->toBeInstanceOf(Select::class)
        ->and($headerFile)->toBeInstanceOf(Select::class)
        ->and($headerFile->getOptions())->toBe($registry->headerOptions())
        ->and(collect($headerFile->getValidationRules())->contains(fn (mixed $rule): bool => $rule instanceof In))->toBeTrue()
        ->and($footerFile)->toBeInstanceOf(Select::class)
        ->and($footerFile->getOptions())->toBe($registry->footerOptions())
        ->and(collect($footerFile->getValidationRules())->contains(fn (mixed $rule): bool => $rule instanceof In))->toBeTrue();
});

it('builds the font controls used by the theme editor', function (): void {
    $components = mountedThemeFormComponents(FontsSchema::make(), [
        'fonts' => [
            [
                'type' => FontTypeEnum::Url->value,
                'name' => 'Inter',
                'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap',
            ],
            [
                'type' => FontTypeEnum::Local->value,
                'name' => 'Capell Sans',
                'weight' => FontWeightEnum::Bold,
                'style' => FontStyleEnum::Italic,
                'files' => ['capell-sans.woff2'],
            ],
        ],
    ]);

    $fonts = $components[0];
    $fontFamily = themeFormComponentByName($components, 'font_family');
    $fontHeadingFamily = themeFormComponentByName($components, 'font_heading_family');
    $fontFiles = themeFormComponentByName($components, 'files');
    assert($fonts instanceof Repeater);
    assert($fontFamily instanceof Select);
    assert($fontHeadingFamily instanceof Select);
    assert($fontFiles instanceof FileUpload);

    expect($fonts)->toBeInstanceOf(Repeater::class)
        ->and($fonts->getLabel())->toBe(__('capell-admin::form.fonts'))
        ->and(themeFormComponentByName($components, 'url'))->toBeInstanceOf(TextInput::class)
        ->and($fontFiles)->toBeInstanceOf(FileUpload::class)
        ->and($fontFiles->getAcceptedFileTypes())->toBe(ThemeFontUploadPolicy::acceptedFileTypes())
        ->and(themeFormComponentByName($components, 'name'))->toBeInstanceOf(TextInput::class)
        ->and($fontFamily)->toBeInstanceOf(Select::class)
        ->and($fontFamily->getOptions())->toBe([
            'Capell Sans' => 'Capell Sans',
            'Inter' => 'Inter',
        ])
        ->and($fontHeadingFamily)->toBeInstanceOf(Select::class)
        ->and($fontHeadingFamily->getOptions())->toBe([
            'Capell Sans' => 'Capell Sans',
            'Inter' => 'Inter',
        ]);
});

it('updates theme font state through mounted repeater interactions', function (): void {
    $livewire = Livewire::make()->data([
        'fonts' => [
            'inter-row' => [
                'type' => FontTypeEnum::Url->value,
                'name' => 'Inter',
                'url' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400&display=swap',
            ],
            'capell-row' => [
                'type' => FontTypeEnum::Local->value,
                'name' => 'Capell Sans',
                'weight' => FontWeightEnum::Bold->value,
                'style' => FontStyleEnum::Italic->value,
                'files' => ['capell-sans.woff2'],
            ],
        ],
        'font_family' => 'Inter',
        'font_heading_family' => 'Capell Sans',
    ]);

    $components = mountedThemeFormComponentsForLivewire(FontsSchema::make(), $livewire);
    $fonts = themeFormComponentByName($components, 'fonts');

    assert($fonts instanceof Repeater);

    $childSchema = $fonts->getChildSchema('inter-row');
    assert($childSchema instanceof Schema);

    $url = themeFormComponentByName(array_filter(
        $childSchema->getComponents(),
        fn (mixed $component): bool => $component instanceof Component,
    ), 'url');
    assert($url instanceof TextInput);

    $fontNames = themeFormComponentByName($components, 'font_family');
    assert($fontNames instanceof Select);

    expect($fonts->getItemLabel('inter-row'))->toBe('Inter')
        ->and($fonts->getItemLabel('capell-row'))->toBe('Capell Sans - italic - bold')
        ->and($fontNames->getOptions())->toBe([
            'Capell Sans' => 'Capell Sans',
            'Inter' => 'Inter',
        ]);

    data_set($livewire->data, 'fonts.inter-row.name', null);
    $url->state('https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@700&display=swap');
    $url->callAfterStateUpdated(false);

    expect(data_get($livewire->data, 'fonts.inter-row.name'))->toBe('Roboto+Slab');

    $fonts->state([]);
    $fonts->callAfterStateUpdated(false);

    expect($livewire->data['font_family'])->toBeNull()
        ->and($livewire->data['font_heading_family'])->toBeNull();
});

it('builds main colour, shared colour, and asset controls for the theme editor', function (): void {
    $components = mountedThemeFormComponents([
        MainFieldset::make('main_options'),
        ...ColorsSchema::make(),
        ...AssetsSchema::make(),
    ]);

    $mainFieldset = $components[0];
    $colorGrid = $components[1];
    $colorSection = $components[2];
    $assetGroup = $components[3];

    assert($mainFieldset instanceof Fieldset);
    assert($colorGrid instanceof Grid);
    assert($colorSection instanceof Section);
    assert($assetGroup instanceof Group);

    expect($mainFieldset->getLabel())->toBe(__('capell-admin::form.main'))
        ->and(themeFormComponentByName($components, 'main_background_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'main_dark_background_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'link_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'link_color_active'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'divider_color'))->toBeInstanceOf(ColorPicker::class)
        ->and(themeFormComponentByName($components, 'colors'))->toBeInstanceOf(ColorsRepeater::class)
        ->and(themeFormComponentByName($components, 'assets_path'))->toBeInstanceOf(TextInput::class)
        ->and(themeFormComponentByName($components, 'critical_asset'))->toBeInstanceOf(TextInput::class)
        ->and(themeFormComponentByName($components, 'assets'))->toBeInstanceOf(Repeater::class);
});

it('guides fallback theme asset file entries', function (): void {
    $components = mountedThemeFormComponents([
        AssetsRepeater::make(),
    ]);

    $assetFile = themeFormComponentByName($components, 'file');

    assert($assetFile instanceof TextInput);

    $helperText = $assetFile->getChildSchema(TextInput::BELOW_CONTENT_SCHEMA_KEY)?->getComponents()[0] ?? null;

    assert($helperText instanceof Text);

    expect($helperText->getContent())->toBe(__('capell-admin::generic.theme_asset_file_info'));
});

it('normalises theme color state and exposes missing default colour repair', function (): void {
    $components = mountedThemeFormComponents([
        ColorsRepeater::make(),
    ]);

    $colors = $components[0];
    assert($colors instanceof ColorsRepeater);

    $partialState = [
        'primary-row' => [
            'name' => DefaultColorEnum::Primary,
            'color' => '#123456',
        ],
    ];
    $colors->state($partialState);

    $repairAction = collect($colors->getHintActions())
        ->first(fn (object $action): bool => filamentObjectName($action) === 'defaultColors');
    $repairAction = expectPresent($repairAction);

    expect(ColorsRepeater::getDefaultName())->toBe('colors')
        ->and(array_values($colors->getDefaultState()))->toBe(DefaultColorEnum::getValues())
        ->and($colors->getItemLabel('primary-row'))->toBe(DefaultColorEnum::Primary->value)
        ->and($colors->mutateDehydratedState($partialState))->toBe([
            DefaultColorEnum::Primary->value => '#123456',
        ])
        ->and($repairAction)->not->toBeNull()
        ->and($repairAction->isVisible())->toBeTrue();
});

/**
 * @param  array<int, Component>  $components
 * @param  array<string, mixed>  $state
 * @return array<int, Component>
 */
function mountedThemeFormComponents(array $components, array $state = []): array
{
    $livewire = Livewire::make()->data($state);

    return mountedThemeFormComponentsForLivewire($components, $livewire);
}

/**
 * @param  array<int, Component>  $components
 * @return array<int, Component>
 */
function mountedThemeFormComponentsForLivewire(array $components, Livewire $livewire): array
{
    $components = Schema::make($livewire)
        ->statePath('data')
        ->components($components)
        ->getComponents();

    return array_values(array_filter(
        $components,
        fn (mixed $component): bool => $component instanceof Component,
    ));
}

/**
 * @param  array<int, Component>  $components
 */
function themeFormComponentByName(array $components, string $name): Component
{
    $component = themeFormFlattenComponents($components)
        ->first(fn (Component $component): bool => method_exists($component, 'getName') && $component->getName() === $name);

    if (! $component instanceof Component) {
        throw new RuntimeException(sprintf('Component [%s] was not found in the theme schema.', $name));
    }

    return $component;
}

/**
 * @param  array<int, Component>  $components
 * @return Collection<int, Component>
 */
function themeFormFlattenComponents(array $components): Collection
{
    return collect($components)->flatMap(function (Component $component): array {
        $children = array_filter(
            $component->getChildSchema()?->getComponents() ?? [],
            fn (mixed $child): bool => $child instanceof Component,
        );

        return [
            $component,
            ...themeFormFlattenComponents(array_values($children))->all(),
        ];
    })->values();
}
