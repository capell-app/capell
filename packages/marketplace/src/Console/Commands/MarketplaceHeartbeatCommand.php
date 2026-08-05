<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Illuminate\Console\Command;

/**
 * The marketplace heartbeat as something other than a button.
 *
 * Until this existed the heartbeat was user-triggered only, which meant update
 * detection and security advisories on a site nobody logs into were permanently
 * as old as the last time somebody did.
 */
final class MarketplaceHeartbeatCommand extends Command
{
    protected $signature = 'capell:marketplace:heartbeat';

    protected $description = 'Send the Marketplace heartbeat and refresh update and advisory data';

    public function handle(): int
    {
        $result = RunMarketplaceHeartbeatAction::run();

        if ($result->successful) {
            $this->components->info('Marketplace heartbeat sent.');

            return self::SUCCESS;
        }

        $this->components->error($result->failureMessage ?? 'The Marketplace heartbeat failed.');

        return self::FAILURE;
    }
}
