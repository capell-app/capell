<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class LatePanelRuntimeProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/panel-runtime', 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([LateSecurityPanelExtender::class], AdminPanelExtender::TAG);
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(LateRuntimePage::class));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(LateRuntimeResource::class, 'Runtime'));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::widget(LateRuntimeWidget::class));
    }
}
