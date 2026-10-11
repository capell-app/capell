<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Feature;

use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Frontend\Tests\Support\LateFrontendRouteMiddlewareTestCase;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class FrontendRouteMiddlewareTest extends LateFrontendRouteMiddlewareTestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pageRoutes(): iterable
    {
        foreach (['capell-frontend.home', 'capell-frontend.index.php', 'capell-frontend.page'] as $routeName) {
            yield $routeName . ' uncached' => [$routeName, false];
            yield $routeName . ' cached' => [$routeName, true];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function internalRoutes(): iterable
    {
        yield 'language preference' => ['capell-frontend.language-preference'];
        yield 'activity beacon' => ['capell-frontend.activity'];
    }

    #[Test]
    #[DataProvider('pageRoutes')]
    public function it_includes_middleware_contributed_after_frontend_boot_on_every_page_route(string $routeName, bool $cached): void
    {
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
            $cachePath = sys_get_temp_dir() . '/frontend-route-cache-' . bin2hex(random_bytes(8)) . '.php';

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
        $actual = $router->gatherRouteMiddleware($route);

        $this->assertSame($expected, $actual);

        foreach (['test.frontend.early', 'test.frontend.late-before', 'test.frontend.late-after'] as $middleware) {
            $this->assertContains($middleware, $actual);
        }
    }

    #[Test]
    #[DataProvider('internalRoutes')]
    public function it_keeps_internal_frontend_endpoints_outside_the_page_middleware_stack(string $routeName): void
    {
        $router = resolve(Router::class);
        $route = $router->getRoutes()->getByName($routeName);
        throw_unless($route instanceof Route, RuntimeException::class, 'Expected an internal frontend route.');

        $actual = $router->gatherRouteMiddleware($route);

        foreach (['test.frontend.early', 'test.frontend.late-before', 'test.frontend.late-after'] as $middleware) {
            $this->assertNotContains($middleware, $actual);
        }
    }
}
