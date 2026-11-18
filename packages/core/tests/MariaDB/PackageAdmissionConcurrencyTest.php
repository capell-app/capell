<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\RunUnderPackageAdmissionAction;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Extensions\ExtensionLifecycleRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

uses()->group('mariadb');

const RACE_PACKAGE = 'capell-test/admission-race-fixture';

/**
 * These proofs hold row locks on separately committed connections, so SQLite cannot
 * run them. They are declared only in phpunit.mariadb.xml and throw, never skip,
 * when pointed at anything but the disposable concurrency database.
 */
function admissionRaceConnections(): array
{
    throw_unless(
        DB::connection()->getDriverName() === 'mysql' && DB::connection()->getDatabaseName() === 'capell_editor_race_test',
        RuntimeException::class,
        'Run with DB_CONNECTION=mysql DB_DATABASE=capell_editor_race_test against a disposable MariaDB server: vendor/bin/pest --configuration=phpunit.mariadb.xml',
    );

    // Trigger test migrations, then leave the suite's wrapping transaction: these
    // proofs need writes that are committed and visible to the other connection.
    DB::connection()->getSchemaBuilder()->hasTable((new CapellExtension)->getTable());
    $previous = DB::getDefaultConnection();
    test()->previousAdmissionConnection = $previous;
    $config = config('database.connections.' . $previous);
    config()->set('database.connections.admission_writer', $config);
    config()->set('database.connections.admission_other_process', $config);
    DB::setDefaultConnection('admission_writer');

    CapellCore::registerPackage(RACE_PACKAGE);
    CapellCore::markPackageInstalled(RACE_PACKAGE);

    $other = DB::connection('admission_other_process');
    $other->statement('SET SESSION innodb_lock_wait_timeout=1');
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout=1');

    return [DB::connection(), $other];
}

function releaseAdmissionRace(Connection $other): void
{
    while ($other->transactionLevel() > 0) {
        $other->rollBack();
    }

    DB::connection('admission_writer')->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->delete();
    DB::setDefaultConnection(test()->previousAdmissionConnection);
    DB::purge('admission_other_process');
    DB::purge('admission_writer');
}

function raceStatus(Connection $connection): string
{
    return (string) $connection->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->value('status');
}

it('makes a disable wait until admitted work has committed', function (): void {
    [, $other] = admissionRaceConnections();

    try {
        $disableError = null;
        $result = RunUnderPackageAdmissionAction::run(RACE_PACKAGE, function () use ($other, &$disableError): string {
            // Another process disables while this work is admitted and not yet committed.
            try {
                $other->transaction(function () use ($other): void {
                    $other->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->lockForUpdate()->first();
                });
            } catch (QueryException $queryException) {
                $disableError = $queryException;
            }

            return 'admitted work';
        });

        expect($result->admitted)->toBeTrue()
            ->and($disableError)->toBeInstanceOf(QueryException::class)
            ->and($disableError?->getMessage())->toContain('Lock wait timeout');

        // Once the admitted work commits, the lifecycle write proceeds.
        CapellCore::markPackageDisabled(RACE_PACKAGE);
        expect(raceStatus($other))->toBe(ExtensionStatusEnum::Disabled->value);
    } finally {
        releaseAdmissionRace($other);
    }
});

it('makes admission wait for an in-flight disable and then refuse the work', function (): void {
    [, $other] = admissionRaceConnections();

    try {
        $other->beginTransaction();
        $other->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->lockForUpdate()->first();
        $other->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->update(['status' => ExtensionStatusEnum::Disabled->value]);

        $runs = 0;
        expect(fn () => RunUnderPackageAdmissionAction::run(RACE_PACKAGE, function () use (&$runs): void {
            $runs++;
        }))->toThrow(QueryException::class, 'Lock wait timeout');
        expect($runs)->toBe(0);

        $other->commit();

        $result = RunUnderPackageAdmissionAction::run(RACE_PACKAGE, function () use (&$runs): void {
            $runs++;
        });
        expect($result->admitted)->toBeFalse()
            ->and($result->reason)->toBe('disabled')
            ->and($runs)->toBe(0);
    } finally {
        releaseAdmissionRace($other);
    }
});

it('releases the admission lock when nested work rolls back, then admits again after re-enable', function (): void {
    [$writer, $other] = admissionRaceConnections();

    try {
        expect(fn () => RunUnderPackageAdmissionAction::run(RACE_PACKAGE, function () use ($writer): never {
            $writer->transaction(function () use ($writer): void {
                $writer->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->update(['description' => 'rolled back']);
            });

            throw new RuntimeException('outer work failed');
        }))->toThrow(RuntimeException::class, 'outer work failed');

        expect($writer->transactionLevel())->toBe(0)
            ->and($other->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->value('description'))->not->toBe('rolled back');

        // The lock was released: a lifecycle write from the other process does not wait.
        $other->transaction(fn () => $other->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->lockForUpdate()->first());
        CapellCore::markPackageDisabled(RACE_PACKAGE);
        CapellCore::markPackageInstalled(RACE_PACKAGE);

        $runs = 0;
        $result = RunUnderPackageAdmissionAction::run(RACE_PACKAGE, function () use (&$runs): void {
            $runs++;
        });
        expect($result->admitted)->toBeTrue()->and($runs)->toBe(1);
    } finally {
        releaseAdmissionRace($other);
    }
});

it('makes each lifecycle write wait while another process holds admission', function (Closure $lifecycleWrite): void {
    [, $other] = admissionRaceConnections();

    try {
        // Another process has admitted work and holds the shared admission lock.
        $other->beginTransaction();
        $other->table((new CapellExtension)->getTable())->where('composer_name', RACE_PACKAGE)->sharedLock()->first();

        expect($lifecycleWrite)->toThrow(QueryException::class, 'Lock wait timeout')
            ->and(raceStatus($other))->toBe(ExtensionStatusEnum::Enabled->value);

        $other->commit();
        $lifecycleWrite();
        expect(raceStatus($other))->not->toBe(ExtensionStatusEnum::Enabled->value);
    } finally {
        releaseAdmissionRace($other);
    }
})->with([
    'disable' => [fn () => CapellCore::markPackageDisabled(RACE_PACKAGE)],
    'uninstall' => [fn () => CapellCore::markPackageUninstalled(RACE_PACKAGE)],
    'quarantine' => [fn () => resolve(ExtensionLifecycleRepository::class)->recordProviderQuarantined(RACE_PACKAGE, 'Fixture\\Provider', 'fixture failure', null)],
]);
