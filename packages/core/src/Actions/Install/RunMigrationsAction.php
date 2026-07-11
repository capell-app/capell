<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Events\DatabaseSchemaChanged;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class RunMigrationsAction
{
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
                $parameters['--path'] = database_path($includeSchema ? 'migrations' : 'settings');
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

    private function flushRuntimeSchemaState(): void
    {
        if (app()->bound(RuntimeSchemaState::class)) {
            resolve(RuntimeSchemaState::class)->flush();
        }

        Event::dispatch(new DatabaseSchemaChanged);
    }
}
