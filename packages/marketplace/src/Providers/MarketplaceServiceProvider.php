<?php

declare(strict_types=1);

namespace Capell\Marketplace\Providers;

use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Marketplace\Actions\BuildMarketplaceInstallOperationsSummaryAction;
use Capell\Marketplace\Actions\VerifyMarketplaceSignedActivationAction;
use Capell\Marketplace\Bridges\MarketplaceAdminBridge;
use Capell\Marketplace\Console\Commands\MarketplaceDoctorCommand;
use Capell\Marketplace\Console\Commands\MarketplaceExtensionsLifecycleQaCommand;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;
use Override;
use Spatie\LaravelPackageTools\Package;

class MarketplaceServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-marketplace';

    public static string $packageName = 'capell-app/marketplace';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile()
            ->hasCommand(MarketplaceDoctorCommand::class)
            ->hasCommand(MarketplaceExtensionsLifecycleQaCommand::class)
            ->hasRoute('marketplace')
            ->hasViews(self::$name)
            ->hasTranslations()
            ->hasMigrations([
                '2026_05_10_190837_01_create_marketplace_instances_table',
                '2026_05_10_190837_02_create_marketplace_update_advisory_snapshots_table',
                '2026_05_10_190837_03_create_marketplace_update_notice_dismissals_table',
                '2026_05_10_190837_05_create_marketplace_install_intents_table',
                '2026_05_10_190837_07_create_marketplace_account_connection_sessions_table',
                '2026_05_10_190837_09_create_marketplace_install_attempts_table',
                '2026_05_25_000001_create_marketplace_install_flow_sessions_table',
                '2026_05_25_000004_create_marketplace_install_attempt_events_table',
                '2026_07_14_000001_add_policy_evidence_to_marketplace_install_attempts',
                '2026_07_19_000002_add_runtime_tracking_to_marketplace_install_attempts',
            ]);
    }

    #[Override]
    public function registeringPackage(): void
    {
        parent::registeringPackage();

        $this->callAfterResolving(
            AdminBridgeRegistry::class,
            static function (AdminBridgeRegistry $registry): void {
                $registry->register(self::$packageName, MarketplaceAdminBridge::class);
            },
        );

        if (config('capell-marketplace.enabled', true)) {
            $this->app->singletonIf(MarketplaceComposerRunner::class, ProcessMarketplaceComposerRunner::class);
            $this->app->scoped(MarketplaceInstanceResolver::class);
            $this->app->scoped(BuildMarketplaceInstallOperationsSummaryAction::class);
            $this->app->scoped(MarketplaceCatalogueRecordProvider::class);
            $this->app->bind(MarketplaceComposerChangePublisherRegistry::class);

            $this->app->bind(
                'capell.marketplace.activation-verifier',
                fn (): callable => VerifyMarketplaceSignedActivationAction::run(...),
            );
        }
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        if (! config('capell-marketplace.enabled', true)) {
            return $this;
        }

        return $this->registerLivewireComponentDefinitions([
            'capell-marketplace.marketplace-extensions-browser' => MarketplaceExtensionsBrowser::class,
            'capell-marketplace::marketplace-extensions-browser' => MarketplaceExtensionsBrowser::class,
        ], [
            'namespace' => 'capell-marketplace',
            'classNamespace' => 'Capell\\Marketplace\\Filament\\Livewire',
        ]);
    }
}
