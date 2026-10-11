<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Http\Middleware\EnsureInstalledRuntimeAvailable;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Tests\CoreTestCase;
use Capell\Core\Tests\Support\RuntimeLifecycleFixture;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class InstalledRuntimeRouteIsolationTest extends CoreTestCase
{
    public function test_activation_does_not_inherit_the_callers_route_group(): void
    {
        $this->registerInstalledFixture();
        $router = resolve(Router::class);
        $runtime = resolve(InstalledRuntimeLifecycle::class);
        $runtime->register(RuntimeLifecycleFixture::class, RuntimeLifecycleFixture::$packageName, 'runtime', static function () use ($router): void {
            $router->get('isolated-runtime', static fn (): string => 'runtime')->middleware('web')->name('isolated-runtime');
        });

        $router->group(['prefix' => 'caller', 'middleware' => ['caller.guard']], function () use ($router, $runtime): void {
            $runtime->refresh();
            $route = $router->get('after-runtime', static fn (): string => 'after');
            $this->assertSame('caller/after-runtime', $route->uri());
            $this->assertSame(['caller.guard'], $route->middleware());
        });

        $router->getRoutes()->refreshNameLookups();
        $registered = $router->getRoutes()->getByName('isolated-runtime');
        $this->assertInstanceOf(Route::class, $registered);
        $this->assertSame('isolated-runtime', $registered->uri());
        $this->assertSame(['web'], $registered->middleware());
        $this->assertCount(1, array_filter($router->getRoutes()->getRoutes(), static fn (Route $route): bool => $route->uri() === 'isolated-runtime'));
        $runtime->refresh();
        $this->assertCount(1, array_filter($router->getRoutes()->getRoutes(), static fn (Route $route): bool => $route->uri() === 'isolated-runtime'));
    }

    public function test_failed_activation_restores_the_caller_and_guards_partial_routes(): void
    {
        $this->registerInstalledFixture();
        $router = resolve(Router::class);
        $runtime = resolve(InstalledRuntimeLifecycle::class);
        $failure = new RuntimeException('isolated runtime failed');
        $runtime->register(RuntimeLifecycleFixture::class, RuntimeLifecycleFixture::$packageName, 'runtime', static function () use ($router, $failure): never {
            $router->get('partial-runtime', static fn (): string => 'partial')->name('partial-runtime');
            throw $failure;
        });

        $router->group(['prefix' => 'caller', 'middleware' => ['caller.guard']], function () use ($router, $runtime, $failure): void {
            try {
                $runtime->refresh();
                $this->fail('Expected the installed runtime exception.');
            } catch (RuntimeException $runtimeException) {
                $this->assertSame($failure, $runtimeException);
            }

            $route = $router->get('after-failure', static fn (): string => 'after');
            $this->assertSame('caller/after-failure', $route->uri());
            $this->assertSame(['caller.guard'], $route->middleware());
        });

        $router->getRoutes()->refreshNameLookups();
        $registered = $router->getRoutes()->getByName('partial-runtime');
        $this->assertInstanceOf(Route::class, $registered);
        $this->assertSame('partial-runtime', $registered->uri());
        $this->assertSame([EnsureInstalledRuntimeAvailable::class . ':' . RuntimeLifecycleFixture::$packageName], $registered->middleware());
        $this->assertTrue($runtime->isUnavailable());
    }

    public function test_activation_preserves_load_routes_from_cache_checks(): void
    {
        $this->registerInstalledFixture();
        $router = resolve(Router::class);
        $runtime = resolve(InstalledRuntimeLifecycle::class);
        $provider = new class(app()) extends ServiceProvider
        {
            public function registerCachedRoutes(): void
            {
                $this->loadRoutesFrom('/missing-isolated-package-routes.php');
            }
        };
        $runtime->register(RuntimeLifecycleFixture::class, RuntimeLifecycleFixture::$packageName, 'runtime', $provider->registerCachedRoutes(...));
        $initialRouteCount = count($router->getRoutes()->getRoutes());
        $router->get('cached-runtime', static fn (): string => 'cached')->middleware('web');
        $cachePath = app()->getCachedRoutesPath();
        $this->assertFileDoesNotExist($cachePath);

        file_put_contents($cachePath, '<?php return [];');
        app()->forgetInstance('routes.cached');

        try {
            $this->assertTrue(app()->routesAreCached());
            $router->group(['prefix' => 'caller', 'middleware' => ['caller.guard']], function () use ($router, $runtime): void {
                $runtime->refresh();
                $route = $router->get('after-cache', static fn (): string => 'after');
                $this->assertSame('caller/after-cache', $route->uri());
                $this->assertSame(['caller.guard'], $route->middleware());
            });

            $registered = $router->getRoutes()->get('GET')['cached-runtime'] ?? null;
            $this->assertInstanceOf(Route::class, $registered);
            $this->assertSame(['web'], $registered->middleware());
            $this->assertCount($initialRouteCount + 2, $router->getRoutes()->getRoutes());
        } finally {
            unlink($cachePath);
            app()->forgetInstance('routes.cached');
        }
    }

    private function registerInstalledFixture(): void
    {
        CapellCore::registerPackage(RuntimeLifecycleFixture::$packageName, serviceProviderClass: RuntimeLifecycleFixture::class);
        CapellCore::markPackageInstalled(RuntimeLifecycleFixture::$packageName);
    }
}
