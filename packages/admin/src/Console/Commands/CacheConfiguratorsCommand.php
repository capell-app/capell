<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Facades\CapellAdmin;
use Illuminate\Console\Command;

class CacheConfiguratorsCommand extends Command
{
    protected $description = 'Cache all configurators';

    protected $signature = 'capell:admin-cache-configurators';

    public function handle(): int
    {
        $this->info('Caching registered configurators...');

        CapellAdmin::cacheConfigurators();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
