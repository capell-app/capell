<?php

declare(strict_types=1);

use Capell\Admin\Actions\Pages\BuildPageFrontendResourceDiagnosticsAction;
use Capell\Core\Models\Page;

it('delegates page resource diagnostics to the frontend package binding', function (): void {
    $page = Page::factory()->createOne();

    app()->instance('capell.frontend.page-resource-diagnostics', fn (Page $resolvedPage): array => [
        'context' => [
            'page' => $resolvedPage->name,
        ],
        'graph' => [
            'assets' => [
                [
                    'source' => 'resources/css/gallery.css',
                ],
            ],
        ],
        'conflicts' => [],
    ]);

    $diagnostics = BuildPageFrontendResourceDiagnosticsAction::run($page);

    expect($diagnostics['context']['page'])->toBe($page->name)
        ->and($diagnostics['graph']['assets'])->toHaveCount(1)
        ->and($diagnostics['graph']['assets'][0]['source'])->toBe('resources/css/gallery.css')
        ->and($diagnostics['conflicts'])->toBe([]);
});

it('returns an empty diagnostics payload when the frontend binding is unavailable', function (): void {
    // Runtime-role Testbench boots trusted Core packages, so explicitly model an
    // unavailable package boundary instead of relying on provider discovery order.
    app()->bind('capell.frontend.page-resource-diagnostics', static fn (): null => null);

    expect(BuildPageFrontendResourceDiagnosticsAction::run(Page::factory()->createOne()))->toBe([]);
});
