<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Override;
use Spatie\LaravelPackageTools\Package;

class ParityMainProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'runtime-parity';

    public static string $packageName = 'test/runtime-parity';

    public static bool $initiallyInstalled = false;

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    #[Override]
    public function packageBooted(): void
    {
        $this->app->make(Schedule::class);
    }

    #[Override]
    protected function registerPackageMetadata(): static
    {
        $manifest = CapellManifestData::fromArray(capellManifestV3Array(
            name: self::$packageName,
            providers: ['runtime' => [self::class], 'admin' => [ParityChildProvider::class]],
            overrides: ['contributes' => [['type' => 'health-check', 'class' => ParityContributor::class, 'key' => 'runtime.parity', 'providerBucket' => 'runtime']]],
        ));
        CapellCore::registerManifestPackage($manifest, '1.0.0');
        CapellCore::getPackage(self::$packageName)->serviceProviderClass = self::class;
        CapellCore::forcePackageInstalled(self::$packageName, self::$initiallyInstalled);
        $this->app->singleton(ParityTaggedRegistry::class, fn (): ParityTaggedRegistry => new ParityTaggedRegistry($this->app));
        // Resolve both the declaration index and an empty tagged consumer before boot.
        $this->app->make(CapellPackageRegistry::class)->register($manifest);
        $this->app->make(CapellPackageRegistry::class)->contributionsForPackage(self::$packageName);
        $this->app->make(ParityTaggedRegistry::class);
        $this->app->make(HealthCheckRegistry::class)->checks();

        return $this;
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([ParityContributor::class], 'runtime.parity.contributors');
        $this->app->tag([ParityContributor::class], HealthCheck::TAG);
        Event::listen('runtime.parity', static function (): void {});
        $this->registerSchedule(static function (Schedule $schedule): void {
            $schedule->call(static function (): void {})->name('runtime.parity');
        });
        Gate::policy(ParityModel::class, ParityPolicy::class);
        Gate::define('runtime.parity', static fn (): bool => true);
        Livewire::component('runtime.parity', ParityComponent::class);
        Blade::component(ParityBladeComponent::class, 'runtime-parity');
        $this->loadViewsFrom(__DIR__, 'runtime-parity');
        Route::get('runtime/parity', static fn (): string => 'runtime')->name('runtime.parity');
    }
}
