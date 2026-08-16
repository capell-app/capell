<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Commands\Fixtures;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Override;
use Spatie\LaravelPackageTools\Package;

final class ComposerBootstrapReplayTestServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'composer-bootstrap-replay-test';

    public static string $packageName = 'vendor/selected-install-package';

    private int $registeringPackageCalls = 0;

    #[Override]
    public function registeringPackage(): void
    {
        $this->registeringPackageCalls++;
    }

    public function registeringPackageCalls(): int
    {
        return $this->registeringPackageCalls;
    }

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }
}
