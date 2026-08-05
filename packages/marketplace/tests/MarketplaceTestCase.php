<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests;

use AmidEsfahani\FilamentTinyEditor\TinyeditorServiceProvider;
use Awcodes\BadgeableColumn\BadgeableColumnServiceProvider;
use BezhanSalleh\FilamentShield\FilamentShieldServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Marketplace\Actions\RunPostOperationHealthCheckAction;
use Capell\Marketplace\Data\MarketplaceHealthCheckResultData;
use Capell\Marketplace\Enums\MarketplaceHealthProbeOutcome;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;
use Capell\Tests\AbstractTestCase;
use CmsMulti\FilamentClearCache\FilamentClearCacheServiceProvider;
use CodeWithDennis\FilamentSelectTree\FilamentSelectTreeServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Guava\IconPicker\IconPickerServiceProvider;
use Illuminate\Contracts\Config\Repository;
use LaraZeus\SpatieTranslatable\SpatieTranslatableServiceProvider;
use Livewire\LivewireServiceProvider;
use Override;
use Pboivin\FilamentPeek\FilamentPeekServiceProvider;
use Saade\FilamentAdjacencyList\FilamentAdjacencyListServiceProvider;
use STS\FilamentImpersonate\FilamentImpersonateServiceProvider;

abstract class MarketplaceTestCase extends AbstractTestCase
{
    protected function getPackageServiceName(): string
    {
        return 'capell-marketplace';
    }

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getDefaultPackageProviders(),
            ActionsServiceProvider::class,
            BadgeableColumnServiceProvider::class,
            SpatieTranslatableServiceProvider::class,
            TinyeditorServiceProvider::class,
            FilamentServiceProvider::class,
            FilamentAdjacencyListServiceProvider::class,
            FilamentShieldServiceProvider::class,
            FilamentSelectTreeServiceProvider::class,
            FilamentClearCacheServiceProvider::class,
            FilamentPeekServiceProvider::class,
            FilamentImpersonateServiceProvider::class,
            FormsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            IconPickerServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            NotificationsServiceProvider::class,
            CapellServiceProvider::class,
            AdminServiceProvider::class,
            AdminPanelProvider::class,
            LivewireServiceProvider::class,
            MarketplaceServiceProvider::class,
        ];
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Repository::class)->set('queue.connections.database.retry_after', 900);

        // The Marketplace suite is written against a host that can install
        // extensions itself. Tests about incapable hosts say so explicitly.
        $app->make(Repository::class)->set('capell.server_side_tooling', true);
        $app->make(Repository::class)->set('capell.release_root_mode', 'mutable');

        foreach (['installer', 'marketplace'] as $packageDirectory) {
            $manifest = json_decode(
                (string) file_get_contents(dirname(__DIR__, 2) . '/' . $packageDirectory . '/capell.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            if (is_array($manifest)) {
                CapellCore::registerManifestPackage(CapellManifestData::fromArray($manifest));
            }
        }

        CapellCore::forcePackageInstalled(AdminServiceProvider::$packageName);
        CapellCore::forcePackageInstalled(MarketplaceServiceProvider::$packageName);

        // Any test that reaches a successful install would otherwise run the
        // real post-operation health check, which spawns
        // `php artisan capell:health-probe` as a genuine subprocess. That ties
        // this whole suite to a working PHP binary and to how PHP_BINARY happens
        // to resolve on the runner, for a question those tests are not asking.
        // RunPostOperationHealthCheckActionTest rebinds the real action and is
        // where the subprocess stays covered end to end.
        $app->instance(RunPostOperationHealthCheckAction::class, new class
        {
            public function handle(int $budgetSeconds): MarketplaceHealthCheckResultData
            {
                return new MarketplaceHealthCheckResultData(
                    bootProbe: MarketplaceHealthProbeOutcome::Passed,
                    httpProbe: MarketplaceHealthProbeOutcome::Skipped,
                );
            }
        });
    }
}
