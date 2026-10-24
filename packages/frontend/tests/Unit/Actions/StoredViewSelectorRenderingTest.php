<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Themes\LayoutViewRegistry;
use Capell\Core\Support\Themes\ThemeChromeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\View\Components\Layout as LayoutComponent;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Route;
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
