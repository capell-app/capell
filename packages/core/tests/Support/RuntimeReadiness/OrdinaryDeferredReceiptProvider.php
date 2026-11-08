<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\RuntimeReadiness;

use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;

final class OrdinaryDeferredReceiptProvider extends ServiceProvider
{
    use DefersReceiptCallbacks;
    use RegistersInstalledRuntime;

    protected function bootInstalledRuntime(): void {}
}
