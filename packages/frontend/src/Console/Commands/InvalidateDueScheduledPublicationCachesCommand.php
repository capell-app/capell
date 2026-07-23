<?php

declare(strict_types=1);

namespace Capell\Frontend\Console\Commands;

use Capell\Frontend\Actions\InvalidateDueScheduledPublicationCachesAction;
use Illuminate\Console\Command;

final class InvalidateDueScheduledPublicationCachesCommand extends Command
{
    protected $signature = 'capell:invalidate-due-scheduled-publications';

    protected $description = 'Invalidate frontend caches for scheduled publication visibility changes';

    public function handle(): int
    {
        $invalidated = InvalidateDueScheduledPublicationCachesAction::run();

        $this->components->info(sprintf(
            'Invalidated %d scheduled publication cache entr%s.',
            $invalidated,
            $invalidated === 1 ? 'y' : 'ies',
        ));

        return self::SUCCESS;
    }
}
