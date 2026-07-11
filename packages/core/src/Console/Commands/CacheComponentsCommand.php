<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Facades\CapellCore;
use Illuminate\Console\Command;

class CacheComponentsCommand extends Command
{
    protected $description = 'Cache all capell components';

    protected $signature = 'capell:cache-components';

    public function handle(): int
    {
        $this->info('Caching registered components...');

        CapellCore::cacheComponents();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
