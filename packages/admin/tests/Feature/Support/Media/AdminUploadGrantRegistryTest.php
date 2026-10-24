<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\PruneAdminUploadGrantsAction;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Media\AdminUploadGrantRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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
