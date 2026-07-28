<?php

declare(strict_types=1);

final class ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public static function run(
        array $command,
        string $workingDirectory,
        array $environment = [],
        ?string $logPath = null,
    ): int {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $workingDirectory,
            [...self::environment(), ...$environment, 'PWD' => $workingDirectory],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . implode(' ', $command));
        }

        $log = $logPath === null ? null : fopen($logPath, 'wb');

        if ($logPath !== null && $log === false) {
            proc_terminate($process);

            throw new RuntimeException("Unable to open process log [{$logPath}].");
        }

        self::drainPipes(
            [$pipes[1], $pipes[2]],
            static function (string $chunk) use ($log): void {
                echo $chunk;

                if (is_resource($log)) {
                    fwrite($log, $chunk);
                }
            },
        );

        if (is_resource($log)) {
            fclose($log);
        }

        return proc_close($process);
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    public static function capture(array $command, string $workingDirectory): array
    {
        $descriptorSpec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $workingDirectory,
            [...self::environment(), 'PWD' => $workingDirectory],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start process: ' . implode(' ', $command));
        }

        $output = '';
        self::drainPipes(
            [$pipes[1], $pipes[2]],
            static function (string $chunk) use (&$output): void {
                $output .= $chunk;
            },
        );

        return [
            'exit_code' => proc_close($process),
            'output' => trim($output),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $environment = getenv();

        if (! is_array($environment)) {
            return [];
        }

        return $environment;
    }

    /**
     * @param  list<resource>  $pipes
     * @param  callable(string): void  $consumer
     */
    private static function drainPipes(array $pipes, callable $consumer): void
    {
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while ($pipes !== []) {
            $read = $pipes;
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, null) === false) {
                throw new RuntimeException('Unable to read process output.');
            }

            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);

                if (is_string($chunk) && $chunk !== '') {
                    $consumer($chunk);
                }

                if (feof($pipe)) {
                    $index = array_search($pipe, $pipes, true);

                    if (is_int($index)) {
                        fclose($pipe);
                        unset($pipes[$index]);
                        $pipes = array_values($pipes);
                    }
                }
            }
        }
    }
}
