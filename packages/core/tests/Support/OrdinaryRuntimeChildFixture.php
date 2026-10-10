<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class OrdinaryRuntimeChildFixture extends ServiceProvider
{
    use RegistersInstalledRuntime;

    public int $calls = 0;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime(RuntimeLifecycleFixture::$packageName, 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->calls++;
    }
}
