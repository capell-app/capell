<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Data\MigrationRunResult;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RunSettingsMigrationsAction
{
    use AsFake;
    use AsObject;

    public function handle(bool $dryRun = false): MigrationRunResult
    {
        if ($dryRun) {
            return new MigrationRunResult(0, '[dry-run] would run: php artisan settings:migrate --force');
        }

        if (! array_key_exists('settings:migrate', Artisan::all())) {
            return new MigrationRunResult(0, 'settings:migrate not available — skipped');
        }

        $exit = Artisan::call('settings:migrate', ['--force' => true]);

        return new MigrationRunResult($exit, Artisan::output());
    }
}
