<?php

declare(strict_types=1);

namespace Capell\Core\Testing\Assertions;

use AssertionError;
use Closure;

final class AssertsCacheInvalidation
{
    /** @param Closure(): bool|null $assertion */
    public static function run(string $packageRoot, ?Closure $assertion): void
    {
        throw_if($assertion instanceof Closure && $assertion() !== true, AssertionError::class, sprintf('[cache.invalidation] %s: cache invalidation assertion failed.', $packageRoot));
    }
}
