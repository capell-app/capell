<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Override;
use Spatie\LaravelPackageTools\Package;

final class LivewireCompatibilityTestServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'livewire-compatibility-test';

    public static string $packageName = 'capell-app/livewire-compatibility-test';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    /**
     * @param  array<string, class-string>  $components
     * @param  array<string, string>|null  $namespace
     */
    public function registerDefinitions(array $components = [], ?array $namespace = null): self
    {
        return $this->registerLivewireComponentDefinitions($components, $namespace);
    }

    public function registerMetadata(): self
    {
        return $this->registerPackageMetadata();
    }

    public function packageSurface(): PackageSurfaceRegistrar
    {
        return $this->surface();
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
