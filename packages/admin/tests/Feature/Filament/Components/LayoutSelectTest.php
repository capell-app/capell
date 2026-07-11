<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Page\LayoutSelect;
use Capell\Core\Models\Layout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

test('layout select search ordering binds user supplied search text', function (): void {
    $component = LayoutSelect::make('layout_id');
    $query = Layout::query();
    $search = "Hero' THEN 1 ELSE 1 END; DROP TABLE layouts; --";

    $method = new ReflectionMethod($component, 'applySearchOrdering');
    $method->invoke($component, $query, $search);

    $bindings = $query->getQuery()->getRawBindings();
    $orderBindings = is_array($bindings['order'] ?? null) ? $bindings['order'] : [];

    expect($query)
        ->toBeInstanceOf(Builder::class)
        ->and($query->toSql())->not->toContain($search)
        ->and($orderBindings)->toContain($search);
});

test('layout select does not preload every layout option into page edit payloads', function (): void {
    $component = LayoutSelect::make('layout_id');

    expect($component->isPreloaded())->toBeFalse();
});

test('layout select falls back to generated preview image metadata', function (): void {
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

test('layout select keeps thumbnail in selected option without rendering preview below field', function (): void {
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
