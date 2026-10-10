<?php

declare(strict_types=1);

use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Livewire;

require_once dirname(__DIR__, 5) . '/tests/Support/FrontendViewFixture.php';

class PublicWidgetResultsFixture extends Component
{
    #[Computed]
    public function results(): Collection
    {
        return collect();
    }

    public function render(): View
    {
        return view('capell::livewire.page.results');
    }
}

it('keeps stable public component classes in rendered frontend component views', function (string $viewPath, string $expectedClass): void {
    $fixture = frontendViewFixture();
    $request = Request::create('/fixture/2');
    $route = Route::get('/fixture/{page?}', static fn (): string => '')->name('frontend-view-fixture');
    $route->bind($request);

    $request->setRouteResolver(static fn () => $route);
    app()->instance('request', $request);
    Route::dispatch($request);
    $fixture['results'] = new LengthAwarePaginator(['First', 'Second'], 20, 2, 2);
    $fixture['item'] = (object) ['active' => false, 'label' => 'Public item', 'data' => ['url' => '/item'], 'children' => []];
    $fixture['paginator'] = $fixture['results'];
    $fixture['elements'] = [[1 => '/page/1', 2 => '/page/2']];
    if (str_contains($viewPath, '/livewire/page/results.')) {
        $html = Livewire::test(PublicWidgetResultsFixture::class)->html();
    } elseif (str_contains($viewPath, '/livewire/')) {
        $html = view('capell::livewire.page.page')->render();
    } elseif (str_contains($viewPath, '/pagination/') && ! str_contains($viewPath, '/index.') && ! str_contains($viewPath, '/summary.')) {
        $view = 'capell::components.pagination.' . basename($viewPath, '.blade.php');
        $html = view($view, $fixture)->render();
    } else {
        $component = str_replace('/', '.', preg_replace('~^.*?/components/|\.blade\.php$~', '', $viewPath));
        $component = $component === 'layout.index' ? 'layout' : $component;
        $props = match ($component) {
            'asset.tile' => 'title="Public title"',
            'layout.main' => ':page="$page" :layout="null" :theme="[]"',
            'media.asset' => ':asset="$page" :loop="null"',
            'page.asset' => ':asset="$page" :loop="null" component-item="capell.asset.card" :with-image="true"',
            'page.neighbor-link' => ':neighbor-page="$page" neighbor="next"',
            'media.index', 'media.video', 'logo.index' => ':media="$media"',
            'list.item' => ':item="$item"',
            'page.results' => ':results="collect()" :wire-links="false"',
            'pagination.index', 'pagination.summary' => ':results="$results" :wire-links="false"',
            'page.title' => 'title="Public title"',
            default => '',
        };
        if ($component === 'footer.index') {
            resolve(RenderHookRegistry::class)->register(RenderHookLocation::FooterBefore, '<p>Public footer copy</p>');
        }

        $html = Blade::render('<x-capell::' . $component . ' ' . $props . '>Public slot</x-capell::' . $component . '>', $fixture);
    }

    expect(frontendHasClass($html, $expectedClass))->toBeTrue();
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

it('renders preloaded media from public asset views without database queries', function (string $viewPath): void {
    $fixture = frontendViewFixture();
    $component = str_contains($viewPath, '/page/') ? 'page.asset' : 'media.asset';
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        $html = Blade::render('<x-capell::' . $component . ' :asset="$page" :loop="null" component-item="capell.asset.card" :with-image="true" />', $fixture);
        $image = domElement(frontendRenderedDom($html), '//img');
        expect($image)->toBeInstanceOf(DOMElement::class);
        expect($image->getAttribute('src'))->toBe($fixture['media']->getFullUrl())
            ->and($connection->getQueryLog())->toBe([]);
    } finally {
        $connection->disableQueryLog();
    }
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

it('renders scalar page title variables while preserving nested page context', function (): void {
    frontendViewFixture();
    $html = Blade::render('<x-capell::page.title title="Welcome to :site" />');
    expect(trim((string) domElement(frontendRenderedDom($html), '//h1')->textContent))->toBe('Welcome to Public site');
});
