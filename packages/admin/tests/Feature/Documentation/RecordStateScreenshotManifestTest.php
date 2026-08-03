<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('captures record-state fixtures through real Filament routes and visible HTML select options', function (): void {
    $root = dirname(__DIR__, 3);
    $manifest = json_decode(File::get($root . '/docs/screenshots.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = collect($manifest['entries'])->keyBy('id');

    $pages = $entries->get('admin-pages-list');
    $layouts = $entries->get('admin-layouts-list');
    $media = $entries->get('admin-media-list');

    expect($pages['surface'])->toBe('admin')
        ->and($pages['url'])->toBe('/screenshot-fixtures/record-states/pages')
        ->and($pages['waitFor'])->toContain('Scheduled', 'No active URL')
        ->and($layouts['surface'])->toBe('admin')
        ->and($layouts['url'])->toBe('/screenshot-fixtures/record-states/layouts')
        ->and($layouts['waitFor'])->toContain('Disabled', 'Unused layout')
        ->and($media['surface'])->toBe('admin')
        ->and($media['url'])->toBe('/screenshot-fixtures/record-states/media')
        ->and($media['interactions'])->toContain([
            'type' => 'waitFor',
            'selector' => ".fi-ta-row:has-text('unused-screenshot-fixture.jpg'):has-text('No tracked uses')",
        ])
        ->and($entries->get('admin-media-edit-focal-point')['waitFor'])->toBe(".fi-sc-tabs:has(button[role='tab']:has-text('Crop and focal point'))")
        ->and($entries->get('admin-media-edit-localized-metadata')['url'])->toBe('/screenshot-fixtures/record-states/media-editor')
        ->and($entries->get('admin-media-edit-localized-metadata')['interactions'])->toContain([
            'type' => 'waitFor',
            'selector' => ".fi-sc-tabs-tab:has(.fi-section-header-heading:has-text('Localized metadata'))",
        ]);

    $layoutSelect = $entries->get('admin-page-layout-select-record-states');

    expect($layoutSelect['url'])->toBe('/screenshot-fixtures/record-states/page-editor')
        ->and($layoutSelect['beforeWait'])->toContain([
            'type' => 'click',
            'selector' => ".fi-fo-field:has(label[for='form.layout_id']) .fi-select-input-btn",
        ])
        ->and($layoutSelect['beforeWait'])->toContain([
            'type' => 'fill',
            'selector' => ".fi-fo-field:has(label[for='form.layout_id']) input[aria-label='Search']",
            'value' => 'Disabled unused layout',
        ])
        ->and($layoutSelect['interactions'])->toContain([
            'type' => 'waitFor',
            'selector' => ".fi-fo-field:has(label[for='form.layout_id']) .fi-select-input-option:has(.select-option-label:has-text('Disabled unused layout')):has-text('Disabled'):has-text('Unused layout')",
        ])
        ->and($layoutSelect['notes'])->toContain('HTML-enabled');
});
