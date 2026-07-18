<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallMemoryLimit;
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
        $failures = [
            ...$this->runtimeFailures(),
            ...$this->filesystemFailures(),
            ...$this->databaseConfigurationFailures(),
        ];

        if (filter_var($inputData->siteUrl, FILTER_VALIDATE_URL) === false) {
            $failures[] = sprintf('The site URL [%s] is not a valid absolute URL.', $inputData->siteUrl);
        }

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

    /** @return list<string> */
    private function runtimeFailures(): array
    {
        $failures = [];
        $memoryLimit = resolve(InstallMemoryLimit::class);

        if (! $memoryLimit->isSatisfied()) {
            $failures[] = $memoryLimit->failureMessage();
        }

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

        $requiredExtension = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            'sqlsrv' => 'pdo_sqlsrv',
            default => null,
        };

        if ($requiredExtension !== null && ! extension_loaded($requiredExtension)) {
            return [sprintf('Database driver [%s] requires PHP extension [%s].', $driver, $requiredExtension)];
        }

        return [];
    }
}
