<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareEnvironmentAction
{
    use AsFake;
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step('Preparing environment…');

        EnsureDatabaseExistsAction::run($reporter);

        Artisan::call('storage:link');
        $reporter->report('✓ Storage linked');

        // Migration files first: that signal comes from registered migration
        // paths, which are set up by service providers and are therefore stable,
        // whereas hasTable() depends on whatever state the database is in.
        if (! $this->sessionsMigrationExists() && ! Schema::hasTable('sessions')) {
            Artisan::call('session:table');
            $reporter->report('✓ Session table created');
        }

        if (! Schema::hasTable('notifications') && ! $this->notificationsMigrationExists()) {
            Artisan::call('notifications:table');
            $reporter->report('✓ Notifications table created');
        }
    }

    /**
     * Whether some migration already creates the sessions table.
     *
     * Detection is by content, not filename. Every Laravel 11+ skeleton — the
     * Capell skeleton included — creates `sessions` inside
     * `0001_01_01_000000_create_users_table.php`, so a `*_create_sessions_table`
     * glob (which is all `session:table` itself checks) misses it and the
     * command happily generates a second migration for the same table. The
     * duplicate then fails with "table sessions already exists" once both run.
     */
    private function sessionsMigrationExists(): bool
    {
        // The migrator's own registered paths are the authoritative set of
        // directories whose migrations will actually run, so ask it rather than
        // guessing at conventional locations — base_path('migrations') resolves
        // differently under Testbench than it does in an application.
        $searchPaths = [
            database_path('migrations'),
            base_path('migrations'),
            ...resolve('migrator')->paths(),
        ];

        foreach ($searchPaths as $searchPath) {
            $migrationFiles = glob($searchPath . '/*.php');

            if ($migrationFiles === false) {
                continue;
            }

            foreach ($migrationFiles as $migrationFile) {
                $contents = @file_get_contents($migrationFile);

                if (! is_string($contents)) {
                    continue;
                }

                if (preg_match('/Schema::create\(\s*[\'"]sessions[\'"]/', $contents) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function notificationsMigrationExists(): bool
    {
        $publishedMigrations = glob(database_path('migrations/*create_notifications_table.php'));

        if ($publishedMigrations !== false && $publishedMigrations !== []) {
            return true;
        }

        $packageMigrations = glob(base_path('vendor/capell-app/*/database/migrations/*create_notifications_table.php'));

        return $packageMigrations !== false && $packageMigrations !== [];
    }
}
