<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Confirms the backup binaries exist for the ACTIVE database connection.
 *
 * Backups fail at the moment you need them most, so this checks up front rather
 * than leaving the operator to discover it during an incident. SQLite needs no
 * external binary, so only MySQL/MariaDB and PostgreSQL are checked.
 */
final class DatabaseBackupBinariesCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.backup.database-binaries';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $label = 'Database backup binaries are available';

        if (config('backup.enabled', false) !== true) {
            return new DoctorCheckResultData($label, true, 'Backups are disabled, so no backup binaries are required.', severity: $this->severity());
        }

        $connectionName = (string) config('backup.database_connection') ?: (string) config('database.default');
        $driver = (string) config(sprintf('database.connections.%s.driver', $connectionName));

        $required = match ($driver) {
            'mysql', 'mariadb' => ['backup.binaries.mysqldump' => 'mysqldump', 'backup.binaries.mysql' => 'mysql'],
            'pgsql' => ['backup.binaries.pg_dump' => 'pg_dump', 'backup.binaries.psql' => 'psql'],
            default => [],
        };

        if ($required === []) {
            return new DoctorCheckResultData(
                $label,
                true,
                sprintf('Connection [%s] uses the [%s] driver, which needs no external backup binary.', $connectionName, $driver !== '' ? $driver : 'unknown'),
                severity: $this->severity(),
            );
        }

        $finder = new ExecutableFinder;
        $missing = [];
        $evidence = ['connection' => $connectionName, 'driver' => $driver];

        foreach ($required as $configKey => $default) {
            $binary = (string) config($configKey, $default);
            $resolved = str_contains($binary, DIRECTORY_SEPARATOR)
                ? (is_executable($binary) ? $binary : null)
                : $finder->find($binary);

            $evidence[$configKey] = $resolved ?? false;

            if ($resolved === null) {
                $missing[] = sprintf('%s (set %s)', $binary, $configKey);
            }
        }

        return $missing === []
            ? new DoctorCheckResultData($label, true, sprintf('Backup binaries for the [%s] driver were found.', $driver), severity: $this->severity(), evidence: $evidence)
            : new DoctorCheckResultData(
                $label,
                false,
                sprintf('Backups are enabled but these executables were not found: %s.', implode('; ', $missing)),
                'Install the database client tools on the server, or set the listed config keys to their absolute paths.',
                severity: $this->severity(),
                evidence: $evidence,
            );
    }
}
