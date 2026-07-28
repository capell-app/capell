<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\EnsureDatabaseExistsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$originalDatabaseDefault = null;
$originalSqliteDatabase = null;

beforeEach(function () use (&$originalDatabaseDefault, &$originalSqliteDatabase): void {
    $originalDatabaseDefault = config('database.default');
    $originalSqliteDatabase = config('database.connections.sqlite.database');
});

afterEach(function () use (&$originalDatabaseDefault, &$originalSqliteDatabase): void {
    DB::purge('sqlite');

    config([
        'database.default' => $originalDatabaseDefault,
        'database.connections.sqlite.database' => $originalSqliteDatabase,
    ]);
});

it('creates a missing sqlite database file', function (): void {
    $databasePath = storage_path('framework/testing/capell-install-test.sqlite');
    File::delete($databasePath);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);

    EnsureDatabaseExistsAction::run(new NullProgressReporter);

    expect(File::exists($databasePath))->toBeTrue();
});

it('keeps an existing database connection and its active transaction intact', function (): void {
    $user = User::factory()->createOne();

    EnsureDatabaseExistsAction::run(new NullProgressReporter);

    expect(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
});

it('skips in-memory sqlite databases', function (): void {
    File::delete(storage_path('framework/testing/capell-install-test.sqlite'));

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
    ]);

    EnsureDatabaseExistsAction::run(new NullProgressReporter);

    expect(File::exists(storage_path('framework/testing/capell-install-test.sqlite')))->toBeFalse();
});

it('creates relative sqlite database paths and reports install progress', function (): void {
    $databasePath = database_path('capell-relative-install-test.sqlite');
    File::delete($databasePath);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => 'capell-relative-install-test.sqlite',
    ]);

    $reporter = new class implements ProgressReporter
    {
        /** @var list<string> */
        public array $reports = [];

        /** @var list<string> */
        public array $steps = [];

        /** @var list<string> */
        public array $errors = [];

        public function step(string $label): void
        {
            $this->steps[] = $label;
        }

        public function report(string $line): void
        {
            $this->reports[] = $line;
        }

        public function error(string $line): void
        {
            $this->errors[] = $line;
        }
    };

    try {
        EnsureDatabaseExistsAction::run($reporter);

        expect(File::exists($databasePath))->toBeTrue()
            ->and($reporter->reports)->toBe(["\u{2713} SQLite database created"])
            ->and($reporter->steps)->toBeEmpty()
            ->and($reporter->errors)->toBeEmpty();
    } finally {
        File::delete($databasePath);
    }
});

it('leaves existing sqlite databases and unsupported connection config untouched', function (): void {
    $databasePath = storage_path('framework/testing/capell-existing-install-test.sqlite');
    File::ensureDirectoryExists(dirname($databasePath));
    File::put($databasePath, 'existing');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
    ]);

    EnsureDatabaseExistsAction::run(new NullProgressReporter);

    config([
        'database.default' => 'missing_connection',
        'database.connections.missing_connection' => null,
    ]);

    EnsureDatabaseExistsAction::run(new NullProgressReporter);

    config([
        'database.default' => 'unsupported_connection',
        'database.connections.unsupported_connection' => [
            'driver' => 'sqlsrv',
            'database' => 'ignored',
        ],
    ]);

    EnsureDatabaseExistsAction::run(new NullProgressReporter);

    expect((string) File::get($databasePath))->toBe('existing');

    File::delete($databasePath);
});

it('skips server database creation when named connections do not provide a database name', function (string $driver): void {
    $reports = [];
    $reporter = new class($reports) implements ProgressReporter
    {
        /** @var list<string> */
        private array $reports;

        /** @param list<string> $reports */
        public function __construct(array &$reports)
        {
            $this->reports = &$reports;
        }

        public function step(string $label): void {}

        public function report(string $line): void
        {
            $this->reports[] = $line;
        }

        public function error(string $line): void {}
    };

    config([
        'database.default' => 'capell_named_install_connection',
        'database.connections.capell_named_install_connection' => [
            'driver' => $driver,
            'database' => ' ',
            'host' => 'invalid.local',
        ],
    ]);

    EnsureDatabaseExistsAction::run($reporter);

    expect($reports)->toBe([]);
})->with(['mysql', 'pgsql']);

it('attempts server level database preparation for configured mysql and postgres installs', function (array $connection): void {
    config([
        'database.default' => 'capell_server_install_connection',
        'database.connections.capell_server_install_connection' => $connection,
    ]);

    expect(fn (): null => EnsureDatabaseExistsAction::run(new NullProgressReporter))
        ->toThrow(PDOException::class);
})->with([
    'mysql' => [[
        'driver' => 'mysql',
        'database' => 'capell_install_test',
        'host' => ['invalid.local'],
        'port' => 3306,
        'username' => 'capell',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => 'ignored',
    ]],
    'postgres' => [[
        'driver' => 'pgsql',
        'database' => 'capell_install_test',
        'maintenance_database' => 'postgres',
        'host' => ['invalid.local'],
        'port' => 5432,
        'username' => 'capell',
        'password' => 'secret',
    ]],
]);
