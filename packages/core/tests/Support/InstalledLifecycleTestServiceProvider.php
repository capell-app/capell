<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Override;
use RuntimeException;
use Spatie\LaravelPackageTools\Package;

final class InstalledLifecycleTestServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'installed-lifecycle-test';

    public static string $packageName = 'capell-app/installed-lifecycle-test';

    private ?Closure $bootedCallback = null;

    public function __construct(
        Application $application,
        private readonly bool $installed,
        private readonly bool $discoveringPackages = false,
    ) {
        parent::__construct($application);
    }

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
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
        Event::dispatch('capell.test.installed-booted');

        return $this;
    }

    #[Override]
    protected function bootPackage(): self
    {
        Event::dispatch('capell.test.package-booted');

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
