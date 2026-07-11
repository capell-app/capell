<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(PackageData $package, array<string, mixed> $arguments = [], ?ProgressReporter $reporter = null, bool $allowLegacyCommand = true)
 */
class AfterInstallPackageAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
        bool $allowLegacyCommand = true,
    ): void {
        if (($package->getAfterInstallCommand() === null || $package->getAfterInstallCommand() === '') && ($package->getAfterInstallAction() === null || $package->getAfterInstallAction() === '')) {
            return;
        }

        resolve(PackageLifecycleRunner::class)->run(
            package: $package,
            phase: 'after-install',
            command: $package->getAfterInstallCommand(),
            actionClass: $package->getAfterInstallAction(),
            arguments: $arguments,
            reporter: $reporter,
            allowLegacyCommand: $allowLegacyCommand,
        );
    }
}
