<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Closure;
use ReflectionMethod;

/**
 * Compatibility shim for packages that call `Model::whenBooted()`.
 *
 * `kalnoy/nestedset`'s `bootNodeTrait` calls `static::whenBooted(...)` to
 * defer observer registration until model boot completes. Trait methods take
 * precedence over inherited parent-class methods, so this shim forwards to the
 * framework helper when it exists. When a parent model has no helper, the
 * consumer is already mid-boot by the time it calls the method via
 * `bootTraits()`, so executing the callback synchronously is equivalent to the
 * deferred path.
 */
trait WhenBootedShim
{
    protected static function whenBooted(Closure $callback): void
    {
        $parentClass = self::parentClassName();

        if ($parentClass !== false && method_exists($parentClass, 'whenBooted')) {
            $parentWhenBooted = new ReflectionMethod($parentClass, 'whenBooted')->getClosure();

            forward_static_call($parentWhenBooted, $callback);

            return;
        }

        $callback();
    }

    private static function parentClassName(): string|false
    {
        return get_parent_class(static::class);
    }
}
