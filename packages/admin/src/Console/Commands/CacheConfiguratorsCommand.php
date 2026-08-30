<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminRuntimeActivator;
use Filament\Events\ServingFilament;
use Filament\Facades\Filament;
use Illuminate\Console\Command;

class CacheConfiguratorsCommand extends Command
{
    protected $description = 'Cache all configurators';

    protected $signature = 'capell:admin-cache-configurators';

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        // Mirror the panel lifecycle so serving listeners contribute before caching.
        $panel = Filament::getDefaultPanel();
        Filament::setCurrentPanel($panel);
        Filament::bootCurrentPanel();
        event(new ServingFilament);

        $this->info('Caching registered configurators...');

        CapellAdmin::cacheConfigurators();

        $this->info('All done!');

        return static::SUCCESS;
    }
}
