<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Illuminate\Support\ServiceProvider;

/**
 * Adapter for ordinary Laravel main and child providers. Call
 * registerInstalledRuntime() from register(); put wiring in bootInstalledRuntime().
 *
 * @mixin ServiceProvider
 */
trait RegistersInstalledRuntime
{
    abstract protected function bootInstalledRuntime(): void;

    protected function registerInstalledRuntime(string $package, string $bucket = 'runtime'): void
    {
        $this->app->singletonIf(InstalledRuntimeLifecycle::class);
        $this->app->make(InstalledRuntimeLifecycle::class)->register(
            static::class,
            $package,
            $bucket,
            $this->bootInstalledRuntime(...),
        );
        $this->booted(function (): void {
            $this->app->booted(function (): void {
                $this->app->make(InstalledRuntimeLifecycle::class)->refresh();
            });
        });
    }
}
