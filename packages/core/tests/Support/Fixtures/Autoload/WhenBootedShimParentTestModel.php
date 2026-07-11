<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Concerns\WhenBootedShim;
use Closure;

final class WhenBootedShimParentTestModel extends WhenBootedShimParentBase
{
    use WhenBootedShim;

    public static function callWhenBooted(Closure $callback): void
    {
        self::whenBooted($callback);
    }
}
