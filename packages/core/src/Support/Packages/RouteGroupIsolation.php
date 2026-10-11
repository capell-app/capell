<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Closure;
use Illuminate\Routing\Router;
use ReflectionProperty;

final class RouteGroupIsolation
{
    /**
     * Register routes synchronously without inheriting the caller's route groups.
     * Nested calls restore their enclosing stack, including when a callback throws.
     * Route-cache checks remain the callback's responsibility (e.g. loadRoutesFrom()).
     *
     * @param  Closure(): void  $callback
     */
    public static function run(Router $router, Closure $callback): void
    {
        $groups = new ReflectionProperty(Router::class, 'groupStack');
        $previous = $router->getGroupStack();
        $groups->setValue($router, []);

        try {
            $callback();
        } finally {
            $groups->setValue($router, $previous);
        }
    }
}
