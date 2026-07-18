<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Process\Process;

/**
 * @method static void run(PackageData $package, array<string, mixed> $arguments = [], ?ProgressReporter $reporter = null)
 */
class DemoPackageAction
{
    use AsFake;
    use AsObject;

    /**
     * @var null|callable(array<int, string>, string): object
     */
    private static mixed $processFactory = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function handle(
        PackageData $package,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
    ): void {
        if ($package->getDemoCommand() === null || $package->getDemoCommand() === '') {
            return;
        }

        $demoCommand = $package->getDemoCommand();

        if (! array_key_exists($demoCommand, Artisan::all())) {
            throw new Exception(sprintf("Demo command '%s' does not exist.", $package->getDemoCommand()));
        }

        $process = self::makeProcess([
            PHP_BINARY,
            '-d',
            'memory_limit=512M',
            base_path('artisan'),
            $demoCommand,
            ...self::commandArguments($arguments),
            '--no-interaction',
        ]);

        self::callProcessVoidMethod($process, 'setTimeout', null);

        $output = '';
        $lineBuffer = '';

        self::callProcessVoidMethod($process, 'run', function (string $outputType, string $buffer) use (&$output, &$lineBuffer, $reporter): void {
            $output .= $buffer;

            if (! $reporter instanceof ProgressReporter) {
                return;
            }

            self::reportBufferedOutput($buffer, $lineBuffer, $reporter);
        });

        if ($reporter instanceof ProgressReporter) {
            self::flushBufferedOutput($lineBuffer, $reporter);
        }

        if (! self::callProcessBoolMethod($process, 'isSuccessful')) {
            $message = self::formatFailureMessage($demoCommand, self::callProcessNullableIntMethod($process, 'getExitCode'), $output, [
                PHP_BINARY,
                '-d',
                'memory_limit=512M',
                base_path('artisan'),
                $demoCommand,
                ...self::commandArguments($arguments),
                '--no-interaction',
            ]);

            if ($reporter instanceof ProgressReporter) {
                $reporter->error($message);
            }

            throw new Exception($message);
        }
    }

    /**
     * @param  null|callable(array<int, string>, string): object  $processFactory
     */
    public static function setProcessFactory(?callable $processFactory): void
    {
        self::$processFactory = $processFactory;
    }

    public static function resetProcessFactory(): void
    {
        self::$processFactory = null;
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function makeProcess(array $command): object
    {
        if (self::$processFactory !== null) {
            return (self::$processFactory)($command, base_path());
        }

        return new Process($command, base_path(), ArtisanProcessEnvironment::prepare());
    }

    private static function callProcessVoidMethod(object $process, string $method, mixed ...$arguments): void
    {
        $callback = [$process, $method];

        if (! is_callable($callback)) {
            throw new Exception(sprintf('Process method %s is not callable.', $method));
        }

        $callback(...$arguments);
    }

    private static function callProcessBoolMethod(object $process, string $method): bool
    {
        $callback = [$process, $method];

        if (! is_callable($callback)) {
            throw new Exception(sprintf('Process method %s is not callable.', $method));
        }

        return (bool) $callback();
    }

    private static function callProcessNullableIntMethod(object $process, string $method): ?int
    {
        $callback = [$process, $method];

        if (! is_callable($callback)) {
            throw new Exception(sprintf('Process method %s is not callable.', $method));
        }

        $result = $callback();

        return is_int($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    private static function commandArguments(array $arguments): array
    {
        return collect($arguments)
            ->flatMap(function (mixed $value, string $key): array {
                if ($value === null || $value === false) {
                    return [];
                }

                if ($value === true) {
                    return [$key];
                }

                if (is_array($value)) {
                    $value = implode(',', array_map(static fn (mixed $item): string => (string) $item, $value));
                }

                if (str_starts_with($key, '--')) {
                    return [sprintf('%s=%s', $key, (string) $value)];
                }

                return [(string) $value];
            })
            ->values()
            ->all();
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

    private static function cleanOutput(string $output): string
    {
        $output = preg_replace('/\e\[[0-9;]*m/', '', $output) ?? $output;
        $output = trim(preg_replace('/[ \t]+/', ' ', $output) ?? $output);

        if (strlen($output) <= 4000) {
            return $output;
        }

        return '...' . substr($output, -3997);
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function formatFailureMessage(string $demoCommand, ?int $exitCode, string $output, array $command): string
    {
        $message = sprintf("Demo command '%s' failed with exit code %d.", $demoCommand, $exitCode ?? 1);
        $message .= "\nCommand: " . self::commandLine($command);

        $logPath = self::writeFailureLog($demoCommand, $command, $exitCode, $output);

        if ($logPath !== null) {
            $message .= "\nFull output: " . $logPath;
        }

        $cleanOutput = self::cleanOutput($output);

        if ($cleanOutput !== '') {
            $message .= "\nOutput tail:\n" . $cleanOutput;
        }

        return $message;
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function writeFailureLog(string $demoCommand, array $command, ?int $exitCode, string $output): ?string
    {
        if (trim($output) === '') {
            return null;
        }

        $directory = storage_path('logs');
        File::ensureDirectoryExists($directory);

        $filename = sprintf(
            'capell-demo-%s-%s.log',
            preg_replace('/[^A-Za-z0-9_.-]+/', '-', $demoCommand) ?: 'command',
            now()->format('Ymd-His'),
        );
        $path = $directory . DIRECTORY_SEPARATOR . $filename;

        File::put($path, implode(PHP_EOL, [
            'Command: ' . self::commandLine($command),
            'Exit code: ' . ($exitCode ?? 1),
            '',
            $output,
        ]));

        return $path;
    }

    /**
     * @param  array<int, string>  $command
     */
    private static function commandLine(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => escapeshellarg(self::redactCommandPart($part)),
            $command,
        ));
    }

    private static function redactCommandPart(string $part): string
    {
        foreach (['--password=', '--token=', '--api-key=', '--secret='] as $sensitivePrefix) {
            if (str_starts_with($part, $sensitivePrefix)) {
                return $sensitivePrefix . '[redacted]';
            }
        }

        return $part;
    }
}
