<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Illuminate\Support\ServiceProvider;
use Override;

class RuntimeProviderInstallPackageFixture extends ServiceProvider
{
    public static bool $registered = false;

    #[Override]
    public function register(): void
    {
        self::$registered = true;
    }
}
