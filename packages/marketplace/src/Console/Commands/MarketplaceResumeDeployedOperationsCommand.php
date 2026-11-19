<?php

declare(strict_types=1);

namespace Capell\Marketplace\Console\Commands;

use Capell\Marketplace\Actions\ResumeMarketplaceAttemptsAwaitingDeploymentAction;
use Illuminate\Console\Command;

/**
 * Continues Marketplace operations whose Composer change has now been deployed.
 *
 * Scheduled every minute, so an extension handed to the deploy pipeline is set
 * up shortly after the deploy goes live, without anyone returning to the
 * admin to press a button.
 */
final class MarketplaceResumeDeployedOperationsCommand extends Command
{
    protected $signature = 'capell:marketplace:resume-deployed';

    protected $description = 'Continue Marketplace operations whose Composer change has been deployed';

    public function handle(): int
    {
        $result = ResumeMarketplaceAttemptsAwaitingDeploymentAction::run();

        $this->components->info(sprintf(
            'Resumed %d deployed Marketplace operation(s); %d stopped waiting.',
            $result['resumed'],
            $result['expired'],
        ));

        return self::SUCCESS;
    }
}
