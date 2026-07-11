<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunMigrationsAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\NullProgressReporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

it('leaves published migration duplicates for the migrate command to handle', function (): void {
    foreach (['settings', 'permissions', 'activity_log', 'media', 'languages', 'users'] as $table) {
        if (Schema::hasTable($table)) {
            Schema::drop($table);
        }
    }

    Schema::create('settings', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('permissions', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('event')->nullable();
        $table->uuid('batch_uuid')->nullable();
    });

    Schema::create('media', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('languages', function (Blueprint $table): void {
        $table->id();
    });

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });

    expect(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasColumn('activity_log', 'event'))->toBeTrue()
        ->and(Schema::hasColumn('activity_log', 'batch_uuid'))->toBeTrue()
        ->and(Schema::hasTable('media'))->toBeTrue()
        ->and(Schema::hasTable('languages'))->toBeTrue();

    $databaseMigrationPath = database_path('migrations');
    $packagePath = base_path('tests/fixtures/run-migrations-package');
    $packageMigrationDirectory = $packagePath . '/database/migrations';
    File::ensureDirectoryExists($databaseMigrationPath);
    File::ensureDirectoryExists($packageMigrationDirectory);

    $migrationToken = str_replace('.', '_', uniqid('run_migrations_', true));

    $duplicateVendorMigrationPaths = [
        $databaseMigrationPath . '/2026_01_01_000000_create_settings_' . $migrationToken . '_table.php',
        $databaseMigrationPath . '/2026_01_01_000001_create_permission_' . $migrationToken . '_tables.php',
        $databaseMigrationPath . '/2026_01_01_000002_create_activity_log_' . $migrationToken . '_table.php',
        $databaseMigrationPath . '/2026_01_01_000003_create_media_' . $migrationToken . '_table.php',
        $databaseMigrationPath . '/2026_01_01_000004_add_event_column_to_activity_log_' . $migrationToken . '_table.php',
        $databaseMigrationPath . '/2026_01_01_000005_add_batch_uuid_column_to_activity_log_' . $migrationToken . '_table.php',
    ];
    $applicationMigrationPath = $databaseMigrationPath . '/2026_01_01_000006_create_users_' . $migrationToken . '_table.php';
    $duplicateCapellMigrationPath = $databaseMigrationPath . '/2026_01_01_000007_create_languages_' . $migrationToken . '_table.php';
    $packageMigrationPath = $packageMigrationDirectory . '/2026_01_01_000008_create_package_install_jobs_' . $migrationToken . '_table.php';
    $publishedPackageMigrationPath = $databaseMigrationPath . '/2026_01_01_000009_create_package_install_jobs_' . $migrationToken . '_table.php';
    $ranMigrations = [
        '2025_01_01_000000_create_settings_' . $migrationToken . '_table',
        '2025_01_01_000001_create_permission_' . $migrationToken . '_tables',
        '2025_01_01_000002_create_activity_log_' . $migrationToken . '_table',
        '2025_01_01_000003_create_media_' . $migrationToken . '_table',
        '2025_01_01_000004_add_event_column_to_activity_log_' . $migrationToken . '_table',
        '2025_01_01_000005_add_batch_uuid_column_to_activity_log_' . $migrationToken . '_table',
        '2025_01_01_000006_create_users_' . $migrationToken . '_table',
        '2025_01_01_000007_create_languages_' . $migrationToken . '_table',
        '2025_01_01_000009_create_package_install_jobs_' . $migrationToken . '_table',
    ];

    foreach ([...$duplicateVendorMigrationPaths, $duplicateCapellMigrationPath, $applicationMigrationPath, $publishedPackageMigrationPath] as $publishedMigrationPath) {
        File::put($publishedMigrationPath, '<?php declare(strict_types=1);');
    }

    File::put($packageMigrationPath, '<?php declare(strict_types=1);');
    foreach ($ranMigrations as $ranMigration) {
        DB::table('migrations')->insert([
            'migration' => $ranMigration,
            'batch' => 1,
        ]);
    }

    CapellCore::registerPackage('vendor/run-migrations-package', path: $packagePath);

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
    $kernel->shouldReceive('output')->andReturn('Nothing to migrate');
    $this->app->instance(Kernel::class, $kernel);

    try {
        RunMigrationsAction::run(new NullProgressReporter);

        $duplicateMigrationPaths = [
            ...$duplicateVendorMigrationPaths,
            $duplicateCapellMigrationPath,
            $applicationMigrationPath,
            $publishedPackageMigrationPath,
        ];

        $remainingMigrationPaths = array_values(array_filter(
            $duplicateMigrationPaths,
            fn (string $publishedMigrationPath): bool => File::exists($publishedMigrationPath),
        ));

        expect($remainingMigrationPaths)->toHaveCount(count($duplicateMigrationPaths));
    } finally {
        DB::table('migrations')->whereIn('migration', $ranMigrations)->delete();

        foreach ([...$duplicateVendorMigrationPaths, $duplicateCapellMigrationPath, $applicationMigrationPath, $publishedPackageMigrationPath] as $publishedMigrationPath) {
            File::delete($publishedMigrationPath);
        }

        File::deleteDirectory($packagePath);

        foreach (['settings', 'permissions', 'activity_log', 'media', 'languages', 'users'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
});

it('leaves existing create migrations for the migrate command to handle', function (): void {
    if (! Schema::hasTable('marketplace_registration_sessions')) {
        Schema::create('marketplace_registration_sessions', function (Blueprint $table): void {
            $table->id();
        });
    }

    $databaseMigrationPath = database_path('migrations');
    File::ensureDirectoryExists($databaseMigrationPath);

    $publishedMigrationPath = $databaseMigrationPath . '/2026_05_10_190837_04_create_marketplace_registration_sessions_table.php';
    File::put($publishedMigrationPath, '<?php declare(strict_types=1);');

    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')->once()->with('migrate', ['--force' => true])->andReturn(0);
    $kernel->shouldReceive('output')->andReturn('Nothing to migrate');
    $this->app->instance(Kernel::class, $kernel);

    try {
        RunMigrationsAction::run(new NullProgressReporter);

        expect(File::exists($publishedMigrationPath))->toBeTrue();
    } finally {
        File::delete($publishedMigrationPath);
        Schema::dropIfExists('marketplace_registration_sessions');
    }
});

it('can run only database migrations before settings migrations are ready', function (): void {
    $kernel = Mockery::mock(Kernel::class);
    $kernel->shouldReceive('call')
        ->once()
        ->with('migrate', [
            '--force' => true,
            '--path' => database_path('migrations'),
            '--realpath' => true,
        ])
        ->andReturn(0);
    $kernel->shouldReceive('output')->andReturn('Nothing to migrate');
    $this->app->instance(Kernel::class, $kernel);

    RunMigrationsAction::run(new NullProgressReporter, includeSettings: false);
});
