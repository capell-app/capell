<?php

declare(strict_types=1);

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Illuminate\Contracts\Foundation\Application;
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
