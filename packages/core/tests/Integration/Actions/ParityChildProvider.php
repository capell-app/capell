<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class ParityChildProvider extends ServiceProvider implements ParityContribution
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->app->instance(self::class, $this);
        $this->registerInstalledRuntime(ParityMainProvider::$packageName, 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([self::class], 'runtime.parity.contributors');
    }
}
