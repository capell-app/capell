<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Backup\PruneBackupsAction;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

final class PruneBackupsCommand extends Command
{
    protected $signature = 'capell:backup:prune {--force : Permanently delete snapshots beyond configured retention}';

    protected $description = 'Preview or apply Capell backup retention pruning.';

    public function handle(PruneBackupsAction $pruneBackups): int
    {
        $force = (bool) $this->option('force');

        try {
            $snapshots = PruneBackupsAction::run($force);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->components->info($force ? 'Pruning applied.' : 'Dry run: no snapshots were deleted.');

        foreach ($snapshots as $snapshotId) {
            $this->line($snapshotId);
        }

        return CommandAlias::SUCCESS;
    }
}
