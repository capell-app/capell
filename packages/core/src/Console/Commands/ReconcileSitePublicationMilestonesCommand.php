<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\FirstRun\ReconcileSitePublicationMilestonesAction;
use Illuminate\Console\Command;

final class ReconcileSitePublicationMilestonesCommand extends Command
{
    protected $signature = 'capell:sites:record-published';

    protected $description = 'Record the go-live date of sites whose home page became public without a publication step.';

    public function handle(ReconcileSitePublicationMilestonesAction $action): int
    {
        $this->info(sprintf('Recorded %d site(s) as live.', $action->handle()));

        return self::SUCCESS;
    }
}
