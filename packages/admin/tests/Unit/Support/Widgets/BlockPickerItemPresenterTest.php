<?php

declare(strict_types=1);

use Capell\Admin\Data\Widgets\BlockPickerItemMetadataData;
use Capell\Admin\Data\Widgets\BlockPickerItemViewData;
use Capell\Admin\Support\Widgets\BlockPickerItemPresenter;

it('prefers metadata over Filament values when metadata is present', function (): void {
    $item = (new BlockPickerItemPresenter)->present(
        blockName: 'hero',
        filamentLabel: 'Hero (Filament label)',
        filamentIcon: 'heroicon-o-filament-icon',
        metadata: new BlockPickerItemMetadataData(
            label: 'Hero',
            description: 'A large intro banner.',
            category: 'Foundation',
            icon: 'heroicon-o-photo',
            searchTerms: ['banner', 'intro'],
        ),
        wireClickAction: "mountAction('add', {\"block\":\"hero\"}, { schemaComponent: 'content' })",
        fallbackCategory: 'Other blocks',
        fallbackIcon: 'heroicon-o-squares-2x2',
    );

    expect($item)->toBeInstanceOf(BlockPickerItemViewData::class)
        ->and($item->key)->toBe('hero')
        ->and($item->label)->toBe('Hero')
        ->and($item->description)->toBe('A large intro banner.')
        ->and($item->category)->toBe('Foundation')
        ->and($item->icon)->toBe('heroicon-o-photo')
        ->and($item->searchHaystack)->toContain('hero', 'a large intro banner', 'foundation', 'banner', 'intro')
        ->and($item->wireClickAction)->toBe("mountAction('add', {\"block\":\"hero\"}, { schemaComponent: 'content' })");
});

it('falls back to Filament label, icon, and the fallback category when metadata is absent', function (): void {
    $item = (new BlockPickerItemPresenter)->present(
        blockName: 'content',
        filamentLabel: 'Content',
        filamentIcon: 'heroicon-o-filament-icon',
        metadata: null,
        wireClickAction: "mountAction('add', {\"block\":\"content\"}, { schemaComponent: 'content' })",
        fallbackCategory: 'Other blocks',
        fallbackIcon: 'heroicon-o-squares-2x2',
    );

    expect($item->label)->toBe('Content')
        ->and($item->description)->toBe('')
        ->and($item->category)->toBe('Other blocks')
        ->and($item->icon)->toBe('heroicon-o-filament-icon');
});

it('falls back to a generic icon when neither metadata nor Filament supply one', function (): void {
    $item = (new BlockPickerItemPresenter)->present(
        blockName: 'content',
        filamentLabel: 'Content',
        filamentIcon: null,
        metadata: null,
        wireClickAction: "mountAction('add', {\"block\":\"content\"}, { schemaComponent: 'content' })",
        fallbackCategory: 'Other blocks',
        fallbackIcon: 'heroicon-o-squares-2x2',
    );

    expect($item->icon)->toBe('heroicon-o-squares-2x2');
});

it('falls back to Filament label when metadata sets an empty label or category', function (): void {
    $item = (new BlockPickerItemPresenter)->present(
        blockName: 'content',
        filamentLabel: 'Content',
        filamentIcon: null,
        metadata: new BlockPickerItemMetadataData(label: '', category: ''),
        wireClickAction: "mountAction('add', {\"block\":\"content\"}, { schemaComponent: 'content' })",
        fallbackCategory: 'Other blocks',
        fallbackIcon: 'heroicon-o-squares-2x2',
    );

    expect($item->label)->toBe('Content')
        ->and($item->category)->toBe('Other blocks');
});

it('groups items by category, sorts items alphabetically within a category, and sorts categories alphabetically', function (): void {
    $presenter = new BlockPickerItemPresenter;

    $items = [
        $presenter->present('testimonial', 'Testimonial', null, new BlockPickerItemMetadataData(label: 'Testimonial', category: 'Social proof'), 'x', 'Other blocks', 'icon'),
        $presenter->present('hero', 'Hero', null, new BlockPickerItemMetadataData(label: 'Hero', category: 'Foundation'), 'x', 'Other blocks', 'icon'),
        $presenter->present('cta', 'Call to action', null, new BlockPickerItemMetadataData(label: 'Call to action', category: 'Foundation'), 'x', 'Other blocks', 'icon'),
        $presenter->present('legacy', 'Legacy widget', null, null, 'x', 'Other blocks', 'icon'),
    ];

    $grouped = $presenter->group($items, 'Other blocks');

    expect(array_keys($grouped))->toBe(['Foundation', 'Social proof', 'Other blocks'])
        ->and(array_map(fn (BlockPickerItemViewData $item): string => $item->label, $grouped['Foundation']))
        ->toBe(['Call to action', 'Hero'])
        ->and($grouped['Other blocks'])->toHaveCount(1)
        ->and($grouped['Other blocks'][0]->label)->toBe('Legacy widget');
});

it('sorts the fallback category last even when it sorts alphabetically before other categories', function (): void {
    $presenter = new BlockPickerItemPresenter;

    $items = [
        $presenter->present('legacy', 'Legacy widget', null, null, 'x', 'AAA fallback', 'icon'),
        $presenter->present('hero', 'Hero', null, new BlockPickerItemMetadataData(label: 'Hero', category: 'Zzz category'), 'x', 'AAA fallback', 'icon'),
    ];

    $grouped = $presenter->group($items, 'AAA fallback');

    expect(array_keys($grouped))->toBe(['Zzz category', 'AAA fallback']);
});

it('returns an empty group list for an empty item list', function (): void {
    expect((new BlockPickerItemPresenter)->group([], 'Other blocks'))->toBe([]);
});
