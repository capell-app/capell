<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Contracts\PackageServiceProvidable;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Request;
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

    /**
     * Canonical entry point for contributing core surfaces (page types,
     * components, models, interceptors, subscribers, settings). Prefer this
     * over the CapellCore facade and raw container tags.
     */
    protected function surface(): PackageSurfaceRegistrar
    {
        return $this->app->make(PackageSurfaceRegistrar::class);
    }
}
