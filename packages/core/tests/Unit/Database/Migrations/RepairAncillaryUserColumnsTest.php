<?php

declare(strict_types=1);

use Capell\Core\Support\Database\SchemaDialects\AncillaryUserColumnRepair;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('repairs missing user columns without modifying custom tables or existing data', function (bool $hasBio): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.user_columns_repair_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('user_columns_repair_test');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        Schema::create('custom_users', fn (Blueprint $table) => $table->id());
        $migration->up();
        expect(Schema::hasTable('users'))->toBeFalse()
            ->and(Schema::hasColumn('custom_users', 'bio'))->toBeFalse()
            ->and(Schema::hasColumn('custom_users', 'dismissed_hints'))->toBeFalse()
            ->and(Schema::hasColumn('custom_users', 'preferred_admin_language_id'))->toBeFalse();

        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table) use ($hasBio): void {
            $table->id();
            $table->string('name');
            if ($hasBio) {
                $table->text('bio')->nullable();
            }
        });
        DB::table('users')->insert($hasBio ? ['name' => 'Editor', 'bio' => 'Existing biography'] : ['name' => 'Editor']);
        $migration->up();
        expect(Schema::hasColumn('users', 'bio'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'dismissed_hints'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'preferred_admin_language_id'))->toBeTrue()
            ->and(DB::table('users')->value('bio'))->toBe($hasBio ? 'Existing biography' : null);
        DB::table('users')->update(['bio' => 'Existing biography']);

        $language = DB::table('languages')->insertGetId([]);
        DB::table('users')->update(['dismissed_hints' => '["welcome"]', 'preferred_admin_language_id' => $language]);
        $migration->up();
        $migration->down();
        $user = DB::table('users')->first();
        assert($user !== null);
        expect($user->bio)->toBe('Existing biography')
            ->and($user->dismissed_hints)->toBe('["welcome"]')
            ->and($user->preferred_admin_language_id)->toBe($language);
        DB::table('languages')->where('id', $language)->delete();
        expect(DB::table('users')->value('preferred_admin_language_id'))->toBeNull();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('user_columns_repair_test');
    }
})->with(['all missing' => false, 'partially present' => true]);

it('retries a partially applied language constraint without losing data', function (): void {
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    $config['prefix'] = 'ancillary_' . strtolower(bin2hex(random_bytes(5))) . '_';
    config()->set('database.connections.ancillary_retry', $config);
    DB::setDefaultConnection('ancillary_retry');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preferred_admin_language_id')->nullable();
        });
        $language = DB::table('languages')->insertGetId([]);
        DB::table('users')->insert(['preferred_admin_language_id' => $language]);
        $migration->up();
        $migration->up();
        expect(DB::table('users')->value('preferred_admin_language_id'))->toBe($language);
        DB::table('languages')->where('id', $language)->delete();
        expect(DB::table('users')->value('preferred_admin_language_id'))->toBeNull();
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('languages');
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_retry');
    }
});

it('rejects an incompatible existing language constraint without replacing it', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_conflict', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_conflict');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preferred_admin_language_id')->nullable()->constrained('languages')->restrictOnDelete();
        });
        $language = DB::table('languages')->insertGetId([]);
        DB::table('users')->insert(['preferred_admin_language_id' => $language]);
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'foreign key is incompatible');
        expect(DB::table('users')->value('preferred_admin_language_id'))->toBe($language)
            ->and(Schema::getForeignKeys('users')[0]['on_delete'])->toBe('restrict');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_conflict');
    }
});

it('records a no-op repair when the users table is created after the migration', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_migrator', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $migrator = resolve('migrator');
    $migrator->setConnection('ancillary_migrator');

    $path = dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        $migrator->getRepository()->createRepository();
        $migrator->run([$path]);
        Schema::create('users', fn (Blueprint $table) => $table->id());
        $migrator->run([$path]);
        expect($migrator->getRepository()->getRan())->toContain('2026_10_29_000002_repair_ancillary_user_columns')
            ->and(Schema::hasColumn('users', 'bio'))->toBeFalse();
    } finally {
        $migrator->setConnection($previous);
        DB::purge('ancillary_migrator');
    }
});

it('accepts only the language table resolved in the current database', function (): void {
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    $suffix = strtolower(bin2hex(random_bytes(5)));
    $config['prefix'] = 'ancillary_' . $suffix . '_';
    $otherDatabase = 'ancillary_foreign_test_' . $suffix;
    config()->set('database.connections.ancillary_schema', $config);
    DB::setDefaultConnection('ancillary_schema');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    $driver = DB::connection()->getDriverName();
    $crossSchema = in_array($driver, ['mysql', 'pgsql'], true);
    $createdDatabase = false;

    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        if ($driver === 'mysql') {
            DB::statement('CREATE DATABASE `' . $otherDatabase . '`');
            $createdDatabase = true;
            DB::statement('CREATE TABLE `' . $otherDatabase . '`.`' . $config['prefix'] . 'languages` (id BIGINT UNSIGNED PRIMARY KEY)');
        }

        if ($driver === 'pgsql') {
            DB::statement('CREATE SCHEMA "' . $otherDatabase . '"');
            $createdDatabase = true;
            DB::statement('CREATE TABLE "' . $otherDatabase . '"."' . $config['prefix'] . 'languages" (id BIGINT PRIMARY KEY)');
        }

        Schema::create('users', function (Blueprint $table) use ($crossSchema): void {
            $table->id();
            if ($crossSchema) {
                $table->foreignId('preferred_admin_language_id')->nullable();
            } else {
                $table->foreignId('preferred_admin_language_id')->nullable()->constrained('languages')->nullOnDelete();
            }
        });
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "' . $config['prefix'] . 'users" ADD CONSTRAINT "fk_cross_schema" FOREIGN KEY ("preferred_admin_language_id") REFERENCES "' . $otherDatabase . '"."' . $config['prefix'] . 'languages" ("id") ON DELETE SET NULL');
        }

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `' . $config['prefix'] . 'users` ADD CONSTRAINT `fk_cross_schema` FOREIGN KEY (`preferred_admin_language_id`) REFERENCES `' . $otherDatabase . '`.`' . $config['prefix'] . 'languages` (`id`) ON DELETE SET NULL');
        }

        if ($crossSchema) {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'foreign key is incompatible');
            expect(Schema::getForeignKeys('users')[0]['foreign_schema'])->toBe($otherDatabase);
        } else {
            $migration->up();
            expect(Schema::getForeignKeys('users')[0]['foreign_schema'])->toBe('main');
        }
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('languages');
        if ($createdDatabase) {
            DB::statement($driver === 'pgsql' ? 'DROP SCHEMA "' . $otherDatabase . '" CASCADE' : 'DROP DATABASE `' . $otherDatabase . '`');
        }

        DB::setDefaultConnection($previous);
        DB::purge('ancillary_schema');
    }
});

it('refuses an unsupported driver before modifying an existing users table', function (): void {
    $previous = DB::getDefaultConnection();
    DB::extend('ancillary_unsupported_driver', fn (): SQLiteConnection => new class(new PDO('sqlite::memory:')) extends SQLiteConnection
    {
        #[Override]
        public function getDriverName(): string
        {
            return 'unsupported';
        }
    });
    config()->set('database.connections.ancillary_unsupported', ['driver' => 'ancillary_unsupported_driver']);
    DB::setDefaultConnection('ancillary_unsupported');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Cannot verify the language foreign key schema');
        expect(Schema::hasColumn('users', 'bio'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'dismissed_hints'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'preferred_admin_language_id'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_unsupported');
        DB::forgetExtension('ancillary_unsupported_driver');
    }
});

it('preserves SQLite user triggers, partial indexes and inbound references during repair', function (bool $insideTransaction): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_preservation', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_preservation');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('children', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        });
        Schema::create('audit', fn (Blueprint $table) => $table->string('message'));
        DB::statement('CREATE TRIGGER user_rename AFTER UPDATE OF name ON users BEGIN INSERT INTO audit (message) VALUES (NEW.name); END');
        DB::statement("CREATE UNIQUE INDEX user_name_partial ON users(name) WHERE name <> ''");
        DB::table('users')->insert(['id' => 1, 'name' => 'Before']);
        DB::table('children')->insert(['user_id' => 1]);
        if ($insideTransaction) {
            DB::beginTransaction();
        }

        $migration->up();
        if ($insideTransaction) {
            DB::commit();
        }

        DB::table('users')->where('id', 1)->update(['name' => 'After']);
        expect(DB::table('audit')->value('message'))->toBe('After')
            ->and(DB::table('children')->where('user_id', 1)->exists())->toBeTrue()
            ->and(DB::selectOne("SELECT sql FROM sqlite_master WHERE name = 'user_name_partial'")->sql)->toContain("WHERE name <> ''")
            ->and(Schema::getForeignKeys('users')[0]['on_delete'])->toBe('set null');
        DB::table('users')->insert(['name' => '']);
        DB::table('users')->insert(['name' => '']);
        expect(DB::table('users')->where('name', '')->count())->toBe(2);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_preservation');
    }
})->with([false, true]);

function ancillarySqliteQuery(PDO $pdo, string $sql): PDOStatement
{
    $statement = $pdo->query($sql);
    throw_unless($statement instanceof PDOStatement, RuntimeException::class, 'SQLite test query failed.');

    return $statement;
}

function ancillarySqliteRebuildFixture(): PDO
{
    $pdo = DB::connection()->getPdo();
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE languages (id INTEGER PRIMARY KEY)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, preferred_admin_language_id INTEGER, name TEXT NOT NULL, lower_name TEXT GENERATED ALWAYS AS (lower(name)) STORED, CHECK (length(name) > 0))');
    $pdo->exec("CREATE UNIQUE INDEX users_nonblank ON users(name) WHERE name <> 'reserved'");
    $pdo->exec('CREATE TABLE audit (name TEXT)');
    $pdo->exec('CREATE TRIGGER users_changed AFTER UPDATE OF name ON users BEGIN INSERT INTO audit VALUES (NEW.name); END');
    $pdo->exec('CREATE TABLE child (id INTEGER PRIMARY KEY, user_id INTEGER REFERENCES users(id) ON DELETE CASCADE)');
    $pdo->exec("INSERT INTO languages VALUES (1); INSERT INTO users(id, preferred_admin_language_id, name) VALUES (5, 1, 'Alice'), (100, NULL, 'Deleted'); DELETE FROM users WHERE id = 100; INSERT INTO child VALUES (1, 5)");

    return $pdo;
}

/** @return array<int, mixed> */
function ancillarySqliteSnapshot(PDO $pdo): array
{
    return [
        ancillarySqliteQuery($pdo, 'SELECT type, name, tbl_name, sql FROM sqlite_schema ORDER BY type, name')->fetchAll(PDO::FETCH_ASSOC),
        ancillarySqliteQuery($pdo, 'SELECT rowid, * FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ancillarySqliteQuery($pdo, 'SELECT * FROM child')->fetchAll(PDO::FETCH_ASSOC),
        ancillarySqliteQuery($pdo, 'SELECT * FROM sqlite_sequence')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

it('repairs a partial SQLite foreign key while preserving host schema and relationships', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_rebuild', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_rebuild');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        $pdo = ancillarySqliteRebuildFixture();
        $migration->up();
        $migration->up();
        $pdo->exec("UPDATE users SET name = 'Bob' WHERE id = 5");
        expect(ancillarySqliteQuery($pdo, 'SELECT name FROM audit')->fetchColumn())->toBe('Bob')
            ->and(ancillarySqliteQuery($pdo, 'SELECT lower_name FROM users WHERE id = 5')->fetchColumn())->toBe('bob')
            ->and((int) ancillarySqliteQuery($pdo, 'SELECT COUNT(*) FROM child')->fetchColumn())->toBe(1)
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name = 'users_nonblank'")->fetchColumn())->toContain("WHERE name <> 'reserved'")
            ->and(Schema::hasColumn('users', 'bio'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'dismissed_hints'))->toBeTrue();
        $pdo->exec('DELETE FROM languages WHERE id = 1');
        expect(ancillarySqliteQuery($pdo, 'SELECT preferred_admin_language_id FROM users WHERE id = 5')->fetchColumn())->toBeNull();
        $pdo->exec("INSERT INTO users(name) VALUES ('New')");
        expect((int) $pdo->lastInsertId())->toBe(101);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_rebuild');
    }
});

it('rolls back SQLite user repair and restores foreign-key mode after each failure', function (string $phase, bool $foreignKeysEnabled): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_fault', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_fault');
    $repairer = new AncillaryUserColumnRepair;
    try {
        $pdo = ancillarySqliteRebuildFixture();
        if (! $foreignKeysEnabled) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }

        $before = ancillarySqliteSnapshot($pdo);
        $rebuild = new ReflectionMethod($repairer, 'sqliteRebuildUsers');
        expect(fn (): mixed => $rebuild->invoke($repairer, $pdo, 'users', 'languages', static function (string $current) use ($phase): void {
            throw_if($current === $phase, RuntimeException::class, 'Injected failure');
        }))->toThrow(RuntimeException::class, 'Injected failure');
        expect(ancillarySqliteSnapshot($pdo))->toBe($before)
            ->and((int) ancillarySqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn())->toBe((int) $foreignKeysEnabled);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_fault');
    }
})->with(['prepared', 'created', 'copied', 'dropped', 'renamed', 'columns', 'replayed', 'verified'])->with([false, true]);

it('refuses unsupported SQLite repair contexts without changing schema or data', function (string $scenario): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_refusal', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_refusal');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = ancillarySqliteRebuildFixture();
        if ($scenario === 'view') {
            $pdo->exec('CREATE VIEW people AS SELECT * FROM users');
        } elseif ($scenario === 'external trigger') {
            $pdo->exec("CREATE TRIGGER audit_users AFTER INSERT ON audit BEGIN UPDATE users SET name = 'Changed' WHERE id = 5; END");
        } elseif ($scenario === 'orphan') {
            $pdo->exec('PRAGMA foreign_keys = OFF; UPDATE users SET preferred_admin_language_id = 999; PRAGMA foreign_keys = ON');
        }

        $before = ancillarySqliteSnapshot($pdo);
        if ($scenario === 'temp shadow') {
            $pdo->exec('CREATE TEMP TABLE users (id INTEGER)');
        } elseif ($scenario === 'pdo silent') {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        } elseif ($scenario === 'deferred mode') {
            $pdo->exec('PRAGMA defer_foreign_keys=ON');
        }

        if ($scenario === 'transaction') {
            DB::beginTransaction();
        }

        expect(fn () => $migration->up())->toThrow(RuntimeException::class);
        if ($scenario === 'transaction') {
            DB::rollBack();
        } elseif ($scenario === 'temp shadow') {
            $pdo->exec('DROP TABLE temp.users');
        } elseif ($scenario === 'pdo silent') {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } elseif ($scenario === 'deferred mode') {
            expect((int) ancillarySqliteQuery($pdo, 'PRAGMA defer_foreign_keys')->fetchColumn())->toBe(1);
        }

        expect(ancillarySqliteSnapshot($pdo))->toBe($before)
            ->and((int) ancillarySqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn())->toBe(1);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_refusal');
    }
})->with(['view', 'external trigger', 'orphan', 'transaction', 'temp shadow', 'pdo silent', 'deferred mode']);

it('retains SQLite row identity and without-rowid table layout during partial repair', function (bool $withoutRowid): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_rowid', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_rowid');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE languages(id INTEGER PRIMARY KEY)');
        if ($withoutRowid) {
            $pdo->exec("CREATE TABLE users (key TEXT PRIMARY KEY, preferred_admin_language_id INTEGER, name TEXT) WITHOUT ROWID; INSERT INTO users VALUES ('abc', NULL, 'Value')");
        } else {
            $pdo->exec("CREATE TABLE users (preferred_admin_language_id INTEGER, name TEXT); INSERT INTO users(rowid, name) VALUES (42, 'Value')");
        }

        $migration->up();
        expect(ancillarySqliteQuery($pdo, 'SELECT name FROM users')->fetchColumn())->toBe('Value');
        if ($withoutRowid) {
            expect(ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users'")->fetchColumn())->toContain('WITHOUT ROWID');
        } else {
            expect((int) ancillarySqliteQuery($pdo, 'SELECT rowid FROM users')->fetchColumn())->toBe(42);
        }
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_rowid');
    }
})->with([false, true]);

it('preserves strict SQLite definitions, quoted identifiers and expression indexes', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_strict', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_strict');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateCollation('CAPELL_TEST', static fn (string $left, string $right): int => strcasecmp($left, $right));
        $pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE "users" (id INTEGER PRIMARY KEY, preferred_admin_language_id INTEGER, "quoted""name" TEXT COLLATE CAPELL_TEST NOT NULL DEFAULT \'a)b\') STRICT; CREATE INDEX users_expr ON users(lower("quoted""name")) WHERE id > 0; INSERT INTO users(id) VALUES (1)');
        $migration->up();
        expect(ancillarySqliteQuery($pdo, 'SELECT "quoted""name" FROM users')->fetchColumn())->toBe('a)b')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users_expr'")->fetchColumn())->toContain('lower(', 'WHERE id > 0')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users'")->fetchColumn())->toContain('STRICT', 'COLLATE CAPELL_TEST');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_strict');
    }
});

it('refuses a SQLite table whose implicit rowid is inaccessible', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_shadow', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_shadow');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users (ROWID INTEGER, _RowID_ INTEGER, OiD INTEGER, preferred_admin_language_id INTEGER); INSERT INTO users VALUES (1, 2, 3, NULL)');
        $before = ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users'")->fetchColumn();
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'rowid is shadowed')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users'")->fetchColumn())->toBe($before)
            ->and((int) ancillarySqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn())->toBe(1);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_shadow');
    }
});

it('rejects an unsupported SQLite table definition before changing it', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_sqlite_unknown_sql', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_sqlite_unknown_sql');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('PRAGMA foreign_keys=ON; CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users /* host-defined table comment */ (id INTEGER PRIMARY KEY, preferred_admin_language_id INTEGER); INSERT INTO users VALUES (7, NULL)');
        $before = ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users'")->fetchColumn();
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Unsupported SQLite CREATE TABLE prefix')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM sqlite_schema WHERE name='users'")->fetchColumn())->toBe($before)
            ->and((int) ancillarySqliteQuery($pdo, 'SELECT id FROM users')->fetchColumn())->toBe(7)
            ->and((int) ancillarySqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn())->toBe(1);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_sqlite_unknown_sql');
    }
});

it('rejects a non-nullable language column before modifying the user table', function (): void {
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    $config['prefix'] = 'ancillary_' . strtolower(bin2hex(random_bytes(5))) . '_';
    config()->set('database.connections.ancillary_not_nullable', $config);
    DB::setDefaultConnection('ancillary_not_nullable');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preferred_admin_language_id');
        });
        $language = DB::table('languages')->insertGetId([]);
        DB::table('users')->insert(['preferred_admin_language_id' => $language]);
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'must be nullable')
            ->and(DB::table('users')->value('preferred_admin_language_id'))->toBe($language)
            ->and(Schema::hasColumn('users', 'bio'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'dismissed_hints'))->toBeFalse();
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('languages');
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_not_nullable');
    }
});

it('recognises mixed-case existing language foreign keys without replacing them', function (bool $compatible): void {
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    $config['prefix'] = 'ancillary_' . strtolower(bin2hex(random_bytes(5))) . '_';
    config()->set('database.connections.ancillary_mixed_case', $config);
    DB::setDefaultConnection('ancillary_mixed_case');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table) use ($compatible): void {
            $table->id();
            $foreign = $table->foreignId('Preferred_Admin_Language_Id')->nullable()->constrained('languages');
            $compatible ? $foreign->nullOnDelete() : $foreign->restrictOnDelete();
        });
        $language = DB::table('languages')->insertGetId([]);
        DB::table('users')->insert(['Preferred_Admin_Language_Id' => $language]);
        $before = Schema::getForeignKeys('users');
        if (DB::connection()->getDriverName() === 'pgsql') {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'exact lowercase column name')
                ->and(Schema::getForeignKeys('users'))->toBe($before)
                ->and(DB::table('users')->value('Preferred_Admin_Language_Id'))->toBe($language)
                ->and(Schema::hasColumn('users', 'bio'))->toBeFalse();
            expect(fn () => DB::table('users')->value('preferred_admin_language_id'))->toThrow(QueryException::class);
        } elseif ($compatible) {
            $migration->up();
            expect(Schema::getForeignKeys('users'))->toBe($before);
            DB::table('languages')->where('id', $language)->delete();
            expect(DB::table('users')->value('Preferred_Admin_Language_Id'))->toBeNull();
        } else {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'foreign key is incompatible')
                ->and(Schema::getForeignKeys('users'))->toBe($before)
                ->and(DB::table('users')->value('Preferred_Admin_Language_Id'))->toBe($language)
                ->and(Schema::hasColumn('users', 'bio'))->toBeFalse();
        }
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('languages');
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_mixed_case');
    }
})->with([true, false]);

it('rejects a SQLite language primary key before modifying existing data', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_language_pk', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_language_pk');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users(preferred_admin_language_id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7, 'Keep')");
        $before = ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn();
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'primary key')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn())->toBe($before)
            ->and(ancillarySqliteQuery($pdo, 'SELECT name FROM main.users')->fetchColumn())->toBe('Keep');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_language_pk');
    }
});

it('does not record a SQLite migration when its target or main-table access is unsafe', function (string $scenario): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_real_migrator', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    $migrator = resolve('migrator');
    $migrator->setConnection('ancillary_real_migrator');

    $path = dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $migrator->getRepository()->createRepository();
        $pdo = DB::connection()->getPdo();
        if ($scenario !== 'missing table') {
            $pdo->exec($scenario === 'missing key' ? 'CREATE TABLE languages(name TEXT)' : 'CREATE TABLE languages(id INTEGER PRIMARY KEY)');
        }

        $pdo->exec($scenario === 'temp with foreign key'
            ? "CREATE TABLE main.users(id INTEGER PRIMARY KEY, name TEXT, preferred_admin_language_id INTEGER REFERENCES languages(id) ON DELETE SET NULL); INSERT INTO main.users(id,name) VALUES(1,'Main')"
            : "CREATE TABLE main.users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO main.users(id,name) VALUES(1,'Main')");
        if (str_starts_with($scenario, 'temp')) {
            $pdo->exec("CREATE TEMP TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO temp.users VALUES(2,'Temp')");
        }

        $before = ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn();
        expect(fn () => $migrator->run([$path]))->toThrow(RuntimeException::class)
            ->and($migrator->getRepository()->getRan())->not->toContain('2026_10_29_000002_repair_ancillary_user_columns')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn())->toBe($before)
            ->and(ancillarySqliteQuery($pdo, 'SELECT name FROM main.users')->fetchColumn())->toBe('Main');
        if (str_starts_with($scenario, 'temp')) {
            expect(ancillarySqliteQuery($pdo, 'SELECT name FROM temp.users')->fetchColumn())->toBe('Temp');
        }
    } finally {
        $migrator->setConnection($previous);
        DB::purge('ancillary_real_migrator');
    }
})->with(['missing table', 'missing key', 'temp without foreign key', 'temp with foreign key']);

it('rejects a generated language column without changing schema or data', function (string $kind): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_generated', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_generated');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY, source INTEGER, preferred_admin_language_id INTEGER GENERATED ALWAYS AS (source) ' . $kind . '); INSERT INTO users(id, source) VALUES (7, 42)');
        $before = ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn();
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'cannot be generated')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn())->toBe($before)
            ->and((int) ancillarySqliteQuery($pdo, 'SELECT preferred_admin_language_id FROM users')->fetchColumn())->toBe(42)
            ->and(Schema::hasColumn('users', 'bio'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_generated');
    }
})->with(['VIRTUAL', 'STORED']);

it('rejects a generated language column on the configured driver', function (): void {
    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    $config['prefix'] = 'ancillary_' . strtolower(bin2hex(random_bytes(5))) . '_';
    config()->set('database.connections.ancillary_generated_driver', $config);
    DB::setDefaultConnection('ancillary_generated_driver');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->integer('source')->nullable();
            $table->integer('preferred_admin_language_id')->storedAs('source');
        });
        DB::table('users')->insert(['source' => 42]);
        $before = Schema::getColumns('users');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'cannot be generated')
            ->and(Schema::getColumns('users'))->toBe($before)
            ->and(DB::table('users')->value('preferred_admin_language_id'))->toBe(42)
            ->and(Schema::hasColumn('users', 'bio'))->toBeFalse();
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('languages');
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_generated_driver');
    }
});

it('rejects PostgreSQL quoted ancillary columns before changing schema or data', function (string $column): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL identifiers are case sensitive.');
    }

    $previous = DB::getDefaultConnection();
    $config = config('database.connections.' . $previous);
    $config['prefix'] = 'ancillary_' . strtolower(bin2hex(random_bytes(5))) . '_';
    config()->set('database.connections.ancillary_quoted', $config);
    DB::setDefaultConnection('ancillary_quoted');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        Schema::create('languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table) use ($column): void {
            $table->id();
            $table->text($column)->nullable();
        });
        DB::table('users')->insert([$column => 'kept']);
        $before = Schema::getColumns('users');
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'exact lowercase column name')
            ->and(Schema::getColumns('users'))->toBe($before)
            ->and(DB::table('users')->value($column))->toBe('kept')
            ->and(Schema::hasColumn('users', 'preferred_admin_language_id'))->toBeFalse();
        expect(fn () => DB::table('users')->value(strtolower($column)))->toThrow(QueryException::class);
    } finally {
        Schema::dropIfExists('users');
        Schema::dropIfExists('languages');
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_quoted');
    }
})->with(['Bio', 'Dismissed_Hints']);

it('rolls back SQLite repair if a stored generated value changes during copying', function (bool $withoutRowid): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_changed_function', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_changed_function');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('host_value', static fn (int $value): int => $value, 1, PDO::SQLITE_DETERMINISTIC);
        $suffix = $withoutRowid ? ' WITHOUT ROWID' : '';
        $pdo->exec('CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY, preferred_admin_language_id INTEGER, source INTEGER, derived INTEGER GENERATED ALWAYS AS (host_value(source)) STORED)' . $suffix . '; INSERT INTO users(id, source) VALUES (7, 42)');
        $before = ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn();
        $pdo->sqliteCreateFunction('host_value', static fn (int $value): int => $value + 1, 1, PDO::SQLITE_DETERMINISTIC);
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'stored generated values changed')
            ->and(ancillarySqliteQuery($pdo, "SELECT sql FROM main.sqlite_schema WHERE name='users'")->fetchColumn())->toBe($before)
            ->and((int) ancillarySqliteQuery($pdo, 'SELECT derived FROM users WHERE id=7')->fetchColumn())->toBe(42)
            ->and(Schema::hasColumn('users', 'bio'))->toBeFalse()
            ->and((int) ancillarySqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn())->toBe(1);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_changed_function');
    }
})->with([false, true]);

it('refuses SQLite repair when another connection changes the schema before the write lock', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-repair-');
    expect($path)->not->toBeFalse();
    try {
        $first = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $first->exec('PRAGMA foreign_keys=ON; CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY, preferred_admin_language_id INTEGER); INSERT INTO users VALUES (7, NULL)');
        $version = (int) ancillarySqliteQuery($first, 'PRAGMA main.schema_version')->fetchColumn();
        $second->exec('CREATE INDEX host_index ON users(id)');
        $repairer = new AncillaryUserColumnRepair;
        $rebuild = new ReflectionMethod($repairer, 'sqliteRebuildUsers');
        expect(fn (): mixed => $rebuild->invoke($repairer, $first, 'users', 'languages', null, $version))->toThrow(RuntimeException::class, 'schema changed')
            ->and((int) ancillarySqliteQuery($first, 'SELECT id FROM users')->fetchColumn())->toBe(7)
            ->and(ancillarySqliteQuery($first, "SELECT name FROM sqlite_schema WHERE name='host_index'")->fetchColumn())->toBe('host_index')
            ->and((int) ancillarySqliteQuery($first, 'PRAGMA foreign_keys')->fetchColumn())->toBe(1);
    } finally {
        unlink($path);
    }
});

it('refuses direct SQLite repair if the target changes before locking', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-direct-');
    expect($path)->not->toBeFalse();
    try {
        $first = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $second = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $first->exec("CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7, 'kept')");
        $version = (int) ancillarySqliteQuery($first, 'PRAGMA main.schema_version')->fetchColumn();
        $second->exec('DROP TABLE languages');
        $repairer = new AncillaryUserColumnRepair;
        $repair = new ReflectionMethod($repairer, 'sqliteDirectRepair');
        expect(fn (): mixed => $repair->invoke($repairer, $first, $version, static fn (): int|false => $first->exec('ALTER TABLE users ADD COLUMN bio TEXT')))->toThrow(RuntimeException::class, 'schema changed')
            ->and(ancillarySqliteQuery($first, 'SELECT name FROM users')->fetchColumn())->toBe('kept')
            ->and(ancillarySqliteQuery($first, "SELECT COUNT(*) FROM pragma_table_info('users') WHERE name='bio'")->fetchColumn())->toBe(0);
    } finally {
        unlink($path);
    }
});

it('rolls back all direct SQLite alterations after a failure', function (): void {
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7, 'kept')");

    $version = (int) ancillarySqliteQuery($pdo, 'PRAGMA main.schema_version')->fetchColumn();
    $repairer = new AncillaryUserColumnRepair;
    $repair = new ReflectionMethod($repairer, 'sqliteDirectRepair');
    expect(fn (): mixed => $repair->invoke($repairer, $pdo, $version, static function () use ($pdo): void {
        $pdo->exec('ALTER TABLE users ADD COLUMN bio TEXT');
        throw new RuntimeException('injected failure');
    }))->toThrow(RuntimeException::class, 'injected failure')
        ->and(ancillarySqliteQuery($pdo, 'SELECT name FROM users')->fetchColumn())->toBe('kept')
        ->and(ancillarySqliteQuery($pdo, "SELECT COUNT(*) FROM pragma_table_info('users') WHERE name='bio'")->fetchColumn())->toBe(0);
});

it('rolls back a failed nested direct repair while preserving the caller transaction', function (): void {
    $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7, 'original')");
    $pdo->beginTransaction();
    $pdo->exec("UPDATE users SET name='caller' WHERE id=7");

    $version = (int) ancillarySqliteQuery($pdo, 'PRAGMA main.schema_version')->fetchColumn();
    $repairer = new AncillaryUserColumnRepair;
    $repair = new ReflectionMethod($repairer, 'sqliteDirectRepair');
    expect(fn (): mixed => $repair->invoke($repairer, $pdo, $version, static function () use ($pdo): void {
        $pdo->exec('ALTER TABLE users ADD COLUMN bio TEXT');
        throw new RuntimeException('injected nested failure');
    }, 'UPDATE users SET id=id WHERE 0'))->toThrow(RuntimeException::class, 'injected nested failure')
        ->and($pdo->inTransaction())->toBeTrue()
        ->and(ancillarySqliteQuery($pdo, 'SELECT name FROM users')->fetchColumn())->toBe('caller')
        ->and(ancillarySqliteQuery($pdo, "SELECT COUNT(*) FROM pragma_table_info('users') WHERE name='bio'")->fetchColumn())->toBe(0);
    $pdo->rollBack();
    expect(ancillarySqliteQuery($pdo, 'SELECT name FROM users')->fetchColumn())->toBe('original');
});

it('refuses a nested SQLite repair when another writer holds the lock', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-lock-');
    expect($path)->not->toBeFalse();
    try {
        $first = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
        $second = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 0]);
        $first->exec("CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7, 'kept')");
        $first->beginTransaction();
        $version = (int) ancillarySqliteQuery($first, 'PRAGMA main.schema_version')->fetchColumn();
        $second->exec('BEGIN IMMEDIATE');
        $repairer = new AncillaryUserColumnRepair;
        $repair = new ReflectionMethod($repairer, 'sqliteDirectRepair');
        expect(fn (): mixed => $repair->invoke($repairer, $first, $version, static fn (): int|false => $first->exec('ALTER TABLE users ADD COLUMN bio TEXT'), 'UPDATE users SET id=id WHERE 0'))->toThrow(PDOException::class)
            ->and($first->inTransaction())->toBeTrue()
            ->and(ancillarySqliteQuery($first, 'SELECT name FROM users')->fetchColumn())->toBe('kept')
            ->and(ancillarySqliteQuery($first, "SELECT COUNT(*) FROM pragma_table_info('users') WHERE name='bio'")->fetchColumn())->toBe(0);
        $first->rollBack();
        $second->exec('ROLLBACK');
    } finally {
        unlink($path);
    }
});

it('lets the caller roll back a successful nested SQLite repair', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.ancillary_caller_rollback', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('ancillary_caller_rollback');
    try {
        $pdo = DB::connection()->getPdo();
        $pdo->exec("CREATE TABLE languages(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY, name TEXT); INSERT INTO users VALUES (7, 'kept')");
        DB::beginTransaction();
        $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';
        $migration->up();
        expect(Schema::hasColumn('users', 'bio'))->toBeTrue()
            ->and(Schema::hasColumn('users', 'preferred_admin_language_id'))->toBeTrue();
        DB::rollBack();
        expect(Schema::hasColumn('users', 'bio'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'preferred_admin_language_id'))->toBeFalse()
            ->and(ancillarySqliteQuery($pdo, 'SELECT name FROM users')->fetchColumn())->toBe('kept');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('ancillary_caller_rollback');
    }
});

it('references the configured language table alias when adding the constraint', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.user_columns_alias_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    config()->set('capell.database.table_aliases', ['languages' => 'cms_languages']);
    DB::setDefaultConnection('user_columns_alias_test');
    $migration = require dirname(__DIR__, 4) . '/database/migrations/2026_10_29_000002_repair_ancillary_user_columns.php';

    try {
        Schema::create('cms_languages', fn (Blueprint $table) => $table->id());
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('users')->insert(['name' => 'Editor']);

        $migration->up();

        $foreignKeys = collect(Schema::getForeignKeys('users'))->where('foreign_table', 'cms_languages');
        expect($foreignKeys)->toHaveCount(1)
            ->and(Schema::hasColumn('users', 'preferred_admin_language_id'))->toBeTrue();
    } finally {
        config()->set('capell.database.table_aliases', []);
        DB::setDefaultConnection($previous);
        DB::purge('user_columns_alias_test');
    }
});
