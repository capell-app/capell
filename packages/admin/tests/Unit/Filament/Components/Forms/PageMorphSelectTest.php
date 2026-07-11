<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\PageMorphToOptionSelect;
use Capell\Admin\Filament\Components\Forms\PageMorphToSelect;
use Capell\Admin\Tests\Fixtures\Livewire;
use Capell\Core\Data\PageVariationData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

it('builds option morph page blueprints from registered page variations', function (): void {
    CapellCore::registerPageVariation(new PageVariationData(
        name: 'landing_page',
        model: Page::class,
        titleAttribute: 'name',
    ));

    $component = PageMorphToOptionSelect::make();
    $types = $component->getTypes();

    expect(PageMorphToOptionSelect::getDefaultName())->toBe('pageable')
        ->and($component->getName())->toBe('pageable')
        ->and($component->hasTypeSelectToggleButtons())->toBeTrue()
        ->and($component->getLabel())->toBe(__('capell-admin::form.page'))
        ->and($types)->toHaveKey('landing_page')
        ->and($types['landing_page']->getLabel())->toBe('Landing page');
});

it('configures standard page morph select defaults', function (): void {
    $component = PageMorphToSelect::make();

    expect(PageMorphToSelect::getDefaultName())->toBe('pageable')
        ->and($component->getName())->toBe('pageable');
});

it('loads page options through registered page variations and query callbacks', function (): void {
    CapellCore::registerPageVariation(new PageVariationData(
        name: 'coverage_landing_page',
        model: Page::class,
        titleAttribute: 'name',
    ));

    $site = Site::factory()->withTranslations()->createOne();
    $type = Blueprint::factory()->page()->createOne(['key' => 'landing']);
    $visiblePage = Page::factory()->site($site)->type($type)->createOne(['name' => 'Alpha landing']);
    $hiddenPage = Page::factory()->site($site)->type($type)->createOne(['name' => 'Hidden landing']);

    $mounted = mountedPageMorphOptionSelectForTest(
        PageMorphToOptionSelect::make()
            ->optionsLimit(1)
            ->modifyKeySelectOptionsQueryUsing(
                fn (Builder $query): Builder => $query->where('name', 'like', 'Alpha%'),
            ),
        [
            'pageable' => [
                'pageable_type' => 'coverage_landing_page',
                'pageable_id' => $hiddenPage->getKey(),
            ],
        ],
    );

    $keyControl = pageMorphOptionChildComponentByName([$mounted], 'pageable_id');
    assert($keyControl instanceof Select);

    expect($keyControl->getOptions())->toBe([
        $visiblePage->getKey() => 'Alpha landing',
        $hiddenPage->getKey() => (string) $hiddenPage->getKey(),
    ])
        ->and($keyControl->getSearchResults('Alpha'))->toBe([
            $visiblePage->getKey() => 'Alpha landing',
        ])
        ->and($keyControl->getSearchResults('Hidden'))->toBe([]);

    $keyControl->state($visiblePage->getKey());

    expect($keyControl->getOptionLabel())->toBe('Alpha landing');

    $keyControl->state($hiddenPage->getKey());

    expect($keyControl->getOptionLabel(withDefault: false))->toBeNull();
});

/**
 * @param  array<string, mixed>  $state
 */
function mountedPageMorphOptionSelectForTest(PageMorphToOptionSelect $component, array $state): PageMorphToOptionSelect
{
    $schema = Schema::make(Livewire::make()->data($state))
        ->statePath('data')
        ->components([$component]);

    $mounted = $schema->getComponents()[0];
    assert($mounted instanceof PageMorphToOptionSelect);
    $mounted->state((array) data_get($state, $mounted->getName(), []));

    return $mounted;
}

/**
 * @param  array<int, Component>  $components
 */
function pageMorphOptionChildComponentByName(array $components, string $name): Component
{
    $component = pageMorphOptionFlattenComponents($components)
        ->first(fn (Component $component): bool => method_exists($component, 'getName') && $component->getName() === $name);

    if (! $component instanceof Component) {
        throw new RuntimeException(sprintf('Page morph child component [%s] was not found.', $name));
    }

    return $component;
}

/**
 * @param  array<int, Component>  $components
 * @return Collection<int, Component>
 */
function pageMorphOptionFlattenComponents(array $components): Collection
{
    return collect($components)->flatMap(function (Component $component): array {
        $children = array_filter(
            $component->getChildSchema()?->getComponents(withHidden: true) ?? [],
            fn (mixed $child): bool => $child instanceof Component,
        );

        return [
            $component,
            ...pageMorphOptionFlattenComponents(array_values($children))->all(),
        ];
    })->values();
}
