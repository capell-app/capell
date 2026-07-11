<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrepareEnvironmentAction
{
    use AsObject;

    public function handle(ProgressReporter $reporter): void
    {
        $reporter->step('Preparing environment…');

        EnsureDatabaseExistsAction::run($reporter);

        Artisan::call('storage:link');
        $reporter->report('✓ Storage linked');

        Artisan::call('session:table');
        $reporter->report('✓ Session table created');

        if (! Schema::hasTable('notifications') && ! $this->notificationsMigrationExists()) {
            Artisan::call('notifications:table');
            $reporter->report('✓ Notifications table created');
        }
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
