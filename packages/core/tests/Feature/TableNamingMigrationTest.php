<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Install\ResolveTableCollisionsAction;
use Capell\Core\Actions\Install\WriteTableConfigurationAction;
use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\Macros\BlueprintMacros;
use Capell\Core\Support\TableName;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;

it('creates and drops the resolved page table', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database', ['table_prefix' => 'cms_']);
    DB::setDefaultConnection('table_naming_test');

    try {
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_12_create_pages_table.php';
        $migration->up();
        expect(Schema::hasTable('cms_pages'))->toBeTrue()
            ->and(Schema::hasTable('pages'))->toBeFalse();
        $migration->down();
        expect(Schema::hasTable('cms_pages'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('refuses to adopt a foreign table in a guarded migration', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');

    try {
        Schema::create('blueprints', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_03_create_blueprints_table.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'blueprints');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('matches userstamp column width to the live users id', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');

    try {
        DB::statement('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
        DB::connection()->useDefaultSchemaGrammar();
        $blueprint = new Blueprint(DB::connection(), 'example');
        (new BlueprintMacros)->userstamps()->call($blueprint);
        expect(array_map(static fn (ColumnDefinition $column): mixed => $column->get('type'), $blueprint->getColumns()))->toBe(['integer', 'integer', 'integer']);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('reads blueprint groups from the resolved table', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.table_aliases', ['blueprints' => 'content_blueprints']);
    DB::setDefaultConnection('table_naming_test');

    try {
        Schema::create('content_blueprints', function (Blueprint $table): void {
            $table->id();
            $table->string('group');
        });
        DB::table('content_blueprints')->insert(['group' => 'content']);
        expect(Capell\Core\Models\Blueprint::getGroups())->toBe(['content' => 'content (1)']);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('runs registered core migrations on an isolated database', function (array $databaseConfig, string $pagesTable, bool $externalAliases): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database', $databaseConfig);
    if ($externalAliases) {
        config()->set('settings.repositories.database.table', 'content_settings');
        config()->set('permission.table_names.roles', 'content_roles');
        config()->set('permission.table_names.permissions', 'content_permissions');
    }

    DB::setDefaultConnection('table_naming_test');

    try {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        $settingsMigration = require dirname(__DIR__, 4) . '/vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub';
        $permissionsMigration = require dirname(__DIR__, 4) . '/vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub';
        $settingsMigration->up();
        $permissionsMigration->up();
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $repository->log('2026_05_10_190824_create_permission_tables', 1);
        $exit = Artisan::call('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);
        expect($exit)->toBe(0)->and(Schema::hasTable($pagesTable))->toBeTrue();
        $longIndexes = [];
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getIndexes($table['name']) as $index) {
                if (strlen($index['name']) > 64) {
                    $longIndexes[] = $index['name'];
                }
            }
        }

        expect($longIndexes)->toBe([]);

        if ($externalAliases) {
            expect(Schema::hasTable('content_settings'))->toBeTrue()
                ->and(Schema::hasTable('content_roles'))->toBeTrue()
                ->and(Schema::hasTable('content_permissions'))->toBeTrue()
                ->and(Schema::hasTable('content_translations'))->toBeTrue()
                ->and(Schema::getForeignKeys('page_role_restrictions')[0]['foreign_table'])->toBe('content_roles');
        }
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
})->with([
    'defaults' => [[], 'pages', false],
    'prefix' => [['table_prefix' => 'cms_'], 'cms_pages', false],
    'alias' => [['table_aliases' => ['pages' => 'content_pages', 'translations' => 'content_translations']], 'content_pages', true],
]);

it('allows a recorded guarded migration to run again', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');

    try {
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_03_create_blueprints_table.php';
        $migration->up();
        $repository->log('2026_05_10_190832_03_create_blueprints_table', 1);
        $migration->up();
        expect(Schema::hasTable('blueprints'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('keeps a foreign pages table intact when core pages are aliased', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database', ['table_aliases' => ['pages' => 'content_pages']]);
    DB::setDefaultConnection('table_naming_test');

    try {
        DB::statement('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('foreign_data');
        });
        DB::table('pages')->insert(['foreign_data' => 'preserve me']);
        $exit = Artisan::call('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);
        expect($exit)->toBe(0)
            ->and(Schema::hasTable('content_pages'))->toBeTrue()
            ->and(DB::table('pages')->value('foreign_data'))->toBe('preserve me');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('refuses to alter a foreign table when its creating migration is unrecorded', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');

    try {
        Schema::create('site_domains', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_08_09_000001_add_port_to_site_domains_table.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'site_domains');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('reports a foreign table collision without changing database configuration', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.table_prefix', '');
    config()->set('capell.database.table_aliases', []);
    DB::setDefaultConnection('table_naming_test');

    try {
        Schema::create('pages', fn (Blueprint $table) => $table->id());
        $before = config('capell.database');
        $collisions = resolve(FindTableCollisionsAction::class)->handle([]);
        expect(collect($collisions)->pluck('table')->all())->toContain('pages')
            ->and(config('capell.database'))->toBe($before);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('writes only selected collision aliases to the published configuration', function (): void {
    $directory = base_path('tmp/table-naming-' . bin2hex(random_bytes(6)));
    mkdir($directory, 0755, true);
    $path = $directory . '/capell.php';
    file_put_contents($path, "<?php\nreturn ['database' => ['table_prefix' => '', 'table_aliases' => []], 'other' => 'preserved'];\n");

    try {
        $action = resolve(WriteTableConfigurationAction::class);
        $before = file_get_contents($path);
        $action->handle([], $directory);
        expect(file_get_contents($path))->toBe($before);
        $action->handle(['capell.database.table_aliases.pages' => 'content_pages'], $directory);
        $config = require $path;
        expect($config['database']['table_aliases'])->toBe(['pages' => 'content_pages'])
            ->and($config['other'])->toBe('preserved');
    } finally {
        unlink($path);
        rmdir($directory);
    }
});

it('requires an explicit choice and resolves only colliding table names', function (): void {
    $collision = new TableCollisionData('pages', 'pages', 'capell.database.table_aliases.pages', 'create_pages_table');
    $action = resolve(ResolveTableCollisionsAction::class);
    expect(fn () => $action->handle([$collision], null, []))->toThrow(RuntimeException::class, 'pages');
    expect($action->handle([$collision], null, ['pages' => 'content_pages']))
        ->toBe(['capell.database.table_aliases.pages' => 'content_pages'])
        ->and($action->handle([], 'cms_', []))->toBe([]);
});

it('detects Tags collisions and writes each package-specific table setting', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.external_tables.tags', 'tags');
    config()->set('tags.taggable.table_name', 'taggables');
    DB::setDefaultConnection('table_naming_test');
    $directory = base_path('tmp/tag-naming-' . bin2hex(random_bytes(6)));
    mkdir($directory, 0755, true);
    file_put_contents($directory . '/capell.php', "<?php return ['database' => ['external_tables' => []]];");
    file_put_contents($directory . '/tags.php', "<?php return ['taggable' => ['table_name' => 'taggables']];");

    try {
        Schema::create('tags', fn (Blueprint $table) => $table->id());
        Schema::create('taggables', fn (Blueprint $table) => $table->id());
        $collisions = resolve(FindTableCollisionsAction::class)->handle(['capell-app/tags']);
        expect(collect($collisions)->pluck('table')->all())->toContain('tags', 'taggables');
        $changes = resolve(ResolveTableCollisionsAction::class)->handle($collisions, null, ['tags' => 'content_tags', 'taggables' => 'content_taggables']);
        resolve(WriteTableConfigurationAction::class)->handle($changes, $directory);
        $capell = require $directory . '/capell.php';
        $tags = require $directory . '/tags.php';
        expect($capell['database']['external_tables']['tags'])->toBe('content_tags')
            ->and($tags['taggable']['table_name'])->toBe('content_taggables');
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
        unlink($directory . '/capell.php');
        unlink($directory . '/tags.php');
        rmdir($directory);
    }
});

it('does not drop a foreign legacy snapshots table', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');
    try {
        Schema::create('page_content_snapshots', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_06_26_000005_drop_legacy_page_content_snapshots_table.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'page_content_snapshots')
            ->and(Schema::hasTable('page_content_snapshots'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('refuses an unrecorded table when resuming a half-run migration set', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');
    try {
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $languages = require __DIR__ . '/../../database/migrations/2026_05_10_190832_02_create_languages_table.php';
        $languages->up();
        $repository->log('2026_05_10_190832_02_create_languages_table', 1);
        Schema::create('blueprints', fn (Blueprint $table) => $table->id());
        $blueprints = require __DIR__ . '/../../database/migrations/2026_05_10_190832_03_create_blueprints_table.php';
        expect(fn () => $blueprints->up())->toThrow(RuntimeException::class, 'Choose a table alias or prefix')
            ->and(Schema::hasTable('languages'))->toBeTrue()
            ->and(Schema::hasTable('blueprints'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('derives first-party index names from their resolved tables', function (): void {
    $previous = DB::getDefaultConnection();
    $collect = function (string $prefix): array {
        config()->set('database.connections.table_naming_indexes', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        config()->set('capell.database', ['table_prefix' => $prefix, 'table_aliases' => []]);
        DB::setDefaultConnection('table_naming_indexes');
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Artisan::call('migrate', ['--path' => realpath(__DIR__ . '/../../database/migrations'), '--realpath' => true, '--force' => true]);
        $result = [];
        foreach (resolve(FindTableCollisionsAction::class)->planned([]) as $table) {
            if (! str_starts_with($table->configKey, 'capell.database.table_aliases.')) {
                continue;
            }

            if (! Schema::hasTable($table->table)) {
                continue;
            }

            $names = array_column(Schema::getIndexes($table->table), 'name');
            $names = array_values(array_filter($names, fn (string $name): bool => ! str_starts_with($name, 'sqlite_autoindex_') && $name !== 'primary'));
            sort($names);
            $result[$table->name] = $names;
        }

        DB::purge('table_naming_indexes');

        return $result;
    };
    try {
        $defaults = $collect('');
        $prefixed = $collect('cms_');
        expect($defaults['pages'])->toContain('pages_uuid_index')
            ->and($defaults['languages'])->toContain('languages_code_unique')
            ->and($prefixed['languages'])->toContain('cms_languages_code_unique');
        foreach ($defaults as $table => $names) {
            $expected = array_map(fn (string $name): string => TableName::namedIndex($table, $name), $names);
            sort($expected);
            expect($prefixed[$table])->toBe($expected);
        }
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_indexes');
    }
});

it('preserves host indexes when creating an aliased page table', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database', ['table_aliases' => ['pages' => 'content_pages']]);
    DB::setDefaultConnection('table_naming_test');
    try {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->index();
        });
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_12_create_pages_table.php';
        $migration->up();
        expect(Schema::hasTable('content_pages'))->toBeTrue()
            ->and(Schema::hasIndex('pages', 'pages_uuid_index'))->toBeTrue()
            ->and(Schema::hasIndex('content_pages', 'content_pages_uuid_index'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('derives userstamp foreign-key names from the resolved table', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database', ['table_prefix' => 'cms_']);
    DB::setDefaultConnection('table_naming_test');
    try {
        DB::connection()->useDefaultSchemaGrammar();
        $blueprint = new Blueprint(DB::connection(), 'cms_pages');
        (new BlueprintMacros)->userstamps()->call($blueprint);
        $commands = array_filter($blueprint->getCommands(), static fn (Fluent $command): bool => $command->get('name') === 'foreign');
        $names = array_values(array_map(static fn (Fluent $command): mixed => $command->get('index'), $commands));
        expect($names)->toBe(['cms_pages_created_by_foreign', 'cms_pages_updated_by_foreign', 'cms_pages_deleted_by_foreign']);
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('refuses a foreign table even when an alter migration checks only a column', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    DB::setDefaultConnection('table_naming_test');
    try {
        Schema::create('capell_extensions', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_08_05_000001_add_auto_update_policy_to_capell_extensions.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'capell_extensions')
            ->and(Schema::hasColumn('capell_extensions', 'auto_update_policy'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('removes a recorded legacy snapshots table without touching a foreign base name', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.table_aliases', ['page_content_snapshots' => 'owned_snapshots']);
    DB::setDefaultConnection('table_naming_test');
    try {
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $repository->log('2026_06_25_000003_create_page_content_snapshots_table', 1);
        Schema::create('owned_snapshots', fn (Blueprint $table) => $table->id());
        Schema::create('page_content_snapshots', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_06_26_000005_drop_legacy_page_content_snapshots_table.php';
        $migration->up();
        expect(Schema::hasTable('owned_snapshots'))->toBeFalse()
            ->and(Schema::hasTable('page_content_snapshots'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('detects case-insensitive SQLite index name collisions', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database', ['table_aliases' => ['pages' => 'content_pages']]);
    DB::setDefaultConnection('table_naming_test');
    try {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->index('PAGES_UUID_INDEX');
        });
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_12_create_pages_table.php';
        $migration->up();
        expect(Schema::hasTable('content_pages'))->toBeTrue();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('derives the media collection index from the external media table name', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.external_tables.media', 'content_media');
    DB::setDefaultConnection('table_naming_test');
    try {
        foreach (['media', 'content_media'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->string('collection_name');
                $table->unsignedInteger('order_column');
            });
        }

        Schema::table('media', fn (Blueprint $table) => $table->index(['model_type', 'model_id', 'collection_name', 'order_column'], 'media_collection_name_index'));
        $repository = resolve('migrator')->getRepository();
        $repository->createRepository();
        $repository->log('2026_05_10_190826_create_media_table', 1);
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_17_add_collection_index_to_media_table.php';
        $migration->up();
        expect(Schema::hasIndex('media', 'media_collection_name_index'))->toBeTrue()
            ->and(Schema::hasIndex('content_media', 'content_media_collection_name_index'))->toBeTrue();
        $migration->down();
        expect(Schema::hasIndex('media', 'media_collection_name_index'))->toBeTrue()
            ->and(Schema::hasIndex('content_media', 'content_media_collection_name_index'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('refuses to alter a foreign external media table without its creating migration', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.external_tables.media', 'foreign_media');
    DB::setDefaultConnection('table_naming_test');
    try {
        Schema::create('foreign_media', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_06_25_000001_add_deleted_at_to_media_table.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'foreign_media')
            ->and(Schema::hasColumn('foreign_media', 'deleted_at'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('refuses to alter foreign permission tables without their creating migration', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.table_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('permission.table_names.roles', 'foreign_roles');
    DB::setDefaultConnection('table_naming_test');
    try {
        Schema::create('foreign_roles', fn (Blueprint $table) => $table->id());
        $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_19_add_team_id_to_permission_tables.php';
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'foreign_roles')
            ->and(Schema::hasColumn('foreign_roles', 'team_id'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('table_naming_test');
    }
});

it('replaces a colliding existing alias when a prefix is selected', function (): void {
    config()->set('capell.database.table_aliases.pages', 'foreign_pages');
    $collision = new TableCollisionData('pages', 'foreign_pages', 'capell.database.table_aliases.pages', 'create_pages_table');
    expect(resolve(ResolveTableCollisionsAction::class)->handle([$collision], 'cms_proof_', []))
        ->toBe([
            'capell.database.table_prefix' => 'cms_proof_',
            'capell.database.table_aliases.pages' => 'cms_proof_pages',
        ]);
});
