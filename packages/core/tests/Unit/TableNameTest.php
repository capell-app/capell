<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\AssertTableOwnershipAction;
use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Install\PrepareVendorTableMigrationAction;
use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\EventSourcing\Models\Snapshot;
use Capell\Core\EventSourcing\Models\StoredEvent;
use Capell\Core\Exceptions\ForeignTableException;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;
use Capell\Core\Support\Migration\MigrationRootRegistry;
use Capell\Core\Support\Migration\TableShape;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('keeps base table names by default', function (): void {
    config()->set('capell.database', []);

    expect(TableName::resolve('pages'))->toBe('pages');
});

it('prefixes table names', function (): void {
    config()->set('capell.database', ['table_prefix' => 'cms_']);

    expect(TableName::resolve('pages'))->toBe('cms_pages')
        ->and(TableName::resolve('translations'))->toBe('cms_translations');
});

it('uses an alias instead of the prefix for that table', function (): void {
    config()->set('capell.database', ['table_prefix' => 'cms_', 'table_aliases' => ['pages' => 'content_pages']]);

    expect(TableName::resolve('pages'))->toBe('content_pages')
        ->and(TableName::resolve('translations'))->toBe('cms_translations');
});

it('publishes empty table naming defaults and binds the resolver', function (): void {
    expect(config('capell.database.table_prefix'))->toBe('')
        ->and(config('capell.database.table_aliases'))->toBe([])
        ->and(app()->bound(TableName::class))->toBeTrue();
});

it('resolves model and pivot tables with the configured prefix and aliases', function (): void {
    config()->set('capell.database', ['table_prefix' => 'cms_', 'table_aliases' => ['pages' => 'content_pages', 'page_term' => 'content_terms']]);

    expect((new Page)->getTable())->toBe('content_pages')
        ->and((new Term)->getTable())->toBe('cms_terms')
        ->and((new Page)->terms()->getTable())->toBe('content_terms');
});

it('keeps external package table names independent of the Capell prefix', function (): void {
    config()->set('capell.database', [
        'table_prefix' => 'cms_',
        'external_tables' => ['media' => 'library_media', 'stored_events' => 'event_log', 'snapshots' => 'event_snapshots'],
    ]);

    expect((new Media)->getTable())->toBe('library_media')
        ->and((new StoredEvent)->getTable())->toBe('event_log')
        ->and((new Snapshot)->getTable())->toBe('event_snapshots');
});

it('creates the media table at the configured external name', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.media_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.external_tables.media', 'library_media');
    DB::setDefaultConnection('media_naming_test');
    $directory = base_path('tmp/media-naming-' . bin2hex(random_bytes(6)));
    mkdir($directory, 0755, true);
    $path = $directory . '/migration.php';

    try {
        $source = file_get_contents(dirname(__DIR__, 4) . '/vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub');
        expect($source)->not->toBeFalse();
        $rewritten = PrepareVendorTableMigrationAction::run('create_media_table', $source);
        file_put_contents($path, $rewritten);
        $migration = require $path;
        $migration->up();
        expect(Schema::hasTable('library_media'))->toBeTrue()
            ->and(Schema::hasTable('media'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('media_naming_test');
        if (is_file($path)) {
            unlink($path);
        }

        rmdir($directory);
    }
});

it('creates the Tags fixture tables using their external settings', function (): void {
    $previous = DB::getDefaultConnection();
    config()->set('database.connections.tag_naming_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.external_tables.tags', 'content_tags');
    config()->set('tags.taggable.table_name', 'content_taggables');
    DB::setDefaultConnection('tag_naming_test');
    $directory = base_path('tmp/tag-migration-' . bin2hex(random_bytes(6)));
    mkdir($directory, 0755, true);
    $path = $directory . '/migration.php';
    $source = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tags', function (Blueprint $table) { $table->id(); });
        Schema::create('taggables', function (Blueprint $table) { $table->foreignId('tag_id')->constrained()->cascadeOnDelete(); });
    }
    public function down(): void {
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
    }
};
PHP;
    try {
        $prepared = PrepareVendorTableMigrationAction::run('create_tag_tables', $source);
        file_put_contents($path, $prepared);
        $migration = require $path;
        $migration->up();
        expect(Schema::hasTable('content_tags'))->toBeTrue()
            ->and(Schema::hasTable('content_taggables'))->toBeTrue()
            ->and(Schema::getForeignKeys('content_taggables')[0]['foreign_table'])->toBe('content_tags');
        $migration->down();
        expect(Schema::hasTable('content_tags'))->toBeFalse()
            ->and(Schema::hasTable('content_taggables'))->toBeFalse();
    } finally {
        DB::setDefaultConnection($previous);
        DB::purge('tag_naming_test');
        if (is_file($path)) {
            unlink($path);
        }

        rmdir($directory);
    }
});

it('applies the prefix to every planned first-party table', function (): void {
    config()->set('capell.database.table_prefix', 'cms_');
    config()->set('capell.database.table_aliases', []);

    $planned = resolve(FindTableCollisionsAction::class)->planned(['capell-app/admin', 'capell-app/marketplace']);
    $owned = array_values(array_filter($planned, fn (TableCollisionData $table): bool => str_starts_with($table->configKey, 'capell.database.table_aliases.')));
    expect(count($owned))->toBeGreaterThan(50);
    foreach ($owned as $table) {
        expect($table->table)->toBe('cms_' . $table->name);
    }
});

it('keeps legacy index and foreign-key names by default and derives custom names from the resolved table', function (): void {
    config()->set('capell.database.table_prefix', '');
    config()->set('capell.database.table_aliases', []);
    expect(TableName::index('pages', 'created_by', 'foreign'))->toBe('pages_created_by_foreign')
        ->and(TableName::namedIndex('pages', 'legacy_lookup'))->toBe('legacy_lookup');
    config()->set('capell.database.table_aliases', ['pages' => 'content_pages']);
    expect(TableName::index('pages', 'created_by', 'foreign'))->toBe('content_pages_created_by_foreign')
        ->and(TableName::namedIndex('pages', 'legacy_lookup'))->toBe('content_pages_legacy_lookup');
});

describe('migration ownership provenance', function (): void {
    beforeEach(function (): void {
        $this->ownershipPreviousConnection = DB::getDefaultConnection();
        $this->ownershipPreviousDatabasePath = database_path();
        $this->ownershipDatabasePath = sys_get_temp_dir() . '/capell-ownership-' . bin2hex(random_bytes(6));
        mkdir($this->ownershipDatabasePath . '/migrations', 0755, true);
        app()->useDatabasePath($this->ownershipDatabasePath);
        config()->set('capell.database.table_prefix', 'cms_');
        config()->set('capell.database.table_aliases', ['widgets' => 'content_widgets']);
        foreach (['ownership_primary', 'ownership_secondary'] as $connection) {
            config()->set('database.connections.' . $connection, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'dbp_', 'foreign_key_constraints' => false]);
            $repository = new DatabaseMigrationRepository(DB::getFacadeRoot(), 'migrations');
            $repository->setSource($connection);
            $repository->createRepository();
        }

        DB::setDefaultConnection('ownership_primary');
        resolve(MigrationRootRegistry::class)->register('example/widgets', __DIR__ . '/../fixtures/table-naming/widget-package', ['2026_01_01_000001_create_widgets_table' => 'widgets']);
        Schema::create('content_widgets', fn (Blueprint $table) => $table->id());
    });

    afterEach(function (): void {
        DB::setDefaultConnection($this->ownershipPreviousConnection);
        DB::purge('ownership_primary');
        DB::purge('ownership_secondary');
        app()->useDatabasePath($this->ownershipPreviousDatabasePath);
        foreach (glob($this->ownershipDatabasePath . '/migrations/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->ownershipDatabasePath . '/migrations');
        rmdir($this->ownershipDatabasePath);
    });

    it('refuses a foreign ledger suffix even when its migration file exists', function (string $recorded): void {
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);
        file_put_contents(database_path('migrations/' . $recorded . '.php'), '<?php return new stdClass;');

        expect(TableName::migrationRecorded('2026_01_01_000001_create_widgets_table'))->toBeFalse()
            ->and(fn (): bool => TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toThrow(ForeignTableException::class);
    })->with(['arbitrary prefix' => 'host2030_create_widgets_table', 'valid timestamp with foreign source' => '2030_01_01_000001_create_widgets_table']);

    it('refuses the effective foreign host migration when it shadows a trusted copy', function (bool $retimestamped, bool $symlink): void {
        $canonical = '2026_01_01_000001_create_widgets_table';
        $recorded = $retimestamped ? '2035_01_01_000001_create_widgets_table' : $canonical;
        $directory = $this->ownershipDatabasePath . '/registered-shadow';
        mkdir($directory);
        $registered = $directory . '/' . $recorded . '.php';
        copy(__DIR__ . '/../fixtures/table-naming/widget-package/' . $canonical . '.php', $registered);
        resolve('migrator')->path($directory);
        $host = database_path('migrations/' . $recorded . '.php');
        $target = $symlink ? $this->ownershipDatabasePath . '/foreign-target.php' : $host;
        file_put_contents($target, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('content_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('host_reference');
        });
    }
};
PHP);
        if ($symlink) {
            symlink($target, $host);
        }

        Schema::table('content_widgets', fn (Blueprint $table) => $table->string('host_reference'));
        DB::table('content_widgets')->insert(['id' => 7, 'host_reference' => 'host-owned']);
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);

        try {
            $effective = resolve('migrator')->getMigrationFiles([...resolve('migrator')->paths(), database_path('migrations')]);
            expect($effective[$recorded])->toBe($host)
                ->and(TableName::migrationRecorded($canonical))->toBeFalse()
                ->and(fn (): bool => TableName::existsAndIsOwned('widgets', $canonical))->toThrow(ForeignTableException::class)
                ->and(DB::table('content_widgets')->where('id', 7)->value('host_reference'))->toBe('host-owned');
        } finally {
            unlink($host);
            if ($symlink) {
                unlink($target);
            }

            unlink($registered);
            rmdir($directory);
        }
    })->with([
        'canonical host file' => [false, false],
        'canonical host symlink' => [false, true],
        'retimestamped host file' => [true, false],
        'retimestamped host symlink' => [true, true],
    ]);

    it('refuses a canonical source symlink escaping its registered ownership root', function (): void {
        $directory = $this->ownershipDatabasePath . '/source-symlink';
        mkdir($directory);
        $migration = '2036_01_01_000001_create_foreign_widgets_table';
        $target = $this->ownershipDatabasePath . '/foreign-source.php';
        $source = $directory . '/' . $migration . '.php';
        file_put_contents($target, '<?php return new stdClass;');
        symlink($target, $source);
        resolve(MigrationRootRegistry::class)->register('example/escaped-source', $directory, [$migration => 'widgets']);
        DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);

        try {
            expect(TableName::migrationRecorded($migration))->toBeFalse()
                ->and(fn (): bool => TableName::existsAndIsOwned('widgets', $migration))->toThrow(ForeignTableException::class);
        } finally {
            unlink($source);
            unlink($target);
            rmdir($directory);
        }
    });

    it('authenticates a retained publication whose shipped source later changed', function (bool $genuine): void {
        $recorded = '2037_01_01_000002_create_page_role_restrictions_table';
        $published = database_path('migrations/' . $recorded . '.php');
        $shipped = (string) file_get_contents(__DIR__ . '/../fixtures/table-naming/legacy/2026_05_10_190832_20_create_page_role_restrictions_table.php.stub');
        file_put_contents($published, $genuine ? $shipped : $shipped . "\n// host edit\n");
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);

        try {
            expect(TableName::migrationRecorded('2026_05_10_190832_20_create_page_role_restrictions_table'))->toBe($genuine);
        } finally {
            File::delete($published);
        }
    })->with(['genuine shipped bytes' => [true], 'edited bytes' => [false]]);

    it('records the bytes of every current foundation create migration', function (): void {
        $root = dirname(__DIR__, 4);
        foreach (['admin', 'core', 'frontend', 'installer', 'marketplace'] as $package) {
            foreach (glob($root . '/packages/' . $package . '/database/migrations/*create_*.php') ?: [] as $path) {
                $history = json_decode((string) file_get_contents($root . '/packages/' . $package . '/database/migration-history.json'), true, flags: JSON_THROW_ON_ERROR);
                $name = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}(?:_\d{2})?_/', '', basename($path, '.php'));
                expect(in_array(hash_file('sha256', $path), $history[$name]['sha256'] ?? [], true))->toBeTrue('Run composer migrations:history for ' . basename($path));
            }
        }
    });

    it('validates retained historical asset relations publications before the supported rename', function (bool $retimestamped, bool $genuine): void {
        $canonical = '2026_05_10_190832_15_create_asset_relations_table';
        $recorded = $retimestamped ? '2037_01_01_000001_create_asset_relations_table' : $canonical;
        $published = database_path('migrations/' . $recorded . '.php');
        if ($genuine) {
            copy(__DIR__ . '/../fixtures/table-naming/legacy/' . $canonical . '.php.stub', $published);
        } else {
            file_put_contents($published, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('cms_asset_relations', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_reference');
        });
    }
};
PHP);
        }

        Schema::create('cms_asset_relations', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_reference');
        });
        DB::table('cms_asset_relations')->insert(['id' => 7, 'owner_reference' => 'retained-row']);
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);
        $rename = require __DIR__ . '/../../database/migrations/2026_05_10_190832_15_create_asset_attachments_table.php';

        expect(TableName::migrationRecorded($canonical))->toBe($genuine);
        if ($genuine) {
            $rename->up();
            expect(Schema::hasTable('cms_asset_relations'))->toBeFalse()
                ->and(Schema::hasTable('cms_asset_attachments'))->toBeTrue()
                ->and(DB::table('cms_asset_attachments')->where('id', 7)->value('owner_reference'))->toBe('retained-row');
        } else {
            expect(function () use ($rename): void {
                $rename->up();
            })->toThrow(ForeignTableException::class)
                ->and(Schema::hasTable('cms_asset_attachments'))->toBeFalse()
                ->and(DB::table('cms_asset_relations')->where('id', 7)->value('owner_reference'))->toBe('retained-row');
        }
    })->with([
        'retained original source' => [false, true],
        'retimestamped original source' => [true, true],
        'foreign canonical source' => [false, false],
        'foreign retimestamped source' => [true, false],
    ]);

    it('refuses a timestamp-only ledger claim without a matching published source', function (): void {
        DB::table('migrations')->insert(['migration' => '2030_01_01_000001_create_widgets_table', 'batch' => 1]);

        expect(TableName::migrationRecorded('create_widgets_table'))->toBeFalse()
            ->and(fn (): bool => TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toThrow(ForeignTableException::class);
    });

    it('recognises an exact registered migration while resolving aliases and driver prefixes once', function (): void {
        DB::table('migrations')->insert(['migration' => '2026_01_01_000001_create_widgets_table', 'batch' => 1]);

        expect(TableName::migrationRecorded('create_widgets_table'))->toBeTrue()
            ->and(TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toBeTrue()
            ->and(DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'dbp_content_widgets'"))->not->toBeNull()
            ->and(Schema::hasTable('cms_content_widgets'))->toBeFalse();
    });

    it('recognises a genuine published migration with a new timestamp', function (): void {
        $recorded = '2030_01_01_000001_create_widgets_table';
        copy(__DIR__ . '/../fixtures/table-naming/widget-package/2026_01_01_000001_create_widgets_table.php', database_path('migrations/' . $recorded . '.php'));
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);

        expect(TableName::migrationRecorded('create_widgets_table'))->toBeTrue()
            ->and(TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toBeTrue();
    });

    it('recognises every canonical source with a shared logical migration name', function (bool $publishedCopy): void {
        $directory = $this->ownershipDatabasePath . '/trusted';
        mkdir($directory);
        $canonical = '2032_01_01_000001_create_widgets_table';
        $source = $directory . '/' . $canonical . '.php';
        copy(__DIR__ . '/../fixtures/table-naming/widget-package/2026_01_01_000001_create_widgets_table.php', $source);
        file_put_contents($source, "\n// Another explicitly registered package version.\n", FILE_APPEND);
        resolve(MigrationRootRegistry::class)->register('example/widgets-next', $directory, [$canonical => 'widgets']);
        $recorded = $publishedCopy ? '2033_01_01_000001_create_widgets_table' : $canonical;
        if ($publishedCopy) {
            copy($source, database_path('migrations/' . $recorded . '.php'));
        }

        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);
        try {
            expect(TableShape::locate($canonical))->toBe($source)
                ->and(TableName::migrationRecorded('create_widgets_table'))->toBeTrue()
                ->and(TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toBeTrue();
        } finally {
            unlink($source);
            rmdir($directory);
        }
    })->with(['canonical identity' => false, 'published from later source' => true]);

    it('recognises foundation migrations when Composer installs the aggregate instead of its splits', function (): void {
        $migration = '2026_05_10_190837_09_create_marketplace_install_attempts_table';
        DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        Schema::create('cms_marketplace_install_attempts', fn (Blueprint $table) => $table->id());

        TableShape::usingInstalledPackages(['capell-app/capell' => dirname(__DIR__, 4)], function () use ($migration): void {
            expect(TableName::migrationRecorded($migration))->toBeTrue()
                ->and(TableName::existsAndIsOwned('marketplace_install_attempts', $migration))->toBeTrue();
        });
    });

    it('excludes undeclared and non-foundation directories from aggregate source ownership', function (): void {
        $aggregate = $this->ownershipDatabasePath . '/aggregate';
        $migrations = [
            'core' => '2040_01_01_000001_create_allowed_table',
            'marketplace' => '2040_01_01_000002_create_undeclared_table',
            'untrusted' => '2040_01_01_000003_create_untrusted_table',
        ];
        foreach ($migrations as $package => $migration) {
            mkdir($aggregate . '/packages/' . $package . '/database/migrations', 0755, true);
            file_put_contents($aggregate . '/packages/' . $package . '/database/migrations/' . $migration . '.php', '<?php return new stdClass;');
            DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        }

        file_put_contents($aggregate . '/composer.json', json_encode(['replace' => ['capell-app/core' => 'self.version', 'capell-app/untrusted' => 'self.version']], JSON_THROW_ON_ERROR));
        try {
            TableShape::usingInstalledPackages(['capell-app/capell' => $aggregate], function () use ($migrations): void {
                expect(TableName::migrationRecorded($migrations['core']))->toBeTrue()
                    ->and(TableName::migrationRecorded($migrations['marketplace']))->toBeFalse()
                    ->and(TableName::migrationRecorded($migrations['untrusted']))->toBeFalse();
            });
        } finally {
            foreach ($migrations as $package => $migration) {
                $directory = $aggregate . '/packages/' . $package;
                unlink($directory . '/database/migrations/' . $migration . '.php');
                rmdir($directory . '/database/migrations');
                rmdir($directory . '/database');
                rmdir($directory);
            }

            unlink($aggregate . '/composer.json');
            rmdir($aggregate . '/packages');
            rmdir($aggregate);
        }
    });

    it('checks source bytes for publications in a registered execution path', function (bool $genuine): void {
        $directory = $this->ownershipDatabasePath . '/registered';
        mkdir($directory);
        $recorded = '2031_01_01_000001_create_widgets_table';
        $published = $directory . '/' . $recorded . '.php';
        $source = __DIR__ . '/../fixtures/table-naming/widget-package/2026_01_01_000001_create_widgets_table.php';
        file_put_contents($published, $genuine ? file_get_contents($source) : '<?php return new stdClass;');
        resolve('migrator')->path($directory);
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);

        try {
            expect(TableName::migrationRecorded('create_widgets_table'))->toBe($genuine);
            if ($genuine) {
                expect(TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toBeTrue();
            } else {
                expect(fn (): bool => TableName::existsAndIsOwned('widgets', '2026_01_01_000001_create_widgets_table'))->toThrow(ForeignTableException::class);
            }
        } finally {
            unlink($published);
            rmdir($directory);
        }
    })->with(['genuine source' => true, 'foreign source' => false]);

    it('recognises an external vendor publication from its original or configured source', function (bool $configured): void {
        $source = file_get_contents(dirname(__DIR__, 4) . '/vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub');
        expect($source)->not->toBeFalse();
        $published = $configured ? PrepareVendorTableMigrationAction::run('create_media_table', $source) : $source;
        file_put_contents(database_path('migrations/2030_01_01_000001_create_media_table.php'), $published);
        DB::table('migrations')->insert(['migration' => '2030_01_01_000001_create_media_table', 'batch' => 1]);
        Schema::create('library_media', fn (Blueprint $table) => $table->id());
        config()->set('capell.database.external_tables.media', 'library_media');

        expect(TableName::migrationRecorded('create_media_table'))->toBeTrue()
            ->and(TableName::externalExistsAndIsOwned('library_media', 'create_media_table'))->toBeTrue();
    })->with(['original' => false, 'configured by Capell' => true]);

    it('does not trust a published copy after its source is changed', function (): void {
        $recorded = '2030_01_01_000001_create_widgets_table';
        $path = database_path('migrations/' . $recorded . '.php');
        copy(__DIR__ . '/../fixtures/table-naming/widget-package/2026_01_01_000001_create_widgets_table.php', $path);
        DB::table('migrations')->insert(['migration' => $recorded, 'batch' => 1]);
        expect(TableName::migrationRecorded('create_widgets_table'))->toBeTrue();

        file_put_contents($path, "\n// Host changed the migration.\n", FILE_APPEND);
        expect(TableName::migrationRecorded('create_widgets_table'))->toBeFalse();
    });

    it('does not allow a recorded migration absent from known package sources', function (): void {
        DB::table('migrations')->insert(['migration' => '2030_01_01_000001_create_foreign_table', 'batch' => 1]);
        file_put_contents(database_path('migrations/2030_01_01_000001_create_foreign_table.php'), '<?php return new stdClass;');

        expect(TableName::migrationRecorded('2030_01_01_000001_create_foreign_table'))->toBeFalse();
    });

    it('uses the schema connection ledger rather than a migrator pinned elsewhere', function (): void {
        $migration = '2026_01_01_000001_create_widgets_table';
        DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
        Schema::connection('ownership_secondary')->create('content_widgets', fn (Blueprint $table) => $table->id());
        resolve('migrator')->getRepository()->setSource('ownership_primary');

        try {
            expect(TableName::migrationRecorded($migration, 'ownership_secondary'))->toBeFalse()
                ->and(fn (): bool => AssertTableOwnershipAction::run('widgets', $migration, null, 'ownership_secondary'))->toThrow(ForeignTableException::class);
            DB::setDefaultConnection('ownership_secondary');
            expect(TableName::migrationRecorded($migration))->toBeFalse();
            DB::connection('ownership_secondary')->table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
            expect(TableName::existsAndIsOwned('widgets', $migration, 'ownership_secondary'))->toBeTrue()
                ->and(TableName::externalExistsAndIsOwned('content_widgets', $migration, 'example.tables.widgets', 'ownership_secondary'))->toBeTrue();
        } finally {
            resolve('migrator')->getRepository()->setSource(null);
        }
    });

    it('preserves nested scratch ledgers without lending them to an explicit connection', function (): void {
        $migration = '2026_01_01_000001_create_widgets_table';
        $scratch = new DatabaseMigrationRepository(DB::getFacadeRoot(), 'migrations');
        $scratch->setSource('ownership_secondary');
        $scratch->log($migration, 1);

        $empty = new DatabaseMigrationRepository(DB::getFacadeRoot(), 'migrations');
        $empty->setSource('ownership_primary');

        TableName::usingLedger($scratch, function () use ($migration, $empty): void {
            expect(TableName::migrationRecorded($migration))->toBeTrue()
                ->and(TableName::migrationRecorded($migration, 'ownership_primary'))->toBeFalse();
            TableName::usingLedger($empty, function () use ($migration): void {
                expect(TableName::migrationRecorded($migration))->toBeFalse();
            });
            expect(TableName::migrationRecorded($migration))->toBeTrue();
        });
        expect(TableName::migrationRecorded($migration))->toBeFalse();
    });
});
