<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Illuminate\Support\ServiceProvider;
use stdClass;

final class BootingReceiptTestProvider extends ServiceProvider
{
    public function boot(): void
    {
        resolve(PackageSurfaceRegistrar::class)->models([stdClass::class]);
    }
}
