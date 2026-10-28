<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use ReflectionMethod;

/**
 * Invokes @deprecated 1.x compatibility adapters from tests without tripping
 * the static gates that guard production code against them.
 *
 * Tests that call this live under a `tests/**\/Legacy/` directory and pass the
 * adapter as a string FQCN: a `::class` reference would emit
 * classConstant.deprecatedClass in phpstan/deprecations.neon, and rector.php
 * skips StringClassNameToClassConstantRector for that directory so the
 * string is not rewritten back. Reflection keeps level 8 satisfied without a
 * typed reference to the deprecated class. Remove with the adapters in 2.0.
 */
final class LegacyAdapter
{
    public static function invoke(string $fqcn, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($fqcn, $method);

        if ($reflection->isStatic()) {
            return $reflection->invoke(null, ...$args);
        }

        return $reflection->invoke(resolve($fqcn), ...$args);
    }
}
