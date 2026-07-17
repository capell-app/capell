<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Autoload;

use Illuminate\Contracts\Foundation\Application;

final class FrontendBridgeForProviderTest
{
    public static int $invocations = 0;

    public static function register(Application $application): void
    {
        self::$invocations++;
    }
}
