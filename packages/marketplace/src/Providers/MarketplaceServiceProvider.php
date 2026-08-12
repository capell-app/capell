<?php

declare(strict_types=1);

namespace Capell\Marketplace\Providers;

use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Marketplace\Actions\BuildMarketplaceInstallOperationsSummaryAction;
use Capell\Marketplace\Actions\VerifyMarketplaceSignedActivationAction;
use Capell\Marketplace\Bridges\MarketplaceAdminBridge;
use Capell\Marketplace\Console\Commands\MarketplaceAutoUpdateCommand;
use Capell\Marketplace\Console\Commands\MarketplaceDoctorCommand;
use Capell\Marketplace\Console\Commands\MarketplaceExtensionsLifecycleQaCommand;
use Capell\Marketplace\Console\Commands\MarketplaceHeartbeatCommand;
use Capell\Marketplace\Contracts\MarketplaceComposerRunner;
use Capell\Marketplace\Contracts\MarketplaceComposerScriptRunner;
use Capell\Marketplace\Contracts\MarketplaceInstalledPackageVersionResolver;
use Capell\Marketplace\Contracts\MarketplaceRuntimeRefresher;
use Capell\Marketplace\Contracts\MarketplaceSelectionRecordProvider;
use Capell\Marketplace\Filament\Livewire\MarketplaceExtensionsBrowser;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Jobs\RecordMarketplaceWorkerHeartbeatJob;
use Capell\Marketplace\Support\ArtisanMarketplaceRuntimeRefresher;
use Capell\Marketplace\Support\ComposerInstalledPackageVersionResolver;
use Capell\Marketplace\Support\MarketplaceComposerChangePublisherRegistry;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Capell\Marketplace\Support\MarketplaceQueueWorkerCommand;
use Capell\Marketplace\Support\ProcessMarketplaceComposerRunner;
use Capell\Marketplace\Support\ProcessMarketplaceComposerScriptRunner;
use Illuminate\Console\Scheduling\Schedule;
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
            ->hasCommand(MarketplaceAutoUpdateCommand::class)
            ->hasCommand(MarketplaceDoctorCommand::class)
            ->hasCommand(MarketplaceHeartbeatCommand::class)
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
                '2026_08_05_000001_add_operation_to_marketplace_install_attempts',
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

                if (app()->resolved(AdminRuntimeActivator::class)) {
                    $activator = resolve(AdminRuntimeActivator::class);

                    if ($activator->isPrepared()) {
                        $activator->prepare();
                    }
                }
            },
        );

        if (config('capell-marketplace.enabled', true)) {
            $this->app->singletonIf(MarketplaceComposerRunner::class, ProcessMarketplaceComposerRunner::class);
            $this->app->singletonIf(MarketplaceComposerScriptRunner::class, ProcessMarketplaceComposerScriptRunner::class);
            $this->app->singletonIf(MarketplaceInstalledPackageVersionResolver::class, ComposerInstalledPackageVersionResolver::class);
            $this->app->scoped(MarketplaceInstanceResolver::class);
            $this->app->scoped(BuildMarketplaceInstallOperationsSummaryAction::class);
            $this->app->scoped(MarketplaceCatalogueRecordProvider::class);
            $this->app->bind(
                MarketplaceSelectionRecordProvider::class,
                fn (): MarketplaceCatalogueRecordProvider => resolve(MarketplaceCatalogueRecordProvider::class),
            );
            $this->app->bind(MarketplaceComposerChangePublisherRegistry::class);
            $this->app->singletonIf(MarketplaceRuntimeRefresher::class, ArtisanMarketplaceRuntimeRefresher::class);

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

        $this->scheduleWorkerHeartbeatProbe();
        $this->scheduleMarketplaceHeartbeat();
        $this->scheduleAutomaticUpdates();

        return $this->registerLivewireComponentDefinitions([
            'capell-marketplace.marketplace-extensions-browser' => MarketplaceExtensionsBrowser::class,
            'capell-marketplace::marketplace-extensions-browser' => MarketplaceExtensionsBrowser::class,
        ], [
            'namespace' => 'capell-marketplace',
            'classNamespace' => 'Capell\\Marketplace\\Filament\\Livewire',
        ]);
    }

    /**
     * Keep this site's view of the catalogue, its updates and its security
     * advisories current without anyone logging in.
     *
     * withoutOverlapping() and onOneServer() for the same reason the upgrade
     * summary uses them: several app servers running the same scheduler would
     * otherwise send the same heartbeat several times a day.
     */
    private function scheduleMarketplaceHeartbeat(): void
    {
        if (! (bool) config('capell-marketplace.heartbeat.scheduled', true)) {
            return;
        }

        $at = (string) config('capell-marketplace.heartbeat.at', '02:40');

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($at): void {
            $schedule->command('capell:marketplace:heartbeat')
                ->dailyAt($at)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    /**
     * Unattended updates, and only for sites that asked for them.
     *
     * Defensible only because every queued update is health-checked and
     * rollback-protected; if that ever stops being true, this schedule has to go
     * with it.
     */
    private function scheduleAutomaticUpdates(): void
    {
        if (! (bool) config('capell-marketplace.auto_update.scheduled', false)) {
            return;
        }

        $at = (string) config('capell-marketplace.auto_update.at', '03:20');

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($at): void {
            $schedule->command('capell:marketplace:auto-update')
                ->dailyAt($at)
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    /**
     * Keep the worker heartbeat current on a quiet installation.
     *
     * Marketplace jobs record a heartbeat as they run, but an installation that
     * has not installed anything for a week has no jobs to record one — and an
     * operator who then starts an install deserves to know whether a worker is
     * there before they wait on it, not after.
     *
     * Pointless on a synchronous connection, where the probe would run inside
     * the scheduler and prove only that the scheduler is alive.
     */
    private function scheduleWorkerHeartbeatProbe(): void
    {
        if (MarketplaceQueueWorkerCommand::isSynchronous()) {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new RecordMarketplaceWorkerHeartbeatJob)->everyMinute();
        });
    }
}
