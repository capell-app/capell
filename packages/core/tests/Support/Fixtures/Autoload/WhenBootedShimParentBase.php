<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Closure;

class WhenBootedShimParentBase
{
    public static mixed $received = null;

    protected static function whenBooted(Closure $callback): void
    {
        self::$received = $callback();
    }
}
