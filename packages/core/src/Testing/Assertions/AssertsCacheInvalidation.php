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
        if ($assertion !== null && $assertion() !== true) {
            throw new AssertionError("[cache.invalidation] {$packageRoot}: cache invalidation assertion failed.");
        }
    }
}
