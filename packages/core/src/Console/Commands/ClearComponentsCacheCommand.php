<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Facades\CapellCore;
use Illuminate\Console\Command;

class ClearComponentsCacheCommand extends Command
{
    protected $description = 'Clear all cached capell components';

    protected $signature = 'capell:clear-components-cache';

    public function handle(): int
    {
        $this->info('Clearing cached components...');

        CapellCore::clearCachedComponents();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
