<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Capell\Core\Support\Registries\TaggedProviderRegistry;
use Capell\Core\Testing\Contracts\InstalledRuntimeContract;
use Capell\Core\Tests\CoreTestCase;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\FileViewFinder;
use Livewire\Component;
use Livewire\Livewire;
use Override;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;

final class InstalledRuntimeParityTest extends CoreTestCase
{
    public function test_fresh_boot_matches_installation_and_repeated_refresh_without_deduplication(): void
    {
        InstalledRuntimeContract::assertParity(
            provider: ParityMainProvider::class,
            boot: function (bool $installed): Application {
                ParityMainProvider::$initiallyInstalled = $installed;
                $this->refreshApplication();
                RefreshDatabaseState::$migrated = false;
                $this->refreshDatabase();

                return $this->app ?? throw new RuntimeException('Test application was not created.');
            },
            snapshot: $this->snapshot(...),
            install: static function (): void {
                InstallPackageAction::run(CapellCore::getPackage(ParityMainProvider::$packageName));
            },
            refresh: static function (): void {
                RefreshInstalledPackageRuntimeAction::run(CapellCore::getPackage(ParityMainProvider::$packageName));
                RefreshInstalledPackageRuntimeAction::run(CapellCore::getPackage(ParityMainProvider::$packageName));
            },
            assertAbsent: function (Application $app): void {
                $this->assertSame([], $app->make(ParityTaggedRegistry::class)->all());
                $this->assertSame([], $app->make(Dispatcher::class)->getRawListeners()['runtime.parity'] ?? []);
                $this->assertFalse(Gate::has('runtime.parity'));
                $this->assertNotInstanceOf(HealthCheck::class, $app->make(HealthCheckRegistry::class)->find('runtime.parity'));
            },
            assertDeclared: function (Application $app): void {
                $snapshot = $this->snapshot($app);
                $this->assertSame([ParityContributor::class, ParityChildProvider::class], $snapshot['contributors']);
                $this->assertSame(1, $snapshot['listeners']);
                $this->assertSame(['runtime.parity'], $snapshot['schedules']);
                $this->assertSame(ParityPolicy::class, $snapshot['policy']);
                $this->assertTrue($snapshot['gate']);
                $this->assertSame(ParityComponent::class, $snapshot['livewire']);
                $this->assertSame(ParityBladeComponent::class, $snapshot['blade']);
                $this->assertNotEmpty($snapshot['views']);
                $this->assertSame('runtime/parity', $snapshot['route']);
                $this->assertSame([ParityContributor::class], $snapshot['declaredHealthChecks']);
            },
        );
        ParityMainProvider::$initiallyInstalled = false;
    }

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ParityMainProvider::class, ParityChildProvider::class];
    }

    /** @return array<string, mixed> */
    private function snapshot(Application $app): array
    {
        $app->make(Router::class)->getRoutes()->refreshNameLookups();
        $finder = $app->make(Factory::class)->getFinder();
        throw_unless($finder instanceof FileViewFinder, RuntimeException::class, 'Expected a filesystem view finder.');

        return [
            'contributors' => $app->make(ParityTaggedRegistry::class)->all(),
            'declaredHealthChecks' => array_values(array_map(
                static fn (HealthCheck $check): string => $check::class,
                array_filter($app->make(HealthCheckRegistry::class)->checks(), static fn (HealthCheck $check): bool => $check->id() === 'runtime.parity'),
            )),
            'listeners' => count($app->make(Dispatcher::class)->getRawListeners()['runtime.parity'] ?? []),
            'schedules' => array_values(array_map(
                static fn (\Illuminate\Console\Scheduling\Event $event): ?string => $event->description,
                array_filter($app->make(Schedule::class)->events(), static fn (\Illuminate\Console\Scheduling\Event $event): bool => $event->description === 'runtime.parity'),
            )),
            'policy' => Gate::policies()[ParityModel::class] ?? null,
            'gate' => Gate::has('runtime.parity'),
            'livewire' => $app->make('livewire.finder')->resolveClassComponentClassName('runtime.parity'),
            'blade' => Blade::getClassComponentAliases()['runtime-parity'] ?? null,
            'views' => $finder->getHints()['runtime-parity'] ?? [],
            'route' => $app->make(Router::class)->getRoutes()->getByName('runtime.parity')?->uri(),
        ];
    }
}

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

final class ParityChildProvider extends ServiceProvider implements ParityContribution
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->app->instance(self::class, $this);
        $this->registerInstalledRuntime(ParityMainProvider::$packageName, 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([self::class], 'runtime.parity.contributors');
    }
}

/** @extends TaggedProviderRegistry<ParityContribution> */
final class ParityTaggedRegistry extends TaggedProviderRegistry
{
    public function __construct(Application $application)
    {
        parent::__construct(self::tagged($application, 'runtime.parity.contributors'), ParityContribution::class);
    }

    /** @return list<class-string> */
    public function all(): array
    {
        return array_map(static fn (object $provider): string => $provider::class, $this->providers());
    }
}

final class ParityModel {}

final class ParityPolicy {}

final class ParityComponent extends Component {}

final class ParityBladeComponent extends \Illuminate\View\Component
{
    #[Override]
    public function render(): string
    {
        return '<div>runtime</div>';
    }
}

interface ParityContribution {}

final class ParityContributor implements HealthCheck, ParityContribution
{
    #[Override]
    public function id(): string
    {
        return 'runtime.parity';
    }

    #[Override]
    public function category(): string
    {
        return 'runtime';
    }

    #[Override]
    public function timeoutSeconds(): int
    {
        return 5;
    }

    #[Override]
    public function run(): HealthCheckResultData
    {
        return new HealthCheckResultData(
            id: 'runtime.parity',
            category: 'runtime',
            status: HealthStatus::Healthy,
            severity: HealthSeverity::Info,
            summary: 'Runtime fixture',
        );
    }
}
