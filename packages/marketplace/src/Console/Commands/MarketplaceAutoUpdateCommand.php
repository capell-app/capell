<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Marketplace\Actions\QueueMarketplaceAutoUpdatesAction;
use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Support\MarketplaceWorkerHeartbeat;
use Illuminate\Console\Command;

final class MarketplaceAutoUpdateCommand extends Command
{
    protected $signature = 'capell:marketplace:auto-update
        {--dry-run : List what would be queued without queueing anything}';

    protected $description = 'Queue updates for extensions whose auto-update policy allows them';

    public function handle(): int
    {
        // Refresh what the marketplace knows about this site before deciding
        // anything. Auto-updating against a week-old advisory snapshot would be
        // acting on stale information at exactly the moment nobody is watching.
        $heartbeat = RunMarketplaceHeartbeatAction::run();

        if (! $heartbeat->successful) {
            $this->components->error(sprintf(
                'The marketplace heartbeat failed, so no automatic update was queued: %s',
                $heartbeat->failureMessage ?? 'no reason given',
            ));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! MarketplaceWorkerHeartbeat::isFresh()) {
            // Queueing without a worker turns an unattended update into an
            // operation that sits there until someone notices, which is the one
            // outcome an unattended feature must not produce.
            $this->components->error('No Marketplace queue worker has reported in, so no automatic update was queued.');

            return self::FAILURE;
        }

        $result = QueueMarketplaceAutoUpdatesAction::run($dryRun);

        if ($dryRun) {
            $this->components->info(sprintf('%d extension(s) would be updated.', $result->requestedCount));

            foreach (resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames() as $composerName) {
                $this->line('  ' . $composerName);
            }

            foreach ($result->skipped as $composerName => $reason) {
                $this->components->warn(sprintf('%s: %s', $composerName, $reason));
            }

            return self::SUCCESS;
        }

        foreach ($result->skipped as $composerName => $reason) {
            $this->components->warn(sprintf('%s: %s', $composerName, $reason));
        }

        $this->components->info(sprintf(
            '%d of %d automatic update(s) queued.',
            $result->queuedCount(),
            $result->requestedCount,
        ));

        return $result->skipped === [] ? self::SUCCESS : self::FAILURE;
    }
}
