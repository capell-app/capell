<?php

declare(strict_types=1);

use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\FrontendRenderPayload;
use Capell\Frontend\Facades\Frontend;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\ComponentAttributeBag;
use Sinnbeck\DomAssertions\Asserts\AssertElement;

uses(TestingFrontend::class);

beforeEach(function (): void {
    View::addNamespace('capell-frontend', dirname(__DIR__, 3) . '/packages/frontend/resources/views');
    Frontend::clearResolvedInstance(FrontendContextReader::class);

    $context = Mockery::mock(FrontendContextReader::class);
    $context->shouldReceive('getFrontendData')->andReturnNull();
    $context->shouldReceive('renderPayload')->andReturn(new FrontendRenderPayload);
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

it('lets image-less blog-card content use the full card width', function (): void {
    TestResponse::fromBaseResponse(new Response(view('capell-frontend::components.asset.index', [
        'title' => 'Studio news',
        'url' => '/news/studio',
        'attributes' => new ComponentAttributeBag(['class' => 'capell-blog-article-card']),
    ])->render()))->assertElementExists('.capell-blog-article-card', function (AssertElement $card): void {
        $card->doesntContain('.asset-image')
            ->doesntContain('script, iframe, [data-capell-authoring-selector]')
            ->contains('a', ['href' => '/news/studio']);
    })->assertDoesntExist('.capell-blog-article-card[style*="grid-template-columns"]');
});

it('keeps the blog-card media column when an image is present', function (): void {
    TestResponse::fromBaseResponse(new Response(view('capell-frontend::components.asset.index', [
        'title' => 'Studio news',
        'url' => '/news/studio',
        'image' => '/images/studio.jpg',
        'attributes' => new ComponentAttributeBag(['class' => 'capell-blog-article-card']),
    ])->render()))->assertElementExists('.capell-blog-article-card', function (AssertElement $card): void {
        $card->contains('.asset-image img', ['alt' => 'Studio news'])
            ->doesntContain('script, iframe, [data-capell-authoring-selector]');
    })->assertContainsElement('.capell-blog-article-card[style*="grid-template-columns"]');
});
