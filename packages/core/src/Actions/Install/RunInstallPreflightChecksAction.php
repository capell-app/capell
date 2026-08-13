<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\InstallReadinessCheckData;
use Capell\Core\Data\Install\InstallReadinessReportData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Enums\Install\InstallReadinessOutcome;
use Capell\Core\Enums\Install\InstallReadinessStage;
use Capell\Core\Enums\Install\InstallReadinessStatus;
use Capell\Core\Exceptions\UnsupportedDatabaseDriver;
use Capell\Core\Facades\CapellDatabase;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RunInstallPreflightChecksAction
{
    use AsFake;
    use AsObject;

    /** @var list<string> */
    private const array REQUIRED_EXTENSIONS = [
        'curl',
        'fileinfo',
        'intl',
        'mbstring',
        'openssl',
        'pdo',
        'simplexml',
    ];

    public function handle(InstallInputData $inputData, ProgressReporter $reporter): void
    {
        $report = $this->report($inputData);
        $failures = array_map(
            static fn (InstallReadinessCheckData $check): string => $check->message,
            $report->blockingChecks(),
        );

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $reporter->error('✗ ' . $failure);
            }

            throw new RuntimeException("Install preflight failed:\n- " . implode("\n- ", $failures));
        }

        $reporter->report('✓ PHP runtime and required extensions are available.');
        $reporter->report('✓ Composer, cache, storage, and database paths are ready.');
        $reporter->report('✓ Database driver configuration is available.');
        $reporter->report('Preflight checks passed.');
    }

    public function report(InstallInputData $inputData): InstallReadinessReportData
    {
        $checks = [
            $this->check(
                key: 'runtime',
                category: 'runtime',
                failures: $this->runtimeFailures(),
                message: 'PHP runtime and required extensions are available.',
                remediation: 'Install or enable the missing PHP extensions, then run the preflight again.',
            ),
            $this->check(
                key: 'filesystem',
                category: 'filesystem',
                failures: $this->filesystemFailures(),
                message: 'Composer, cache, storage, and database paths are ready.',
                remediation: 'Create the missing directories and grant the application user the required access.',
            ),
            $this->check(
                key: 'database-configuration',
                category: 'database',
                failures: $this->databaseConfigurationFailures(),
                message: 'Database driver configuration is available.',
                remediation: 'Configure a supported database connection and install its PHP extension.',
            ),
        ];

        $siteUrlFailure = filter_var($inputData->siteUrl, FILTER_VALIDATE_URL) === false
            ? [sprintf('The site URL [%s] is not a valid absolute URL.', $inputData->siteUrl)]
            : [];

        $checks[] = $this->check(
            key: 'site-url',
            category: 'configuration',
            failures: $siteUrlFailure,
            message: 'The site URL is a valid absolute URL.',
            remediation: 'Set the site URL to an absolute URL, including the scheme.',
        );

        return new InstallReadinessReportData(
            stage: InstallReadinessStage::Boot,
            checks: $checks,
        );
    }

    /** @param list<string> $failures */
    private function check(
        string $key,
        string $category,
        array $failures,
        string $message,
        string $remediation,
    ): InstallReadinessCheckData {
        $failed = $failures !== [];

        return new InstallReadinessCheckData(
            key: $key,
            stage: InstallReadinessStage::Boot,
            category: $category,
            status: $failed ? InstallReadinessStatus::Blocked : InstallReadinessStatus::Passed,
            blocking: $failed,
            outcome: $failed ? InstallReadinessOutcome::Blocked : InstallReadinessOutcome::AutomatedNow,
            message: $failed ? implode(' ', $failures) : $message,
            remediation: $failed ? $remediation : null,
        );
    }

    /** @return list<string> */
    private function runtimeFailures(): array
    {
        $failures = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $failures[] = sprintf('Required PHP extension [%s] is not loaded.', $extension);
            }
        }

        if (! extension_loaded('gd') && ! extension_loaded('imagick')) {
            $failures[] = 'Either the [gd] or [imagick] PHP extension must be loaded.';
        }

        return $failures;
    }

    /** @return list<string> */
    private function filesystemFailures(): array
    {
        $failures = [];

        if (! is_readable(base_path('composer.json'))) {
            $failures[] = 'composer.json is missing or unreadable.';
        }

        foreach ([base_path('bootstrap/cache'), storage_path(), database_path()] as $path) {
            if (! is_dir($path)) {
                $failures[] = sprintf('Required directory [%s] does not exist.', $path);

                continue;
            }

            if (! is_writable($path)) {
                $failures[] = sprintf('Required directory [%s] is not writable.', $path);
            }
        }

        return $failures;
    }

    /** @return list<string> */
    private function databaseConfigurationFailures(): array
    {
        $connection = (string) config('database.default');
        $driver = (string) config(sprintf('database.connections.%s.driver', $connection));

        if ($connection === '' || $driver === '') {
            return ['A default database connection and driver must be configured.'];
        }

        try {
            $requiredExtension = CapellDatabase::forDriver($driver)->phpExtension();
        } catch (UnsupportedDatabaseDriver) {
            return [sprintf('Database driver [%s] is not supported.', $driver)];
        }

        if (! extension_loaded($requiredExtension)) {
            return [sprintf('Database driver [%s] requires PHP extension [%s].', $driver, $requiredExtension)];
        }

        return [];
    }
}
