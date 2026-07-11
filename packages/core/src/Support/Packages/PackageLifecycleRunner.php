<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class PackageLifecycleRunner
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function run(
        PackageData $package,
        string $phase,
        ?string $command,
        ?string $actionClass,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
        bool $allowLegacyCommand = true,
    ): void {
        if ($actionClass !== null && $actionClass !== '') {
            $this->runAction($package, $phase, $actionClass, $arguments, $reporter);

            return;
        }

        if ($command === null || $command === '') {
            return;
        }

        if (! $allowLegacyCommand) {
            throw new RuntimeException(sprintf(
                'Package %s declares legacy %s command "%s", but web-triggered package lifecycle work must use a lifecycle Action. Add actions.%s to capell.json with a class implementing %s.',
                $package->name,
                $phase,
                $command,
                $phase === 'after-install' ? 'afterInstall' : $phase,
                PackageLifecycleAction::class,
            ));
        }

        if (! array_key_exists($command, Artisan::all())) {
            throw new RuntimeException(sprintf("%s command '%s' does not exist.", str($phase)->replace('-', ' ')->headline(), $command));
        }

        $exitCode = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());

        if ($reporter instanceof ProgressReporter && $output !== '') {
            $reporter->report($output);
        }

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf("%s command '%s' failed with exit code %d.", str($phase)->replace('-', ' ')->headline(), $command, $exitCode));
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function runAction(
        PackageData $package,
        string $phase,
        string $actionClass,
        array $arguments,
        ?ProgressReporter $reporter,
    ): void {
        if (! class_exists($actionClass)) {
            throw new RuntimeException(sprintf('%s lifecycle action %s for %s does not exist.', str($phase)->replace('-', ' ')->headline(), $actionClass, $package->name));
        }

        $action = resolve($actionClass);

        if (! $action instanceof PackageLifecycleAction) {
            throw new RuntimeException(sprintf('%s lifecycle action %s for %s must implement %s.', str($phase)->replace('-', ' ')->headline(), $actionClass, $package->name, PackageLifecycleAction::class));
        }

        $action->handle($package, $arguments, $reporter);
    }
}
