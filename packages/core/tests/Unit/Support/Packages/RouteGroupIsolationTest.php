<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Support\Packages;

use Capell\Core\Support\Packages\RouteGroupIsolation;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @extends TestCase<MockObject> */
final class RouteGroupIsolationTest extends TestCase
{
    public function test_callback_routes_keep_only_their_own_group_attributes(): void
    {
        $router = new Router(new Dispatcher);

        $router->group(['prefix' => 'caller', 'middleware' => ['caller.guard'], 'as' => 'caller.', 'domain' => 'caller.test'], function () use ($router): void {
            RouteGroupIsolation::run($router, function () use ($router): void {
                $router->group(['prefix' => 'package', 'middleware' => ['package.guard'], 'as' => 'package.'], function () use ($router): void {
                    $route = $router->get('inside', static fn (): string => 'inside')->name('inside');

                    $this->assertSame('package/inside', $route->uri());
                    $this->assertSame(['package.guard'], $route->middleware());
                    $this->assertSame('package.inside', $route->getName());
                    $this->assertNull($route->getDomain());
                });
            });

            $route = $router->get('after', static fn (): string => 'after')->name('after');
            $this->assertSame('caller/after', $route->uri());
            $this->assertSame(['caller.guard'], $route->middleware());
            $this->assertSame('caller.after', $route->getName());
            $this->assertSame('caller.test', $route->getDomain());
        });

        $route = $router->get('outside', static fn (): string => 'outside');
        $this->assertSame('outside', $route->uri());
        $this->assertSame([], $route->middleware());
    }

    public function test_callback_exception_restores_the_caller_even_after_an_unfinished_group(): void
    {
        $router = new Router(new Dispatcher);
        $failure = new RuntimeException('route registration failed');

        $router->group(['prefix' => 'caller', 'middleware' => ['caller.guard']], function () use ($router, $failure): void {
            try {
                RouteGroupIsolation::run($router, static function () use ($router, $failure): void {
                    $router->group(['prefix' => 'unfinished', 'middleware' => ['unfinished.guard']], static function () use ($failure): never {
                        throw $failure;
                    });
                });
                $this->fail('Expected the route registration exception.');
            } catch (RuntimeException $runtimeException) {
                $this->assertSame($failure, $runtimeException);
            }

            $route = $router->get('after', static fn (): string => 'after');
            $this->assertSame('caller/after', $route->uri());
            $this->assertSame(['caller.guard'], $route->middleware());
        });

        $this->assertSame([], $router->get('outside', static fn (): string => 'outside')->middleware());
    }

    public function test_nested_isolation_restores_each_enclosing_group(): void
    {
        $router = new Router(new Dispatcher);

        $router->group(['prefix' => 'caller', 'middleware' => ['caller.guard']], function () use ($router): void {
            RouteGroupIsolation::run($router, function () use ($router): void {
                $router->group(['prefix' => 'first', 'middleware' => ['first.guard']], function () use ($router): void {
                    RouteGroupIsolation::run($router, function () use ($router): void {
                        $route = $router->get('nested', static fn (): string => 'nested');
                        $this->assertSame('nested', $route->uri());
                        $this->assertSame([], $route->middleware());
                    });

                    $route = $router->get('after-nested', static fn (): string => 'after nested');
                    $this->assertSame('first/after-nested', $route->uri());
                    $this->assertSame(['first.guard'], $route->middleware());
                });

                $this->assertSame('after-first', $router->get('after-first', static fn (): string => 'after first')->uri());
            });

            $route = $router->get('after', static fn (): string => 'after');
            $this->assertSame('caller/after', $route->uri());
            $this->assertSame(['caller.guard'], $route->middleware());
        });
    }

    public function test_nested_exception_restores_the_immediate_enclosing_group(): void
    {
        $router = new Router(new Dispatcher);

        RouteGroupIsolation::run($router, function () use ($router): void {
            $router->group(['prefix' => 'first', 'middleware' => ['first.guard']], function () use ($router): void {
                try {
                    RouteGroupIsolation::run($router, static function () use ($router): void {
                        $router->group(['prefix' => 'unfinished'], static function (): never {
                            throw new RuntimeException('nested registration failed');
                        });
                    });
                    $this->fail('Expected the nested route registration exception.');
                } catch (RuntimeException $runtimeException) {
                    $this->assertSame('nested registration failed', $runtimeException->getMessage());
                }

                $route = $router->get('after', static fn (): string => 'after');
                $this->assertSame('first/after', $route->uri());
                $this->assertSame(['first.guard'], $route->middleware());
            });
        });
    }

    public function test_registration_without_an_enclosing_group_remains_ungrouped(): void
    {
        $router = new Router(new Dispatcher);

        RouteGroupIsolation::run($router, function () use ($router): void {
            $route = $router->get('inside', static fn (): string => 'inside');
            $this->assertSame('inside', $route->uri());
            $this->assertSame([], $route->middleware());
        });

        $this->assertSame('outside', $router->get('outside', static fn (): string => 'outside')->uri());
    }
}
