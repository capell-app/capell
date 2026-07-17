<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures\Autoload;

use Illuminate\Contracts\Foundation\Application;

final class FrontendBridgeForProviderTest
{
    public static ?Application $application = null;

    public static function register(Application $application): void
    {
        self::$application = $application;
    }
}
