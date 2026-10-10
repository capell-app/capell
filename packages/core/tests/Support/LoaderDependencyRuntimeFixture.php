<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class LoaderDependencyRuntimeFixture extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/loader-dependency');
    }

    protected function bootInstalledRuntime(): void
    {
        LoaderDependentRuntimeFixture::$order[] = 'dependency';
    }
}
