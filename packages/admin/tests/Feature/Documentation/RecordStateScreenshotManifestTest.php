<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('captures record-state fixtures through real Filament routes and visible HTML select options', function (): void {
    $root = dirname(__DIR__, 3);
    $manifest = json_decode(File::get($root . '/docs/screenshots.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = collect($manifest['entries'])->keyBy('id');

    expect($entries->get('admin-pages-list')['url'])->toBe('/screenshot-fixtures/record-states/pages')
        ->and($entries->get('admin-layouts-list')['url'])->toBe('/screenshot-fixtures/record-states/layouts')
        ->and($entries->get('admin-media-list')['url'])->toBe('/screenshot-fixtures/record-states/media')
        ->and($entries->get('admin-media-edit-localized-metadata')['url'])->toBe('/screenshot-fixtures/record-states/media-editor');

    $layoutSelect = $entries->get('admin-page-layout-select-record-states');

    expect($layoutSelect['url'])->toBe('/screenshot-fixtures/record-states/page-editor')
        ->and($layoutSelect['beforeWait'])->toContain([
            'type' => 'click',
            'selector' => ".fi-fo-select-wrp:has(label:has-text('Layout')) .fi-select-input-btn",
        ])
        ->and($layoutSelect['interactions'])->toContain([
            'type' => 'waitFor',
            'selector' => ".fi-select-input-option:has-text('Disabled'):has-text('Unused layout')",
        ])
        ->and($layoutSelect['notes'])->toContain('HTML-enabled');
});
