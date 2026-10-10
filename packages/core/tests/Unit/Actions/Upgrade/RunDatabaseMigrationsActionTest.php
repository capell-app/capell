<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('upgrades the schema and records the migration while a dry run preserves both', function (bool $dryRun): void {
    $migration = '2026_08_09_000001_add_port_to_site_domains_table';
    $instance = require dirname(__DIR__, 4) . '/database/migrations/' . $migration . '.php';
    $instance->down();
    DB::table('migrations')->where('migration', $migration)->delete();

    $result = RunDatabaseMigrationsAction::run(dryRun: $dryRun);

    expect($result->exitCode)->toBe(0)
        ->and(Schema::hasColumn('site_domains', 'port'))->toBe(! $dryRun)
        ->and(DB::table('migrations')->where('migration', $migration)->exists())->toBe(! $dryRun);
    if ($dryRun) {
        expect($result->output)->toContain('[dry-run]');
    }
})->with(['apply' => false, 'dry run' => true]);

it('removes published create migrations when the target table already exists without package paths', function (): void {
    if (! Schema::hasTable('page_role_restrictions')) {
        Schema::create('page_role_restrictions', function (Blueprint $table): void {
            $table->id();
        });
    }

    $databaseMigrationPath = database_path('migrations');
    File::ensureDirectoryExists($databaseMigrationPath);

    $publishedMigrationPath = $databaseMigrationPath . '/2026_01_01_000000_create_page_role_restrictions_table.php';
    File::put($publishedMigrationPath, '<?php declare(strict_types=1);');

    $pendingMigration = '2026_08_09_000001_add_port_to_site_domains_table';
    DB::table('migrations')->where('migration', $pendingMigration)->delete();

    $migrator = new Migrator(
        resolve(Migrator::class)->getRepository(),
        resolve(DatabaseManager::class),
        resolve(Filesystem::class),
    );
    $migrator->path($databaseMigrationPath);

    app()->instance('migrator', $migrator);

    expect(RunDatabaseMigrationsAction::run()->exitCode)->toBe(0);

    expect(File::exists($publishedMigrationPath))->toBeFalse();
});
