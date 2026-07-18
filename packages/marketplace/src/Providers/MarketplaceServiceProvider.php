<?php

declare(strict_types=1);

namespace Capell\Marketplace\Providers;

use Capell\Admin\Contracts\Extenders\ExtensionsPageExtender;
use Capell\Admin\Contracts\Extenders\ResourceHeaderActionExtender;
use Capell\Admin\Contracts\Extensions\ExtensionCatalogueMetadataProvider;
use Capell\Admin\Contracts\Themes\PendingThemeInstallProvider;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Admin\Support\Extensions\ExtensionsPageActionRegistry;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Marketplace\Actions\BuildMarketplaceInstallOperationsSummaryAction;
use Capell\Marketplace\Actions\VerifyMarketplaceSignedActivationAction;
use Capell\Marketplace\Console\Commands\MarketplaceExtensionsLifecycleQaCommand;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Filament\Actions\ConnectMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\CreateMarketplaceAccountAction;
use Capell\Marketplace\Filament\Actions\MarketplaceConnectionFormModel;
use Capell\Marketplace\Filament\Actions\MarketplaceInstallOperationsAction;
use Capell\Marketplace\Filament\Actions\OpenMarketplaceAction;
use Capell\Marketplace\Filament\Extenders\MarketplaceExtensionsPageExtender;
use Capell\Marketplace\Filament\Extenders\ThemeMarketplaceHeaderActionExtender;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Filament\Pages\ThemeExtensionPage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Filament\Widgets\MarketplacePackageOperationsAlertFilamentWidget;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Marketplace\Support\PendingMarketplaceThemeInstallProvider;
use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;
use Composer\InstalledVersions;
use Filament\Actions\Action;
use Livewire\Livewire;
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
            ]);
    }

    #[Override]
    public function registeringPackage(): void
    {
        CapellCore::registerPackage(
            static::$packageName,
            type: static::getType(),
            serviceProviderClass: static::class,
            path: realpath(__DIR__ . '/../..'),
            version: $this->getVersion(),
        );

        if (config('capell-marketplace.enabled', true)) {
            $this->app->singletonIf(MarketplaceComposerRunner::class, ProcessMarketplaceComposerRunner::class);
            $this->app->scoped(MarketplaceInstanceResolver::class);
            $this->app->scoped(BuildMarketplaceInstallOperationsSummaryAction::class);
            $this->app->scoped(MarketplaceCatalogueRecordProvider::class);

            $this->app->bind(
                'capell.marketplace.activation-verifier',
                fn (): callable => VerifyMarketplaceSignedActivationAction::run(...),
            );
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(MarketplacePage::class));
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(MarketplaceExtensionDetailPage::class));
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(MarketplacePackageOperationsPage::class));
            CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(ThemeExtensionPage::class));
            CapellAdmin::registerDashboardFilamentWidget(MarketplacePackageOperationsAlertFilamentWidget::class, DashboardEnum::Main);
            $this->app->tag(MarketplaceExtensionsPageExtender::class, ExtensionsPageExtender::TAG);
            $this->app->tag(MarketplaceCatalogueRecordProvider::class, ExtensionCatalogueMetadataProvider::TAG);
            $this->app->tag(ThemeMarketplaceHeaderActionExtender::class, ResourceHeaderActionExtender::TAG);
            $this->app->tag(PendingMarketplaceThemeInstallProvider::class, PendingThemeInstallProvider::TAG);
            $this->registerExtensionsPageActions();
        }
    }

    #[Override]
    public function packageBooted(): void
    {
        $livewire = Livewire::getFacadeRoot();

        if (! is_object($livewire)) {
            return;
        }

        if (! $this->app->bound('livewire.finder')) {
            return;
        }

        if ($this->isLivewireV3() === false && method_exists($livewire, 'addNamespace')) {
            Livewire::addNamespace(
                namespace: 'capell-marketplace',
                classNamespace: 'Capell\\Marketplace\\Filament\\Livewire',
            );
        }

        if (method_exists($livewire, 'component')) {
            Livewire::component('capell-marketplace.marketplace-extensions-browser', MarketplaceExtensionsBrowser::class);
            Livewire::component('capell-marketplace::marketplace-extensions-browser', MarketplaceExtensionsBrowser::class);
        }

    }

    private function getVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'dev';
        }

        if (! InstalledVersions::isInstalled(static::$packageName)) {
            return 'dev';
        }

        return InstalledVersions::getPrettyVersion(static::$packageName) ?? 'dev';
    }

    private function registerExtensionsPageActions(): void
    {
        $this->app->afterResolving(ExtensionsPageActionRegistry::class, function (ExtensionsPageActionRegistry $registry): void {
            $registry->registerHeaderAction(
                fn (): Action => OpenMarketplaceAction::make(resolve(MarketplaceConnectionFormModel::class)),
                'capell-marketplace.open-marketplace',
            );

            $registry->registerHeaderAction(
                fn (): Action => MarketplaceInstallOperationsAction::make(),
                'capell-marketplace.install-operations',
            );

            $registry->registerHeaderActionGroupAction(
                fn (): Action => ConnectMarketplaceAccountAction::make(resolve(MarketplaceConnectionFormModel::class)),
                'capell-marketplace.connect-account',
            );

            $registry->registerHeaderActionGroupAction(
                fn (): Action => CreateMarketplaceAccountAction::make(resolve(MarketplaceConnectionFormModel::class)),
                'capell-marketplace.create-account',
            );

            $registry->registerTableAction(
                fn (ExtensionsPage $page): Action => OpenMarketplaceAction::make(resolve(MarketplaceConnectionFormModel::class))
                    ->label(function (mixed $livewire): string {
                        $search = $livewire instanceof ExtensionsPage ? $livewire->extensionTableSearchTerm() : null;

                        return filled($search)
                            ? (string) __('capell-marketplace::marketplace.marketplace.search_marketplace_for', ['search' => $search])
                            : (string) __('capell-marketplace::marketplace.marketplace.install_from_marketplace');
                    })
                    ->button(),
                'capell-marketplace.search-marketplace',
            );
        });
    }
}
