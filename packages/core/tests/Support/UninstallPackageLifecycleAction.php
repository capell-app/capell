<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Override;

final class UninstallPackageLifecycleAction implements PackageLifecycleAction
{
    /** @var list<array{string, array<string, mixed>, bool}> */
    public static array $packages = [];

    #[Override]
    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        self::$packages[] = [$package->name, $arguments, CapellCore::isPackageInstalled($package->name)];
    }
}
