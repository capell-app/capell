<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\ContentFilamentWidget;
use Filament\Forms\Components\Field;

it('content block exposes media, align and ordering fields', function (): void {
    $block = ContentFilamentWidget::make();
    $childComponents = $block->getDefaultChildComponents();
    assert(is_array($childComponents));

    $componentNames = collect($childComponents)
        ->filter(fn (mixed $component): bool => $component instanceof Field)
        ->map(fn (Field $component): string => $component->getName())
        ->values()
        ->all();

    expect($componentNames)->toContain('media')
        ->toContain('mediaAlign')
        ->toContain('mediaOrdering');
});
