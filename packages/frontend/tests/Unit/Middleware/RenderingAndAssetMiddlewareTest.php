<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Http\Middleware\RenderingStrategyMiddleware;
use Capell\Frontend\Support\Assets\AssetOptimizationMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

it('adds the page rendering strategy header from frontend context', function (): void {
    bindFrontendContext(page: new Page([
        'meta' => ['rendering_strategy' => RenderingStrategyEnum::BladeWithIslands->value],
    ]));

    $response = resolve(RenderingStrategyMiddleware::class)->handle(
        Request::create('/about'),
        fn (): Response => new Response('<html></html>', Response::HTTP_OK),
    );

    expect($response->headers->get('X-Rendering-Strategy'))
        ->toBe(RenderingStrategyEnum::BladeWithIslands->value);
});

it('falls back to blade-only rendering for missing or unavailable page context', function (): void {
    bindFrontendContext(page: new Page(['meta' => []]));

    $response = resolve(RenderingStrategyMiddleware::class)->handle(
        Request::create('/about'),
        fn (): Response => new Response('<html></html>', Response::HTTP_OK),
    );

    app()->forgetInstance(FrontendContextReader::class);

    $missingContextResponse = resolve(RenderingStrategyMiddleware::class)->handle(
        Request::create('/missing-context'),
        fn (): Response => new Response('<html></html>', Response::HTTP_OK),
    );

    expect($response->headers->get('X-Rendering-Strategy'))
        ->toBe(RenderingStrategyEnum::BladeOnly->value)
        ->and($missingContextResponse->headers->has('X-Rendering-Strategy'))->toBeFalse();
});

it('injects asset hints into successful html responses only', function (): void {
    $theme = new Theme;
    $theme->setAttribute('assetUrl', 'https://cdn.example.test');

    bindFrontendContext(theme: $theme);

    $htmlResponse = resolve(AssetOptimizationMiddleware::class)->handle(
        Request::create('/home'),
        fn (): Response => new Response(
            '<html><head><title>Home</title></head><body></body></html>',
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ),
    );

    $jsonResponse = resolve(AssetOptimizationMiddleware::class)->handle(
        Request::create('/api/home'),
        fn (): Response => new Response(
            '{"ok":true}',
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        ),
    );

    expect((string) $htmlResponse->getContent())
        ->toContain('<link rel="dns-prefetch" href="https://cdn.example.test"></head>')
        ->and((string) $jsonResponse->getContent())->toBe('{"ok":true}');
});

it('registers the opt-in asset optimization middleware alias', function (): void {
    expect(resolve(Router::class)->getMiddleware()['frontend.asset-optimization'] ?? null)
        ->toBe(AssetOptimizationMiddleware::class);
});

it('leaves asset responses unchanged when optimization cannot run', function (): void {
    app()->forgetInstance(FrontendContextReader::class);

    $response = resolve(AssetOptimizationMiddleware::class)->handle(
        Request::create('/home'),
        fn (): Response => new Response(
            '<html><head></head><body></body></html>',
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ),
    );

    expect((string) $response->getContent())
        ->toBe('<html><head></head><body></body></html>');
});

function bindFrontendContext(?Page $page = null, ?Theme $theme = null): void
{
    app()->instance(
        FrontendContextReader::class,
        new readonly class($page, $theme) implements FrontendContextReader
        {
            public function __construct(
                private ?Page $page,
                private ?Theme $theme,
            ) {}

            public function site(): ?Site
            {
                return null;
            }

            public function language(): ?Language
            {
                return null;
            }

            public function page(): ?Page
            {
                return $this->page;
            }

            public function layout(): ?Layout
            {
                return null;
            }

            public function theme(): ?Theme
            {
                return $this->theme;
            }

            public function params(): array
            {
                return [];
            }

            public function slug(): ?string
            {
                return null;
            }

            public function isError(): bool
            {
                return false;
            }

            public function setFrontendData(string $key, mixed $value): self
            {
                return $this;
            }

            public function getFrontendData(?string $key = null): mixed
            {
                return $key === null ? [] : null;
            }
        },
    );
}
