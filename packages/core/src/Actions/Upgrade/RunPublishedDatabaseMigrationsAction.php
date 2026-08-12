<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\MigrationRunResult;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RunPublishedDatabaseMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $dryRun = false): MigrationRunResult
    {
        if ($dryRun) {
            return new MigrationRunResult(0, '[dry-run] would run: php artisan migrate --force --path=database/migrations --realpath');
        }

        $exit = Artisan::call('migrate', [
            '--force' => true,
            '--path' => database_path('migrations'),
            '--realpath' => true,
        ]);

        return new MigrationRunResult($exit, Artisan::output());
    }
}
