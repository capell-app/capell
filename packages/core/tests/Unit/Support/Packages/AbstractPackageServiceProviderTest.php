<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Livewire\LivewireManager;
use Spatie\LaravelPackageTools\Package;

it('defers installed package boot work until the application has booted', function (): void {
    $provider = new InstalledLifecycleTestServiceProvider(app(), installed: true);

    $provider->registeringPackage();

    expect($provider->installedBootCount())->toBe(0);

    $provider->runBootedCallback();

    expect($provider->packageBootCount())->toBe(1)
        ->and($provider->installedBootCount())->toBe(1);
});

it('skips installed package boot work while discovering packages', function (): void {
    $provider = new InstalledLifecycleTestServiceProvider(
        app(),
        installed: true,
        discoveringPackages: true,
    );

    $provider->registeringPackage();

    $provider->runBootedCallback();

    expect($provider->packageBootCount())->toBe(1)
        ->and($provider->installedBootCount())->toBe(0);
});

it('skips installed package boot work when the package is not installed', function (): void {
    $provider = new InstalledLifecycleTestServiceProvider(app(), installed: false);

    $provider->registeringPackage();

    $provider->runBootedCallback();

    expect($provider->packageBootCount())->toBe(1)
        ->and($provider->installedBootCount())->toBe(0);
});

it('allows companion providers to retain a private livewire registration method', function (): void {
    $provider = new LivewireCompatibilityTestServiceProvider(app());

    expect($provider->registerDefinitions())->toBe($provider)
        ->and($provider->registerPrivateDefinitions())->toBe($provider)
        ->and(new ReflectionMethod($provider, 'registerLivewireComponents')->isPrivate())->toBeTrue();
});

it('does not resolve the livewire facade when the finder is unbound', function (): void {
    $application = app();
    $provider = new LivewireCompatibilityTestServiceProvider($application);
    $finder = $application->make('livewire.finder');
    $livewire = $application->make(LivewireManager::class);

    $application->offsetUnset('livewire.finder');
    $application->offsetUnset(LivewireManager::class);
    $application->bind(
        LivewireManager::class,
        fn (): never => throw new RuntimeException('Livewire facade must not be resolved.'),
    );

    Facade::clearResolvedInstance(LivewireManager::class);

    try {
        expect($provider->registerDefinitions())->toBe($provider);
    } finally {
        $application->instance('livewire.finder', $finder);
        $application->instance(LivewireManager::class, $livewire);
        Facade::clearResolvedInstance(LivewireManager::class);
    }
});

it('uses the legacy development version when composer has no pretty version', function (): void {
    $provider = new LivewireCompatibilityTestServiceProvider(app());

    $provider->registerMetadata();

    expect(CapellCore::getPackage($provider::$packageName)->version)->toBe('dev');
});

final class InstalledLifecycleTestServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'installed-lifecycle-test';

    public static string $packageName = 'capell-app/installed-lifecycle-test';

    private int $installedBootCount = 0;

    private int $packageBootCount = 0;

    private ?Closure $bootedCallback = null;

    public function __construct(
        Application $application,
        private readonly bool $installed,
        private readonly bool $discoveringPackages = false,
    ) {
        parent::__construct($application);
    }

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    public function installedBootCount(): int
    {
        return $this->installedBootCount;
    }

    public function packageBootCount(): int
    {
        return $this->packageBootCount;
    }

    #[Override]
    public function booted(Closure $callback): void
    {
        $this->bootedCallback = $callback;
    }

    public function runBootedCallback(): void
    {
        ($this->bootedCallback ?? throw new RuntimeException('Booted callback was not registered.'))();
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        $this->installedBootCount++;

        return $this;
    }

    #[Override]
    protected function bootPackage(): self
    {
        $this->packageBootCount++;

        return $this;
    }

    #[Override]
    protected function isDiscoveringPackages(): bool
    {
        return $this->discoveringPackages;
    }

    #[Override]
    protected function isPackageInstalled(): bool
    {
        return $this->installed;
    }
}

final class LivewireCompatibilityTestServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'livewire-compatibility-test';

    public static string $packageName = 'capell-app/livewire-compatibility-test';

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    public function registerDefinitions(): self
    {
        return $this->registerLivewireComponentDefinitions([]);
    }

    public function registerMetadata(): self
    {
        return $this->registerPackageMetadata();
    }

    public function registerPrivateDefinitions(): self
    {
        return $this->registerLivewireComponents();
    }

    private function registerLivewireComponents(): self
    {
        return $this;
    }
}
