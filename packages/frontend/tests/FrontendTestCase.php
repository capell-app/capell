<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests;

use Capell\Core\Facades\CapellCore;
use Capell\Core\Tests\Support\View\Components\PackageAlert;
use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Frontend\Support\Locale\FrontendLocaleScope;
use Capell\Tests\AbstractTestCase;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Livewire\LivewireServiceProvider;
use MichalOravec\PaginateRoute\PaginateRouteServiceProvider;
use Override;
use Saade\FilamentAdjacencyList\FilamentAdjacencyListServiceProvider;

class FrontendTestCase extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        $this->forgetResolvedCacheServices();

        $this->registerAndMigrateSettings(
            CapellCore::getSettingMigrations(),
            __DIR__ . '/../../../packages/core/database/settings',
        );

        $this->registerAndMigrateSettings(
            resolve(SettingsMigrationProviderInterface::class)->getSettingMigrations(),
            __DIR__ . '/../../../packages/frontend/database/settings',
        );
    }

    /**
     * Frontend requests push the served site language into the application and
     * Carbon locale. ResolveFrontendMiddleware restores it and production also
     * has the terminating callback, but a Livewire update never passes through
     * that middleware and the test kernel never terminates the application. A
     * leaked Carbon locale silently changes locale-dependent values — month
     * names, the first day of the week — for every test that runs afterwards in
     * the same process, including other packages' tests.
     */
    protected function tearDown(): void
    {
        resolve(FrontendLocaleScope::class)->restore();

        parent::tearDown();
    }

    #[Override]
    protected function shouldResetTestbenchMigrationState(): bool
    {
        return false;
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
