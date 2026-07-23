<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Reports the external tooling Capell shells out to.
 *
 * Shared hosts commonly disable proc_open, and slim containers ship no Composer
 * or Node. Each of those removes a specific feature rather than breaking the
 * site, so this is a warning that names what stops working.
 */
final class RuntimeToolingCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.runtime.tooling';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $label = 'Runtime tooling is available';
        $missing = [];

        // Only an installation that installs extensions or builds assets on the
        // server actually needs this tooling. A deployment that runs Composer and
        // builds assets in CI ships a server with neither, which is correct and
        // must not fail the health gate that capell:install exits on.
        $serverSideTooling = config('capell.server_side_tooling', false) === true;
        $evidence = ['server_side_tooling' => $serverSideTooling];

        $processExecution = $this->processExecutionAvailable();
        $evidence['proc_open'] = $processExecution;

        if (! $processExecution) {
            return new DoctorCheckResultData(
                $label,
                ! $serverSideTooling,
                'Process execution (proc_open) is disabled, so Capell cannot install extensions, run package setup, or create database backups.',
                'Remove proc_open from disable_functions in php.ini, or install extensions and take backups from a machine that allows it. Set CAPELL_SERVER_SIDE_TOOLING=false when this server deliberately does none of that.',
                severity: $this->severity(),
                evidence: $evidence,
            );
        }

        $finder = new ExecutableFinder;

        foreach ($this->requiredBinaries() as $binary => $consequence) {
            $path = $finder->find($binary);
            $evidence[$binary] = $path ?? false;

            if ($path === null) {
                $missing[] = sprintf('%s (%s)', $binary, $consequence);
            }
        }

        if ($missing === []) {
            return new DoctorCheckResultData($label, true, 'Process execution is allowed and the expected binaries were found.', severity: $this->severity(), evidence: $evidence);
        }

        return new DoctorCheckResultData(
            $label,
            ! $serverSideTooling,
            $serverSideTooling
                ? sprintf('Executable(s) not found on PATH: %s.', implode('; ', $missing))
                : sprintf('Executable(s) not found on PATH: %s. This installation is not configured to run that tooling on the server, so it is reported for information only.', implode('; ', $missing)),
            'Install the listed tools on the server, or perform those operations during your build and deploy the result. Set CAPELL_SERVER_SIDE_TOOLING=true when the server must run them itself.',
            severity: $this->severity(),
            evidence: $evidence,
        );
    }

    /**
     * @return array<string, string>
     */
    private function requiredBinaries(): array
    {
        return [
            'composer' => 'installing and removing extensions',
            'npm' => 'rebuilding frontend assets from the admin',
        ];
    }

    private function processExecutionAvailable(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');

        if ($disabled === '') {
            return true;
        }

        $disabledFunctions = array_map(
            static fn (string $function): string => strtolower(trim($function)),
            explode(',', $disabled),
        );

        return ! in_array('proc_open', $disabledFunctions, true);
    }
}
