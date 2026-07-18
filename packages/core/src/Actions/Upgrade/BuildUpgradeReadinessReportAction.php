<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\PackageData;
use Capell\Core\Data\Upgrade\UpgradeReadinessCheckData;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\Upgrade\UpgradeReadinessResult;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildUpgradeReadinessReportAction
{
    use AsFake;
    use AsObject;

    public function handle(): UpgradeReadinessReportData
    {
        /** @var list<UpgradeReadinessCheckData> $checks */
        $checks = [
            $this->databaseConnectivityCheck(),
            $this->operationTablesCheck(),
            $this->queueDriverCheck(),
            $this->databaseQueueTableCheck(),
            $this->cacheLockCheck(),
            $this->migrationLockPathCheck(),
            ...$this->legacyUpgradeCommandChecks(),
        ];

        $warnings = array_values(collect($checks)
            ->filter(fn (UpgradeReadinessCheckData $check): bool => ! $check->passed && ! $check->blocking)
            ->map(fn (UpgradeReadinessCheckData $check): string => $check->message)
            ->values()
            ->all());

        $errors = array_values(collect($checks)
            ->filter(fn (UpgradeReadinessCheckData $check): bool => ! $check->passed && $check->blocking)
            ->map(fn (UpgradeReadinessCheckData $check): string => $check->message)
            ->values()
            ->all());

        return new UpgradeReadinessReportData(
            result: $errors === [] ? UpgradeReadinessResult::Ready : UpgradeReadinessResult::ManualRequired,
            checks: $checks,
            warnings: $warnings,
            errors: $errors,
        );
    }

    private function operationTablesCheck(): UpgradeReadinessCheckData
    {
        try {
            $tablesExist = Schema::hasTable('capell_upgrade_runs') && Schema::hasTable('capell_upgrade_run_events');
        } catch (Throwable $throwable) {
            return new UpgradeReadinessCheckData(
                key: 'upgrade_operation_tables',
                passed: false,
                message: 'Upgrade operation table check failed: ' . $throwable->getMessage(),
                blocking: true,
            );
        }

        if (! $tablesExist) {
            return new UpgradeReadinessCheckData(
                key: 'upgrade_operation_tables',
                passed: false,
                message: 'Upgrade operation tables are missing; run the manual upgrade command from the server shell.',
                blocking: true,
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'upgrade_operation_tables',
            passed: true,
            message: 'Upgrade operation tables are available.',
        );
    }

    private function queueDriverCheck(): UpgradeReadinessCheckData
    {
        $driver = Queue::getDefaultDriver();

        if ($driver === 'sync') {
            return new UpgradeReadinessCheckData(
                key: 'queue_driver',
                passed: false,
                message: 'Queue driver is sync; run the upgrade manually from the server shell.',
                blocking: true,
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'queue_driver',
            passed: true,
            message: sprintf('Queue driver "%s" can run background upgrade jobs.', $driver),
        );
    }

    private function databaseQueueTableCheck(): UpgradeReadinessCheckData
    {
        if (Queue::getDefaultDriver() !== 'database') {
            return new UpgradeReadinessCheckData(
                key: 'database_queue_table',
                passed: true,
                message: 'Database queue table check skipped for non-database queue driver.',
            );
        }

        $table = config('queue.connections.database.table', 'jobs');
        $table = is_string($table) && $table !== '' ? $table : 'jobs';

        if (! Schema::hasTable($table)) {
            return new UpgradeReadinessCheckData(
                key: 'database_queue_table',
                passed: false,
                message: sprintf('Database queue table "%s" is missing.', $table),
                blocking: true,
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'database_queue_table',
            passed: true,
            message: sprintf('Database queue table "%s" exists.', $table),
        );
    }

    private function cacheLockCheck(): UpgradeReadinessCheckData
    {
        try {
            $lock = Cache::lock(CacheEnum::UpgradeLock->value, 1);

            if ($lock->get() === false) {
                return new UpgradeReadinessCheckData(
                    key: 'cache_lock',
                    passed: false,
                    message: 'Upgrade coordination lock is already held or unavailable.',
                    blocking: true,
                );
            }

            $lock->release();
        } catch (Throwable $throwable) {
            return new UpgradeReadinessCheckData(
                key: 'cache_lock',
                passed: false,
                message: 'Cache lock check failed: ' . $throwable->getMessage(),
                blocking: true,
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'cache_lock',
            passed: true,
            message: 'Upgrade coordination lock is available.',
        );
    }

    private function migrationLockPathCheck(): UpgradeReadinessCheckData
    {
        $lockPath = storage_path('framework/cache/capell-database-migrations.lock');

        try {
            File::ensureDirectoryExists(dirname($lockPath));
            $lockFile = fopen($lockPath, 'c');

            if (! is_resource($lockFile)) {
                return new UpgradeReadinessCheckData(
                    key: 'migration_lock_path',
                    passed: false,
                    message: 'Migration lock file cannot be opened.',
                    blocking: true,
                );
            }

            fclose($lockFile);
        } catch (Throwable $throwable) {
            return new UpgradeReadinessCheckData(
                key: 'migration_lock_path',
                passed: false,
                message: 'Migration lock path is not writable: ' . $throwable->getMessage(),
                blocking: true,
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'migration_lock_path',
            passed: true,
            message: 'Migration lock path is writable.',
        );
    }

    private function databaseConnectivityCheck(): UpgradeReadinessCheckData
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $throwable) {
            return new UpgradeReadinessCheckData(
                key: 'database_connectivity',
                passed: false,
                message: 'Database connection failed: ' . $throwable->getMessage(),
                blocking: true,
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'database_connectivity',
            passed: true,
            message: 'Database connection is available.',
        );
    }

    /** @return list<UpgradeReadinessCheckData> */
    private function legacyUpgradeCommandChecks(): array
    {
        $commands = Artisan::all();

        return array_values(CapellCore::getPackages()
            ->map(fn (PackageData $package): ?UpgradeReadinessCheckData => $this->legacyUpgradeCommandCheck($package, $commands))
            ->filter()
            ->values()
            ->all());
    }

    /**
     * @param  array<string, mixed>  $commands
     */
    private function legacyUpgradeCommandCheck(PackageData $package, array $commands): ?UpgradeReadinessCheckData
    {
        $command = $package->getUpgradeCommand();

        if (! is_string($command) || $command === '') {
            return null;
        }

        $commandName = str($command)->before(' ')->trim()->toString();

        if (! array_key_exists($commandName, $commands)) {
            return new UpgradeReadinessCheckData(
                key: 'legacy_upgrade_command:' . $package->name,
                passed: false,
                message: sprintf('Legacy upgrade command "%s" for %s is not registered; it will be skipped if unavailable at runtime.', $commandName, $package->name),
            );
        }

        return new UpgradeReadinessCheckData(
            key: 'legacy_upgrade_command:' . $package->name,
            passed: false,
            message: sprintf('Package %s still uses legacy manifest upgrade command "%s"; migrate it to a tagged UpgradeStepContract.', $package->name, $commandName),
        );
    }
}
