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
use Capell\Core\Support\TableName;
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

it('still adopts an owned table during an upgrade', function (): void {
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

it('adopts a real Capell table whose migrations row was pruned and logs its create migration', function (): void {
    createRealCapellTable('2026_05_10_190832_02_create_languages_table');

    expect(CoreSchemaMigrations::createsExistingTable('2026_05_10_190832_02_create_languages_table'))->toBeTrue()
        ->and(TableName::migrationRecorded('2026_05_10_190832_02_create_languages_table'))->toBeFalse();

    expect(Artisan::call('capell:tables:adopt', ['table' => 'languages']))->toBe(1);
    expect(TableName::migrationRecorded('2026_05_10_190832_02_create_languages_table'))->toBeFalse();

    expect(Artisan::call('capell:tables:adopt', ['table' => 'languages', '--confirm' => true]))->toBe(0);
    expect(TableName::migrationRecorded('2026_05_10_190832_02_create_languages_table'))->toBeTrue();
});

it('refuses a foreign table with the same name and names the table, migration and recovery command', function (): void {
    Schema::create('languages', fn (Blueprint $table) => $table->id());

    try {
        CoreSchemaMigrations::createsExistingTable('2026_05_10_190832_02_create_languages_table');
        $message = '';
    } catch (ForeignTableException $foreignTableException) {
        $message = $foreignTableException->getMessage();
    }

    expect($message)->toContain('languages')
        ->and($message)->toContain('2026_05_10_190832_02_create_languages_table')
        ->and($message)->toContain('capell:tables:adopt languages --confirm')
        ->and($message)->toContain('for foreign tables only');

    expect(Artisan::call('capell:tables:adopt', ['table' => 'languages', '--confirm' => true]))->toBe(1);
    expect(TableName::migrationRecorded('2026_05_10_190832_02_create_languages_table'))->toBeFalse();
});

it('adopts a table created by a registered package migration and a renamed legacy table', function (): void {
    resolve(MigrationRootRegistry::class)->register('example/widgets', __DIR__ . '/../fixtures/table-naming/widget-package', ['2026_01_01_000001_create_widgets_table' => 'widgets']);
    (require __DIR__ . '/../fixtures/table-naming/widget-package/2026_01_01_000001_create_widgets_table.php')->up();

    expect(AssertTableOwnershipAction::run('widgets', '2026_01_01_000001_create_widgets_table'))->toBeTrue();
    expect(Artisan::call('capell:tables:adopt', ['table' => 'widgets', '--confirm' => true]))->toBe(0);
    expect(TableName::migrationRecorded('2026_01_01_000001_create_widgets_table'))->toBeTrue();

    createRealCapellTable('2026_05_10_190832_15_create_asset_attachments_table');
    Schema::rename('asset_attachments', 'asset_relations');

    expect(AssertTableOwnershipAction::run('asset_relations', '2026_05_10_190832_15_create_asset_relations_table'))->toBeTrue();
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
