<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Providers;

use Illuminate\Support\ServiceProvider;
use Override;

final class GeneratedTestbenchProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->instance('generated-testbench-provider', true);
    }
}
