<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Facades\CapellAdmin;
use Illuminate\Console\Command;

class ClearWidgetsCacheCommand extends Command
{
    protected $description = 'Clear the cached Filament widgets';

    protected $signature = 'capell:admin-clear-widgets-cache';

    public function handle(): int
    {
        $this->info('Clearing cached widgets...');

        CapellAdmin::clearCachedWidgets();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
