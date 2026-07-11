<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Facades\CapellAdmin;
use Illuminate\Console\Command;

class ClearConfiguratorsCacheCommand extends Command
{
    protected $description = 'Clear all cached Capell configurators';

    protected $signature = 'capell:admin-clear-configurators-cache';

    public function handle(): int
    {
        $this->info('Clearing cached configurators...');

        CapellAdmin::clearCachedConfigurators();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
