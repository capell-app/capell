<?php

declare(strict_types=1);

namespace Capell\Core\Support\Reporting;

use Fiber;
use Illuminate\Contracts\Container\Container;
use WeakMap;

final class SignalDispatchGuard
{
    /** @var WeakMap<Container, WeakMap<object, true>>|null */
    private static ?WeakMap $active = null;

    public static function enter(Container $container): bool
    {
        self::$active ??= new WeakMap;
        $execution = Fiber::getCurrent() ?? $container;
        $executions = self::$active[$container] ??= new WeakMap;

        if (isset($executions[$execution])) {
            return false;
        }

        $executions[$execution] = true;

        return true;
    }

    public static function leave(Container $container): void
    {
        if (! self::$active instanceof WeakMap || ! isset(self::$active[$container])) {
            return;
        }

        $executions = self::$active[$container];
        unset($executions[Fiber::getCurrent() ?? $container]);

        if (count($executions) === 0) {
            unset(self::$active[$container]);
        }
    }
}
