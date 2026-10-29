<?php

declare(strict_types=1);

use Capell\Core\Actions\Diagnostics\ResolveCapellInstallationStateAction;
use Capell\Core\Actions\Install\FindTableCollisionsAction;
use Capell\Core\Actions\Install\ResolveTableCollisionsAction;
use Capell\Core\Actions\Install\WriteTableConfigurationAction;
use Capell\Core\Actions\Upgrade\ReadLatestInstalledVersionsAction;
use Capell\Core\Data\Install\TableCollisionData;
use Capell\Core\Enums\Diagnostics\CapellInstallationState;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Support\Diagnostics\CapellRuntimeSchemaContract;
use Capell\Core\Support\Migration\CoreSchemaMigrations;
use Capell\Core\Support\Migration\MigrationRootRegistry;
use Capell\Core\Support\TableName;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->previousConnection = DB::getDefaultConnection();
    config()->set('database.connections.naming_review', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
    config()->set('capell.database.table_prefix', 'cms_');
    config()->set('capell.database.table_aliases', []);
    DB::setDefaultConnection('naming_review');
});

afterEach(function (): void {
    DB::setDefaultConnection($this->previousConnection);
    DB::purge('naming_review');
});

it('reads latest version snapshots from a prefixed upgrade log', function (): void {
    $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_23_create_capell_upgrade_log_table.php';
    $migration->up();
    DB::table('cms_capell_upgrade_log')->insert([
        ['type' => 'version_snapshot', 'key' => 'capell-app/core', 'status' => 'success', 'ran_at' => '2026-01-01 00:00:00', 'meta' => json_encode(['to_version' => '1.0'])],
        ['type' => 'version_snapshot', 'key' => 'capell-app/core', 'status' => 'success', 'ran_at' => '2026-02-01 00:00:00', 'meta' => json_encode(['to_version' => '2.0'])],
    ]);
    expect(resolve(ReadLatestInstalledVersionsAction::class)->handle(['capell-app/core']))->toBe(['capell-app/core' => '2.0']);
});

it('ignores a foreign footprint and resolves required tables', function (): void {
    Schema::create('pages', fn (Blueprint $table) => $table->id());
    $contract = resolve(CapellRuntimeSchemaContract::class);
    expect($contract->hasFootprint())->toBeFalse()
        ->and($contract->requiredTables())->toContain('cms_pages')
        ->and($contract->missingTables())->not->toContain('pages');
});

it('does not adopt a foreign table during aliased migration recovery', function (): void {
    config()->set('capell.database.table_prefix', '');
    config()->set('capell.database.table_aliases.pages', 'content_pages');
    Schema::create('pages', fn (Blueprint $table) => $table->id());
    expect(CoreSchemaMigrations::createsExistingTable('2026_05_10_190832_12_create_pages_table'))->toBeFalse();
});

it('rejects unsafe or oversized resolved identifiers', function (string $value): void {
    config()->set('capell.database.table_aliases.pages', $value);
    expect(fn (): string => TableName::resolve('pages'))->toThrow(RuntimeException::class);
})->with(['qualified' => ['otherdb.pages'], 'too long' => [str_repeat('a', 64)]]);

it('reports collisions from a registered package before migrations run', function (): void {
    config()->set('capell.database.table_prefix', '');
    Schema::create('widgets', fn (Blueprint $table) => $table->id());
    $registry = resolve(MigrationRootRegistry::class);
    $registry->register('example/widgets', __DIR__ . '/../fixtures/table-naming/widget-package', ['2026_01_01_000001_create_widgets_table' => 'widgets']);

    $collisions = resolve(FindTableCollisionsAction::class)->handle(['example/widgets']);
    expect(collect($collisions)->pluck('table')->all())->toContain('widgets')
        ->and(resolve('migrator')->getRepository()->repositoryExists())->toBeFalse();
});

it('rejects a prefix that makes a non-colliding planned table exceed PostgreSQL limits', function (): void {
    $collision = new TableCollisionData('pages', 'pages', 'capell.database.table_aliases.pages', 'create_pages_table');
    $long = new TableCollisionData(str_repeat('x', 60), str_repeat('x', 60), 'capell.database.table_aliases.long', 'create_long_table');
    expect(fn () => resolve(ResolveTableCollisionsAction::class)->handle([$collision], 'cms_', [], [$collision, $long]))->toThrow(RuntimeException::class);
});

it('restores every published config when a staged rename fails', function (): void {
    $directory = base_path('tmp/config-atomic-' . bin2hex(random_bytes(4)));
    mkdir($directory, 0755, true);
    $capell = $directory . '/capell.php';
    $tags = $directory . '/tags.php';
    $originalCapell = "<?php return ['database' => ['external_tables' => []]];";
    $originalTags = "<?php return ['taggable' => ['table_name' => 'taggables']];";
    file_put_contents($capell, $originalCapell);
    file_put_contents($tags, $originalTags);
    chmod($capell, 0644);
    $filesystem = resolve(Filesystem::class);
    $moves = 0;
    File::partialMock()->shouldReceive('move')->andReturnUsing(function (string $from, string $to) use ($filesystem, &$moves): bool {
        $moves++;

        return $moves === 2 ? false : $filesystem->move($from, $to);
    });
    try {
        expect(fn () => resolve(WriteTableConfigurationAction::class)->handle([
            'capell.database.external_tables.tags' => 'content_tags',
            'tags.taggable.table_name' => 'content_taggables',
        ], $directory))->toThrow(RuntimeException::class)
            ->and(file_get_contents($capell))->toBe($originalCapell)
            ->and(file_get_contents($tags))->toBe($originalTags)
            ->and(fileperms($capell) & 0777)->toBe(0644);
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
});

it('reads version snapshots when Laravel also prefixes physical tables', function (): void {
    DB::connection()->setTablePrefix('dbp_');
    $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_23_create_capell_upgrade_log_table.php';
    $migration->up();
    DB::table('cms_capell_upgrade_log')->insert(['type' => 'version_snapshot', 'key' => 'capell-app/core', 'status' => 'success', 'ran_at' => '2026-02-01 00:00:00', 'meta' => json_encode(['to_version' => '2.0'])]);
    expect(resolve(ReadLatestInstalledVersionsAction::class)->handle(['capell-app/core']))->toBe(['capell-app/core' => '2.0']);
});

it('preserves Laravel connection prefixes in the restriction morph index', function (): void {
    config()->set('capell.database.table_prefix', '');
    config()->set('database.connections.naming_review.prefix_indexes', true);
    DB::purge('naming_review');
    DB::connection()->setTablePrefix('dbp_');
    $migration = require __DIR__ . '/../../database/migrations/2026_05_10_190832_20_create_page_role_restrictions_table.php';
    $migration->up();

    $legacy = 'dbp_page_role_restrictions_restrictable_type_restrictable_id_index';
    $expected = substr($legacy, 0, 54) . '_' . substr(hash('sha256', $legacy), 0, 8);
    expect(Schema::hasIndex('page_role_restrictions', $expected))->toBeTrue()->and(strlen($expected))->toBe(63);
});

it('recognises a complete prefixed installation with recorded ownership', function (): void {
    $tables = ['cms_languages', 'cms_blueprints', 'cms_sites', 'cms_site_domains', 'cms_translations', 'cms_pages', 'cms_page_urls', 'cms_asset_attachments', 'cms_content_graph_edges', 'cms_content_locks', 'cms_block_templates', 'cms_layout_content_snapshots', 'cms_blueprint_schema_snapshots', 'cms_capell_extensions', 'cms_page_workflow_states', 'cms_page_revisions', 'cms_themes', 'cms_layouts', 'stored_events', 'snapshots'];
    foreach ($tables as $table) {
        Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
    }

    $repository = resolve('migrator')->getRepository();
    $repository->createRepository();
    $repository->log('2026_05_10_190832_12_create_pages_table', 1);
    $repository->log('2026_05_10_190832_24_create_capell_extensions_table', 1);

    $state = resolve(ResolveCapellInstallationStateAction::class)->handle(
        coreStatus: ExtensionStatusEnum::Enabled,
        coreRecorded: true,
    );
    expect($state)->toBe(CapellInstallationState::Installed);
});

it('checks all tables from a registered multi-table migration', function (): void {
    config()->set('capell.database.table_prefix', '');
    Schema::create('widgets', fn (Blueprint $table) => $table->id());
    Schema::create('widget_items', fn (Blueprint $table) => $table->id());
    resolve(MigrationRootRegistry::class)->register('example/widgets', __DIR__ . '/../fixtures/table-naming/widget-package', ['2026_01_01_000002_create_widget_tables' => ['widgets', 'widget_items']]);
    $collisions = resolve(FindTableCollisionsAction::class)->handle(['example/widgets']);
    expect(collect($collisions)->pluck('table')->all())->toContain('widgets', 'widget_items');
});

it('does not report a foreign pages-only host as a partial Capell installation', function (): void {
    config()->set('capell.database.table_prefix', '');
    Schema::create('pages', fn (Blueprint $table) => $table->id());
    $state = resolve(ResolveCapellInstallationStateAction::class)->handle();
    expect($state)->toBe(CapellInstallationState::NotInstalled);
});

it('retains the original config backup if rollback fails', function (bool $throws): void {
    $directory = base_path('tmp/config-rollback-' . bin2hex(random_bytes(4)));
    mkdir($directory, 0755, true);
    $capell = $directory . '/capell.php';
    $tags = $directory . '/tags.php';
    $original = "<?php return ['database' => ['external_tables' => []]];";
    file_put_contents($capell, $original);
    file_put_contents($tags, "<?php return ['taggable' => ['table_name' => 'taggables']];");
    $filesystem = resolve(Filesystem::class);
    $moves = 0;
    File::partialMock()->shouldReceive('move')->andReturnUsing(function (string $from, string $to) use ($filesystem, &$moves, $throws): bool {
        $moves++;
        throw_if($moves === 3 && $throws, RuntimeException::class, 'Rollback move failed');

        return in_array($moves, [2, 3], true) ? false : $filesystem->move($from, $to);
    });
    try {
        expect(fn () => resolve(WriteTableConfigurationAction::class)->handle([
            'capell.database.external_tables.tags' => 'content_tags',
            'tags.taggable.table_name' => 'content_taggables',
        ], $directory))->toThrow(RuntimeException::class, 'backup');
        $backups = glob($directory . '/capell-backup-*') ?: [];
        expect($backups)->not->toBeEmpty()
            ->and(array_map(file_get_contents(...), $backups))->toContain($original);
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
})->with([false, true]);
