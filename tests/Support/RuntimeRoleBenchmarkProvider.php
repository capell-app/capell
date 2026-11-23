<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * The boot benchmark runs Artisan through Testbench's console, which builds a
 * plain application that never loads bootstrap/app.php. This provider is
 * listed first in the benchmark workspace so that application is bootstrapped
 * for its runtime role before the Capell providers check for it.
 */
final class RuntimeRoleBenchmarkProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        RuntimeRoleBootstrap::configureResolvedEnvironment($this->app);
    }
}
