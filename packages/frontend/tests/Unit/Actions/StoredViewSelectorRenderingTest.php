<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Render\BladeFrontendResponseRenderer;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\View\Components\Layout as LayoutComponent;
use Capell\Tests\Support\ScriptFixture;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

it('rejects previously stored unregistered selectors before rendering public chrome', function (string $model, string $field): void {
    $record = new $model(['meta' => [$field => 'private.report']]);
    $frontend = Mockery::mock(FrontendContextReader::class);
    $frontend->shouldReceive('theme')->andReturn($record instanceof Theme ? $record : null);
    $frontend->shouldReceive('layout')->andReturn($record instanceof Layout ? $record : null);
    $frontend->shouldReceive('page', 'site')->andReturnNull();
    expect(fn (): LayoutComponent => new LayoutComponent($frontend, resolve(Factory::class)))->toThrow(NotFoundHttpException::class);
})->with([[Layout::class, 'master_file'], [Layout::class, 'layout_file'], [Theme::class, 'header_file'], [Theme::class, 'footer_file']]);

it('keeps registered stored selectors usable without changing their data', function (): void {
    resolve(LayoutViewRegistry::class)->registerMaster('host::page', 'Host page');
    resolve(ThemeChromeRegistry::class)->registerHeader('host::header', 'Host header');

    $layout = new Layout(['meta' => ['master_file' => 'host::page', 'layout_file' => 'capell::app']]);
    $theme = new Theme(['meta' => ['header_file' => 'host::header']]);
    $frontend = Mockery::mock(FrontendContextReader::class);
    $frontend->shouldReceive('theme')->andReturn($theme);
    $frontend->shouldReceive('layout')->andReturn($layout);
    $frontend->shouldReceive('page', 'site')->andReturnNull();
    $component = new LayoutComponent($frontend, resolve(Factory::class));
    expect($component->layout?->meta)->toBe(['master_file' => 'host::page', 'layout_file' => 'capell::app'])
        ->and($component->theme?->meta)->toBe(['header_file' => 'host::header']);
});

it('keeps selector validation metadata out of anonymous error responses', function (): void {
    $frontend = Mockery::mock(FrontendContextReader::class);
    $frontend->shouldReceive('theme')->andReturn(new Theme(['meta' => ['header_file' => 'private.report']]));
    $frontend->shouldReceive('layout', 'page', 'site')->andReturnNull();
    Route::get('/invalid-stored-selector', fn (): LayoutComponent => new LayoutComponent($frontend, resolve(Factory::class)));
    test()->getJson('/invalid-stored-selector')->assertNotFound()->assertDontSee('meta.header_file')->assertDontSee('private.report');
});

it('omits unregistered ordinary private logo views at both render consumers', function (bool $inherited, string $consumer): void {
    $fixture = new ScriptFixture;
    $fixture->write('report.blade.php', '<p>Confidential financial report</p>');
    $fixture->write('logo.blade.php', '<svg aria-label="Trusted brand"></svg>');

    view()->addNamespace('selector-logo-test', $fixture->root);
    $site = new Site(['name' => 'Public site', 'meta' => $inherited ? [] : ['logo_blade_view' => 'selector-logo-test::report']]);
    $site->setRelation('blueprint', new Blueprint(['meta' => $inherited ? ['logo_blade_view' => 'selector-logo-test::report'] : []]));

    $frontend = Mockery::mock(FrontendContextReader::class);
    $frontend->shouldReceive('theme', 'layout', 'page')->andReturnNull();
    $frontend->shouldReceive('site')->andReturn($site);
    resolve(FrontendState::class)->withSite($site);
    try {
        $component = new LayoutComponent($frontend, resolve(Factory::class));
        expect(view('selector-logo-test::report')->render())->toContain('Confidential financial report');
        if ($consumer === 'layout') {
            expect($component->siteLogoBladeView())->toBeNull();
        } else {
            expect(view('capell::components.header.index')->render())->not->toContain('Confidential financial report');
        }

        resolve(ThemeChromeRegistry::class)->registerLogo('selector-logo-test::logo', 'Trusted brand');
        $site->meta = ['logo_blade_view' => 'selector-logo-test::logo'];
        expect($component->siteLogoBladeView())->toBe('selector-logo-test::logo');
        expect(view('capell::components.header.index')->render())->toContain('Trusted brand');
    } finally {
        $fixture->close();
    }
})->with([false, true])->with(['layout', 'header']);

it('rejects invalid selectors directly through the Blade response renderer', function (string $model, string $field): void {
    $record = new $model(['meta' => [$field => 'private.report']]);
    $context = new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: $record instanceof Layout ? $record : null,
        theme: $record instanceof Theme ? $record : null,
    );
    expect(fn (): Response|Responsable => (new BladeFrontendResponseRenderer)->render($context))
        ->toThrow(NotFoundHttpException::class);
})->with([[Layout::class, 'master_file'], [Layout::class, 'layout_file'], [Theme::class, 'header_file'], [Theme::class, 'footer_file']]);

it('returns a generic 404 through the Livewire page entry point for invalid selectors', function (string $model, string $field): void {
    $record = new $model(['meta' => [$field => 'private.report']]);
    resolve(FrontendState::class)
        ->withPage(new Page)
        ->withSite(new Site)
        ->withLanguage(new Language(['code' => 'en']))
        ->withLayout($record instanceof Layout ? $record : new Layout)
        ->withTheme($record instanceof Theme ? $record : new Theme);
    Route::get('/invalid-selector-livewire', Capell\Frontend\Livewire\Page\Page::class);
    test()->get('/invalid-selector-livewire')->assertNotFound()
        ->assertDontSee('private.report')->assertDontSee('meta.' . $field);
})->with([[Layout::class, 'master_file'], [Layout::class, 'layout_file'], [Theme::class, 'header_file'], [Theme::class, 'footer_file']]);
