<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use Capell\Core\Support\Runtime\RuntimeRoleBootstrap;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Override;
use RuntimeException;

final class RuntimeRoleTestbenchApplication extends TestbenchApplication
{
    #[Override]
    protected function resolveApplicationConfiguration(mixed $app): void
    {
        parent::resolveApplicationConfiguration($app);

        throw_unless($app instanceof Application, RuntimeException::class, 'Testbench did not resolve a Laravel application.');

        // Testbench calls this method immediately before registering providers. The runtime
        // role must therefore replace the provider and cache manifests before that bootstrapper.
        RuntimeRoleBootstrap::configureResolvedApplication($app);
    }
}
