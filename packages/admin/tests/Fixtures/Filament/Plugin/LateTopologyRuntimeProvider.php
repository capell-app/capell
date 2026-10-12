<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\ServiceProvider;
use Override;

final class LateTopologyRuntimeProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/panel-topology', 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([LateTopologyPanelExtender::class], AdminPanelExtender::TAG);
    }
}
