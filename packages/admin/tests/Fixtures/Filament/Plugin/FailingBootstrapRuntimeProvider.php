<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

final class FailingBootstrapRuntimeProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    public static string $failure = 'none';

    #[Override]
    public function register(): void
    {
        CapellCore::registerPackage('test/bootstrap-failure');
        CapellCore::forcePackageInstalled('test/bootstrap-failure', true);
        $this->registerInstalledRuntime('test/bootstrap-failure');
        if (self::$failure === 'panel') {
            $this->app->tag([FailingBootstrapPanelExtender::class], AdminPanelExtender::TAG);
        }
    }

    protected function bootInstalledRuntime(): void
    {
        Route::get('/runtime-failed-package', static fn (): string => 'package route');
        throw_if(self::$failure === 'hook', RuntimeException::class, 'Cold bootstrap hook failure.');
    }
}
