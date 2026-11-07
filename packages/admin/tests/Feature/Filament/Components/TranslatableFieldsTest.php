<?php

declare(strict_types=1);

use Capell\Admin\Enums\TranslationTabsOrientation;
use Capell\Admin\Filament\Components\Forms\RepeaterTabs;
use Capell\Admin\Filament\Components\Forms\TranslatableFields;
use Capell\Admin\Tests\Fixtures\Livewire as FormLivewire;
use Capell\Core\Models\Language;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

function translatableFieldsLanguage(string $name, string $code, string $flag): Language
{
    return new Language(['name' => $name, 'code' => $code, 'flag' => $flag, 'locale' => $code]);
}

/**
 * @param  array<int, Component>  $components
 */
function translatableFieldsMount(array $components): void
{
    Schema::make(FormLivewire::make())
        ->statePath('data')
        ->components($components)
        ->getComponents();
}

/**
 * @return array<int, Tab>
 */
function translatableFieldsTabs(Component $component): array
{
    return array_values(array_filter($component->getChildComponents(), fn (mixed $child): bool => $child instanceof Tab));
}

/**
 * @return array<int, Component>
 */
function translatableFieldsChildren(Component $component): array
{
    return array_values(array_filter($component->getChildComponents(), fn (mixed $child): bool => $child instanceof Component));
}

it('binds state to the language locale, which can differ from its code', function (): void {
    $language = new Language(['name' => 'English', 'code' => 'en', 'flag' => 'gb', 'locale' => 'en_EN']);
    $tabs = TranslatableFields::make('translations')->languages(collect([$language]))->fields([TextInput::make('name')]);
    translatableFieldsMount([$tabs]);

    $field = translatableFieldsChildren(translatableFieldsTabs($tabs)[0])[0];

    expect($field)->toBeInstanceOf(TextInput::class)
        ->and($field->getStatePath())->toBe('data.name.en_EN');
});

it('localises a state-dependent layout schema once it has a container', function (): void {
    $language = translatableFieldsLanguage('English', 'en', 'gb');
    $tabs = TranslatableFields::make('translations')->languages(collect([$language]))->fields([
        Grid::make()->schema(fn (Get $get): array => [TextInput::make($get('kind') === 'long' ? 'body' : 'name')]),
    ]);
    translatableFieldsMount([$tabs]);

    $grid = translatableFieldsChildren(translatableFieldsTabs($tabs)[0])[0];
    $field = translatableFieldsChildren($grid)[0];

    expect($grid)->toBeInstanceOf(Grid::class)
        ->and($field->getStatePath())->toBe('data.name.en');
});

it('falls back to the language code when the locale is empty', function (): void {
    $language = new Language(['name' => 'English', 'code' => 'en', 'flag' => 'gb', 'locale' => '']);
    $tabs = TranslatableFields::make('translations')->languages(collect([$language]))->fields([TextInput::make('name')]);
    translatableFieldsMount([$tabs]);

    $field = translatableFieldsChildren(translatableFieldsTabs($tabs)[0])[0];

    expect($field)->toBeInstanceOf(TextInput::class)
        ->and($field->getStatePath())->toBe('data.name.en');
});

it('creates one tab per language with field state bound to the locale', function (): void {
    $english = translatableFieldsLanguage('English', 'en', 'gb');
    $welsh = translatableFieldsLanguage('Welsh', 'cy', 'gb-wls');

    $component = TranslatableFields::make()
        ->languages(collect([$english, $welsh]))
        ->fields([TextInput::make('name'), TextInput::make('price')]);

    translatableFieldsMount([$component]);

    $tabs = translatableFieldsTabs($component);
    $paths = [];

    foreach ($tabs as $tab) {
        foreach (translatableFieldsChildren($tab) as $field) {
            $paths[] = $field->getStatePath();
        }
    }

    expect($tabs)->toHaveCount(2)
        ->and($tabs[0]->getLabel())->toBeInstanceOf(Htmlable::class)
        ->and($tabs[0]->getLabel() instanceof Htmlable ? $tabs[0]->getLabel()->toHtml() : '')->toContain('English')
        ->and($paths)->toBe(['data.name.en', 'data.price.en', 'data.name.cy', 'data.price.cy']);
});

it('binds fields nested in layouts and keeps tab keys unique across instances', function (): void {
    $english = translatableFieldsLanguage('English', 'en', 'gb');
    $welsh = translatableFieldsLanguage('Welsh', 'cy', 'gb-wls');
    $name = TextInput::make('name');

    $first = TranslatableFields::make()->languages(collect([$english, $welsh]))
        ->fields([Grid::make()->schema([$name])]);
    $second = TranslatableFields::make()->languages(collect([$english]))->fields([TextInput::make('title')]);

    translatableFieldsMount([$first, $second]);

    $paths = [];
    $keys = [];

    foreach ([$first, $second] as $component) {
        foreach (translatableFieldsTabs($component) as $tab) {
            $keys[] = $tab->getKey();
        }
    }

    foreach (translatableFieldsTabs($first) as $tab) {
        $grid = translatableFieldsChildren($tab)[0];
        $paths[] = translatableFieldsChildren($grid)[0]->getStatePath();
    }

    expect($paths)->toBe(['data.name.en', 'data.name.cy'])
        ->and($name->getStatePath(false))->toBe('name')
        ->and($keys)->toHaveCount(3)
        ->and(array_unique($keys))->toHaveCount(3);
});

it('names the language in validation messages', function (): void {
    $component = TranslatableFields::make()
        ->languages(collect([translatableFieldsLanguage('English', 'en', 'gb')]))
        ->fields([TextInput::make('name')->label('Name')]);

    translatableFieldsMount([$component]);

    $field = translatableFieldsChildren(translatableFieldsTabs($component)[0])[0];

    expect($field)->toBeInstanceOf(Field::class)
        ->and($field instanceof Field ? $field->getValidationAttribute() : null)->toBe('name (English)');
});

it('lays tabs out horizontally by default and vertically on request', function (): void {
    $horizontal = TranslatableFields::make();
    $vertical = TranslatableFields::make()->orientation(TranslationTabsOrientation::Vertical);
    $shorthand = TranslatableFields::make()->verticalTabs();
    $fromString = TranslatableFields::make()->orientation(fn (): string => 'vertical');

    expect($horizontal->getOrientation())->toBe(TranslationTabsOrientation::Horizontal)
        ->and($horizontal->isVertical())->toBeFalse()
        ->and($vertical->isVertical())->toBeTrue()
        ->and($shorthand->getOrientation())->toBe(TranslationTabsOrientation::Vertical)
        ->and($fromString->isVertical())->toBeTrue();
});

it('shares the orientation option with repeater tabs', function (): void {
    $repeater = RepeaterTabs::make('translations');

    expect($repeater->isVertical())->toBeFalse()
        ->and($repeater->verticalTabs()->isVertical())->toBeTrue()
        ->and($repeater->verticalTabs(false)->getOrientation())->toBe(TranslationTabsOrientation::Horizontal);
});

it('takes the default orientation from config and lets a component override it', function (): void {
    config()->set('capell-admin.translation_tabs_orientation', 'vertical');

    expect(TranslatableFields::make()->isVertical())->toBeTrue()
        ->and(RepeaterTabs::make('translations')->isVertical())->toBeTrue()
        ->and(RepeaterTabs::make('translations')->verticalTabs(false)->isVertical())->toBeFalse();

    config()->set('capell-admin.translation_tabs_orientation', 'sideways');

    expect(TranslatableFields::make()->isVertical())->toBeFalse();
});
