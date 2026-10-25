<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\PruneAdminUploadGrantsAction;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Media\AdminUploadGrantRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('physically prunes abandoned expired grants in bounded scheduled batches with one delete', function (): void {
    $registry = resolve(AdminUploadGrantRepository::class);
    $rows = array_map(fn (int $index): array => [
        'scope_hash' => hash('sha256', 'abandoned-session-' . $index),
        'path_hash' => hash('sha256', 'abandoned.txt'),
        'path' => 'abandoned.txt',
        'expires_at' => now()->subMinute(),
    ], range(1, 1005));
    foreach (array_chunk($rows, 100) as $batch) {
        DB::table('admin_upload_grants')->insert($batch);
    }

    $registry->grant(hash('sha256', 'active-session'), 'active.txt');
    $connection = DB::connection();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    try {
        expect(PruneAdminUploadGrantsAction::run())->toBe(1000);
        $queries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }

    expect($queries)->toHaveCount(1)
        ->and(strtolower(ltrim($queries[0]['query'])))->toStartWith('delete ')
        ->and(DB::table('admin_upload_grants')->where('expires_at', '<=', now())->count())->toBe(5);
    artisanCommand('capell:admin-prune-upload-grants')->assertSuccessful();
    expect(DB::table('admin_upload_grants')->pluck('path')->all())->toBe(['active.txt']);
});

it('leaves expired rows untouched during a read-only lookup and never renews duplicates', function (): void {
    $registry = resolve(AdminUploadGrantRepository::class);
    $scope = hash('sha256', 'session');
    $registry->grant($scope, 'owned.txt');
    $issued = DB::table('admin_upload_grants')->first();
    $this->travel(1)->minutes();
    $registry->grant($scope, 'owned.txt');
    expect(DB::table('admin_upload_grants')->get()->all())->toEqual([$issued]);

    $this->travel((int) config('session.lifetime'))->minutes();
    expect($registry->paths($scope, ['owned.txt']))->toBe([])
        ->and(DB::table('admin_upload_grants')->get()->all())->toEqual([$issued]);
    expect(PruneAdminUploadGrantsAction::run(0))->toBe(0)
        ->and(PruneAdminUploadGrantsAction::run(-1))->toBe(0)
        ->and(PruneAdminUploadGrantsAction::run(1))->toBe(1)
        ->and(DB::table('admin_upload_grants')->count())->toBe(0);
});

it('schedules hourly upload grant maintenance with the usual scheduler guards', function (): void {
    $events = array_values(array_filter(
        resolve(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, 'capell:admin-prune-upload-grants'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->getExpression())->toBe('0 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeTrue();
});

it('registers every Admin migration for publication', function (): void {
    $files = glob(dirname(__DIR__, 4) . '/database/migrations/*.php');
    expect($files)->not->toBeFalse()->not->toBeEmpty();
    $names = array_map(fn (string $path): string => basename($path, '.php'), $files === false ? [] : $files);
    expect(CapellAdmin::getMigrations())->toEqualCanonicalizing($names);
    $published = ServiceProvider::pathsToPublish(AdminServiceProvider::class, 'capell-admin-migrations');
    foreach ($files === false ? [] : $files as $file) {
        expect(array_map(realpath(...), array_keys($published)))->toContain(realpath($file));
    }
});

it('migrates a prefixed registry additively and enforces independent unique grants', function (): void {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.upload-schema', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'capell_test_',
    ]);
    DB::setDefaultConnection('upload-schema');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_24_000001_create_admin_upload_grants_table.php';
    try {
        Schema::create('existing_content', fn (Blueprint $table) => $table->string('value'));
        DB::table('existing_content')->insert(['value' => 'preserve me']);
        $migration->up();
        expect(Schema::hasColumns('admin_upload_grants', ['id', 'scope_hash', 'path_hash', 'path', 'expires_at']))->toBeTrue()
            ->and(Schema::hasIndex('admin_upload_grants', ['scope_hash', 'path_hash'], 'unique'))->toBeTrue()
            ->and(Schema::hasIndex('admin_upload_grants', ['expires_at']))->toBeTrue();
        $registry = resolve(AdminUploadGrantRepository::class);
        $scope = hash('sha256', 'same scope');
        $registry->grant($scope, str_repeat('long-path/', 100) . 'file.txt');
        $registry->grant($scope, str_repeat('long-path/', 100) . 'file.txt');
        $registry->grant(hash('sha256', 'other scope'), 'file.txt');
        expect(DB::table('admin_upload_grants')->count())->toBe(2);
        $this->travel((int) config('session.lifetime'))->minutes();
        expect($registry->prune(1))->toBe(1)
            ->and(DB::table('admin_upload_grants')->count())->toBe(1);
        $migration->down();
        expect(Schema::hasTable('admin_upload_grants'))->toBeFalse()
            ->and(DB::table('existing_content')->value('value'))->toBe('preserve me');
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('upload-schema');
    }
});

it('compiles migration and atomic registry statements with prefixed tables for each supported database', function (string $driver): void {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.upload-grammar', [
        'driver' => $driver, 'database' => ':memory:', 'prefix' => 'capell_test_',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
        'version' => '10.11.0',
    ]);
    DB::setDefaultConnection('upload-grammar');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_24_000001_create_admin_upload_grants_table.php';
    try {
        $registry = resolve(AdminUploadGrantRepository::class);
        $connection = DB::connection();
        // Compilation only: never open a connection to a database server.
        $pdo = new PDO('sqlite::memory:');
        $connection->setPdo($pdo)->setReadPdo($pdo);
        $schema = $connection->pretend(fn () => $migration->up());
        $grant = $connection->pretend(fn () => $registry->grant(hash('sha256', 'scope'), 'file.txt'));
        $lookup = $connection->pretend(fn () => $registry->paths(hash('sha256', 'scope'), ['file.txt']));
        $forget = $connection->pretend(fn () => $registry->forget(hash('sha256', 'scope'), 'file.txt'));
        $prune = $connection->pretend(fn () => $registry->prune(100));
        expect($schema)->not->toBeEmpty();
        foreach ([$grant, $lookup, $forget, $prune] as $statements) {
            expect($statements)->toHaveCount(1)
                ->and($statements[0]['query'])->toContain('capell_test_admin_upload_grants');
        }

        expect(strtolower($prune[0]['query']))->toStartWith('delete ')
            ->toContain('limit 100');
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('upload-grammar');
    }
})->with(['mysql', 'sqlite', 'pgsql', 'mariadb']);

it('reads ownership from the write database so replica lag cannot lose a retry grant', function (): void {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.upload-replica', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    DB::setDefaultConnection('upload-replica');
    try {
        $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_24_000001_create_admin_upload_grants_table.php';
        $migration->up();
        $registry = resolve(AdminUploadGrantRepository::class);
        $scope = hash('sha256', 'retry-session');
        $registry->grant($scope, 'owned.txt');
        DB::connection()->setReadPdo(static fn (): never => throw new LogicException('Ownership reached a potentially stale read replica.'));
        expect($registry->paths($scope, ['owned.txt']))->toBe(['owned.txt']);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('upload-replica');
    }
});

it('reasserts grants with their original expiry after nested and outer rollback on an in-memory connection', function (bool $commitInner): void {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.upload-rollback', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'retry_']);
    DB::setDefaultConnection('upload-rollback');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_24_000001_create_admin_upload_grants_table.php';
    try {
        $migration->up();
        $registry = resolve(AdminUploadGrantRepository::class);
        $scope = hash('sha256', 'rolled-back');
        DB::beginTransaction();
        DB::beginTransaction();
        $registry->grant($scope, 'owned.txt');
        $expiry = DB::table('admin_upload_grants')->value('expires_at');
        $commitInner ? DB::commit() : DB::rollBack();
        expect($registry->paths($scope, ['owned.txt']))->toBe(['owned.txt']);
        $this->travel(1)->minutes();
        DB::rollBack();
        expect(DB::transactionLevel())->toBe(0)
            ->and($registry->paths($scope, ['owned.txt']))->toBe(['owned.txt'])
            ->and(DB::table('admin_upload_grants')->value('expires_at'))->toBe($expiry);

        DB::beginTransaction();
        $registry->forget($scope, 'owned.txt');
        DB::rollBack();
        expect($registry->paths($scope, ['owned.txt']))->toBe([]);

        // A completed journal must not revive a later revocation.
        DB::beginTransaction();
        $registry->grant($scope, 'committed.txt');
        DB::commit();
        $registry->forget($scope, 'committed.txt');
        DB::beginTransaction();
        DB::rollBack();
        expect($registry->paths($scope, ['committed.txt']))->toBe([]);
    } finally {
        DB::disconnect('upload-rollback');
        DB::setDefaultConnection($originalConnection);
    }
})->with([false, true]);

function withUploadRollbackConnection(Closure $callback): void
{
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.upload-rollback', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'retry_']);
    DB::setDefaultConnection('upload-rollback');
    try {
        (require dirname(__DIR__, 4) . '/database/migrations/2026_10_24_000001_create_admin_upload_grants_table.php')->up();
        $callback();
    } finally {
        DB::disconnect('upload-rollback');
        DB::setDefaultConnection($originalConnection);
    }
}

it('registers its transaction listeners once and lets discarded scoped repositories be collected', function (): void {
    $events = resolve(Dispatcher::class);
    $counts = static fn (): array => array_map(
        static fn (string $event): int => count($events->getListeners($event)),
        [TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class],
    );
    $before = $counts();

    withUploadRollbackConnection(function () use ($counts, $before): void {
        $first = WeakReference::create(resolve(AdminUploadGrantRepository::class));
        foreach (range(1, 10) as $index) {
            DB::beginTransaction();
            resolve(AdminUploadGrantRepository::class)->grant(hash('sha256', 'scope-' . $index), 'owned.txt');
            DB::commit();
            app()->forgetScopedInstances();
        }

        gc_collect_cycles();

        expect($counts())->toBe($before)
            ->and($first->get())->toBeNull();
    });
});

it('never replays a journal left by a terminally failed commit into a later transaction or scope', function (bool $newScope): void {
    withUploadRollbackConnection(function () use ($newScope): void {
        $scope = hash('sha256', 'failed-commit');
        $registry = resolve(AdminUploadGrantRepository::class);
        DB::beginTransaction();
        $registry->grant($scope, 'deleted.txt');

        // A lost connection during COMMIT rolls back and resets the transaction level
        // without dispatching either terminal transaction event.
        DB::connection()->getPdo()->rollBack();
        $property = new ReflectionProperty(DB::connection(), 'transactions');
        $property->setValue(DB::connection(), 0);

        if ($newScope) {
            app()->forgetScopedInstances();
            $registry = resolve(AdminUploadGrantRepository::class);
            $registry->grant($scope, 'deleted.txt');
        }

        $registry->forget($scope, 'deleted.txt');
        DB::beginTransaction();
        DB::rollBack();

        expect($registry->paths($scope, ['deleted.txt']))->toBe([]);
    });
})->with([false, true]);

it('settles a failed direct commit at level one without replaying stale grants or revocations', function (bool $commit): void {
    withUploadRollbackConnection(function () use ($commit): void {
        $connection = DB::connection();
        $connection->statement('PRAGMA foreign_keys = ON');
        $connection->statement('CREATE TABLE retry_upload_parents (id INTEGER PRIMARY KEY)');
        $connection->statement('CREATE TABLE retry_upload_children (parent_id INTEGER REFERENCES retry_upload_parents(id) DEFERRABLE INITIALLY DEFERRED)');

        $registry = resolve(AdminUploadGrantRepository::class);
        $scope = hash('sha256', 'direct-commit');
        $registry->grant($scope, 'revoked.txt');

        $connection->beginTransaction();
        $registry->grant($scope, 'retry.txt');
        $expiry = $connection->table('admin_upload_grants')->where('path', 'retry.txt')->value('expires_at');
        $registry->forget($scope, 'revoked.txt');
        $connection->table('upload_children')->insert(['parent_id' => 1]);

        // A real deferred constraint fails at COMMIT, leaving Laravel and PDO
        // inside the same outer transaction, with no terminal event dispatched.
        expect(fn () => $connection->commit())->toThrow(PDOException::class)
            ->and($connection->transactionLevel())->toBe(1)
            ->and($connection->getPdo()->inTransaction())->toBeTrue();
        $this->travel(1)->minutes();
        if ($commit) {
            $connection->table('upload_parents')->insert(['id' => 1]);
            $connection->commit();
        } else {
            $connection->rollBack();
        }

        expect($connection->transactionLevel())->toBe(0)
            ->and($registry->paths($scope, ['retry.txt', 'revoked.txt']))->toBe(['retry.txt'])
            ->and($connection->table('admin_upload_grants')->value('expires_at'))->toBe($expiry);

        // Both journal directions must have ended: a later unrelated rollback
        // must neither revive a deleted grant nor delete a newly issued one.
        $registry->forget($scope, 'retry.txt');
        $registry->grant($scope, 'revoked.txt');

        $connection->beginTransaction();
        $connection->rollBack();

        expect($registry->paths($scope, ['retry.txt', 'revoked.txt']))->toBe(['revoked.txt']);
    });
})->with(['later commit' => true, 'later rollback' => false]);

it('keeps grant and revocation journals on their issuing connection when the default changes', function (): void {
    withUploadRollbackConnection(function (): void {
        $first = DB::connection();
        config()->set('database.connections.upload-second', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'second_']);
        DB::setDefaultConnection('upload-second');
        try {
            (require dirname(__DIR__, 4) . '/database/migrations/2026_10_24_000001_create_admin_upload_grants_table.php')->up();
            $second = DB::connection();
            $registry = resolve(AdminUploadGrantRepository::class);
            $scope = hash('sha256', 'shared-scope');
            $registry->grant($scope, 'revoked.txt');
            $second->beginTransaction();
            $registry->grant($scope, 'second.txt');
            $registry->forget($scope, 'revoked.txt');

            DB::setDefaultConnection('upload-rollback');
            $registry->grant($scope, 'revoked.txt');
            $first->beginTransaction();
            $registry->grant($scope, 'first.txt');

            // Dispatch the second connection's rollback while the first is the
            // default: replay must use the connection captured by each write.
            $second->rollBack();
            expect($registry->paths($scope, ['first.txt', 'second.txt', 'revoked.txt']))->toBe(['first.txt', 'revoked.txt']);

            DB::setDefaultConnection('upload-second');
            expect($registry->paths($scope, ['first.txt', 'second.txt', 'revoked.txt']))->toBe(['second.txt']);
            $first->rollBack();
            expect($registry->paths($scope, ['first.txt', 'second.txt', 'revoked.txt']))->toBe(['second.txt']);

            // A completed second journal cannot revive its grant or replay its
            // revocation during another rollback on either connection.
            $registry->forget($scope, 'second.txt');
            $registry->grant($scope, 'revoked.txt');
            $second->beginTransaction();
            $second->rollBack();
            expect($registry->paths($scope, ['second.txt', 'revoked.txt']))->toBe(['revoked.txt']);
            DB::setDefaultConnection('upload-rollback');
            expect($registry->paths($scope, ['first.txt', 'second.txt', 'revoked.txt']))->toBe(['first.txt', 'revoked.txt']);
        } finally {
            DB::setDefaultConnection('upload-rollback');
            DB::purge('upload-second');
        }
    });
});

it('keeps a grant revoked through savepoint rollback and outer rollback', function (bool $commitInner): void {
    withUploadRollbackConnection(function () use ($commitInner): void {
        $registry = resolve(AdminUploadGrantRepository::class);
        $scope = hash('sha256', 'nested-revocation');
        DB::beginTransaction();
        $registry->grant($scope, 'deleted.txt');
        DB::beginTransaction();
        $registry->forget($scope, 'deleted.txt');
        $commitInner ? DB::commit() : DB::rollBack();
        expect(DB::transactionLevel())->toBe(1)
            ->and($registry->paths($scope, ['deleted.txt']))->toBe([]);

        DB::rollBack();
        expect(DB::transactionLevel())->toBe(0)
            ->and($registry->paths($scope, ['deleted.txt']))->toBe([]);
    });
})->with(['savepoint rollback' => false, 'nested commit then outer rollback' => true]);
