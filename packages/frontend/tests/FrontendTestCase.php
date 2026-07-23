<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Tests\Support\View\Components\PackageAlert;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Tests\AbstractTestCase;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Blade;
use Livewire\LivewireServiceProvider;
use MichalOravec\PaginateRoute\PaginateRouteServiceProvider;
use Override;
use Saade\FilamentAdjacencyList\FilamentAdjacencyListServiceProvider;

class FrontendTestCase extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->registerAndMigrateSettings(
            CapellCore::getSettingMigrations(),
            __DIR__ . '/../../../packages/core/database/settings',
        );

        $this->registerAndMigrateSettings(
            resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(),
            __DIR__ . '/../../../packages/frontend/database/settings',
        );
    }

    protected function getPackageServiceName(): string
    {
        return 'capell-frontend';
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        CapellCore::forcePackageInstalled(FrontendServiceProvider::$packageName);
        $app->make(Factory::class)->addNamespace('capell-admin', __DIR__ . '/../../../packages/admin/resources/views');

        Blade::anonymousComponentPath(
            __DIR__ . '/../../../packages/admin/resources/views/components',
            'capell-admin',
        );
        Blade::component('capell::widget.default', PackageAlert::class);
    }

    /**
     * @return class-string[]
     */
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getDefaultPackageProviders(),
            ActionsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            SupportServiceProvider::class,
            WidgetsServiceProvider::class,
            FrontendServiceProvider::class,
            FilamentAdjacencyListServiceProvider::class,
            PaginateRouteServiceProvider::class,
            LivewireServiceProvider::class,
        ];
    }
}
