<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\PublishVendorMigrationsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Support\Install\NullProgressReporter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function withIsolatedVendorMigrationFiles(string $migrationSuffix, Closure $callback): mixed
{
    $migrationDirectory = database_path('migrations');
    File::ensureDirectoryExists($migrationDirectory);

    $originalMigrationRows = DB::table('migrations')
        ->where('migration', 'like', '%_' . $migrationSuffix)
        ->get(['migration', 'batch'])
        ->map(fn (stdClass $migration): array => [
            'migration' => $migration->migration,
            'batch' => $migration->batch,
        ])
        ->all();

    DB::table('migrations')
        ->where('migration', 'like', '%_' . $migrationSuffix)
        ->delete();

    $originalFiles = [];
    foreach (File::glob($migrationDirectory . '/*_' . $migrationSuffix . '.php') ?? [] as $migrationPath) {
        $originalFiles[$migrationPath] = File::get($migrationPath);
        File::delete($migrationPath);
    }

    try {
        return $callback($migrationDirectory);
    } finally {
        foreach (File::glob($migrationDirectory . '/*_' . $migrationSuffix . '.php') ?? [] as $migrationPath) {
            File::delete($migrationPath);
        }

        foreach ($originalFiles as $migrationPath => $contents) {
            File::put($migrationPath, $contents);
        }

        DB::table('migrations')
            ->where('migration', 'like', '%_' . $migrationSuffix)
            ->delete();

        foreach ($originalMigrationRows as $migrationRow) {
            DB::table('migrations')->insert($migrationRow);
        }
    }
}

function withIsolatedVendorDependencyMigrationFiles(Closure $callback): mixed
{
    $migrationDirectory = database_path('migrations');
    File::ensureDirectoryExists($migrationDirectory);

    $migrationSuffixes = [
        'create_settings_table',
        'create_permission_tables',
        'create_activity_log_table',
        'create_media_table',
        'create_tag_tables',
        'add_event_column_to_activity_log_table',
        'add_batch_uuid_column_to_activity_log_table',
    ];

    $originalMigrationRows = DB::table('migrations')
        ->where(function (Builder $query) use ($migrationSuffixes): void {
            foreach ($migrationSuffixes as $migrationSuffix) {
                $query->orWhere('migration', 'like', '%_' . $migrationSuffix);
            }
        })
        ->get(['migration', 'batch'])
        ->map(fn (stdClass $migration): array => [
            'migration' => $migration->migration,
            'batch' => $migration->batch,
        ])
        ->all();

    $originalFiles = [];
    foreach ($migrationSuffixes as $migrationSuffix) {
        DB::table('migrations')
            ->where('migration', 'like', '%_' . $migrationSuffix)
            ->delete();

        foreach (File::glob($migrationDirectory . '/*_' . $migrationSuffix . '.php') ?? [] as $migrationPath) {
            $originalFiles[$migrationPath] = File::get($migrationPath);
            File::delete($migrationPath);
        }
    }

    try {
        return $callback($migrationDirectory);
    } finally {
        foreach ($migrationSuffixes as $migrationSuffix) {
            foreach (File::glob($migrationDirectory . '/*_' . $migrationSuffix . '.php') ?? [] as $migrationPath) {
                File::delete($migrationPath);
            }

            DB::table('migrations')
                ->where('migration', 'like', '%_' . $migrationSuffix)
                ->delete();
        }

        foreach ($originalFiles as $migrationPath => $contents) {
            File::put($migrationPath, $contents);
        }

        foreach ($originalMigrationRows as $migrationRow) {
            DB::table('migrations')->insert($migrationRow);
        }
    }
}

it('does not publish authentication log vendor migrations from core', function (): void {
    $reporter = new class implements ProgressReporter
    {
        /** @var list<string> */
        public array $messages = [];

        public function step(string $message): void
        {
            $this->messages[] = $message;
        }

        public function report(string $message): void
        {
            $this->messages[] = $message;
        }

        public function error(string $message): void
        {
            $this->messages[] = $message;
        }
    };

    withIsolatedVendorDependencyMigrationFiles(function () use ($reporter): void {
        PublishVendorMigrationsAction::run($reporter);
    });

    expect(collect($reporter->messages)->implode("\n"))
        ->not->toContain('Auth log')
        ->not->toContain('login_audit');
});

it('normalizes a pending Spatie media migration to Capell canonical order', function (): void {
    withIsolatedVendorDependencyMigrationFiles(function (string $migrationDirectory): void {
        $spatiePublishedMigrationPath = $migrationDirectory . '/2035_01_01_000000_create_media_table.php';
        $canonicalMigrationPath = $migrationDirectory . '/2026_05_10_190826_create_media_table.php';

        File::put($spatiePublishedMigrationPath, "<?php\n\ndeclare(strict_types=1);\n");

        PublishVendorMigrationsAction::run(new NullProgressReporter);

        expect(File::exists($spatiePublishedMigrationPath))->toBeFalse()
            ->and(File::exists($canonicalMigrationPath))->toBeTrue()
            ->and(File::get($canonicalMigrationPath))->toContain('declare(strict_types=1)');
    });
});

it('leaves a Spatie media migration alone when it already ran', function (): void {
    withIsolatedVendorDependencyMigrationFiles(function (string $migrationDirectory): void {
        $spatiePublishedMigrationPath = $migrationDirectory . '/2035_01_01_000000_create_media_table.php';
        $canonicalMigrationPath = $migrationDirectory . '/2026_05_10_190826_create_media_table.php';

        File::put($spatiePublishedMigrationPath, "<?php\n\ndeclare(strict_types=1);\n");

        DB::table('migrations')->insert([
            'migration' => '2035_01_01_000000_create_media_table',
            'batch' => 1,
        ]);

        try {
            PublishVendorMigrationsAction::run(new NullProgressReporter);

            expect(File::exists($spatiePublishedMigrationPath))->toBeTrue()
                ->and(File::exists($canonicalMigrationPath))->toBeFalse();
        } finally {
            DB::table('migrations')
                ->where('migration', '2035_01_01_000000_create_media_table')
                ->delete();
        }
    });
});

it('does not republish a canonical vendor migration when a matching migration row already exists', function (): void {
    withIsolatedVendorDependencyMigrationFiles(function (string $migrationDirectory): void {
        $canonicalMigrationPath = $migrationDirectory . '/2026_05_10_190826_create_media_table.php';

        DB::table('migrations')->insert([
            'migration' => '2035_01_01_000000_create_media_table',
            'batch' => 1,
        ]);

        try {
            PublishVendorMigrationsAction::run(new NullProgressReporter);

            expect(File::exists($canonicalMigrationPath))->toBeFalse();
        } finally {
            DB::table('migrations')
                ->where('migration', '2035_01_01_000000_create_media_table')
                ->delete();
        }
    });
});

it('removes a pending canonical vendor migration when a non-canonical one already ran', function (): void {
    withIsolatedVendorDependencyMigrationFiles(function (string $migrationDirectory): void {
        $spatiePublishedMigrationPath = $migrationDirectory . '/2035_01_01_000000_create_media_table.php';
        $canonicalMigrationPath = $migrationDirectory . '/2026_05_10_190826_create_media_table.php';

        File::put($spatiePublishedMigrationPath, "<?php\n\ndeclare(strict_types=1);\n");
        File::put($canonicalMigrationPath, "<?php\n\ndeclare(strict_types=1);\n");

        DB::table('migrations')->insert([
            'migration' => '2035_01_01_000000_create_media_table',
            'batch' => 1,
        ]);

        try {
            PublishVendorMigrationsAction::run(new NullProgressReporter);

            expect(File::exists($spatiePublishedMigrationPath))->toBeTrue()
                ->and(File::exists($canonicalMigrationPath))->toBeFalse();
        } finally {
            DB::table('migrations')
                ->where('migration', '2035_01_01_000000_create_media_table')
                ->delete();
        }
    });
});

it('publishes activity log column migrations when the base activity log migration already exists', function (): void {
    $migrationDirectory = database_path('migrations');
    File::ensureDirectoryExists($migrationDirectory);

    $eventColumnMigrations = File::glob($migrationDirectory . '/*_add_event_column_to_activity_log_table.php') ?? [];
    $batchUuidColumnMigrations = File::glob($migrationDirectory . '/*_add_batch_uuid_column_to_activity_log_table.php') ?? [];
    $activityLogColumnMigrations = [
        ...$eventColumnMigrations,
        ...$batchUuidColumnMigrations,
    ];

    foreach ($activityLogColumnMigrations as $activityLogColumnMigration) {
        File::delete($activityLogColumnMigration);
    }

    $existingMigrationPath = $migrationDirectory . '/2026_01_01_000000_create_activity_log_table.php';
    File::put($existingMigrationPath, "<?php\n\ndeclare(strict_types=1);\n");

    $existingFiles = File::glob($migrationDirectory . '/*.php') ?? [];

    try {
        PublishVendorMigrationsAction::run(new NullProgressReporter);

        $currentFiles = File::glob($migrationDirectory . '/*.php') ?? [];
        $newFiles = array_values(array_diff($currentFiles, $existingFiles));
        $newFilenames = array_map(basename(...), $newFiles);

        expect(implode("\n", $newFilenames))
            ->toContain(
                'add_event_column_to_activity_log_table.php',
                'add_batch_uuid_column_to_activity_log_table.php',
            );
    } finally {
        $currentFiles = File::glob($migrationDirectory . '/*.php') ?? [];
        $filesToDelete = array_diff($currentFiles, $existingFiles);

        foreach ($filesToDelete as $fileToDelete) {
            File::delete($fileToDelete);
        }

        File::delete($existingMigrationPath);
    }
});

it('publishes the media library migration before Capell media indexes run', function (): void {
    withIsolatedVendorDependencyMigrationFiles(function (string $migrationDirectory): void {
        $existingFiles = File::glob($migrationDirectory . '/*.php') ?? [];

        PublishVendorMigrationsAction::run(new NullProgressReporter);

        $currentFiles = File::glob($migrationDirectory . '/*.php') ?? [];
        $newFiles = array_values(array_diff($currentFiles, $existingFiles));
        $newFilenames = array_map(basename(...), $newFiles);

        expect(implode("\n", $newFilenames))
            ->toContain('create_media_table.php');
    });
});

it('publishes tag tables before package tag migrations run', function (): void {
    $migrationDirectory = database_path('migrations');
    $stubDirectory = base_path('vendor/spatie/laravel-tags/database/migrations');
    $stubPath = $stubDirectory . '/create_tag_tables.php.stub';
    $createdStub = false;

    File::ensureDirectoryExists($migrationDirectory);

    foreach (File::glob($migrationDirectory . '/*_create_tag_tables.php') ?? [] as $tagMigration) {
        File::delete($tagMigration);
    }

    if (! File::exists($stubPath)) {
        File::ensureDirectoryExists($stubDirectory);
        File::put($stubPath, "<?php\n\ndeclare(strict_types=1);\n");
        $createdStub = true;
    }

    $existingFiles = File::glob($migrationDirectory . '/*.php') ?? [];

    try {
        PublishVendorMigrationsAction::run(new NullProgressReporter);

        $currentFiles = File::glob($migrationDirectory . '/*.php') ?? [];
        $newFiles = array_values(array_diff($currentFiles, $existingFiles));
        $newFilenames = array_map(basename(...), $newFiles);

        expect(implode("\n", $newFilenames))
            ->toContain('create_tag_tables.php');
    } finally {
        $currentFiles = File::glob($migrationDirectory . '/*.php') ?? [];
        $filesToDelete = array_diff($currentFiles, $existingFiles);

        foreach ($filesToDelete as $fileToDelete) {
            File::delete($fileToDelete);
        }

        if ($createdStub) {
            File::delete($stubPath);
        }
    }
});
