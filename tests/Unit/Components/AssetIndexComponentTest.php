<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Facades\Frontend;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Illuminate\Support\Facades\View;

uses(TestingFrontend::class);

beforeEach(function (): void {
    View::addNamespace('capell-frontend', dirname(__DIR__, 3) . '/packages/frontend/resources/views');
    Frontend::clearResolvedInstance(FrontendContextReader::class);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('getFrontendData')->andReturnNull();
    $context->shouldReceive('theme')->andReturn(Theme::factory()->make(['meta' => []]));

    app()->instance(FrontendContextReader::class, $context);
});

it('renders asset index component with icon prop', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'Test Asset',
        'icon' => 'heroicon-o-document',
        'url' => '/test',
    ]);

    $html = $view->render();

    expect($html)
        ->toContain('<svg')
        ->toContain('Test Asset');
});

it('renders asset index component with headingSize prop', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'Test Heading',
        'headingSize' => 'h2',
        'url' => '/test',
    ]);

    $html = $view->render();

    expect($html)
        ->toContain('<h2')
        ->toContain('Test Heading')
        ->toContain('</h2>');
});

it('renders asset index component with count badge', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'Test Asset',
        'count' => '5',
        'url' => '/test',
    ]);

    $html = $view->render();

    expect($html)
        ->toContain('5');
});

it('renders asset index component with linkText', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'Test Asset',
        'linkText' => 'View More',
        'url' => '/test-url',
    ]);

    $html = $view->render();

    expect($html)
        ->toContain('View More')
        ->toContain('/test-url');
});

it('does not render linkText when url is missing', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'Test Asset',
        'linkText' => 'View More',
        'url' => '',
    ]);

    $html = $view->render();

    expect($html)
        ->not->toContain('View More');
});

it('renders all props together', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'Complete Test',
        'icon' => 'heroicon-o-star',
        'headingSize' => 'h3',
        'count' => '42',
        'linkText' => 'Explore',
        'url' => '/complete',
    ]);

    $html = $view->render();

    expect($html)
        ->toContain('<svg')
        ->toContain('<h3')
        ->toContain('Complete Test')
        ->toContain('42')
        ->toContain('Explore')
        ->toContain('/complete');
});

it('does not render heading tag when headingSize is null', function (): void {
    $view = view('capell-frontend::components.asset.index', [
        'title' => 'No Heading Tag',
        'headingSize' => null,
        'url' => '/test',
    ]);

    $html = $view->render();

    expect($html)
        ->toContain('No Heading Tag');
});
