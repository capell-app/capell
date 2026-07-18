<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Capell\Core\Contracts\PackageServiceProvidable;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Request;
use Livewire\Livewire;
use ReflectionClass;
use RuntimeException;
use Spatie\LaravelPackageTools\PackageServiceProvider;

abstract class AbstractPackageServiceProvider extends PackageServiceProvider implements PackageServiceProvidable
{
    public static string $name;

    public static string $packageName;

    public static PackageTypeEnum $type = PackageTypeEnum::Plugin;

    public static function getName(): string
    {
        return static::$name !== '' && static::$name !== '0' ? static::$name : throw new RuntimeException('Name not set');
    }

    public static function getType(): PackageTypeEnum
    {
        return static::$type;
    }

    public function registeringPackage(): void
    {
        $this->booted(function (): void {
            $this->bootPackage();

            if ($this->isDiscoveringPackages() || ! $this->isPackageInstalled()) {
                return;
            }

            $this->bootInstalledPackage();
        });
    }

    /**
     * Boot work required before installation or during package discovery.
     *
     * Ordinary package boot work belongs in bootInstalledPackage().
     */
    protected function bootPackage(): self
    {
        return $this;
    }

    protected function bootInstalledPackage(): self
    {
        return $this;
    }

    protected function isDiscoveringPackages(): bool
    {
        $arguments = Request::server('argv') ?? [];

        return is_array($arguments) && in_array('package:discover', $arguments, true);
    }

    protected function isPackageInstalled(): bool
    {
        return CapellCore::getPackage(static::$packageName)->isInstalled();
    }

    protected function isLivewireV3(): bool
    {
        $version = InstalledVersions::getVersion('livewire/livewire');

        return $version !== null && version_compare($version, '4.0.0', '<');
    }

    protected function registerAboutInfo(?string $packageName = null): static
    {
        if ($this->app->runningInConsole()) {
            AboutCommand::add('Capell', [
                static::$name => fn (): ?string => CapellCore::getInstalledPrettyVersion($packageName ?? static::$packageName),
            ]);
        }

        return $this;
    }

    /**
     * @param  class-string|null  $setting
     * @param  array<int, string>  $setupParams
     */
    protected function registerPackageMetadata(
        ?string $setting = null,
        ?string $setupCommand = null,
        array $setupParams = [],
    ): static {
        $providerFile = new ReflectionClass(static::class)->getFileName();

        throw_if($providerFile === false, RuntimeException::class, 'Package service provider file not found');

        CapellCore::registerPackage(
            static::$packageName,
            type: static::getType(),
            serviceProviderClass: static::class,
            path: dirname($providerFile, 3),
            version: CapellCore::getInstalledPrettyVersion(static::$packageName) ?? 'dev',
            setting: $setting,
            setupCommand: $setupCommand,
            setupParams: $setupParams,
        );

        return $this;
    }

    /** @param array<int, string> $viewPaths */
    protected function registerBlazeOptimizedViews(array $viewPaths): static
    {
        foreach ($viewPaths as $viewPath) {
            RegisterBlazeOptimizedViewsAction::run($viewPath);
        }

        return $this;
    }

    /**
     * @param  array<string, class-string>  $components
     * @param  array<string, string>|null  $namespace
     */
    protected function registerLivewireComponentDefinitions(array $components, ?array $namespace = null): static
    {
        if (! $this->app->bound('livewire.finder')) {
            return $this;
        }

        $livewire = Livewire::getFacadeRoot();

        if (! is_object($livewire) || ! method_exists($livewire, 'component')) {
            return $this;
        }

        foreach ($components as $name => $component) {
            Livewire::component($name, $component);
        }

        if ($namespace !== null && $this->isLivewireV3() === false && method_exists($livewire, 'addNamespace')) {
            Livewire::addNamespace(...$namespace);
        }

        return $this;
    }

    /**
     * Canonical entry point for contributing core surfaces (page types,
     * components, models, interceptors, subscribers, settings). Prefer this
     * over the CapellCore facade and raw container tags.
     */
    protected function surface(): PackageSurfaceRegistrar
    {
        return $this->app->make(PackageSurfaceRegistrar::class);
    }

    /** @param Closure(Schedule): void $callback */
    protected function registerSchedule(Closure $callback): static
    {
        $this->callAfterResolving(Schedule::class, $callback);

        return $this;
    }
}
