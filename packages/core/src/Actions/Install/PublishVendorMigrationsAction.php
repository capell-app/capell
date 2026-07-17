<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class PublishVendorMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step('Publishing vendor migrations…');

        foreach ($this->stubs() as [$migrationName, $stubRelativePath, $targetName, $successLabel]) {
            if ($this->canonicalMigrationExists($targetName)) {
                $canonicalMigrationPath = database_path('migrations/' . $targetName);

                if (! $this->migrationFileHasRun($canonicalMigrationPath) && $this->nonCanonicalMigrationHasRun($migrationName, $targetName)) {
                    File::delete($canonicalMigrationPath);

                    continue;
                }

                $this->removePendingNonCanonicalMigrations($migrationName, $targetName);

                continue;
            }

            $existingMigrationPath = $this->pendingNonCanonicalMigrationPath($migrationName, $targetName);

            if ($existingMigrationPath !== null) {
                File::move($existingMigrationPath, database_path('migrations/' . $targetName));
                $reporter->report($successLabel);

                continue;
            }

            if ($this->migrationHasRun($migrationName)) {
                continue;
            }

            $stubPath = $this->resolveStubPath($stubRelativePath);

            if ($stubPath === null) {
                // Package not installed yet — will be published in a later step.
                continue;
            }

            $destinationPath = database_path('migrations/' . $targetName);
            File::copy($stubPath, $destinationPath);

            // Permissions stub is critical — the page_role_restrictions migration needs it.
            throw_if($migrationName === 'create_permission_tables' && ! File::exists($destinationPath), RuntimeException::class, 'Failed to publish Spatie permission migrations. '
            . 'The capell_app/page_role_restrictions migration depends on the roles table. '
            . 'Run `php artisan vendor:publish --tag=permission-migrations --force` manually and re-run the installer.');

            $reporter->report($successLabel);
        }
    }

    /**
     * Each entry: [migration-name-check, stub-relative-path, target-name, success-label].
     * Stubs that live in packages not yet required (e.g. admin) are silently
     * skipped - a second publish step runs after require-extra-packages.
     *
     * @return list<array{string, string, string, string}>
     */
    private function stubs(): array
    {
        return [
            [
                'create_settings_table',
                'vendor/spatie/laravel-settings/database/migrations/create_settings_table.php.stub',
                '2026_05_10_190823_create_settings_table.php',
                '✓ Settings migrations published',
            ],
            [
                'create_permission_tables',
                'vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub',
                '2026_05_10_190824_create_permission_tables.php',
                '✓ Permission migrations published',
            ],
            [
                'create_activity_log',
                'vendor/spatie/laravel-activitylog/database/migrations/create_activity_log_table.php.stub',
                '2026_05_10_190825_create_activity_log_table.php',
                '✓ Activity log migrations published',
            ],
            [
                'create_media_table',
                'vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub',
                '2026_05_10_190826_create_media_table.php',
                '✓ Media library migrations published',
            ],
            [
                'create_tag_tables',
                'vendor/spatie/laravel-tags/database/migrations/create_tag_tables.php.stub',
                '2026_05_10_190827_create_tag_tables.php',
                '✓ Tag migrations published',
            ],
            [
                'create_queue_monitors',
                'vendor/croustibat/filament-jobs-monitor/database/migrations/create_filament-jobs-monitor_table.php.stub',
                '2026_05_29_000001_create_queue_monitors_table.php',
                '✓ Queue monitor migrations published',
            ],
            [
                'add_event_column_to_activity_log',
                'vendor/spatie/laravel-activitylog/database/migrations/add_event_column_to_activity_log_table.php.stub',
                '2026_05_10_190828_add_event_column_to_activity_log_table.php',
                '✓ Activity log event migration published',
            ],
            [
                'add_batch_uuid_column_to_activity_log',
                'vendor/spatie/laravel-activitylog/database/migrations/add_batch_uuid_column_to_activity_log_table.php.stub',
                '2026_05_10_190829_add_batch_uuid_column_to_activity_log_table.php',
                '✓ Activity log batch migration published',
            ],
        ];
    }

    private function resolveStubPath(string $stubRelativePath): ?string
    {
        $basePathStub = base_path($stubRelativePath);
        if (File::exists($basePathStub)) {
            return $basePathStub;
        }

        $projectPathStub = realpath(__DIR__ . '/../../../../../' . $stubRelativePath);

        return $projectPathStub !== false ? $projectPathStub : null;
    }

    private function canonicalMigrationExists(string $targetName): bool
    {
        return File::exists(database_path('migrations/' . $targetName));
    }

    private function pendingNonCanonicalMigrationPath(string $name, string $targetName): ?string
    {
        foreach ($this->matchingMigrationPaths($name) as $migrationPath) {
            if (basename($migrationPath) === $targetName) {
                continue;
            }

            if (! $this->migrationFileHasRun($migrationPath)) {
                return $migrationPath;
            }
        }

        return null;
    }

    private function removePendingNonCanonicalMigrations(string $name, string $targetName): void
    {
        foreach ($this->matchingMigrationPaths($name) as $migrationPath) {
            if (basename($migrationPath) === $targetName) {
                continue;
            }

            if ($this->migrationFileHasRun($migrationPath)) {
                continue;
            }

            File::delete($migrationPath);
        }
    }

    private function migrationHasRun(string $name): bool
    {
        foreach ($this->matchingMigrationPaths($name) as $migrationPath) {
            if ($this->migrationFileHasRun($migrationPath)) {
                return true;
            }
        }

        if (! Schema::hasTable('migrations')) {
            return false;
        }

        $filename = $this->migrationFilename($name);

        return DB::table('migrations')
            ->pluck('migration')
            ->contains(
                fn (mixed $migration): bool => preg_match(
                    '/^\d{4}_\d{2}_\d{2}_\d{6}_' . preg_quote($filename, '/') . '$/',
                    (string) $migration,
                ) === 1,
            );
    }

    private function nonCanonicalMigrationHasRun(string $name, string $targetName): bool
    {
        foreach ($this->matchingMigrationPaths($name) as $migrationPath) {
            if (basename($migrationPath) === $targetName) {
                continue;
            }

            if ($this->migrationFileHasRun($migrationPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function matchingMigrationPaths(string $name): array
    {
        $className = Str::studly($name);
        if (class_exists($className)) {
            return [];
        }

        $filename = $this->migrationFilename($name);

        $found = glob(database_path('migrations/*.php'));
        $files = $found !== false ? $found : [];

        $matchingFiles = [];
        foreach ($files as $file) {
            if (preg_match(
                '/^\d{4}_\d{2}_\d{2}_\d{6}_' . preg_quote($filename, '/') . '\.php$/',
                basename($file),
            )) {
                $matchingFiles[] = $file;
            }
        }

        sort($matchingFiles);

        return $matchingFiles;
    }

    private function migrationFileHasRun(string $migrationPath): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')
            ->where('migration', pathinfo($migrationPath, PATHINFO_FILENAME))
            ->exists();
    }

    private function migrationFilename(string $name): string
    {
        $filename = Str::snake($name);
        if (! str_ends_with($filename, '_table') && ! str_ends_with($filename, '_tables')) {
            $filename .= '_table';
        }

        return $filename;
    }
}
