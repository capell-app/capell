<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('invokes migrate --force', function (): void {
    $calls = [];
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->andReturnUsing(function (string $command, array $parameters = []) use (&$calls): int {
        $calls[] = [$command, $parameters];

        return 0;
    });
    $kernel->shouldReceive('output')->andReturn('Migrated');
    $this->app->instance(Kernel::class, $kernel);

    $result = RunDatabaseMigrationsAction::run();

    expect($result->exitCode)->toBe(0)
        ->and($result->output)->toContain('Migrated')
        ->and($calls)->toBe([['migrate', [
            '--force' => true,
            '--path' => dirname(__DIR__, 4) . '/database/migrations',
            '--realpath' => true,
        ]]]);
});

it('dry-run skips artisan', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldNotReceive('call');

    $this->app->instance(Kernel::class, $kernel);

    $result = RunDatabaseMigrationsAction::run(dryRun: true);

    expect($result->output)->toContain('[dry-run]');
});

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

    $migrationRepository = Mockery::mock(Migrator::class);
    $migrationRepository->shouldReceive('paths')->once()->andReturn([$databaseMigrationPath]);
    $migrationRepository->shouldReceive('getMigrationFiles')->once()->andReturn([]);
    $ledger = Mockery::mock(MigrationRepositoryInterface::class);
    $ledger->shouldReceive('repositoryExists')->andReturn(false);
    $migrationRepository->shouldReceive('getRepository')->andReturn($ledger);
    $this->app->instance('migrator', $migrationRepository);

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('migrate', [
        '--force' => true,
        '--path' => dirname(__DIR__, 4) . '/database/migrations',
        '--realpath' => true,
    ])->andReturn(0);
    $kernel->shouldReceive('output')->andReturn('Migrated');
    $this->app->instance(Kernel::class, $kernel);

    RunDatabaseMigrationsAction::run();

    expect(File::exists($publishedMigrationPath))->toBeFalse();
});
