<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Data\PackageData;
use Capell\Core\Events\InstalledRuntimeRefreshed;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\PackageRegistry\CapellPackageLoader;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RefreshInstalledPackageRuntimeAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Application $app) {}

    public function handle(PackageData $package, bool $replayBootedCallbacks = true): void
    {
        $this->app->make(InstalledRuntimeLifecycle::class)->assertCanActivate();
        $this->app->make(PackageSurfaceRegistrar::class)->duringPackageInstallation(function () use ($package, $replayBootedCallbacks): void {
            new CapellPackageLoader(
                $this->app,
                $this->app->make(CapellPackageRegistry::class),
                runtimeRoleResolver: $this->app->make(RuntimeRoleResolver::class),
                receipts: $this->app->make(ExtensionContributionReceiptRegistry::class),
            )->refreshPackage($package, $replayBootedCallbacks);
            $this->app->make(InstalledRuntimeLifecycle::class)->refresh();
            if ($replayBootedCallbacks) {
                $this->app->make(Dispatcher::class)->dispatch(new InstalledRuntimeRefreshed($package));
            }
        });
    }
}
