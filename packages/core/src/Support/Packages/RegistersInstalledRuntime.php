<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Adapter for ordinary Laravel main and child providers. Call
 * registerInstalledRuntime() from register(); put wiring in bootInstalledRuntime().
 *
 * @mixin ServiceProvider
 */
trait RegistersInstalledRuntime
{
    abstract protected function bootInstalledRuntime(): void;

    /**
     * Keep deferred registry contributions owned by the provider declaring them,
     * even when another package causes the registry to resolve later.
     *
     * @param  string  $name
     * @param  callable  $callback
     */
    #[Override]
    protected function callAfterResolving(mixed $name, mixed $callback): void
    {
        $receipts = $this->app->make(ExtensionContributionReceiptRegistry::class);
        $contexts = $receipts->activeContexts();

        parent::callAfterResolving($name, static function (mixed ...$arguments) use ($receipts, $contexts, $callback): void {
            $receipts->withContexts($contexts, static fn (): mixed => $callback(...$arguments));
        });
    }

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
            $this->app->make(InstalledRuntimeLifecycle::class)->providerBooted(static::class);
        });
    }
}
