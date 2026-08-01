<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Page\LayoutSelect;
use Capell\Core\Models\Layout;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('layout select search ordering binds user supplied search text', function (): void {
    $component = LayoutSelect::make('layout_id');
    $query = Layout::query();
    $search = "Hero' THEN 1 ELSE 1 END; DROP TABLE layouts; --";

    $method = new ReflectionMethod($component, 'applySearchOrdering');
    $method->invoke($component, $query, $search);

    $bindings = $query->getQuery()->getRawBindings();
    $orderBindings = $bindings['order'];

    expect($query)
        ->toBeInstanceOf(Builder::class)
        ->and($query->toSql())->not->toContain($search)
        ->and($orderBindings)->toContain(mb_strtolower($search));
});

it('layout select does not preload every layout option into page edit payloads', function (): void {
    $component = LayoutSelect::make('layout_id');

    expect($component->isPreloaded())->toBeFalse();
});

it('layout select falls back to generated preview image metadata', function (): void {
    Storage::fake('public');

    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne([
        'admin' => [
            'generated_preview_image' => 'generated-layout-previews/layout-preview.png',
        ],
    ]);

    $method = new ReflectionMethod($component, 'layoutPreviewImageUrl');

    expect($method->invoke($component, $layout))
        ->toContain('generated-layout-previews/layout-preview.png');
});

it('layout select keeps thumbnail in selected option without rendering preview below field', function (): void {
    Storage::fake('public');

    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne([
        'admin' => [
            'generated_preview_image' => 'generated-layout-previews/layout-preview.png',
        ],
    ]);

    expect($component->getOptionLabelFromRecord($layout))
        ->toContain('generated-layout-previews/layout-preview.png')
        ->and(layoutSelectChildComponents($component))
        ->not->toHaveKey(LayoutSelect::BELOW_CONTENT_SCHEMA_KEY);
});

it('marks disabled and unused layouts in select options', function (): void {
    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne(['status' => false]);
    $layout->setAttribute('pages_count', 0);

    expect($component->getOptionLabelFromRecord($layout))
        ->toContain(__('capell-admin::form.disabled'))
        ->toContain(__('capell-admin::table.layout_usage_unused'));
});

it('does not run a fallback usage query while rendering an option without the selected aggregate', function (): void {
    $component = LayoutSelect::make('layout_id');
    $layout = Layout::factory()->createOne();

    DB::enableQueryLog();
    $component->getOptionLabelFromRecord($layout);

    expect(collect(DB::getQueryLog())->pluck('query')->implode(' '))
        ->not->toContain('from "pages"');
});

it('loads a single cross-variation usage aggregate for relationship options', function (): void {
    $component = LayoutSelect::make('layout_id');
    $property = new ReflectionProperty(Select::class, 'modifyRelationshipQueryUsing');
    $modifyQuery = $property->getValue($component);

    expect($modifyQuery)->toBeInstanceOf(Closure::class);
    assert($modifyQuery instanceof Closure);

    $query = $component->evaluate($modifyQuery, ['query' => Layout::query(), 'search' => null]);

    expect($query->toSql())->toContain('pages_count');
});

/**
 * @return array<string, mixed>
 */
function layoutSelectChildComponents(LayoutSelect $component): array
{
    $callback = Closure::bind(
        fn (): array => $component->childComponents,
        null,
        LayoutSelect::class,
    );

    return $callback();
}
