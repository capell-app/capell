<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class LoaderDependentRuntimeFixture extends ServiceProvider
{
    use RegistersInstalledRuntime;

    /** @var list<string> */
    public static array $order = [];

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/loader-dependent');
    }

    protected function bootInstalledRuntime(): void
    {
        self::$order[] = 'dependent';
    }
}
