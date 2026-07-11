<?php

declare(strict_types=1);

use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Facades\File;

it('keeps stable public component classes in rendered frontend component views', function (string $viewPath, string $expectedClass): void {
    $contents = File::get(dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . $viewPath);

    expect($contents)->toContain($expectedClass);
})->with([
    'asset index' => ['packages/frontend/resources/views/components/asset/index.blade.php', 'capell-asset-index'],
    'asset tile' => ['packages/frontend/resources/views/components/asset/tile.blade.php', 'capell-asset-tile'],
    'content' => ['packages/frontend/resources/views/components/content.blade.php', 'capell-components-content'],
    'footer' => ['packages/frontend/resources/views/components/footer/index.blade.php', 'capell-footer-index'],
    'header' => ['packages/frontend/resources/views/components/header/index.blade.php', 'capell-header-index'],
    'layout index' => ['packages/frontend/resources/views/components/layout/index.blade.php', 'capell-layout-index'],
    'layout main' => ['packages/frontend/resources/views/components/layout/main.blade.php', 'capell-layout-main'],
    'list index' => ['packages/frontend/resources/views/components/list/index.blade.php', 'capell-list-index'],
    'list item' => ['packages/frontend/resources/views/components/list/item.blade.php', 'capell-list-item'],
    'list list item' => ['packages/frontend/resources/views/components/list/list-item.blade.php', 'capell-list-list-item'],
    'logo index' => ['packages/frontend/resources/views/components/logo/index.blade.php', 'capell-logo-index'],
    'logo title' => ['packages/frontend/resources/views/components/logo/title.blade.php', 'capell-logo-title'],
    'media asset' => ['packages/frontend/resources/views/components/media/asset.blade.php', 'capell-media-asset'],
    'media index' => ['packages/frontend/resources/views/components/media/index.blade.php', 'capell-media-index'],
    'media video' => ['packages/frontend/resources/views/components/media/video.blade.php', 'capell-media-video'],
    'no results' => ['packages/frontend/resources/views/components/no-results.blade.php', 'capell-no-results'],
    'page asset' => ['packages/frontend/resources/views/components/page/asset.blade.php', 'capell-page-asset'],
    'page neighbor link' => ['packages/frontend/resources/views/components/page/neighbor-link.blade.php', 'capell-page-neighbor-link'],
    'page results' => ['packages/frontend/resources/views/components/page/results.blade.php', 'capell-page-results'],
    'page title' => ['packages/frontend/resources/views/components/page/title.blade.php', 'capell-page-title'],
    'pagination index' => ['packages/frontend/resources/views/components/pagination/index.blade.php', 'capell-pagination-index'],
    'pagination links' => ['packages/frontend/resources/views/components/pagination/links.blade.php', 'capell-pagination-links'],
    'pagination simple links' => ['packages/frontend/resources/views/components/pagination/simple-links.blade.php', 'capell-pagination-simple-links'],
    'pagination summary' => ['packages/frontend/resources/views/components/pagination/summary.blade.php', 'capell-pagination-summary'],
    'pagination wire links' => ['packages/frontend/resources/views/components/pagination/wire-links.blade.php', 'capell-pagination-wire-links'],
    'pagination wire simple links' => ['packages/frontend/resources/views/components/pagination/wire-simple-links.blade.php', 'capell-pagination-wire-simple-links'],
    'livewire page' => ['packages/frontend/resources/views/livewire/page/page.blade.php', 'capell-livewire-page-page'],
    'livewire page results' => ['packages/frontend/resources/views/livewire/page/results.blade.php', 'capell-livewire-page-results'],
]);

it('reads preloaded media relations from public asset views without lazy loading', function (string $viewPath): void {
    $contents = File::get(dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . $viewPath);

    expect($contents)
        ->toContain("getRelation('media')->first()")
        ->not->toContain('->media->first()');
})->with([
    'media asset' => ['packages/frontend/resources/views/components/media/asset.blade.php'],
    'page asset' => ['packages/frontend/resources/views/components/page/asset.blade.php'],
]);

it('does not render an empty default footer without footer hook content', function (): void {
    expect(view('capell::components.footer.index')->render())->toBe('');
});

it('renders the default footer when footer hooks contribute public content', function (): void {
    resolve(RenderHookRegistry::class)->register(
        RenderHookLocation::FooterBefore,
        '<p>Public footer copy</p>',
    );

    expect(view('capell::components.footer.index')->render())
        ->toContain('capell-footer-index')
        ->toContain('Public footer copy');
});
