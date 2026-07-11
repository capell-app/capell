<?php

declare(strict_types=1);

namespace Capell\Tests;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Illuminate\Support\Facades\Route;
use Override;

class PackagesTestCase extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerAndMigrateSettings(
            resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(),
            dirname(__DIR__) . '/packages/frontend/database/settings',
        );
    }

    #[Override]
    protected function defineRoutes(mixed $router): void
    {
        parent::defineRoutes($router);

        $this->registerInstallRoutes();
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            ...parent::getPackageProviders($app),
            FrontendServiceProvider::class,
        ];
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->registerInstallRoutes();

        CapellCore::forcePackageInstalled(FrontendServiceProvider::$packageName);
    }

    private function registerInstallRoutes(): void
    {
        if (! Route::has('capell-installer.show')) {
            require dirname(__DIR__) . '/packages/installer/routes/web.php';
        }
    }
}
