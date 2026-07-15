<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Exception;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @method static void run(PackageData $package, array<string, mixed> $arguments = [], ?ProgressReporter $reporter = null, bool $allowLegacyCommand = true)
 */
class SetupPackageAction
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
        if (($package->getSetupCommand() === null || $package->getSetupCommand() === '') && ($package->getSetupAction() === null || $package->getSetupAction() === '')) {
            return;
        }

        if ($allowLegacyCommand && ($package->getSetupAction() === null || $package->getSetupAction() === '') && $package->getSetupCommand() === 'capell:admin-setup') {
            self::runSetupCommandInFreshProcess($package->getSetupCommand(), $arguments, $reporter);

            return;
        }

        resolve(PackageLifecycleRunner::class)->run(
            package: $package,
            phase: 'setup',
            command: $package->getSetupCommand(),
            actionClass: $package->getSetupAction(),
            arguments: $arguments,
            reporter: $reporter,
            allowLegacyCommand: $allowLegacyCommand,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private static function runSetupCommandInFreshProcess(
        string $setupCommand,
        array $arguments,
        ?ProgressReporter $reporter,
    ): void {
        $phpBinary = self::resolvePhpCliBinary();
        $command = [$phpBinary, '-d', 'memory_limit=512M', 'artisan', $setupCommand, '--no-interaction'];

        foreach ($arguments as $option => $value) {
            if ($value === null) {
                continue;
            }

            if ($value === false) {
                continue;
            }

            $normalizedOption = str_starts_with($option, '--')
                ? $option
                : '--' . $option;

            if ($value === true) {
                $command[] = $normalizedOption;

                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    $command[] = $normalizedOption . '=' . $item;
                }

                continue;
            }

            $command[] = $normalizedOption . '=' . $value;
        }

        $process = new Process($command, base_path());
        $process->setTimeout(null);

        $output = '';
        $lineBuffer = '';

        $process->run(function (string $outputType, string $buffer) use (&$output, &$lineBuffer, $reporter): void {
            $output .= $buffer;

            if (! $reporter instanceof ProgressReporter) {
                return;
            }

            self::reportBufferedOutput($buffer, $lineBuffer, $reporter);
        });

        if ($reporter instanceof ProgressReporter) {
            self::flushBufferedOutput($lineBuffer, $reporter);
        }

        if (! $process->isSuccessful()) {
            $message = self::formatFailureMessage($setupCommand, $command, $process, $output);

            throw new Exception($message);
        }
    }

    private static function reportBufferedOutput(string $buffer, string &$lineBuffer, ProgressReporter $reporter): void
    {
        $lineBuffer .= str_replace("\r", "\n", $buffer);
        $lines = explode("\n", $lineBuffer);
        $lineBuffer = array_pop($lines);

        foreach ($lines as $line) {
            self::reportOutputLine($line, $reporter);
        }
    }

    private static function flushBufferedOutput(string &$lineBuffer, ProgressReporter $reporter): void
    {
        if ($lineBuffer === '') {
            return;
        }

        self::reportOutputLine($lineBuffer, $reporter);
        $lineBuffer = '';
    }

    private static function reportOutputLine(string $line, ProgressReporter $reporter): void
    {
        $line = trim($line);

        if ($line === '') {
            return;
        }

        $reporter->report($line);
    }

    private static function resolvePhpCliBinary(): string
    {
        $finder = new ExecutableFinder;
        $configuredBinary = config('capell-installer.php_binary');
        $candidates = [];

        if (is_string($configuredBinary) && $configuredBinary !== '') {
            $candidates[] = $configuredBinary;
        }

        $candidates[] = 'php';
        $candidates[] = PHP_BINARY;

        foreach (array_unique($candidates) as $candidate) {
            $resolvedBinary = self::resolveExecutable($candidate, $finder);

            if ($resolvedBinary !== null && ! self::looksLikePhpFpm($resolvedBinary)) {
                return $resolvedBinary;
            }
        }

        throw new Exception('Unable to locate a CLI PHP binary. Set CAPELL_INSTALLER_PHP_BINARY to the php executable, not php-fpm.');
    }

    private static function resolveExecutable(string $candidate, ExecutableFinder $finder): ?string
    {
        if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
            return is_file($candidate) && is_executable($candidate) ? $candidate : null;
        }

        return $finder->find($candidate);
    }

    private static function looksLikePhpFpm(string $binary): bool
    {
        $filename = basename($binary);

        return str_contains($filename, 'php-fpm') || str_contains($filename, 'phpfpm');
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function formatFailureMessage(
        string $setupCommand,
        array $command,
        Process $process,
        string $output,
    ): string {
        $lines = [
            sprintf("Setup command '%s' failed with exit code %d.", $setupCommand, $process->getExitCode()),
            'Command: ' . self::formatCommand($command),
            'Working directory: ' . base_path(),
        ];

        $cleanOutput = self::cleanOutput($output);
        if ($cleanOutput !== '') {
            $lines[] = 'Output: ' . $cleanOutput;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function formatCommand(array $command): string
    {
        return implode(' ', array_map(
            fn (string $argument): string => str_contains($argument, ' ') ? escapeshellarg($argument) : $argument,
            $command,
        ));
    }

    private static function cleanOutput(string $output): string
    {
        $output = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output;
        $output = trim(preg_replace('/\s+/', ' ', $output) ?? $output);

        if (strlen($output) <= 1200) {
            return $output;
        }

        return substr($output, 0, 1197) . '...';
    }
}
