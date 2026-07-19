<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\AeoRouteProvider;
use Capell\Frontend\Support\Routing\AeoRouteRegistry;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

it('serves a package registered aeo path ahead of the page fallback', function (): void {
    resolve(AeoRouteRegistry::class)->register(new class implements AeoRouteProvider
    {
        public function path(): string
        {
            return 'llms.txt';
        }

        public function handle(Request $request): Response
        {
            return response('# Test Site', Response::HTTP_OK, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }
    });

    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('capell-frontend.aeo.llms.txt')?->getActionName())
        ->toBe(AeoRouteRegistry::class . '@dispatch');

    $this->get('/llms.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertSee('# Test Site', false);
});

it('discovers aeo route providers through the stable container tag', function (): void {
    $provider = new class implements AeoRouteProvider
    {
        public function path(): string
        {
            return 'tagged.txt';
        }

        public function handle(Request $request): Response
        {
            return response('tagged');
        }
    };

    $container = new Container;
    $container->instance('test.aeo-route-provider', $provider);
    $container->tag('test.aeo-route-provider', AeoRouteProvider::TAG);

    $registry = new AeoRouteRegistry(
        $container,
        new FrontendRouteMiddlewareRegistry,
        new Router(new Dispatcher($container), $container),
    );

    expect($registry->providers())->toBe([$provider]);
});

it('uses a fallback provider only when a tagged provider has not claimed its path', function (): void {
    $tagged = aeoTestProvider('robots.txt', 'package');
    $fallback = aeoTestProvider('robots.txt', 'core');
    $container = new Container;
    $container->instance('test.robots-provider', $tagged);
    $container->tag('test.robots-provider', AeoRouteProvider::TAG);
    $registry = new AeoRouteRegistry(
        $container,
        new FrontendRouteMiddlewareRegistry,
        new Router(new Dispatcher($container), $container),
        [$fallback],
    );

    expect($registry->providers())->toBe([$tagged]);
});

it('rejects duplicate aeo paths after normalization', function (): void {
    $registry = resolve(AeoRouteRegistry::class);

    $registry->register(new class implements AeoRouteProvider
    {
        public function path(): string
        {
            return '/duplicate.txt';
        }

        public function handle(Request $request): Response
        {
            return response('first');
        }
    });

    $duplicate = new class implements AeoRouteProvider
    {
        public function path(): string
        {
            return 'duplicate.txt/';
        }

        public function handle(Request $request): Response
        {
            return response('second');
        }
    };

    expect(fn () => $registry->register($duplicate))
        ->toThrow(LogicException::class, 'duplicate.txt');
});

it('rejects wildcard and unsafe aeo paths', function (string $path): void {
    $provider = new readonly class($path) implements AeoRouteProvider
    {
        public function __construct(private string $registeredPath) {}

        public function path(): string
        {
            return $this->registeredPath;
        }

        public function handle(Request $request): Response
        {
            return response('unsafe');
        }
    };

    expect(fn () => resolve(AeoRouteRegistry::class)->register($provider))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'route parameter' => ['robots/{site}.txt'],
    'wildcard' => ['llms*'],
    'uppercase' => ['LLMS.txt'],
    'too long' => [str_repeat('a', 65)],
]);

function aeoTestProvider(string $path, string $body): AeoRouteProvider
{
    return new readonly class($path, $body) implements AeoRouteProvider
    {
        public function __construct(
            private string $registeredPath,
            private string $body,
        ) {}

        public function path(): string
        {
            return $this->registeredPath;
        }

        public function handle(Request $request): Response
        {
            return response($this->body);
        }
    };
}
