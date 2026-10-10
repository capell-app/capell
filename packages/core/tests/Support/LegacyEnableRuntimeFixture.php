<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Override;
use Spatie\LaravelPackageTools\Package;

final class LegacyEnableRuntimeFixture extends AbstractPackageServiceProvider
{
    public static string $name = 'legacy-enable-runtime';

    public static string $packageName = 'test/legacy-enable-runtime';

    public int $calls = 0;

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
        $this->calls++;

        return $this;
    }
}
