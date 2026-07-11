<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\OptionMorphToSelect;
use Capell\Admin\Filament\Components\Forms\OptionMorphToSelectType;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

it('configures morph option select component state', function (): void {
    $type = OptionMorphToSelectType::make('blog_post')->label('Blog post');

    $component = OptionMorphToSelect::make('content.target')
        ->label('Target')
        ->required()
        ->optionsLimit(fn (): int => 12)
        ->typeSelectToggleButtons()
        ->types([$type]);

    expect($component->getName())->toBe('content.target')
        ->and($component->getLabel())->toBe('Target')
        ->and($component->isRequired())->toBeTrue()
        ->and($component->getOptionsLimit())->toBe(12)
        ->and($component->hasTypeSelectToggleButtons())->toBeTrue()
        ->and($component->getTypes())->toHaveKey('blog_post')
        ->and($component->getTypes()['blog_post'])->toBe($type);
});

it('requires an explicit component name', function (): void {
    expect(fn (): mixed => OptionMorphToSelect::make())
        ->toThrow(InvalidArgumentException::class, 'must have a unique name');
});

it('resolves morph type labels options search results and selected labels', function (): void {
    $select = Select::make('target_id');

    /** @var Collection<int|string, mixed> $options */
    $options = new Collection([
        'home' => 'Homepage',
        'about' => 'About us',
        'blog' => 'Blog',
    ]);

    $type = OptionMorphToSelectType::make('landing-page')
        ->options($options);

    expect($type->getAlias())->toBe('landing-page')
        ->and($type->getLabel())->toBe('Landing page')
        ->and($type->getOptions($select))->toBe([
            'home' => 'Homepage',
            'about' => 'About us',
            'blog' => 'Blog',
        ])
        ->and($type->getSearchResults($select, 'bo'))->toBe([
            'about' => 'About us',
        ])
        ->and($type->getOptionLabel($select, 'home'))->toBe('Homepage')
        ->and($type->getOptionLabel($select, 'missing'))->toBe('missing')
        ->and($type->getOptionLabel($select, null))->toBeNull();
});

it('uses morph type callbacks when provided', function (): void {
    $select = Select::make('target_id');

    $type = OptionMorphToSelectType::make('page')
        ->label('Page')
        ->options(fn (): array => ['ignored' => 'Ignored'])
        ->getOptionsUsing(fn (): array => ['one' => 'One'])
        ->getSearchResultsUsing(fn (string $search): array => [$search => strtoupper($search)])
        ->getOptionLabelUsing(fn (string $value): string => 'Label ' . $value)
        ->modifyKeySelectUsing(fn (Select $select): Select => $select->helperText('Pick one'));

    expect($type->getLabel())->toBe('Page')
        ->and($type->getOptions($select))->toBe(['one' => 'One'])
        ->and($type->getSearchResults($select, 'abc'))->toBe(['abc' => 'ABC'])
        ->and($type->getOptionLabel($select, 'abc'))->toBe('Label abc')
        ->and($type->getModifyKeySelectUsingCallback())->toBeInstanceOf(Closure::class);
});

it('builds dependent type and key controls from mounted form state', function (): void {
    $component = OptionMorphToSelect::make('content.target')
        ->label('Target')
        ->required()
        ->searchable()
        ->preload()
        ->optionsLimit(2)
        ->typeSelectToggleButtons()
        ->types([
            OptionMorphToSelectType::make('page')
                ->label('Page')
                ->options([
                    'home' => 'Homepage',
                    'about' => 'About us',
                    'blog' => 'Blog',
                ])
                ->modifyKeySelectUsing(fn (Select $select): Select => $select->helperText('Pick a page')),
            OptionMorphToSelectType::make('asset')
                ->label('Asset')
                ->getSearchResultsUsing(fn (string $search): array => [
                    'asset-a' => 'Asset A',
                    'asset-b' => 'Asset B',
                    'asset-c' => 'Asset C',
                ])
                ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null : 'Asset ' . $value),
        ]);

    $mounted = optionMorphMountedComponent($component, [
        'content' => [
            'target' => [
                'target_type' => 'page',
                'target_id' => 'archived-page',
            ],
        ],
    ]);

    $typeControl = optionMorphComponentByName([$mounted], 'target_type');
    $keyControl = optionMorphComponentByName([$mounted], 'target_id');
    assert($typeControl instanceof ToggleButtons);
    assert($keyControl instanceof Select);

    expect($typeControl)->toBeInstanceOf(ToggleButtons::class)
        ->and($typeControl->getOptions())->toBe([
            'page' => 'Page',
            'asset' => 'Asset',
        ])
        ->and($keyControl)->toBeInstanceOf(Select::class)
        ->and($keyControl->getOptions())->toBe([
            'home' => 'Homepage',
            'about' => 'About us',
            'blog' => 'Blog',
            'archived-page' => 'archived-page',
        ])
        ->and($keyControl->getOptions()['about'])->toBe('About us')
        ->and($keyControl->isRequired())->toBeTrue()
        ->and($keyControl->hasCustomLabel())->toBeTrue();
});

it('limits dynamic search results for the selected morph type', function (): void {
    $component = OptionMorphToSelect::make('content.target')
        ->optionsLimit(2)
        ->types([
            OptionMorphToSelectType::make('asset')
                ->label('Asset')
                ->getSearchResultsUsing(fn (string $search): array => [
                    'asset-a' => 'Asset A',
                    'asset-b' => 'Asset B',
                    'asset-c' => 'Asset C',
                ])
                ->getOptionLabelUsing(fn (?string $value): ?string => $value === null ? null : 'Asset ' . $value),
        ]);

    $mounted = optionMorphMountedComponent($component, [
        'content' => [
            'target' => [
                'target_type' => 'asset',
                'target_id' => 'asset-c',
            ],
        ],
    ]);

    $keyControl = optionMorphComponentByName([$mounted], 'target_id');
    assert($keyControl instanceof Select);

    expect($keyControl)->toBeInstanceOf(Select::class)
        ->and($keyControl->getSearchResults('asset'))->toBe([
            'asset-a' => 'Asset A',
            'asset-b' => 'Asset B',
        ])
        ->and($keyControl->getOptionLabel())->toBe('Asset asset-c');
});

it('keeps stale multiple selections visible while applying parent select customisation', function (): void {
    $component = OptionMorphToSelect::make('content.target')
        ->modifyTypeSelectUsing(fn (Select $select): Select => $select->searchable())
        ->modifyKeySelectUsing(fn (Select $select): Select => $select->multiple())
        ->types([
            OptionMorphToSelectType::make('page')
                ->label('Page')
                ->options([
                    'home' => 'Homepage',
                ]),
        ]);

    $mounted = optionMorphMountedComponent($component, [
        'content' => [
            'target' => [
                'target_type' => 'page',
                'target_id' => ['home', 'archived-page'],
            ],
        ],
    ]);

    $typeControl = optionMorphComponentByName([$mounted], 'target_type');
    $keyControl = optionMorphComponentByName([$mounted], 'target_id');
    assert($typeControl instanceof Select);
    assert($keyControl instanceof Select);

    expect($typeControl->isSearchable())->toBeTrue()
        ->and($keyControl->isMultiple())->toBeTrue()
        ->and($keyControl->getOptions())->toBe([
            'home' => 'Homepage',
            'archived-page' => 'archived-page',
        ]);
});

it('hides the key select until a valid morph type has been chosen', function (): void {
    $component = OptionMorphToSelect::make('content.target')
        ->types([
            OptionMorphToSelectType::make('page')
                ->label('Page')
                ->options([
                    'home' => 'Homepage',
                ]),
        ]);

    $mounted = optionMorphMountedComponent($component, [
        'content' => [
            'target' => [
                'target_type' => null,
                'target_id' => null,
            ],
        ],
    ]);

    $keyControl = optionMorphComponentByName([$mounted], 'target_id');
    assert($keyControl instanceof Select);

    expect($keyControl->isHidden())->toBeTrue()
        ->and($keyControl->isRequired())->toBeFalse()
        ->and($keyControl->getOptions())->toBe([])
        ->and($keyControl->getOptionLabel())->toBeNull();
});

/**
 * @param  array<string, mixed>  $state
 */
function optionMorphMountedComponent(OptionMorphToSelect $component, array $state): OptionMorphToSelect
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->components([$component]);

    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof OptionMorphToSelect);
    $mounted->state((array) data_get($state, $mounted->getName(), []));

    return $mounted;
}

/**
 * @param  array<int, Component>  $components
 */
function optionMorphComponentByName(array $components, string $name): Component
{
    $component = optionMorphFlattenComponents($components)
        ->first(fn (Component $component): bool => method_exists($component, 'getName') && $component->getName() === $name);

    if (! $component instanceof Component) {
        $names = optionMorphFlattenComponents($components)
            ->map(fn (Component $component): ?string => method_exists($component, 'getName') ? $component->getName() : null)
            ->filter()
            ->implode(', ');

        throw new RuntimeException(sprintf('Morph select child component [%s] was not found. Available: %s', $name, $names));
    }

    return $component;
}

/**
 * @param  array<int, Component>  $components
 * @return Collection<int, Component>
 */
function optionMorphFlattenComponents(array $components): Collection
{
    return collect($components)->flatMap(function (Component $component): array {
        $children = array_filter(
            $component->getChildSchema()?->getComponents(withHidden: true) ?? [],
            fn (mixed $child): bool => $child instanceof Component,
        );

        return [
            $component,
            ...optionMorphFlattenComponents(array_values($children))->all(),
        ];
    })->values();
}
