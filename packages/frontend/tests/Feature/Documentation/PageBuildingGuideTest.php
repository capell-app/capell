<?php

declare(strict_types=1);

function pageBuildingGuideRepositoryPath(string $path): string
{
    return dirname(__DIR__, 5) . '/' . $path;
}

function pageBuildingGuideContents(string $path): string
{
    $contents = file_get_contents(pageBuildingGuideRepositoryPath($path));

    expect($contents)->not->toBeFalse();

    return (string) $contents;
}

it('documents every supported page-building path and the page view boundary', function (): void {
    $guide = pageBuildingGuideContents('docs/getting-started/building-pages.md');

    expect($guide)
        ->toContain('## 1. Basic HTML content')
        ->toContain('## 2. Structured content blocks')
        ->toContain('## 3. Layout Builder widgets')
        ->toContain('## 4. Custom Blade rendering')
        ->toContain('Blocks are a body-authoring choice, not a replacement for a page layout.')
        ->toContain('[Block Library documentation](https://docs.capell.app/packages/block-library)')
        ->toContain('There is no page-level `view_file`. A page does not have a direct `view_file` override; assigning a dedicated layout with `master_file` and `layout_file` is the supported per-page route to full Blade control.');
});

it('links into the guide from the canonical documentation paths', function (): void {
    $readme = pageBuildingGuideContents('README.md');
    $documentationIndex = pageBuildingGuideContents('docs/README.md');
    $firstPageGuide = pageBuildingGuideContents('docs/getting-started/create-your-first-page.md');
    $frontendIndex = pageBuildingGuideContents('docs/frontend/index.md');

    expect($readme)->toContain('[Build a page](docs/getting-started/building-pages.md)')
        ->and($documentationIndex)->toMatch('/\\|\\s*Build a page\\s*\\|\\s*\\[Build a page\\]\\(getting-started\\/building-pages\\.md\\)/')
        ->and($documentationIndex)->toMatch('/\\|\\s*How should I build this page\\?\\s*\\|\\s*\\[Build a page\\]\\(getting-started\\/building-pages\\.md\\)/')
        ->and($firstPageGuide)->toContain('[Build a page](building-pages.md)')
        ->and($frontendIndex)->toContain('[Build a page](../getting-started/building-pages.md)');
});

it('features the page-building path from the documentation landing page', function (): void {
    $documentationIndex = pageBuildingGuideContents('docs/README.md');

    expect($documentationIndex)
        ->toContain('## Build pages with the right amount of structure')
        ->toContain('[Choose a page-building path](getting-started/building-pages.md)')
        ->toContain('normal HTML page body')
        ->toContain('typed blocks')
        ->toContain('approved Layout Builder widgets')
        ->toContain('dedicated Blade layouts')
        ->toContain('The frontend stays in your Laravel application.');
});

it('keeps the guide illustration and documented screenshot captures available', function (): void {
    $guide = pageBuildingGuideContents('docs/getting-started/building-pages.md');

    $paths = [
        'docs/images/generated/page-building-continuum.webp',
        'docs/images/generated/admin/first-page-content-editor.png',
        'docs/images/generated/page-building-blocks-editor.png',
        'docs/images/generated/page-building-layout-builder-editor.png',
        'docs/images/generated/page-building-layout-builder-add-widget.png',
        'docs/images/generated/admin/admin-layouts-list.png',
    ];

    foreach ($paths as $path) {
        expect(pageBuildingGuideRepositoryPath($path))->toBeFile();
        expect($guide)->toContain('../' . str_replace('docs/', '', $path));
    }

    expect($guide)
        ->toContain('page-building-blocks-editor.png')
        ->toContain('The Blocks page-body editor');
});

it('declares deterministic screenshot provenance for guide-owned captures', function (): void {
    $manifest = json_decode(
        pageBuildingGuideContents('docs/screenshots.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $entries = collect($manifest['entries'] ?? [])->keyBy('id');

    $expectedEntries = [
        'page-building-blocks-editor' => [
            'output' => 'docs/images/generated/page-building-blocks-editor.png',
            'target' => 'PageResource/EditPage',
            'url' => '/screenshot-fixtures/page-building-blocks-editor',
            'waitFor' => '.fi-fo-builder',
            'interactions' => null,
        ],
        'page-building-layout-builder-editor' => [
            'output' => 'docs/images/generated/page-building-layout-builder-editor.png',
            'target' => 'PageResource/EditPage',
            'url' => '/screenshot-fixtures/layout-builder-admin-editor',
            'waitFor' => '.layout-builder-visual-toolbar',
            'interactions' => [
                ['type' => 'scrollIntoView', 'selector' => '[wire\\:name="capell-layout-builder::filament.layout-builder"]'],
                ['type' => 'click', 'selector' => '[data-layout-builder-action="add-container"]:visible'],
                ['type' => 'waitFor', 'selector' => '.fi-modal-window:visible'],
            ],
        ],
        'page-building-layout-builder-add-widget' => [
            'output' => 'docs/images/generated/page-building-layout-builder-add-widget.png',
            'target' => 'PageResource/EditPage',
            'url' => '/screenshot-fixtures/layout-builder-admin-editor',
            'waitFor' => '.layout-builder-visual-toolbar',
            'interactions' => [
                ['type' => 'scrollIntoView', 'selector' => '[wire\\:name="capell-layout-builder::filament.layout-builder"]'],
                ['type' => 'click', 'selector' => '[data-layout-builder-tree-item="main"]'],
                ['type' => 'waitFor', 'selector' => '[data-layout-builder-selected="true"]'],
                ['type' => 'click', 'selector' => '[data-layout-builder-action="add-widget"]:visible'],
                ['type' => 'waitFor', 'selector' => '.fi-modal-window:visible'],
            ],
        ],
    ];

    foreach ($expectedEntries as $id => $expectedEntry) {
        $entry = $entries->get($id);

        expect($entry)
            ->not->toBeNull()
            ->and($entry['docsPage'] ?? null)->toBe('docs/getting-started/building-pages.md')
            ->and($entry['surface'] ?? null)->toBe('admin')
            ->and($entry['targetType'] ?? null)->toBe('admin-surface')
            ->and($entry['scenario'] ?? null)->toBe('admin-form')
            ->and($entry['colorSchemes'] ?? null)->toBe(['light'])
            ->and($entry['required'] ?? false)->toBeTrue()
            ->and($entry['target'] ?? null)->toBe($expectedEntry['target'])
            ->and($entry['url'] ?? null)->toBe($expectedEntry['url'])
            ->and($entry['waitFor'] ?? null)->toBe($expectedEntry['waitFor'])
            ->and($entry['output'] ?? null)->toBe($expectedEntry['output'])
            ->and(pageBuildingGuideRepositoryPath($expectedEntry['output']))->toBeFile()
            ->and($entry['notes'] ?? '')->not->toBeEmpty()
            ->and($entry['useCase'] ?? '')->not->toBeEmpty()
            ->and($entry['interactions'] ?? null)->toBe($expectedEntry['interactions']);
    }
});
