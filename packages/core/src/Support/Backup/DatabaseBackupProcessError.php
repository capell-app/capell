<?php

declare(strict_types=1);

namespace Capell\Core\Support\Backup;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Builds operator-facing messages for failed database backup processes.
 *
 * Backup drivers shell out to mysqldump/pg_dump, which are absent from most slim
 * PHP containers. Reporting only "backup failed" leaves an operator unable to tell
 * a missing binary from a bad password, so this distinguishes the two and names the
 * config key that fixes it.
 *
 * Connection passwords are passed to these binaries through the environment, never
 * through argv, so process output is safe to surface.
 */
final class DatabaseBackupProcessError
{
    private const int MAX_OUTPUT_LENGTH = 2000;

    public static function message(
        string $operation,
        string $connectionName,
        string $binary,
        string $binaryConfigKey,
        Throwable $throwable,
    ): string {
        $summary = sprintf('Database backup %s failed for connection [%s].', $operation, $connectionName);

        if (self::binaryIsMissing($binary)) {
            return $summary . sprintf(
                ' The [%s] executable was not found. Install it on the server, or set [%s] to its absolute path.',
                $binary,
                $binaryConfigKey,
            );
        }

        $detail = self::detail($throwable);

        return $detail === ''
            ? $summary . ' ' . $throwable->getMessage()
            : $summary . ' ' . $detail;
    }

    private static function binaryIsMissing(string $binary): bool
    {
        if ($binary === '') {
            return true;
        }

        // An absolute or relative path is used as-is by Symfony Process.
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return ! is_executable($binary);
        }

        return (new ExecutableFinder)->find($binary) === null;
    }

    private static function detail(Throwable $throwable): string
    {
        if (! $throwable instanceof ProcessFailedException) {
            return '';
        }

        $process = $throwable->getProcess();
        $output = trim($process->getErrorOutput());

        if ($output === '') {
            $output = trim($process->getOutput());
        }

        if ($output === '') {
            return sprintf('The process exited with code %s and produced no output.', (string) $process->getExitCode());
        }

        if (mb_strlen($output) > self::MAX_OUTPUT_LENGTH) {
            return mb_substr($output, 0, self::MAX_OUTPUT_LENGTH) . '…';
        }

        return $output;
    }
}
