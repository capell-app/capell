<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\PublicRead\WithdrawPublicReadProjectionsAction;
use Capell\Core\Support\PublicRead\PublicReadWithdrawalSteps;
use Capell\Core\Support\TableName;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Runs public-read withdrawal steps that are still owed because an earlier
 * attempt failed (a cache store, disk or CDN was unavailable). Scheduled every
 * minute; each step is idempotent and cleared only once it succeeds.
 */
#[AsCommand(name: 'capell:public-read:retry-withdrawals')]
final class RetryOwedPublicReadWithdrawalsCommand extends Command
{
    protected $signature = 'capell:public-read:retry-withdrawals {--limit=100 : The most owed steps to run}';

    protected $description = 'Retry public-read withdrawal steps that have not yet succeeded.';

    public function handle(PublicReadWithdrawalSteps $steps): int
    {
        $table = TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE);

        if (! Schema::hasTable($table)) {
            return self::SUCCESS;
        }

        $owed = DB::table($table)->orderBy('id')->limit(max(1, (int) $this->option('limit')))->get();
        $failed = 0;

        foreach ($owed as $row) {
            $key = (string) $row->step;
            $siteId = $row->site_id === null ? null : (int) $row->site_id;
            $step = $steps->get($key);

            if (! $step instanceof Closure) {
                // The package that owned this step is gone, and with it the copies it kept.
                report(new RuntimeException(sprintf('Dropping owed public-read withdrawal step "%s": no step is registered under that key.', $key)));
                DB::table($table)->where('id', $row->id)->delete();

                continue;
            }

            $failure = WithdrawPublicReadProjectionsAction::attempt((string) $row->revision, $key, (string) $row->scope, $siteId, $step);

            if ($failure instanceof Throwable) {
                $failed++;
                $this->components->error(sprintf('%s (%s): %s', $key, $row->scope, $failure->getMessage()));
            }
        }

        $this->components->info(sprintf('Ran %d owed public-read withdrawal step(s); %d still owed.', $owed->count(), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
