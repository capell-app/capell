<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class BootstrapRuntimeProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        CapellCore::registerPackage('test/bootstrap-panel');
        CapellCore::forcePackageInstalled('test/bootstrap-panel', true);
        $this->registerInstalledRuntime('test/bootstrap-panel', 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(BootstrapRuntimePage::class));
    }
}
