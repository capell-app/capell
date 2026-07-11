<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Concerns\WhenBootedShim;
use Closure;

final class WhenBootedShimFallbackTestModel extends WhenBootedShimFallbackBase
{
    use WhenBootedShim;

    public static function callWhenBooted(Closure $callback): void
    {
        self::whenBooted($callback);
    }
}
