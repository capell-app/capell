<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\AssertTableOwnershipAction;
use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Install\ResolveTableCollisionsAction;
use Capell\Core\Actions\Install\ValidateTablePrefixAction;
use Capell\Core\Actions\Upgrade\ReportCapellUpgradeDryRunAction;
use Capell\Core\Contracts\UpgradeReporter;
use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Exceptions\ForeignTableException;
use Capell\Core\Support\Migration\CoreSchemaMigrations;
use Capell\Core\Support\Migration\MigrationRootRegistry;
use Capell\Core\Support\Migration\TableShape;
use Capell\Core\Support\TableName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->previousConnection = DB::getDefaultConnection();
    config()->set('database.connections.naming_guards', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.table_prefix', '');
    config()->set('capell.database.table_aliases', []);
    DB::setDefaultConnection('naming_guards');
});

afterEach(function (): void {
    DB::setDefaultConnection($this->previousConnection);
    DB::purge('naming_guards');
});

it('lets a migration create an absent table and skips an owned one', function (): void {
    expect(AssertTableOwnershipAction::run('pages', '2026_05_10_190832_12_create_pages_table'))->toBeFalse();

    Schema::create('pages', fn (Blueprint $table) => $table->id());
    $repository = resolve('migrator')->getRepository();
    $repository->createRepository();
    $repository->log('2026_01_01_000000_create_pages_table', 1);

    expect(AssertTableOwnershipAction::run('pages', '2026_05_10_190832_12_create_pages_table'))->toBeTrue();
});

it('refuses a foreign table and names the prefix and alias settings', function (): void {
    Schema::create('pages', fn (Blueprint $table) => $table->id());

    expect(fn (): bool => AssertTableOwnershipAction::run('pages', '2026_05_10_190832_12_create_pages_table'))
        ->toThrow(ForeignTableException::class, 'capell.database.table_prefix');

    try {
        AssertTableOwnershipAction::run('pages', '2026_05_10_190832_12_create_pages_table');
    } catch (ForeignTableException $foreignTableException) {
        expect($foreignTableException->getMessage())->toContain('pages')
            ->and($foreignTableException->getMessage())->toContain('capell.database.table_aliases.pages');
    }
});

it('refuses a foreign table owned by another package and names its setting', function (): void {
    Schema::create('shared_things', fn (Blueprint $table) => $table->id());

    expect(fn (): bool => AssertTableOwnershipAction::run('shared_things', 'create_things_table', 'things.table'))
        ->toThrow(ForeignTableException::class, 'things.table');
});

it('stops an upgrade from adopting a foreign table instead of marking its create migration as run', function (): void {
    Schema::create('pages', fn (Blueprint $table) => $table->id());

    expect(fn (): bool => CoreSchemaMigrations::createsExistingTable('2026_05_10_190832_12_create_pages_table'))
        ->toThrow(ForeignTableException::class, 'capell.database.table_aliases.pages');
});

it('still adopts a recorded table during an upgrade', function (): void {
    Schema::create('pages', fn (Blueprint $table) => $table->id());
    $repository = resolve('migrator')->getRepository();
    $repository->createRepository();
    $repository->log('2025_01_01_000000_create_pages_table', 1);

    expect(CoreSchemaMigrations::createsExistingTable('2026_05_10_190832_12_create_pages_table'))->toBeTrue();
});

it('validates a prefix against the longest Capell table and the identifier cap', function (): void {
    $planned = array_map(static fn (TableCollisionData $table): string => $table->name, array_filter(
        resolve(FindTableCollisionsAction::class)->planned([]),
        static fn (TableCollisionData $table): bool => str_starts_with($table->configKey, 'capell.database.table_aliases.'),
    ));
    $longest = max(array_map(strlen(...), $planned));

    ValidateTablePrefixAction::run(str_repeat('p', TableName::MAX_IDENTIFIER_LENGTH - $longest), $planned);

    expect(fn (): mixed => ValidateTablePrefixAction::run(str_repeat('p', TableName::MAX_IDENTIFIER_LENGTH - $longest + 1), $planned))
        ->toThrow(RuntimeException::class, 'too long');
});

it('rejects a prefix with unsafe characters', function (string $prefix): void {
    expect(fn (): mixed => ValidateTablePrefixAction::run($prefix, ['pages']))->toThrow(RuntimeException::class);
})->with(['dot' => ['db.'], 'space' => ['my cms_'], 'leading digit' => ['1cms_'], 'quote' => ["a'_"]]);

it('accepts an empty prefix whatever the table names', function (): void {
    ValidateTablePrefixAction::run('', [str_repeat('x', 200)]);

    expect(true)->toBeTrue();
});

it('rejects malformed base names and non-string settings when resolving a table', function (): void {
    expect(fn (): string => TableName::resolve('other.pages'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => TableName::resolve(''))->toThrow(InvalidArgumentException::class);

    config()->set('capell.database.table_prefix', ['cms_']);
    expect(fn (): string => TableName::resolve('pages'))->toThrow(RuntimeException::class, 'table prefix');

    config()->set('capell.database.table_prefix', '');
    config()->set('capell.database.table_aliases.pages', ['x']);

    expect(fn (): string => TableName::resolve('pages'))->toThrow(RuntimeException::class, 'alias');
});

it('keeps every first-party table and index name unchanged when the prefix is empty', function (): void {
    foreach (resolve(FindTableCollisionsAction::class)->planned([]) as $table) {
        if (str_starts_with($table->configKey, 'capell.database.table_aliases.')) {
            expect($table->table)->toBe($table->name)
                ->and(TableName::index($table->name, 'id', 'index'))->toBe($table->name . '_id_index')
                ->and(TableName::namedIndex($table->name, 'legacy_name'))->toBe('legacy_name');
        }
    }
});

it('derives the page role restriction unique index from the prefixed table', function (): void {
    config()->set('capell.database.table_prefix', 'cms_');
    $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_20_create_page_role_restrictions_table.php';
    Schema::create('roles', fn (Blueprint $table) => $table->id());
    $migration->up();

    expect(Schema::hasIndex('cms_page_role_restrictions', 'cms_page_role_restrictions_restrictable_role'))->toBeTrue();
});

function createRealCapellTable(string $file): void
{
    (require __DIR__ . '/../../database/migrations/' . $file . '.php')->up();
}

function upgradeRefusal(string $migration): string
{
    try {
        CoreSchemaMigrations::createsExistingTable($migration);
    } catch (ForeignTableException $foreignTableException) {
        return $foreignTableException->getMessage();
    }

    return '';
}

it('refuses a matching Capell table during an upgrade, then adopts it only through the command', function (): void {
    createRealCapellTable('2026_05_10_190832_02_create_languages_table');
    $migration = '2026_05_10_190832_02_create_languages_table';

    expect(upgradeRefusal($migration))->toContain('languages')
        ->and(upgradeRefusal($migration))->toContain($migration)
        ->and(upgradeRefusal($migration))->toContain('capell:tables:adopt languages --confirm')
        ->and(TableName::migrationRecorded($migration))->toBeFalse();

    expect(Artisan::call('capell:tables:adopt', ['table' => 'languages']))->toBe(1)
        ->and(TableName::migrationRecorded($migration))->toBeFalse();

    expect(Artisan::call('capell:tables:adopt', ['table' => 'languages', '--confirm' => true]))->toBe(0)
        ->and(TableName::migrationRecorded($migration))->toBeTrue()
        ->and(CoreSchemaMigrations::createsExistingTable($migration))->toBeTrue();
});

it('refuses a foreign table with the same name from both the upgrade and the command', function (): void {
    Schema::create('languages', fn (Blueprint $table) => $table->id());
    $migration = '2026_05_10_190832_02_create_languages_table';
    $message = upgradeRefusal($migration);

    expect($message)->toContain('languages')
        ->and($message)->toContain($migration)
        ->and($message)->toContain('capell:tables:adopt languages --confirm')
        ->and($message)->toContain('for foreign tables only');

    expect(Artisan::call('capell:tables:adopt', ['table' => 'languages', '--confirm' => true]))->toBe(1)
        ->and(TableName::migrationRecorded($migration))->toBeFalse();
});

it('refuses a table with the same column names but different types, nullability or keys', function (): void {
    createRealCapellTable('2026_05_10_190832_02_create_languages_table');
    $names = Schema::getColumnListing('languages');
    Schema::drop('languages');
    Schema::create('languages', function (Blueprint $table) use ($names): void {
        foreach ($names as $name) {
            $table->text($name)->nullable();
        }
    });
    $migration = '2026_05_10_190832_02_create_languages_table';

    expect(Schema::getColumnListing('languages'))->toBe($names)
        ->and(upgradeRefusal($migration))->toContain('capell:tables:adopt languages --confirm')
        ->and(Artisan::call('capell:tables:adopt', ['table' => 'languages', '--confirm' => true]))->toBe(1)
        ->and(TableName::migrationRecorded($migration))->toBeFalse()
        ->and(TableShape::differences('languages', $migration))->toContain('id differs in type', 'id differs in primary key');
});

it('refuses package and legacy tables during an upgrade and adopts them through the command', function (): void {
    resolve(MigrationRootRegistry::class)->register('example/widgets', __DIR__ . '/../fixtures/table-naming/widget-package', ['2026_01_01_000001_create_widgets_table' => 'widgets']);
    (require __DIR__ . '/../fixtures/table-naming/widget-package/2026_01_01_000001_create_widgets_table.php')->up();

    expect(fn (): bool => AssertTableOwnershipAction::run('widgets', '2026_01_01_000001_create_widgets_table'))->toThrow(ForeignTableException::class, 'capell:tables:adopt widgets --confirm');
    expect(Artisan::call('capell:tables:adopt', ['table' => 'widgets', '--confirm' => true]))->toBe(0)
        ->and(AssertTableOwnershipAction::run('widgets', '2026_01_01_000001_create_widgets_table'))->toBeTrue();

    createRealCapellTable('2026_05_10_190832_15_create_asset_attachments_table');
    Schema::rename('asset_attachments', 'asset_relations');
    $legacy = '2026_05_10_190832_15_create_asset_relations_table';

    expect(fn (): bool => AssertTableOwnershipAction::run('asset_relations', $legacy))->toThrow(ForeignTableException::class);
    expect(Artisan::call('capell:tables:adopt', ['table' => 'asset_relations', '--confirm' => true]))->toBe(0)
        ->and(AssertTableOwnershipAction::run('asset_relations', $legacy))->toBeTrue();
});

it('counts later alter migrations: adopts a table the recorded alter changed, refuses one it did not', function (): void {
    $root = __DIR__ . '/../fixtures/table-naming/alter-package';
    resolve(MigrationRootRegistry::class)->register('example/gizmos', $root, ['2026_03_01_000001_create_gizmos_table' => 'gizmos']);
    $create = '2026_03_01_000001_create_gizmos_table';
    $repository = resolve('migrator')->getRepository();
    $repository->createRepository();
    $repository->log('2026_03_02_000001_make_gizmo_name_nullable', 1);

    Schema::create('gizmos', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    expect(fn (): bool => AssertTableOwnershipAction::run('gizmos', $create))->toThrow(ForeignTableException::class, 'capell:tables:adopt gizmos --confirm')
        ->and(TableShape::differences('gizmos', $create))->toBe([]);
    expect(Artisan::call('capell:tables:adopt', ['table' => 'gizmos', '--confirm' => true]))->toBe(0)
        ->and(TableName::migrationRecorded($create))->toBeTrue();

    Schema::drop('gizmos');
    DB::table('migrations')->where('migration', $create)->delete();
    Schema::create('gizmos', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    expect(TableShape::differences('gizmos', $create))->toBe(['name differs in nullability'])
        ->and(Artisan::call('capell:tables:adopt', ['table' => 'gizmos', '--confirm' => true]))->toBe(1);
});

it('refuses with a clear message when a migration cannot run on SQLite', function (): void {
    $root = sys_get_temp_dir() . '/capell-sqlite-fail-' . bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root . '/2026_04_01_000001_create_sprockets_table.php', "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration { public function up(): void { DB::statement('ALTER TABLE sprockets ENGINE=InnoDB'); } };\n");
    resolve(MigrationRootRegistry::class)->register('example/sprockets', $root, ['2026_04_01_000001_create_sprockets_table' => 'sprockets']);
    Schema::create('sprockets', fn (Blueprint $table) => $table->id());

    try {
        expect(TableShape::differences('sprockets', '2026_04_01_000001_create_sprockets_table')[0])->toContain('cannot run on SQLite')
            ->and(Artisan::call('capell:tables:adopt', ['table' => 'sprockets', '--confirm' => true]))->toBe(1);
    } finally {
        unlink($root . '/2026_04_01_000001_create_sprockets_table.php');
        rmdir($root);
    }
});

it('reports the nullable mismatch when the alter migration is not recorded', function (): void {
    $create = '2026_03_01_000001_create_gizmos_table';
    resolve(MigrationRootRegistry::class)->register('example/gizmos', __DIR__ . '/../fixtures/table-naming/alter-package', [$create => 'gizmos']);
    Schema::create('gizmos', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
    });

    expect(TableShape::differences('gizmos', $create))->toBe(['name differs in nullability'])
        ->and(Artisan::call('capell:tables:adopt', ['table' => 'gizmos', '--confirm' => true]))->toBe(1);
});

it('keeps a migration that names another connection away from the live database', function (): void {
    $root = sys_get_temp_dir() . '/capell-other-connection-' . bin2hex(random_bytes(4));
    mkdir($root);
    $file = $root . '/2026_05_02_000001_create_cogs_table.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::connection('naming_guards')->create('cogs_elsewhere', fn (Blueprint \$table) => \$table->id()); } };\n");
    resolve(MigrationRootRegistry::class)->register('example/cogs', $root, ['2026_05_02_000001_create_cogs_table' => 'cogs']);
    Schema::create('cogs', fn (Blueprint $table) => $table->id());

    try {
        expect(TableShape::differences('cogs', '2026_05_02_000001_create_cogs_table')[0])->toContain('2026_05_02_000001_create_cogs_table')->toContain('cannot run on SQLite')
            ->and(Schema::hasTable('cogs_elsewhere'))->toBeFalse()
            ->and(DB::getDefaultConnection())->toBe('naming_guards')
            ->and(Schema::hasTable('cogs'))->toBeTrue();
    } finally {
        unlink($file);
        rmdir($root);
    }
});

it('keeps Eloquent writes made by a probed migration off the live database', function (): void {
    $root = sys_get_temp_dir() . '/capell-eloquent-' . bin2hex(random_bytes(4));
    mkdir($root);
    $file = $root . '/2026_05_04_000001_create_bolts_table.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration { public function up(): void { Schema::create('bolts', fn (Blueprint \$table) => \$table->id()); (new class extends Model { protected \$table = 'bolts'; public \$timestamps = false; })->newQuery()->insert(['id' => 99]); } };\n");
    resolve(MigrationRootRegistry::class)->register('example/bolts', $root, ['2026_05_04_000001_create_bolts_table' => 'bolts']);
    Schema::create('bolts', fn (Blueprint $table) => $table->id());
    $resolver = Model::getConnectionResolver();

    try {
        expect(TableShape::differences('bolts', '2026_05_04_000001_create_bolts_table'))->toBe([])
            ->and(DB::table('bolts')->count())->toBe(0)
            ->and(Model::getConnectionResolver())->toBe($resolver);
    } finally {
        unlink($file);
        rmdir($root);
    }
});

it('refuses to probe under Octane', function (): void {
    $create = '2026_03_01_000001_create_gizmos_table';
    resolve(MigrationRootRegistry::class)->register('example/gizmos', __DIR__ . '/../fixtures/table-naming/alter-package', [$create => 'gizmos']);
    Schema::create('gizmos', fn (Blueprint $table) => $table->id());
    app()->instance('octane', new stdClass);

    try {
        expect(TableShape::differences('gizmos', $create)[0])->toContain('Octane')
            ->and(Artisan::call('capell:tables:adopt', ['table' => 'gizmos', '--confirm' => true]))->toBe(1);
    } finally {
        app()->forgetInstance('octane');
        app()->offsetUnset('octane');
    }
});

it('loads a legacy named-class migration that is already declared', function (): void {
    $root = sys_get_temp_dir() . '/capell-named-class-' . bin2hex(random_bytes(4));
    mkdir($root);
    $file = $root . '/2026_05_03_000001_create_doodads_table.php';
    file_put_contents($file, "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\nclass CreateDoodadsTable extends Migration { public function up(): void { Schema::create('doodads', fn (Blueprint \$table) => \$table->id()); } }\n");
    require_once $file;
    resolve(MigrationRootRegistry::class)->register('example/doodads', $root, ['2026_05_03_000001_create_doodads_table' => 'doodads']);
    Schema::create('doodads', fn (Blueprint $table) => $table->id());

    try {
        expect(TableShape::differences('doodads', '2026_05_03_000001_create_doodads_table'))->toBe([])
            ->and(Artisan::call('capell:tables:adopt', ['table' => 'doodads', '--confirm' => true]))->toBe(0);
    } finally {
        unlink($file);
        rmdir($root);
    }
});

it('finds migrations of installed packages in a vendor layout, not only the monorepo', function (): void {
    $root = sys_get_temp_dir() . '/capell-vendor-layout-' . bin2hex(random_bytes(4));
    $directory = $root . '/vendor/capell-app/gadgets/database/migrations';
    mkdir($directory, 0777, true);
    file_put_contents($directory . '/2026_02_01_000001_create_gadgets_table.php', <<<'PHP'
<?php

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('gadgets'), fn (Blueprint $table) => $table->id());
    }
};
PHP);

    try {
        TableShape::usingInstalledPackages(['capell-app/gadgets' => $root . '/vendor/capell-app/gadgets', 'capell-app/absent' => $root . '/vendor/capell-app/absent'], function () use ($directory): void {
            expect(TableShape::migrationFor('gadgets'))->toBe('2026_02_01_000001_create_gadgets_table')
                ->and(TableShape::allBaseTables())->toContain('gadgets');

            (require $directory . '/2026_02_01_000001_create_gadgets_table.php')->up();
            expect(Artisan::call('capell:tables:adopt', ['table' => 'gadgets', '--confirm' => true]))->toBe(0)
                ->and(TableName::migrationRecorded('2026_02_01_000001_create_gadgets_table'))->toBeTrue();
        });
    } finally {
        unlink($directory . '/2026_02_01_000001_create_gadgets_table.php');
        foreach ([$directory, dirname($directory), dirname($directory, 2), $root . '/vendor/capell-app', $root . '/vendor', $root] as $path) {
            rmdir($path);
        }
    }
});

it('reports a conflicting table in the upgrade dry run instead of throwing', function (): void {
    resolve('migrator')->getRepository()->createRepository();
    Schema::create('pages', fn (Blueprint $table) => $table->id());
    $lines = [];
    $reporter = new class($lines) implements UpgradeReporter
    {
        public function __construct(public array &$lines) {}

        public function stage(UpgradeStage $stage, string $message): void {}

        public function line(string $message): void {}

        public function info(string $message): void {}

        public function warn(string $message): void
        {
            $this->lines[] = $message;
        }

        public function error(string $message): void {}

        public function newLine(): void {}

        public function confirm(string $message, bool $default = true): bool
        {
            return $default;
        }

        public function multiselect(string $label, array $options): array
        {
            return [];
        }

        public function commandExists(string $command): bool
        {
            return false;
        }

        public function callCommand(string $command, array $parameters = []): int
        {
            return 0;
        }
    };

    $method = new ReflectionMethod(ReportCapellUpgradeDryRunAction::class, 'reportPendingSchemaMigrations');
    $method->invoke(resolve(ReportCapellUpgradeDryRunAction::class), $reporter);

    expect(implode(' ', $lines))->toContain('capell:tables:adopt pages --confirm');
});

it('counts tables from registered packages when validating a prefix, even with no collision', function (): void {
    resolve(MigrationRootRegistry::class)->register('example/widgets', __DIR__ . '/../fixtures/table-naming/widget-package', ['2026_01_01_000001_create_widgets_table' => str_repeat('w', 60)]);

    expect(fn (): array => ResolveTableCollisionsAction::run([], 'cms_', []))->toThrow(RuntimeException::class, 'too long');
    expect(ResolveTableCollisionsAction::run([], 'c_', []))->toBe(['capell.database.table_prefix' => 'c_']);
});
