<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunMigrationsAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Support\Fakes\FakeConsoleKernel;
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

    FakeConsoleKernel::bind()->returns('migrate', 0, 'Nothing to migrate');

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

    FakeConsoleKernel::bind()->returns('migrate', 0, 'Nothing to migrate');

    try {
        RunMigrationsAction::run(new NullProgressReporter);

        expect(File::exists($publishedMigrationPath))->toBeTrue();
    } finally {
        File::delete($publishedMigrationPath);
        Schema::dropIfExists('marketplace_registration_sessions');
    }
});

it('runs only the selected migration directory', function (bool $schema): void {
    $original = database_path();
    $directory = sys_get_temp_dir() . '/capell-selected-migrations-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory . '/migrations');
    File::ensureDirectoryExists($directory . '/settings');
    $template = <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('%s', function (\Illuminate\Database\Schema\Blueprint $table): void { $table->id(); });
    }
};
PHP;
    File::put($directory . '/migrations/2035_01_01_000001_wave_schema.php', sprintf($template, 'wave_schema'));
    File::put($directory . '/settings/2035_01_01_000002_wave_settings.php', sprintf($template, 'wave_settings'));
    app()->useDatabasePath($directory);

    try {
        RunMigrationsAction::run(new NullProgressReporter, includeSettings: ! $schema, includeSchema: $schema);

        expect(Schema::hasTable('wave_schema'))->toBe($schema)
            ->and(Schema::hasTable('wave_settings'))->toBe(! $schema)
            ->and(DB::table('migrations')->where('migration', $schema ? '2035_01_01_000001_wave_schema' : '2035_01_01_000002_wave_settings')->exists())->toBeTrue();
    } finally {
        app()->useDatabasePath($original);
        File::deleteDirectory($directory);
    }
})->with(['schema only' => true, 'settings only' => false]);

it('orders registered and application schema migrations together without running settings', function (bool $registeredCreatesUsers): void {
    $originalDatabasePath = database_path();
    $migrator = resolve('migrator');
    $pathsProperty = new ReflectionProperty($migrator, 'paths');
    $originalPaths = $pathsProperty->getValue($migrator);
    $originalSettingsPaths = config('settings.migrations_paths');
    $originalSettingsPath = config('settings.migrations_path');
    $directory = sys_get_temp_dir() . '/capell-schema-order-' . bin2hex(random_bytes(8));
    foreach (['migrations', 'registered', 'settings', 'registered-settings'] as $name) {
        File::ensureDirectoryExists($directory . '/' . $name);
    }

    $create = <<<'PHPFILE'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\Schema::create('wave_order_users', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->id()); }
};
PHPFILE;
    $alter = <<<'PHPFILE'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\Schema::table('wave_order_users', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->json('admin_workspace_preferences')->nullable()); }
};
PHPFILE;
    $settings = <<<'PHPFILE'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\Schema::create('wave_order_settings', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->id()); }
};
PHPFILE;
    $createDir = $registeredCreatesUsers ? 'registered' : 'migrations';
    $alterDir = $registeredCreatesUsers ? 'migrations' : 'registered';
    File::put($directory . '/' . $createDir . '/2036_01_01_000001_create_wave_order_users.php', $create);
    File::put($directory . '/' . $alterDir . '/2036_01_01_000002_add_wave_order_preferences.php', $alter);
    File::put($directory . '/registered-settings/2036_01_01_000003_wave_order_settings.php', $settings);
    $pathsProperty->setValue($migrator, [$directory . '/registered', $directory . '/registered-settings']);
    config()->set('settings.migrations_path');
    config()->set('settings.migrations_paths', [$directory . '/registered-settings']);

    app()->useDatabasePath($directory);

    try {
        RunMigrationsAction::run(new NullProgressReporter, includeSettings: false);
        expect(Schema::hasTable('wave_order_users'))->toBeTrue()
            ->and(Schema::hasColumn('wave_order_users', 'admin_workspace_preferences'))->toBeTrue()
            ->and(Schema::hasTable('wave_order_settings'))->toBeFalse();
    } finally {
        app()->useDatabasePath($originalDatabasePath);
        $pathsProperty->setValue($migrator, $originalPaths);
        config()->set('settings.migrations_paths', $originalSettingsPaths);
        config()->set('settings.migrations_path', $originalSettingsPath);
        Schema::dropIfExists('wave_order_users');
        Schema::dropIfExists('wave_order_settings');
        DB::table('migrations')->whereIn('migration', ['2036_01_01_000001_create_wave_order_users', '2036_01_01_000002_add_wave_order_preferences', '2036_01_01_000003_wave_order_settings'])->delete();
        File::deleteDirectory($directory);
    }
})->with(['registered user migration' => true, 'application user migration' => false]);

it('prefers the application migration when its directory was registered before a package', function (bool $useAlias): void {
    $originalDatabasePath = database_path();
    $migrator = resolve('migrator');
    $pathsProperty = new ReflectionProperty($migrator, 'paths');
    $originalPaths = $pathsProperty->getValue($migrator);
    $directory = sys_get_temp_dir() . '/capell-migration-precedence-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory . '/migrations');
    File::ensureDirectoryExists($directory . '/package');
    $alias = $directory . '/application-alias';
    throw_if($useAlias && ! symlink($directory . '/migrations', $alias), RuntimeException::class, 'Could not create the migration directory alias.');

    $name = '2037_01_01_000001_create_wave_precedence.php';
    $template = <<<'PHPFILE'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\Schema::create('%s', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->id()); }
};
PHPFILE;
    File::put($directory . '/migrations/' . $name, sprintf($template, 'wave_host_precedence'));
    File::put($directory . '/package/' . $name, sprintf($template, 'wave_package_precedence'));
    $registeredPaths = $useAlias ? [$directory . '/migrations', $alias, $directory . '/package'] : [$directory . '/migrations', $directory . '/package'];
    $pathsProperty->setValue($migrator, $registeredPaths);
    app()->useDatabasePath($directory);

    try {
        RunMigrationsAction::run(new NullProgressReporter, includeSettings: false);
        expect(Schema::hasTable('wave_host_precedence'))->toBeTrue()
            ->and(Schema::hasTable('wave_package_precedence'))->toBeFalse();
    } finally {
        app()->useDatabasePath($originalDatabasePath);
        $pathsProperty->setValue($migrator, $originalPaths);
        Schema::dropIfExists('wave_host_precedence');
        Schema::dropIfExists('wave_package_precedence');
        DB::table('migrations')->where('migration', basename($name, '.php'))->delete();
        if ($useAlias) {
            unlink($alias);
        }

        File::deleteDirectory($directory);
    }
})->with(['registered application path' => false, 'registered application alias' => true]);
