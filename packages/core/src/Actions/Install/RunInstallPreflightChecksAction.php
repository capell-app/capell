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
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Composer\InstallPackageArguments;
use Capell\Core\Support\Install\InstallSiteUrl;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
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

            foreach ($report->blockingChecks() as $check) {
                if ($check->remediation !== null) {
                    $reporter->report($check->remediation);
                }
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

        $siteUrlError = InstallSiteUrl::validationError($inputData->siteUrl);
        $siteUrlFailure = $siteUrlError !== null
            ? [$siteUrlError]
            : [];

        $checks[] = $this->check(
            key: 'site-url',
            category: 'configuration',
            failures: $siteUrlFailure,
            message: 'The site URL is a valid absolute URL.',
            remediation: 'Set the site URL to an absolute URL, including the scheme.',
        );

        if ($inputData->extraPackages !== []) {
            $checks[] = $this->selectedPackageCheck($inputData->extraPackages);
        }

        return new InstallReadinessReportData(
            stage: InstallReadinessStage::Boot,
            checks: $checks,
        );
    }

    /** @param array<int, string> $packages */
    public function selectedPackageCheck(
        array $packages,
        bool $dev = false,
        ?ProcessFactoryInterface $processFactory = null,
        ?RuntimeBinaryResolver $binaryResolver = null,
    ): InstallReadinessCheckData {
        $binaryResolver ??= resolve(RuntimeBinaryResolver::class);
        $binary = $binaryResolver->misconfiguredComposerBinary() === null
            ? $binaryResolver->composerOrNull()
            : null;

        if ($binary === null) {
            return $this->selectedPackageResult(
                $dev,
                false,
                'Composer is required to install selected downloadable packages.',
                'Make Composer available to the web PHP process or deselect downloadable packages.',
            );
        }

        $arguments = array_map(
            fn (string $package): string => str_contains($package, ':') ? $package : $package . ':*',
            resolve(InstallPackageArguments::class)->resolve(array_values($packages)),
        );
        $options = [
            '--dry-run', '--no-audit', '--no-interaction', '--no-progress', '--no-scripts', '--with-all-dependencies',
        ];
        if ($dev) {
            $options[] = '--dev';
        }

        // Resolve the whole selection afresh in the process that will install it.
        $process = ($processFactory ?? resolve(ProcessFactoryInterface::class))->make(
            [...$binary, 'require', ...$arguments, ...$options],
            base_path(),
            ComposerProcessEnvironment::forInstall($_SERVER),
        );
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful()) {
            return $this->selectedPackageResult($dev, true, 'Selected downloadable packages can be resolved by Composer.');
        }

        $message = $process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput();
        $message = preg_replace('/\e\[[0-9;]*m/', '', $message) ?? $message;
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if (strlen($message) > 600) {
            $message = substr($message, 0, 597) . '...';
        }

        return $this->selectedPackageResult(
            $dev,
            false,
            $message,
            'Check package names, Composer repositories, GitHub access, and HTTPS/SSH clone configuration.',
        );
    }

    private function selectedPackageResult(bool $dev, bool $passed, string $message, ?string $remediation = null): InstallReadinessCheckData
    {
        return new InstallReadinessCheckData(
            key: $dev ? 'developer-tooling-packages' : 'selected-packages',
            stage: InstallReadinessStage::Boot,
            category: 'packages',
            status: $passed ? InstallReadinessStatus::Passed : InstallReadinessStatus::Blocked,
            blocking: ! $passed,
            outcome: $passed ? InstallReadinessOutcome::AutomatedNow : InstallReadinessOutcome::Blocked,
            message: $message,
            remediation: $remediation,
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
