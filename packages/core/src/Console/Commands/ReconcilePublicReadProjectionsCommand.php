<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\PublicRead\ReconcilePublicReadProjectionsAction;
use Capell\Core\Actions\PublicRead\WithdrawPublicReadProjectionsAction;
use Capell\Core\Support\PublicRead\PublicReadWithdrawalSteps;
use Capell\Core\Support\TableName;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Keeps stored public output in line with the public-read rules. Scheduled
 * every minute (without overlapping, on one server).
 *
 * 1. The fast path: withdrawal steps recorded as owed by an earlier
 *    eligibility change that did not finish are run again.
 * 2. The source of truth: ReconcilePublicReadProjectionsAction advances the
 *    revision when the stored rules no longer match it and withdraws every
 *    store whose copies were built under another stamp, whether or not
 *    anything was recorded as owed.
 */
#[AsCommand(name: 'capell:public-read:reconcile')]
final class ReconcilePublicReadProjectionsCommand extends Command
{
    protected $signature = 'capell:public-read:reconcile {--limit=100 : The most owed steps, and separately the most store and site withdrawals, to run}';

    protected $description = 'Withdraw stored public output built under public-read rules that no longer apply.';

    public function handle(PublicReadWithdrawalSteps $steps): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $owedFailed = $this->retryOwed($steps, $limit);
        $result = ReconcilePublicReadProjectionsAction::run($limit);

        foreach ($result->failures as $label => $message) {
            $this->components->error($label . ': ' . $message);
        }

        $this->components->info(sprintf('Reconciled %d store withdrawal(s); %d failed and will be retried.', count($result->withdrawn), count($result->failures)));

        return $owedFailed === 0 && $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    /** Runs owed steps; returns how many are still owed. */
    private function retryOwed(PublicReadWithdrawalSteps $steps, int $limit): int
    {
        try {
            $table = TableName::resolve(WithdrawPublicReadProjectionsAction::TABLE);
            $owed = DB::table($table)->orderBy('id')->limit($limit)->get();
        } catch (Throwable $throwable) {
            // The reconciler below does not depend on owed rows.
            report($throwable);

            return 0;
        }

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

        return $failed;
    }
}
