<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Events\DatabaseSchemaChanged;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class RunMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(
        ProgressReporter $reporter,
        bool $includeSettings = true,
        bool $includeSchema = true,
    ): void {
        if (! $includeSchema && ! $includeSettings) {
            return;
        }

        $reporter->step('Running migrations…');

        try {
            $parameters = ['--force' => true];
            if ($includeSchema !== $includeSettings) {
                $parameters['--path'] = $includeSchema ? $this->schemaMigrationPaths() : database_path('settings');
                $parameters['--realpath'] = true;
            }

            $exitCode = Artisan::call('migrate', $parameters);
            $output = trim(Artisan::output());

            if ($output !== '') {
                $reporter->report($output);
            }

            if ($exitCode !== 0) {
                throw new RuntimeException(sprintf('Migration command exited with status %d.', $exitCode));
            }

            $this->flushRuntimeSchemaState();
        } catch (Throwable $throwable) {
            $output = trim(Artisan::output());
            if ($output !== '') {
                $reporter->error($output);
            }

            $reporter->error('✗ Migration failed: ' . $throwable->getMessage());

            throw $throwable;
        }
    }

    /** @return list<string> */
    private function schemaMigrationPaths(): array
    {
        $settings = config('settings.migrations_path');
        $configured = filled($settings) ? [$settings] : config('settings.migrations_paths', []);
        $settingsPaths = [database_path('settings')];
        foreach (is_array($configured) ? $configured : [] as $path) {
            if (is_string($path) && $path !== '') {
                $settingsPaths[] = $path;
            }
        }

        $settingsPaths = array_map($this->normalizePath(...), $settingsPaths);
        $applicationPath = $this->normalizePath(database_path('migrations'));
        $paths = array_map($this->normalizePath(...), resolve(Migrator::class)->paths());
        $registered = array_values(array_unique(array_filter($paths, static fn (string $candidate): bool => $candidate !== $applicationPath && array_all($settingsPaths, fn ($settingsPath): bool => $candidate !== $settingsPath && ! str_starts_with($candidate, $settingsPath . DIRECTORY_SEPARATOR)))));

        return [...$registered, $applicationPath];
    }

    private function normalizePath(string $path): string
    {
        return rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
    }

    private function flushRuntimeSchemaState(): void
    {
        if (app()->bound(RuntimeSchemaState::class)) {
            resolve(RuntimeSchemaState::class)->flush();
        }

        Event::dispatch(new DatabaseSchemaChanged);
    }
}
