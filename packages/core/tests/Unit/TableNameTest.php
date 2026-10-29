<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Install\PrepareVendorTableMigrationAction;
use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\EventSourcing\Models\Snapshot;
use Capell\Core\EventSourcing\Models\StoredEvent;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Term;
use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\DB;
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
