<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Override;

final class PrepareFrontendPackageAction implements PackageLifecycleAction
{
    /** @param array<string, mixed> $arguments */
    #[Override]
    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        PrepareFrontendInstallationAction::run(reporter: $reporter);
    }
}
