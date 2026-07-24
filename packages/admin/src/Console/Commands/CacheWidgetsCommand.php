<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminRuntimeActivator;
use Illuminate\Console\Command;

class CacheWidgetsCommand extends Command
{
    protected $description = 'Cache all discoverable Filament widgets';

    protected $signature = 'capell:admin-cache-widgets';

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        $this->info('Caching discoverable widgets...');

        CapellAdmin::cacheWidgets();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
