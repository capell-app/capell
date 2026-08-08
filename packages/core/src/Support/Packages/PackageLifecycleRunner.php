<?php

declare(strict_types=1);

namespace Capell\Core\Support\Packages;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class PackageLifecycleRunner
{
    public function __construct(
        private readonly ProcessFactoryInterface $processFactory,
    ) {}

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
        bool $freshProcess = false,
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

        if ($freshProcess || ! array_key_exists($command, Artisan::all())) {
            $this->runCommandInFreshProcess($phase, $command, $arguments, $reporter);

            return;
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

    /**
     * Composer can add a package after the current Artisan application has
     * already started. Its command classes are then available to a fresh PHP
     * process even though they cannot be added reliably to this command list.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function runCommandInFreshProcess(
        string $phase,
        string $command,
        array $arguments,
        ?ProgressReporter $reporter,
    ): void {
        if ($this->commandMissingFromFreshProcess($command)) {
            throw new RuntimeException(sprintf(
                "%s command '%s' does not exist.",
                str($phase)->replace('-', ' ')->headline(),
                $command,
            ));
        }

        $processCommand = $this->freshProcessCommand($command, $arguments);
        $environment = ArtisanProcessEnvironment::prepare();
        $process = $environment === null
            ? $this->processFactory->make($processCommand, base_path())
            : $this->processFactory->make($processCommand, base_path(), $environment);
        $process->setTimeout(null);

        $output = '';
        $lineBuffer = '';

        $process->run(function (string $outputType, string $buffer) use (&$output, &$lineBuffer, $reporter): void {
            $output .= $buffer;

            if (! $reporter instanceof ProgressReporter) {
                return;
            }

            $lineBuffer .= str_replace("\r", "\n", $buffer);
            $lines = explode("\n", $lineBuffer);
            $lineBuffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line !== '') {
                    $reporter->report($line);
                }
            }
        });

        if ($reporter instanceof ProgressReporter && trim($lineBuffer) !== '') {
            $reporter->report(trim($lineBuffer));
        }

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        $output = trim($errorOutput !== '' ? $errorOutput : $output);

        throw new RuntimeException(sprintf(
            "%s command '%s' failed in a fresh process with exit code %d.%s",
            str($phase)->replace('-', ' ')->headline(),
            $command,
            $process->getExitCode() ?? 1,
            $output !== '' ? ' ' . $output : '',
        ));
    }

    /**
     * `php artisan list --raw --no-interaction` prints one machine-readable
     * line per registered command (`name  description`), and exits 0. Probing
     * with it before running the real command replaces scraping the human
     * "Command is not defined" error text, which Laravel 13 no longer emits
     * reliably (the CLI now prints a "Did you mean" block and still exits 0).
     *
     * A failed probe means the child process could not boot at all, which is
     * a different failure than a missing command; that case is left for the
     * real command run to surface via its own "failed in a fresh process"
     * exception.
     */
    private function commandMissingFromFreshProcess(string $command): bool
    {
        $probeCommand = [...new RuntimeBinaryResolver()->php(), base_path('artisan'), 'list', '--raw', '--no-interaction'];
        $environment = ArtisanProcessEnvironment::prepare();
        $probeProcess = $environment === null
            ? $this->processFactory->make($probeCommand, base_path())
            : $this->processFactory->make($probeCommand, base_path(), $environment);
        $probeProcess->setTimeout(null);
        $probeProcess->run();

        if (! $probeProcess->isSuccessful()) {
            return false;
        }

        foreach (explode("\n", $probeProcess->getOutput()) as $line) {
            $name = strtok(trim($line), " \t");

            if ($name === $command) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    private function freshProcessCommand(string $command, array $arguments): array
    {
        $processCommand = [...new RuntimeBinaryResolver()->php(), base_path('artisan'), $command, '--no-interaction'];

        foreach ($arguments as $option => $value) {
            if ($value === null) {
                continue;
            }

            if ($value === false) {
                continue;
            }

            $option = str_starts_with($option, '--') ? $option : '--' . $option;

            if ($value === true) {
                $processCommand[] = $option;

                continue;
            }

            foreach (is_array($value) ? $value : [$value] as $item) {
                $processCommand[] = $option . '=' . $item;
            }
        }

        return $processCommand;
    }
}
