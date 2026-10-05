<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Override;
use Spatie\LaravelPackageTools\Package;

final class UnrelatedLegacyAvailabilityProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'unrelated-legacy-availability';

    public static string $packageName = 'test/panel-runtime';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    #[Override]
    protected function registerPackageMetadata(): static
    {
        return $this;
    }

    #[Override]
    protected function bootInstalledPackage(): self
    {
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(LateRuntimePage::class));

        return $this;
    }
}
