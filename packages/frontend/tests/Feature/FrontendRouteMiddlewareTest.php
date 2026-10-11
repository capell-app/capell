<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Tests\Support\RegistersLateFrontendRouteMiddleware;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;

uses(RegistersLateFrontendRouteMiddleware::class);

it('includes middleware contributed after Frontend boot on every page route', function (string $routeName, bool $cached): void {
    $router = resolve(Router::class);

    if ($cached) {
        $routes = new RouteCollection;

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'capell-frontend.')) {
                $route->prepareForSerialization();
                $routes->add($route);
            }
        }

        // Use the same serialisation and compilation as route:cache, then load it.
        $cachePath = tempnam(sys_get_temp_dir(), 'frontend-route-cache-');
        throw_unless(is_string($cachePath), RuntimeException::class, 'Could not create a route cache fixture.');

        try {
            file_put_contents($cachePath, '<?php return ' . var_export($routes->compile(), true) . ';');
            $router->setCompiledRoutes(require $cachePath);
        } finally {
            unlink($cachePath);
        }
    }

    $route = $router->getRoutes()->getByName($routeName);
    throw_unless($route instanceof Route, RuntimeException::class, 'Expected a frontend page route.');

    $expected = $router->resolveMiddleware(resolve(FrontendRouteMiddlewareRegistry::class)->all());

    expect($router->gatherRouteMiddleware($route))
        ->toBe($expected)
        ->toContain('test.frontend.early', 'test.frontend.late-before', 'test.frontend.late-after');
})->with(['capell-frontend.home', 'capell-frontend.index.php', 'capell-frontend.page'])
    ->with(['uncached' => false, 'cached' => true]);

it('keeps internal frontend endpoints outside the page middleware stack', function (string $routeName): void {
    $router = resolve(Router::class);
    $route = $router->getRoutes()->getByName($routeName);
    throw_unless($route instanceof Route, RuntimeException::class, 'Expected an internal frontend route.');

    expect($router->gatherRouteMiddleware($route))
        ->not->toContain('test.frontend.early', 'test.frontend.late-before', 'test.frontend.late-after');
})->with(['capell-frontend.language-preference', 'capell-frontend.activity']);
