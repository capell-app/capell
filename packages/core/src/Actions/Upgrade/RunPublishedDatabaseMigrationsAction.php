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

        $paths = array_values(ResolvePendingUpgradeMigrationsAction::run()->published);

        // An empty --path falls back to every registered Laravel migration path.
        if ($paths === []) {
            return new MigrationRunResult(0, 'No pending published schema migrations.');
        }

        $exit = Artisan::call('migrate', [
            '--force' => true,
            '--path' => $paths,
            '--realpath' => true,
        ]);

        return new MigrationRunResult($exit, Artisan::output());
    }
}
